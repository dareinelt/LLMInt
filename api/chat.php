<?php
/**
 * api/chat.php
 *
 * Proxies a POST /v1/chat/completions request to the best available
 * LM Studio endpoint, selected by the load balancer.
 *
 * The load balancer groups endpoints by their configured default_model.
 * Within a group it picks the endpoint with the fewest running tasks
 * (max 4 per endpoint), favouring the one that was used least recently.
 *
 * Every dispatched request is registered as a task in the DB.
 * Token counts from the LM Studio response are persisted on completion
 * so that statistics can be derived later.
 *
 * Streaming (stream: true) is supported – the response is forwarded
 * as Server-Sent Events (text/event-stream).
 */

// Start the PHP session so that $_SESSION['admin_id'] is available for
// linking conversation sessions to registered users.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/balancer.php';
require_once __DIR__ . '/sd_balancer.php';
require_once __DIR__ . '/comfy_balancer.php';

function buildSearxngSearchUrl(string $baseUrl, string $query): string
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Ungültige SearXNG-URL.');
    }

    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if ($path === '') {
        $path = '/search';
    } elseif (!preg_match('#/search$#', $path)) {
        $path .= '/search';
    }

    $url = $parts['scheme'] . '://';
    if (isset($parts['user'])) {
        $url .= $parts['user'];
        if (isset($parts['pass'])) {
            $url .= ':' . $parts['pass'];
        }
        $url .= '@';
    }
    $url .= $parts['host'];
    if (isset($parts['port'])) {
        $url .= ':' . $parts['port'];
    }
    $url .= $path;

    return $url . '?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'language' => 'de',
        'safesearch' => '0',
    ]);
}

function runSearxngSearch(string $baseUrl, string $query, int $timeout = 15): array
{
    $url = buildSearxngSearchUrl($baseUrl, $query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new RuntimeException('SearXNG nicht erreichbar: ' . $curlErr);
    }

    $data = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($data)) {
        throw new RuntimeException('Unerwartete Antwort von SearXNG (HTTP ' . $httpCode . ').');
    }

    $results = [];
    foreach (array_slice($data['results'] ?? [], 0, 5) as $result) {
        if (!is_array($result)) {
            continue;
        }
        $results[] = [
            'title' => (string) ($result['title'] ?? ''),
            'url' => (string) ($result['url'] ?? ''),
            'snippet' => (string) ($result['content'] ?? ''),
            'source' => (string) ($result['engine'] ?? ''),
        ];
    }

    return [
        'query' => $query,
        'result_count' => count($results),
        'results' => $results,
    ];
}

function startSearchLog(string $query): int
{
    try {
        $db = getDb();
        $db->prepare(
            "INSERT INTO search_logs (query, status, started_at) VALUES (?, 'running', NOW(3))"
        )->execute([$query]);
        return (int) $db->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function completeSearchLog(int $id, string $status): void
{
    if ($id <= 0) {
        return;
    }
    try {
        getDb()->prepare(
            'UPDATE search_logs SET status = ?, finished_at = NOW(3) WHERE id = ?'
        )->execute([$status, $id]);
    } catch (Throwable $e) {
        // Best-effort
    }
}

function createSearchToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'search_web',
            'description' => 'Suche aktuelle Informationen im Web über SearXNG.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Suchanfrage für aktuelle Informationen im Web.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ]];
}

function createImageGenerationToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'generate_image',
            'description' => 'Generiert ein Bild mit Stable Diffusion (AUTOMATIC1111) anhand eines Text-Prompts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Englischer Text-Prompt, der das zu generierende Bild beschreibt.',
                    ],
                    'negative_prompt' => [
                        'type' => 'string',
                        'description' => 'Optionaler negativer Prompt – Elemente, die im Bild vermieden werden sollen.',
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Breite des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Höhe des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                ],
                'required' => ['prompt'],
            ],
        ],
    ]];
}

/**
 * Returns true when at least one active SD endpoint is configured.
 */
function hasSdEndpoints(): bool
{
    try {
        $count = (int) getDb()->query(
            "SELECT COUNT(*) FROM sd_endpoints WHERE is_active = 1"
        )->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Calls api/sd_generate.php internally by performing a loopback HTTP request.
 * Returns an associative array with either 'image_url' (success) or 'error'.
 */
function callSdGenerate(array $params, int $timeout = 120): array
{
    $outputDir = __DIR__ . '/../sd_output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    // Resolve a slot directly instead of doing an HTTP round-trip.
    $mode = in_array($params['mode'] ?? '', ['img2img'], true) ? 'img2img' : 'txt2img';

    try {
        $slot = pickSdEndpoint($mode);
    } catch (Throwable $e) {
        return ['error' => 'Interner Fehler beim SD-Endpunkt-Routing.'];
    }

    if ($slot === null) {
        return ['error' => 'Kein SD-Endpunkt verfügbar.'];
    }

    $endpoint = $slot['endpoint'];
    $taskId   = $slot['task_id'];
    $baseUrl  = rtrim($endpoint['base_url'], '/');
    $epTimeout = max(1, (int) $endpoint['timeout']);

    $prompt          = trim((string) ($params['prompt'] ?? ''));
    $negativePrompt  = (string) ($params['negative_prompt'] ?? '');
    $width           = max(64, min(2048, (int) ($params['width']  ?? 512)));
    $height          = max(64, min(2048, (int) ($params['height'] ?? 512)));
    $steps           = max(1,  min(150,  (int) ($params['steps']  ?? 20)));
    $cfgScale        = max(1.0, min(30.0, (float) ($params['cfg_scale'] ?? 7.0)));

    $sdPayload = [
        'prompt'          => $prompt,
        'negative_prompt' => $negativePrompt,
        'width'           => $width,
        'height'          => $height,
        'steps'           => $steps,
        'cfg_scale'       => $cfgScale,
        'save_images'     => false,
        'send_images'     => true,
    ];

    $apiPath = $mode === 'img2img' ? '/sdapi/v1/img2img' : '/sdapi/v1/txt2img';
    $url     = $baseUrl . $apiPath;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($sdPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $epTimeout,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        completeSdTask($taskId, 'error');
        return ['error' => 'AUTOMATIC1111 nicht erreichbar: ' . $curlErr];
    }

    $data = json_decode($body, true);

    if ($httpCode !== 200 || !is_array($data)) {
        completeSdTask($taskId, 'error');
        $msg = isset($data['detail']) ? (string) $data['detail'] : 'AUTOMATIC1111 Fehler (HTTP ' . $httpCode . ')';
        return ['error' => $msg];
    }

    $images = $data['images'] ?? [];
    if (!is_array($images) || count($images) === 0) {
        completeSdTask($taskId, 'error');
        return ['error' => 'AUTOMATIC1111 hat kein Bild zurückgegeben.'];
    }

    $imageData = base64_decode($images[0], true);
    if ($imageData === false) {
        completeSdTask($taskId, 'error');
        return ['error' => 'Ungültige Bilddaten von AUTOMATIC1111.'];
    }

    $filename = 'sd_' . bin2hex(random_bytes(12)) . '.png';
    $filePath = $outputDir . '/' . $filename;

    if (file_put_contents($filePath, $imageData) === false) {
        completeSdTask($taskId, 'error');
        return ['error' => 'Bild konnte nicht gespeichert werden.'];
    }

    completeSdTask($taskId, 'done');

    $seed = null;
    if (isset($data['info'])) {
        $info = json_decode((string) $data['info'], true);
        if (is_array($info) && isset($info['seed'])) {
            $seed = (int) $info['seed'];
        }
    }

    return [
        'image_url' => 'sd_output/' . $filename,
        'width'     => $width,
        'height'    => $height,
        'prompt'    => $prompt,
        'seed'      => $seed,
    ];
}

function tokenizeQueryTerms(string $query): array
{
    $query = mb_strtolower(trim($query));
    if ($query === '') {
        return [];
    }
    $parts = preg_split('/[^\p{L}\p{N}_-]+/u', $query) ?: [];
    $terms = [];
    foreach ($parts as $term) {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            continue;
        }
        $terms[$term] = true;
    }
    return array_keys($terms);
}

function buildRagSnippet(string $text, array $terms, int $maxLen = 1200): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    $lower = mb_strtolower($text);
    $pos = null;
    foreach ($terms as $term) {
        $idx = mb_strpos($lower, $term);
        if ($idx !== false && ($pos === null || $idx < $pos)) {
            $pos = $idx;
        }
    }

    if ($pos === null) {
        return mb_substr($text, 0, $maxLen) . ' … [gekürzt]';
    }

    $start = max(0, $pos - (int) floor($maxLen / 3));
    $snippet = mb_substr($text, $start, $maxLen);
    if ($start > 0) {
        $snippet = '… ' . $snippet;
    }
    if (($start + $maxLen) < mb_strlen($text)) {
        $snippet .= ' …';
    }
    return $snippet;
}

function scoreRagChunk(string $chunkText, string $query, array $terms): float
{
    $lowerChunk = mb_strtolower($chunkText);
    $score = 0.0;
    $matchedTerms = 0;

    $queryLower = mb_strtolower($query);
    if ($queryLower !== '' && mb_strpos($lowerChunk, $queryLower) !== false) {
        $score += 8.0;
    }

    foreach ($terms as $term) {
        $hits = substr_count($lowerChunk, $term);
        if ($hits > 0) {
            $matchedTerms++;
            $score += min(4.0, 1.2 * $hits);
        }
    }

    if ($matchedTerms > 0) {
        $coverage = $matchedTerms / max(1, count($terms));
        $score += $coverage * 4.0;
    }

    $len = max(1, mb_strlen($chunkText));
    $score += min(1.5, 700 / $len);

    return $score;
}

/**
 * Returns true when at least one analysed document upload is available
 * for the current user (own uploads or globally shared uploads).
 */
function hasDocumentUploads(?int $userId): bool
{
    if ($userId === null || $userId <= 0) {
        return false;
    }
    try {
        $stmt = getDb()->prepare(
            "SELECT COUNT(*)
               FROM document_uploads
              WHERE status = 'done'
                AND chunk_count > 0"
                . " AND (user_id = ? OR is_global_rag = 1)"
        );
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function createDocumentQueryToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'query_documents',
            'description' => 'Durchsucht analysierte Dokumente (eigene Uploads sowie global freigegebene Uploads anderer Nutzer) per chunk-basierter RAG-Suche. Gib in deiner Antwort an, aus welchem Dokument die Informationen stammen.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Suchanfrage, nach der in den Dokumenten gesucht werden soll.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ]];
}

/**
 * Search through extracted document texts for relevant content.
 * Returns an array with matching document excerpts.
 */
function queryDocuments(string $query, ?int $userId): array
{
    $query = trim($query);
    if ($query === '') {
        return ['error' => 'Leere Suchanfrage.'];
    }
    if ($userId === null || $userId <= 0) {
        return ['error' => 'Dokumentsuche ist nur für angemeldete Nutzer verfügbar.'];
    }

    try {
        $db = getDb();
        $terms = array_slice(tokenizeQueryTerms($query), 0, 12);

        $stmt = $db->prepare(
            "SELECT du.id AS document_id, du.original_name, dc.chunk_index, dc.chunk_text
               FROM document_chunks dc
               JOIN document_uploads du ON du.id = dc.document_upload_id
              WHERE du.status = 'done'
                AND (du.user_id = ? OR du.is_global_rag = 1)
              ORDER BY du.uploaded_at DESC, dc.chunk_index ASC
              LIMIT 500"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [
                'found' => false,
                'message' => 'Es sind noch keine analysierten Dokument-Chunks (eigene oder global freigegebene) verfügbar.',
            ];
        }

        $ranked = [];
        foreach ($rows as $row) {
            $chunkText = trim((string) ($row['chunk_text'] ?? ''));
            if ($chunkText === '') {
                continue;
            }
            $score = scoreRagChunk($chunkText, $query, $terms);
            if ($score <= 0.0) {
                continue;
            }
            $ranked[] = [
                'document_id' => (int) ($row['document_id'] ?? 0),
                'document' => (string) ($row['original_name'] ?? 'Unbekannt'),
                'chunk_index' => (int) ($row['chunk_index'] ?? 0),
                'content' => buildRagSnippet($chunkText, $terms),
                'score' => $score,
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $ranked = array_slice($ranked, 0, 5);
        if (empty($ranked)) {
            return [
                'found' => false,
                'message' => 'Keine passenden Informationen in den analysierten Dokumenten gefunden.',
            ];
        }

        $results = array_map(static function (array $row): array {
            return [
                'document' => $row['document'],
                'chunk' => $row['chunk_index'] + 1,
                'relevance' => round((float) $row['score'], 3),
                'content' => $row['content'],
            ];
        }, $ranked);

        return [
            'found'   => true,
            'results' => $results,
        ];
    } catch (Throwable $e) {
        return ['error' => 'Datenbankfehler: ' . $e->getMessage()];
    }
}

/**
 * Returns true when at least one active ComfyUI endpoint is configured.
 */
function hasComfyEndpoints(): bool
{
    try {
        $count = (int) getDb()->query(
            "SELECT COUNT(*) FROM comfy_endpoints WHERE is_active = 1"
        )->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function createComfyToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'generate_image_comfy',
            'description' => 'Generiert ein Bild mit ComfyUI anhand eines Text-Prompts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Englischer Text-Prompt, der das zu generierende Bild beschreibt.',
                    ],
                    'negative_prompt' => [
                        'type' => 'string',
                        'description' => 'Optionaler negativer Prompt – Elemente, die im Bild vermieden werden sollen.',
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Breite des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Höhe des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                ],
                'required' => ['prompt'],
            ],
        ],
    ]];
}

/**
 * Generate an image via ComfyUI, analogous to callSdGenerate().
 * Returns an associative array with either 'image_url' (success) or 'error'.
 */
function callComfyGenerate(array $params, int $timeout = 120): array
{
    $outputDir = __DIR__ . '/../sd_output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    try {
        $slot = pickComfyEndpoint();
    } catch (Throwable $e) {
        return ['error' => 'Interner Fehler beim ComfyUI-Endpunkt-Routing.'];
    }

    if ($slot === null) {
        return ['error' => 'Kein ComfyUI-Endpunkt verfügbar.'];
    }

    $endpoint   = $slot['endpoint'];
    $taskId     = $slot['task_id'];
    $baseUrl    = rtrim($endpoint['base_url'], '/');
    $epTimeout  = max(1, (int) $endpoint['timeout']);
    $checkpoint = (string) ($endpoint['default_checkpoint'] ?: '');

    $prompt         = trim((string) ($params['prompt'] ?? ''));
    $negativePrompt = (string) ($params['negative_prompt'] ?? '');
    $width          = max(64, min(2048, (int) ($params['width']  ?? 512)));
    $height         = max(64, min(2048, (int) ($params['height'] ?? 512)));
    $steps          = max(1,  min(150,  (int) ($params['steps']  ?? 20)));
    $cfgScale       = max(1.0, min(30.0, (float) ($params['cfg_scale'] ?? 7.0)));
    $seed           = isset($params['seed']) ? (int) $params['seed'] : random_int(0, PHP_INT_MAX);
    $clientId       = bin2hex(random_bytes(8));

    // If no checkpoint configured, query the first available one.
    if ($checkpoint === '') {
        $infoUrl = $baseUrl . '/object_info/CheckpointLoaderSimple';
        $infoCh  = curl_init($infoUrl);
        curl_setopt_array($infoCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $infoBody = curl_exec($infoCh);
        $infoData = json_decode((string) $infoBody, true);
        curl_close($infoCh);

        $ckptList = $infoData['CheckpointLoaderSimple']['input']['required']['ckpt_name'][0] ?? [];
        if (is_array($ckptList) && !empty($ckptList)) {
            $checkpoint = (string) reset($ckptList);
        } else {
            completeComfyTask($taskId, 'error');
            return ['error' => 'Kein Checkpoint konfiguriert und kein Checkpoint auf dem ComfyUI-Server gefunden.'];
        }
    }

    $workflow = [
        '4' => [
            'class_type' => 'CheckpointLoaderSimple',
            'inputs'     => ['ckpt_name' => $checkpoint],
        ],
        '5' => [
            'class_type' => 'EmptyLatentImage',
            'inputs'     => ['batch_size' => 1, 'height' => $height, 'width' => $width],
        ],
        '6' => [
            'class_type' => 'CLIPTextEncode',
            'inputs'     => ['clip' => ['4', 1], 'text' => $prompt],
        ],
        '7' => [
            'class_type' => 'CLIPTextEncode',
            'inputs'     => ['clip' => ['4', 1], 'text' => $negativePrompt],
        ],
        '3' => [
            'class_type' => 'KSampler',
            'inputs'     => [
                'seed'         => $seed,
                'steps'        => $steps,
                'cfg'          => $cfgScale,
                'sampler_name' => 'euler',
                'scheduler'    => 'normal',
                'denoise'      => 1.0,
                'model'        => ['4', 0],
                'positive'     => ['6', 0],
                'negative'     => ['7', 0],
                'latent_image' => ['5', 0],
            ],
        ],
        '8' => [
            'class_type' => 'VAEDecode',
            'inputs'     => ['samples' => ['3', 0], 'vae' => ['4', 2]],
        ],
        '9' => [
            'class_type' => 'SaveImage',
            'inputs'     => ['filename_prefix' => 'ComfyUI', 'images' => ['8', 0]],
        ],
    ];

    $ch = curl_init($baseUrl . '/prompt');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['client_id' => $clientId, 'prompt' => $workflow]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        completeComfyTask($taskId, 'error');
        return ['error' => 'ComfyUI nicht erreichbar: ' . $curlErr];
    }

    $queueData = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($queueData) || empty($queueData['prompt_id'])) {
        completeComfyTask($taskId, 'error');
        $errMsg = isset($queueData['error']) ? (string) $queueData['error'] : 'ComfyUI Fehler beim Einreihen (HTTP ' . $httpCode . ')';
        return ['error' => $errMsg];
    }

    $promptId = (string) $queueData['prompt_id'];
    $deadline = time() + $epTimeout;
    $historyData = null;

    while (time() < $deadline) {
        sleep(1);
        $hCh = curl_init($baseUrl . '/history/' . rawurlencode($promptId));
        curl_setopt_array($hCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $hBody = curl_exec($hCh);
        $hCode = curl_getinfo($hCh, CURLINFO_HTTP_CODE);
        curl_close($hCh);

        if ($hCode !== 200) {
            continue;
        }
        $hData = json_decode($hBody, true);
        if (!is_array($hData) || empty($hData[$promptId])) {
            continue;
        }
        $entry = $hData[$promptId];

        if (!empty($entry['status']['status_str']) && $entry['status']['status_str'] === 'error') {
            completeComfyTask($taskId, 'error');
            return ['error' => 'ComfyUI Generierungsfehler.'];
        }
        if (!empty($entry['outputs'])) {
            $historyData = $entry;
            break;
        }
    }

    if ($historyData === null) {
        completeComfyTask($taskId, 'error');
        return ['error' => 'ComfyUI Timeout: Bild wurde nicht rechtzeitig fertiggestellt.'];
    }

    $imageInfo = null;
    foreach ($historyData['outputs'] as $nodeOutput) {
        $images = is_array($nodeOutput) ? ($nodeOutput['images'] ?? []) : [];
        if (is_array($images) && !empty($images)) {
            $imageInfo = $images[0];
            break;
        }
    }

    if (!is_array($imageInfo) || empty($imageInfo['filename'])) {
        completeComfyTask($taskId, 'error');
        return ['error' => 'ComfyUI hat kein Bild zurückgegeben.'];
    }

    $viewUrl = $baseUrl . '/view?' . http_build_query([
        'filename'  => $imageInfo['filename'],
        'subfolder' => $imageInfo['subfolder'] ?? '',
        'type'      => $imageInfo['type'] ?? 'output',
    ]);

    $imgCh = curl_init($viewUrl);
    curl_setopt_array($imgCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $imageData = curl_exec($imgCh);
    $imgCode   = curl_getinfo($imgCh, CURLINFO_HTTP_CODE);
    $imgErr    = curl_error($imgCh);
    curl_close($imgCh);

    if ($imgErr !== '' || $imgCode !== 200 || $imageData === false || $imageData === '') {
        completeComfyTask($taskId, 'error');
        return ['error' => 'Bild konnte nicht von ComfyUI heruntergeladen werden.'];
    }

    $filename = 'comfy_' . bin2hex(random_bytes(12)) . '.png';
    $filePath = $outputDir . '/' . $filename;

    if (file_put_contents($filePath, $imageData) === false) {
        completeComfyTask($taskId, 'error');
        return ['error' => 'Bild konnte nicht gespeichert werden.'];
    }

    completeComfyTask($taskId, 'done');

    return [
        'image_url' => 'sd_output/' . $filename,
        'width'     => $width,
        'height'    => $height,
        'prompt'    => $prompt,
        'seed'      => $seed,
    ];
}
function extractUsage(array $data): array
{
    return [
        'prompt' => max(0, (int) ($data['usage']['prompt_tokens'] ?? 0)),
        'completion' => max(0, (int) ($data['usage']['completion_tokens'] ?? 0)),
        'total' => max(0, (int) ($data['usage']['total_tokens'] ?? 0)),
    ];
}

function normalizeAssistantContent(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $item) {
        if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text']) && is_string($item['text'])) {
            $parts[] = $item['text'];
        }
    }

    return implode("\n", $parts);
}

function ensureSseHeaders(): void
{
    static $headersSent = false;

    if ($headersSent) {
        return;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    $headersSent = true;
}

function emitSseData(array|string $payload): void
{
    ensureSseHeaders();

    if (is_array($payload)) {
        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    echo 'data: ' . $payload . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function formatIntelligenceLabel(float $value): string
{
    if (abs($value - round($value)) < 0.00001) {
        return ((string) ((int) round($value))) . 'b';
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . 'b';
}

function buildIntelligenceUpgradePayload(array $suggestion): array
{
    $defaultMessage = 'Es stehen Ressourcen bereit um die Aufgabe erneut mit größerer Intelligenz zu bearbeiten. Dies kann länger dauern als zuvor, kann jedoch genauere Antworten liefern. Fortfahren?';
    $configuredMessage = trim(getSetting('intelligence_upgrade_message', ''));
    return [
        'available' => true,
        'current_model' => (string) ($suggestion['requested_model'] ?? ''),
        'current_intelligence' => formatIntelligenceLabel((float) ($suggestion['requested_intelligence'] ?? 0)),
        'suggested_model' => (string) ($suggestion['model'] ?? ''),
        'suggested_intelligence' => formatIntelligenceLabel((float) ($suggestion['suggested_intelligence'] ?? 0)),
        'message' => $configuredMessage !== '' ? $configuredMessage : $defaultMessage,
    ];
}

function emitIntelligenceUpgradeSse(?array $suggestion): void
{
    if ($suggestion === null) {
        return;
    }
    emitSseData([
        'type' => 'intelligence_upgrade',
        'upgrade' => buildIntelligenceUpgradePayload($suggestion),
    ]);
}

function buildResponseDetails(array $endpoint): array
{
    $alias = trim((string) ($endpoint['alias'] ?? ''));
    $baseUrl = trim((string) ($endpoint['base_url'] ?? ''));
    return [
        'processed_by' => $alias !== '' ? $alias : $baseUrl,
    ];
}

function emitResponseDetailsSse(?array $responseDetails): void
{
    if ($responseDetails === null) {
        return;
    }
    emitSseData([
        'type' => 'response_details',
        'details' => $responseDetails,
    ]);
}

function emitSyntheticStream(array $data, ?array $upgradeSuggestion = null, ?array $responseDetails = null): void
{
    $content = normalizeAssistantContent($data['choices'][0]['message']['content'] ?? '');
    $id = (string) ($data['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(8))));
    $created = (int) ($data['created'] ?? time());
    $model = (string) ($data['model'] ?? '');

    ensureSseHeaders();

    if ($content !== '') {
        emitSseData([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => ['content' => $content],
                'finish_reason' => null,
            ]],
        ]);
    }

    emitSseData([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'delta' => new stdClass(),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'stop',
        ]],
    ]);
    emitIntelligenceUpgradeSse($upgradeSuggestion);
    emitResponseDetailsSse($responseDetails);
    emitSseData('[DONE]');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ungültiger JSON-Body.']);
    exit;
}

if (empty($payload['model']) || empty($payload['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '"model" und "messages" sind Pflichtfelder.']);
    exit;
}

// Validate messages array.
foreach ($payload['messages'] as $msg) {
    if (!isset($msg['role'], $msg['content'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Jede Nachricht muss "role" und "content" enthalten.']);
        exit;
    }
    $allowed = ['system', 'user', 'assistant'];
    if (!in_array($msg['role'], $allowed, true)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Ungültige Rolle: ' . htmlspecialchars($msg['role'])]);
        exit;
    }
}

// ── Select endpoint via load balancer ─────────────────────────────────────────

$model = $payload['model'];

// ── Rule-based model routing ──────────────────────────────────────────────────
// If a routing decision model is configured, classify the user's prompt and
// replace $model with the category-specific target model before dispatching.

$routingDecisionModel = trim(getSetting('routing_decision_model', ''));
if ($routingDecisionModel !== '') {
    // Extract the last user message text for classification.
    $lastUserText = '';
    foreach (array_reverse($payload['messages']) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $lastUserText = is_string($msg['content']) ? $msg['content'] : '';
            break;
        }
    }

    if ($lastUserText !== '') {
        $systemPrompt = buildRoutingPrompt();

        if ($systemPrompt !== '') {
            // Pick a slot for the decision model (non-blocking: skip if unavailable).
            try {
                $routingSlot = pickEndpointForModel($routingDecisionModel);
            } catch (Throwable $e) {
                $routingSlot = null;
            }

            if ($routingSlot !== null) {
                $routingEndpoint = $routingSlot['endpoint'];
                $routingTaskId   = $routingSlot['task_id'];
                $routingBaseUrl  = rtrim($routingEndpoint['base_url'], '/');
                $routingTimeout  = max(10, min(60, (int) $routingEndpoint['timeout']));

                $routingPayload = [
                    'model'       => $routingDecisionModel,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $lastUserText],
                    ],
                    'stream'      => false,
                    'temperature' => 0.0,
                    'max_tokens'  => 20,
                ];

                $rch = curl_init($routingBaseUrl . '/chat/completions');
                curl_setopt_array($rch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($routingPayload),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $routingTimeout,
                ]);
                $routingBody = curl_exec($rch);
                $routingHttpCode = (int) curl_getinfo($rch, CURLINFO_HTTP_CODE);
                $routingCurlErr  = curl_error($rch);
                curl_close($rch);

                completeTask($routingTaskId, ($routingCurlErr !== '' || $routingHttpCode !== 200) ? 'error' : 'done');

                if ($routingCurlErr === '' && $routingHttpCode === 200) {
                    $routingData = json_decode($routingBody, true);
                    $detectedCategory = trim(
                        (string) ($routingData['choices'][0]['message']['content'] ?? '')
                    );
                    // Strip surrounding whitespace, newlines and any markdown artefacts.
                    $detectedCategory = trim($detectedCategory, " \t\n\r\0\x0B`\"'");

                    // Look up the model assigned to this category.
                    $routingRules = loadRoutingRules();
                    if ($detectedCategory !== '' && isset($routingRules[$detectedCategory]) && $routingRules[$detectedCategory] !== '') {
                        $model = $routingRules[$detectedCategory];
                        $payload['model'] = $model;
                    }
                }
            }
        }
    }
}

$intelligenceUpgrade = null;
try {
    $intelligenceUpgrade = getUpgradeModelSuggestionForRequestedModel($model);
} catch (Throwable $e) {
    $intelligenceUpgrade = null;
}

// Extract and validate the optional session ID for conversation persistence.
$sessionId = '';
if (isset($payload['session_id']) && is_string($payload['session_id'])) {
    $rawSessionId = $payload['session_id'];
    if (preg_match('/^[a-f0-9]{8,128}$/', $rawSessionId)) {
        $sessionId = $rawSessionId;
    }
}

// ── Session-level intelligence upgrade persistence ────────────────────────────
// When the user accepted an intelligence upgrade, remember the upgraded model
// for 20 minutes so that all subsequent requests in the same session are also
// routed to the higher-intelligence endpoint.
$upgradeAccepted = isset($payload['intelligence_upgrade_accepted']) && $payload['intelligence_upgrade_accepted'] === true;

if ($upgradeAccepted && $sessionId !== '' && $model !== '') {
    // Persist the accepted upgrade model so future requests in this session
    // (within 20 minutes) are automatically routed to it.
    setSessionUpgradeModel($sessionId, $model);
} elseif (!$upgradeAccepted && $sessionId !== '') {
    // Apply a previously accepted upgrade if it is still within the 20-minute window.
    $activeUpgradeModel = getActiveSessionUpgradeModel($sessionId);
    if ($activeUpgradeModel !== null && $activeUpgradeModel !== $model) {
        $model = $activeUpgradeModel;
        $payload['model'] = $model;
        $intelligenceUpgrade = null; // already on the upgraded model, no further suggestion needed
    }
}

// Resolve the currently logged-in user for session ownership.
$sessionUserId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
// Release the session write lock immediately. chat.php can run for many seconds
// (waiting for the LLM response) and holding the lock blocks every other same-session
// request – most critically the admin/load_stats.php polling endpoint.
session_write_close();

// Occasionally purge expired conversation sessions (5 % probability).
if (mt_rand(1, 20) === 1) {
    purgeExpiredConversationSessions();
}

// When the user-selected model is also the routing decision model, reserve one
// slot per endpoint exclusively for routing decisions so they are never delayed.
$userSlotMax = ($routingDecisionModel !== '' && $model === $routingDecisionModel) ? 3 : 4;

try {
    $slot = pickEndpointForModel($model, $userSlotMax);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
    exit;
}

if ($slot === null) {
    try {
        $hasMatchingEndpoint = hasActiveEndpointForModel($model);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
        exit;
    }

    if (!$hasMatchingEndpoint) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Kein passender Endpunkt verfügbar.']);
        exit;
    }

    ignore_user_abort(true);
    @set_time_limit(0);

    if (isset($payload['stream']) && $payload['stream'] === true) {
        emitSseData([
            'status' => 'queued',
            'message' => 'Alle LLM-Ressourcen sind derzeit belegt. Die Bearbeitung beginnt automatisch, sobald ein Slot frei wird.',
        ]);
    }

    while ($slot === null) {
        if (connection_aborted()) {
            exit;
        }

        usleep(500000);

        try {
            if (!hasActiveEndpointForModel($model)) {
                if (isset($payload['stream']) && $payload['stream'] === true) {
                    emitSseData(['error' => 'Kein passender Endpunkt mehr verfügbar.']);
                } else {
                    http_response_code(503);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => 'Kein passender Endpunkt mehr verfügbar.']);
                }
                exit;
            }

            $slot = pickEndpointForModel($model, $userSlotMax);
        } catch (Throwable $e) {
            if (isset($payload['stream']) && $payload['stream'] === true) {
                emitSseData(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
            } else {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
            }
            exit;
        }
    }
}

$endpoint = $slot['endpoint'];
$taskId   = $slot['task_id'];
$baseUrl  = rtrim($endpoint['base_url'], '/');
$timeout  = max(1, (int) $endpoint['timeout']);
$responseDetails = buildResponseDetails($endpoint);
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));
$requestStart = microtime(true);

// Ensure the task is always marked finished, even on unexpected PHP termination.
$taskFinished = false;
register_shutdown_function(static function () use (&$taskId, &$taskFinished): void {
    if (!$taskFinished) {
        try {
            completeTask($taskId, 'error');
        } catch (Throwable $e) {
            // Best-effort – nothing more we can do in a shutdown handler.
        }
    }
});

// Maximum number of additional endpoints to try when the primary one fails.
$endpointRetries = 2;
$upgradeFailoverTried = false;
$forwardPayload = [];

/**
 * Marks the current task as failed, picks a new endpoint for $model,
 * and updates the shared $endpoint / $taskId / $baseUrl / $timeout / $url
 * variables in the outer scope. Returns false when no further endpoint
 * is available or the retry budget is exhausted.
 */
$switchEndpoint = function (bool $allowUpgradeFallback = false) use (
    &$model, $userSlotMax,
    &$endpoint, &$taskId, &$baseUrl, &$timeout, &$url, &$endpointRetries, &$responseDetails,
    &$upgradeFailoverTried, &$intelligenceUpgrade, &$forwardPayload, &$payload
): bool {
    if ($endpointRetries <= 0) {
        return false;
    }
    $endpointRetries--;
    try {
        completeTask($taskId, 'error');
    } catch (Throwable $e) {}
    try {
        $newSlot = pickEndpointForModel($model, $userSlotMax);
    } catch (Throwable $e) {
        return false;
    }

    if ($newSlot === null && $allowUpgradeFallback && !$upgradeFailoverTried) {
        $upgradeFailoverTried = true;
        try {
            $upgrade = getUpgradeModelSuggestionForRequestedModel($model);
            if (is_array($upgrade) && !empty($upgrade['model'])) {
                $upgradeModel = (string) $upgrade['model'];
                $newSlot = pickEndpointForModel($upgradeModel, 4);
                if ($newSlot !== null) {
                    $model = $upgradeModel;
                    $payload['model'] = $upgradeModel;
                    $forwardPayload['model'] = $upgradeModel;
                    $intelligenceUpgrade = null;
                }
            }
        } catch (Throwable $e) {
            $newSlot = null;
        }
    }

    if ($newSlot === null) {
        return false;
    }
    $endpoint = $newSlot['endpoint'];
    $taskId   = $newSlot['task_id'];
    $baseUrl  = rtrim($endpoint['base_url'], '/');
    $timeout  = max(1, (int) $endpoint['timeout']);
    $responseDetails = buildResponseDetails($endpoint);
    $url      = $baseUrl . '/chat/completions';
    return true;
};

$clientRequestedStream = isset($payload['stream']) && $payload['stream'] === true;
$useSearchTool   = $searxngBaseUrl !== '';
$useSdTool       = hasSdEndpoints();
$useComfyTool    = hasComfyEndpoints();
$useDocQueryTool = hasDocumentUploads($sessionUserId);
$useTools        = $useSearchTool || $useSdTool || $useComfyTool || $useDocQueryTool;
$stream = $clientRequestedStream && !$useTools;

// Forward only the fields LM Studio expects.
$forwardPayload = [
    'model'       => $model,
    'messages'    => $payload['messages'],
    'stream'      => $stream,
    'temperature' => $payload['temperature'] ?? 0.7,
    'max_tokens'  => $payload['max_tokens']  ?? -1,
];

$url = $baseUrl . '/chat/completions';

if ($useTools) {
    $messages = $payload['messages'];
    $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
    $finalData = null;
    $searchQueryUsed = '';

    // When an intelligence upgrade re-runs a query that already used search_web,
    // the caller may supply the original search query so we can pre-fetch fresh
    // results and inject them into the context before the LLM loop starts.
    $forceSearchQuery = '';
    if ($useSearchTool && isset($payload['force_search_query']) && is_string($payload['force_search_query'])) {
        $forceSearchQuery = trim($payload['force_search_query']);
    }
    if ($forceSearchQuery !== '') {
        $fakeToolCallId = 'call_' . bin2hex(random_bytes(8));
        $searchLogId = startSearchLog(substr($forceSearchQuery, 0, 400));
        try {
            $searchResult = runSearxngSearch($searxngBaseUrl, substr($forceSearchQuery, 0, 400), min($timeout, 15));
            completeSearchLog($searchLogId, 'done');
            $searchQueryUsed = $forceSearchQuery;
        } catch (Throwable $e) {
            completeSearchLog($searchLogId, 'error');
            $searchResult = ['error' => $e->getMessage()];
        }
        $messages[] = [
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => [[
                'id'       => $fakeToolCallId,
                'type'     => 'function',
                'function' => [
                    'name'      => 'search_web',
                    'arguments' => json_encode(['query' => $forceSearchQuery], JSON_UNESCAPED_UNICODE),
                ],
            ]],
        ];
        $messages[] = [
            'role'         => 'tool',
            'tool_call_id' => $fakeToolCallId,
            'content'      => json_encode($searchResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    // Build the tool list from active integrations.
    $tools = [];
    if ($useSearchTool) {
        $tools = array_merge($tools, createSearchToolDefinition());
    }
    if ($useSdTool) {
        $tools = array_merge($tools, createImageGenerationToolDefinition());
    }
    if ($useComfyTool) {
        $tools = array_merge($tools, createComfyToolDefinition());
    }
    if ($useDocQueryTool) {
        $tools = array_merge($tools, createDocumentQueryToolDefinition());
    }

    for ($iteration = 0; $iteration < 6; $iteration++) {
        $toolPayload = $forwardPayload;
        $toolPayload['messages'] = $messages;
        $toolPayload['stream'] = false;
        $toolPayload['tools'] = $tools;
        $toolPayload['tool_choice'] = 'auto';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($toolPayload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if ($curlErr !== '') {
            if ($switchEndpoint(true)) {
                $url = $baseUrl . '/chat/completions';
                $iteration--;  // redo this iteration with the new endpoint
                continue;
            }
            $taskFinished = true;
            completeTask($taskId, 'error');
            if ($clientRequestedStream && headers_sent()) {
                emitSseData(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
            } else {
                http_response_code(502);
                echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
            }
            exit;
        }

        $data = json_decode($body, true);
        if ($httpCode !== 200 || !is_array($data)) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
            if ($switchEndpoint()) {
                $url = $baseUrl . '/chat/completions';
                $iteration--;  // redo this iteration with the new endpoint
                continue;
            }
            $taskFinished = true;
            completeTask($taskId, 'error');
            if ($clientRequestedStream && headers_sent()) {
                emitSseData(['error' => $msg]);
            } else {
                http_response_code(502);
                echo json_encode(['error' => $msg]);
            }
            exit;
        }

        $stepUsage = extractUsage($data);
        $usage['prompt'] += $stepUsage['prompt'];
        $usage['completion'] += $stepUsage['completion'];
        $usage['total'] += $stepUsage['total'];

        $message = $data['choices'][0]['message'] ?? null;
        $toolCalls = is_array($message) ? ($message['tool_calls'] ?? []) : [];
        if (!is_array($toolCalls) || $toolCalls === []) {
            $finalData = $data;
            break;
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $toolCalls,
        ];

        foreach ($toolCalls as $toolCall) {
            $toolResult = ['error' => 'Unbekannter Tool-Aufruf.'];
            $toolName   = $toolCall['function']['name'] ?? '';

            if ($toolName === 'search_web' && $useSearchTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $query = trim((string) ($args['query'] ?? ''));

                if ($query === '') {
                    $toolResult = ['error' => 'Leere Suchanfrage.'];
                } else {
                    $searchLogId = startSearchLog(substr($query, 0, 400));
                    try {
                        $toolResult = runSearxngSearch($searxngBaseUrl, substr($query, 0, 400), min($timeout, 15));
                        completeSearchLog($searchLogId, 'done');
                        $searchQueryUsed = $query;
                    } catch (Throwable $e) {
                        completeSearchLog($searchLogId, 'error');
                        $toolResult = ['error' => $e->getMessage()];
                    }
                }
            } elseif ($toolName === 'generate_image' && $useSdTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                $toolResult = callSdGenerate($args, $timeout);
                // Include a markdown image in the result so the LLM can reference it.
                if (isset($toolResult['image_url'])) {
                    $toolResult['markdown'] = '![Generiertes Bild](' . $toolResult['image_url'] . ')';
                }
            } elseif ($toolName === 'generate_image_comfy' && $useComfyTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                $toolResult = callComfyGenerate($args, $timeout);
                if (isset($toolResult['image_url'])) {
                    $toolResult['markdown'] = '![Generiertes Bild (ComfyUI)](' . $toolResult['image_url'] . ')';
                }
            } elseif ($toolName === 'query_documents' && $useDocQueryTool) {
                $args  = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $query = trim((string) ($args['query'] ?? ''));
                $toolResult = queryDocuments($query, $sessionUserId);
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    if ($finalData === null) {
        $taskFinished = true;
        completeTask($taskId, 'error');
        if ($clientRequestedStream && headers_sent()) {
            emitSseData(['error' => 'Der Tool-Dialog mit dem Modell konnte nicht abgeschlossen werden.']);
        } else {
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Der Tool-Dialog mit dem Modell konnte nicht abgeschlossen werden.']);
        }
        exit;
    }

    $finalData['usage'] = [
        'prompt_tokens' => $usage['prompt'],
        'completion_tokens' => $usage['completion'],
        'total_tokens' => $usage['total'],
    ];
    if ($intelligenceUpgrade !== null) {
        $finalData['intelligence_upgrade'] = buildIntelligenceUpgradePayload($intelligenceUpgrade);
    }
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    if ($searchQueryUsed !== '') {
        $responseDetails['search_query'] = $searchQueryUsed;
    }
    $finalData['response_details'] = $responseDetails;

    $taskFinished = true;
    completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total']);

    // Persist the conversation so it survives future endpoint failures.
    if ($sessionId !== '') {
        $assistantContent = normalizeAssistantContent(
            $finalData['choices'][0]['message']['content'] ?? ''
        );
        $sessionMessages = array_merge(
            $payload['messages'],
            [['role' => 'assistant', 'content' => $assistantContent]]
        );
        saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
    }

    if ($clientRequestedStream) {
        emitSyntheticStream($finalData, $intelligenceUpgrade, $responseDetails);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
]);

// ── Streaming path ────────────────────────────────────────────────────────────

if ($stream) {
    ignore_user_abort(true);
    @set_time_limit(0);

    // Attempt the stream; retry with a different endpoint if it fails before
    // any data has been written to the client.
    $dataWritten      = false;
    $tailBuffer       = '';
    $accumulatedText  = '';  // for session persistence
    $streamCurlErr    = '';
    $streamHttpCode   = 0;

    do {
        $tailBuffer      = '';
        $accumulatedText = '';
        $dataWritten     = false;

        $chStream = curl_init($url);
        curl_setopt_array($chStream, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => 0,
        ]);

        curl_setopt($chStream, CURLOPT_WRITEFUNCTION,
            static function ($ch, $data) use (&$tailBuffer, &$accumulatedText, &$dataWritten): int {
                if (!$dataWritten) {
                    // Emit SSE headers on first data chunk (deferred so we can
                    // still switch the endpoint if the connection was refused).
                    ensureSseHeaders();
                    $dataWritten = true;
                }
                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                $tailBuffer .= $data;
                if (strlen($tailBuffer) > 8192) {
                    $tailBuffer = substr($tailBuffer, -8192);
                }
                // Accumulate assistant content from delta events.
                if (preg_match_all('/^data:\s*(\{.+\})$/m', $data, $dm)) {
                    foreach ($dm[1] as $djson) {
                        $dobj = json_decode($djson, true);
                        if (is_array($dobj) && isset($dobj['choices'][0]['delta']['content'])) {
                            $accumulatedText .= (string) $dobj['choices'][0]['delta']['content'];
                        }
                    }
                }
                return strlen($data);
            }
        );

        curl_exec($chStream);
        $streamCurlErr  = curl_error($chStream);
        $streamHttpCode = (int) curl_getinfo($chStream, CURLINFO_HTTP_CODE);
        curl_close($chStream);

        // Retry with a different endpoint only if the failure occurred before
        // we sent anything to the client (headers not yet committed).
        if (($streamCurlErr !== '' || ($streamHttpCode !== 0 && $streamHttpCode !== 200)) && !$dataWritten) {
            if ($switchEndpoint(true)) {
                $forwardPayload['stream'] = true;
                continue;
            }
        }
        break;
    } while (true);

    // Extract token usage from the tail of the SSE stream.
    $promptTokens     = null;
    $completionTokens = null;
    $totalTokens      = null;
    if (preg_match_all('/^data:\s*(\{.+\})$/m', $tailBuffer, $matches)) {
        foreach (array_reverse($matches[1]) as $rawEvt) {
            $obj = json_decode($rawEvt, true);
            if (is_array($obj) && isset($obj['usage']['total_tokens'])) {
                $promptTokens     = isset($obj['usage']['prompt_tokens'])     ? (int) $obj['usage']['prompt_tokens']     : null;
                $completionTokens = isset($obj['usage']['completion_tokens']) ? (int) $obj['usage']['completion_tokens'] : null;
                $totalTokens      = (int) $obj['usage']['total_tokens'];
                break;
            }
        }
    }

    $taskFinished = true;
    $streamStatus = ($streamCurlErr === '' && ($streamHttpCode === 0 || $streamHttpCode === 200))
        ? 'done' : 'error';
    completeTask($taskId, $streamStatus, $promptTokens, $completionTokens, $totalTokens);

    // Persist conversation on success.
    if ($streamStatus === 'done' && $sessionId !== '' && $accumulatedText !== '') {
        $sessionMessages = array_merge(
            $payload['messages'],
            [['role' => 'assistant', 'content' => $accumulatedText]]
        );
        saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
    }
    if ($streamStatus === 'done' && ($dataWritten || headers_sent())) {
        $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
        emitIntelligenceUpgradeSse($intelligenceUpgrade);
        emitResponseDetailsSse($responseDetails);
    }

    if ($streamCurlErr !== '') {
        if ($dataWritten || headers_sent()) {
            emitSseData(['error' => $streamCurlErr]);
        } else {
            // Nothing sent yet – return a plain JSON error.
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $streamCurlErr]);
        }
    }
    exit;
}

// ── Non-streaming path ────────────────────────────────────────────────────────

do {
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    header('Content-Type: application/json; charset=utf-8');

    if ($curlErr !== '') {
        if ($switchEndpoint(true)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            continue;
        }
        $taskFinished = true;
        completeTask($taskId, 'error');
        http_response_code(502);
        echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
        exit;
    }

    if ($httpCode !== 200) {
        $data = json_decode($body, true);
        $msg  = isset($data['error']['message'])
            ? $data['error']['message']
            : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
        if ($switchEndpoint()) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            continue;
        }
        $taskFinished = true;
        completeTask($taskId, 'error');
        http_response_code(502);
        echo json_encode(['error' => $msg]);
        exit;
    }
    break;
} while (true);

// Extract token usage and complete the task.
$data             = json_decode($body, true);
$promptTokens     = isset($data['usage']['prompt_tokens'])     ? (int) $data['usage']['prompt_tokens']     : null;
$completionTokens = isset($data['usage']['completion_tokens']) ? (int) $data['usage']['completion_tokens'] : null;
$totalTokens      = isset($data['usage']['total_tokens'])      ? (int) $data['usage']['total_tokens']      : null;

$taskFinished = true;
completeTask($taskId, 'done', $promptTokens, $completionTokens, $totalTokens);

// Persist conversation on success.
if ($sessionId !== '' && is_array($data)) {
    $assistantContent = normalizeAssistantContent($data['choices'][0]['message']['content'] ?? '');
    $sessionMessages  = array_merge(
        $payload['messages'],
        [['role' => 'assistant', 'content' => $assistantContent]]
    );
    saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
}

// Forward the raw LM Studio response.
if (is_array($data) && $intelligenceUpgrade !== null) {
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    $data['intelligence_upgrade'] = buildIntelligenceUpgradePayload($intelligenceUpgrade);
    $data['response_details'] = $responseDetails;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (is_array($data)) {
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    $data['response_details'] = $responseDetails;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    echo $body;
}
