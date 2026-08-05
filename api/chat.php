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
require_once __DIR__ . '/../lib/prompt_security.php';
require_once __DIR__ . '/balancer.php';
require_once __DIR__ . '/sd_balancer.php';
require_once __DIR__ . '/comfy_balancer.php';
require_once __DIR__ . '/embedding.php';

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
 *
 * When hybrid search is enabled (hybrid_search_enabled = 1) and an
 * embedding endpoint is configured, results are obtained through:
 *   1. BM25-style keyword scoring (existing logic).
 *   2. Cosine-similarity scoring against stored chunk embeddings.
 *   3. Reciprocal Rank Fusion (RRF) of both result lists.
 *   4. Optional reranking via an OpenAI-compatible reranker API.
 *
 * Falls back to plain BM25 when embeddings or the reranker are unavailable.
 *
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

    // Read configuration.
    $hybridEnabled    = getSetting('hybrid_search_enabled', '0') === '1';
    $embeddingEnabled = getSetting('embedding_enabled', '0') === '1';
    $rerankerEnabled  = getSetting('reranker_enabled', '0') === '1';
    $bm25Weight       = max(0.0, (float) getSetting('bm25_weight', '0.5'));
    $embeddingWeight  = max(0.0, (float) getSetting('embedding_weight', '0.5'));
    $rerankerTopK     = max(1, (int) getSetting('reranker_top_k', '5'));

    // Number of candidates to load for each step.
    $chunkLimit       = 200; // fetch limit from DB
    $fusionCandidates = 50;  // max candidates after RRF fusion, before reranking

    writeLog('info', 'Dokumentensuche im Wissensspeicher gestartet' . ($hybridEnabled ? ' (Hybrid-Modus)' : '') . '.');

    try {
        $db    = getDb();
        $terms = array_slice(tokenizeQueryTerms($query), 0, 12);

        // ── Step 1: Load chunk candidates ────────────────────────────────────
        // When hybrid search is on and embeddings exist, also fetch the stored
        // embedding so we can compute cosine similarity in PHP.
        $selectEmbedding = ($hybridEnabled && $embeddingEnabled) ? ', dc.embedding' : '';
        $stmt = $db->prepare(
            "SELECT du.id AS document_id, du.original_name,
                    dc.id AS chunk_id, dc.chunk_index, dc.chunk_text{$selectEmbedding}
               FROM document_chunks dc
               JOIN document_uploads du ON du.id = dc.document_upload_id
              WHERE du.status = 'done'
                AND (du.user_id = ? OR du.is_global_rag = 1)
              ORDER BY du.uploaded_at DESC, dc.chunk_index ASC
              LIMIT {$chunkLimit}"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            writeLog('info', 'Dokumentensuche lieferte 0 relevante Dokumente.');
            return [
                'found'   => false,
                'message' => 'Es sind noch keine analysierten Dokument-Chunks (eigene oder global freigegebene) verfügbar.',
            ];
        }

        // ── Step 2: BM25-style scoring ────────────────────────────────────────
        $bm25Results = [];
        foreach ($rows as $row) {
            $chunkText = trim((string) ($row['chunk_text'] ?? ''));
            if ($chunkText === '') {
                continue;
            }
            $score = scoreRagChunk($chunkText, $query, $terms);
            if ($score <= 0.0) {
                continue;
            }
            $bm25Results[] = [
                'document_id'  => (int) ($row['document_id'] ?? 0),
                'document'     => (string) ($row['original_name'] ?? 'Unbekannt'),
                'chunk_id'     => (int) ($row['chunk_id'] ?? 0),
                'chunk_index'  => (int) ($row['chunk_index'] ?? 0),
                'chunk_text'   => $chunkText,
                'content'      => buildRagSnippet($chunkText, $terms),
                'bm25_score'   => $score,
                'embedding'    => $row['embedding'] ?? null,
            ];
        }

        // Sort BM25 results by score descending.
        usort($bm25Results, static fn($a, $b) => $b['bm25_score'] <=> $a['bm25_score']);

        // ── Step 3: Embedding search (optional) ───────────────────────────────
        $embeddingResults = [];
        $useEmbedding = $hybridEnabled && $embeddingEnabled && hasActiveEmbeddingEndpoint();

        if ($useEmbedding) {
            // Retrieve or compute the query embedding.
            $embModel   = trim(getSetting('embedding_model', ''));
            $queryEmbed = null;

            if ($embModel !== '') {
                $queryEmbed = getCachedQueryEmbedding($query, $embModel);
            }

            if ($queryEmbed === null) {
                $startMs    = (int) round(microtime(true) * 1000);
                $queryEmbed = generateEmbeddingAuto($query, 'query');
                $durationMs = (int) round(microtime(true) * 1000) - $startMs;

                if ($queryEmbed !== null && $embModel !== '') {
                    setCachedQueryEmbedding($query, $embModel, $queryEmbed);
                }
            }

            if ($queryEmbed !== null) {
                // Score every chunk that has a stored embedding.
                $maxSim = null;
                foreach ($rows as $row) {
                    $chunkText = trim((string) ($row['chunk_text'] ?? ''));
                    if ($chunkText === '') {
                        continue;
                    }
                    $chunkEmbed = embeddingFromJson($row['embedding'] ?? null);
                    if ($chunkEmbed === null) {
                        continue;
                    }
                    $sim = cosineSimilarity($queryEmbed, $chunkEmbed);
                    if ($sim <= 0.0) {
                        continue;
                    }
                    if ($maxSim === null || $sim > $maxSim) {
                        $maxSim = $sim;
                    }
                    $embeddingResults[] = [
                        'document_id' => (int) ($row['document_id'] ?? 0),
                        'document'    => (string) ($row['original_name'] ?? 'Unbekannt'),
                        'chunk_id'    => (int) ($row['chunk_id'] ?? 0),
                        'chunk_index' => (int) ($row['chunk_index'] ?? 0),
                        'chunk_text'  => $chunkText,
                        'content'     => buildRagSnippet($chunkText, $terms),
                        'sim_score'   => $sim,
                    ];
                }
                // Sort embedding results by similarity descending.
                usort($embeddingResults, static fn($a, $b) => $b['sim_score'] <=> $a['sim_score']);

                if ($maxSim !== null) {
                    logEmbeddingRequest('query', $embModel, 0, $maxSim, false, 'ok');
                }
            }
        }

        // ── Step 4: Reciprocal Rank Fusion (RRF) ─────────────────────────────
        // k = 60 is the standard constant that prevents very high-rank dominance.
        $rrfK       = 60;
        $rrfScores  = []; // keyed by chunk_id
        $rrfMeta    = []; // keyed by chunk_id → metadata

        // Helper to assign RRF contribution from one ranked list.
        $applyRrf = static function (array $list, float $weight) use ($rrfK, &$rrfScores, &$rrfMeta): void {
            foreach ($list as $rank => $item) {
                $cid = (int) ($item['chunk_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $contribution = $weight * (1.0 / ($rrfK + $rank + 1));
                $rrfScores[$cid] = ($rrfScores[$cid] ?? 0.0) + $contribution;
                if (!isset($rrfMeta[$cid])) {
                    $rrfMeta[$cid] = $item;
                }
            }
        };

        if ($useEmbedding && !empty($embeddingResults)) {
            // Both lists contribute with their configured weights.
            $applyRrf($bm25Results, $bm25Weight);
            $applyRrf($embeddingResults, $embeddingWeight);
        } else {
            // Only BM25 available – use it directly (weight = 1.0 for simplicity).
            $applyRrf($bm25Results, 1.0);
        }

        // Sort fused results by RRF score descending.
        arsort($rrfScores);

        // Build fused candidate list (up to $fusionCandidates entries).
        $fused = [];
        foreach (array_slice($rrfScores, 0, $fusionCandidates, true) as $cid => $rrfScore) {
            if (!isset($rrfMeta[$cid])) {
                continue;
            }
            $item = $rrfMeta[$cid];
            $item['rrf_score'] = $rrfScore;
            $fused[] = $item;
        }

        if (empty($fused)) {
            // No BM25 hits either – nothing found.
            writeLog('info', 'Dokumentensuche lieferte 0 relevante Dokumente.');
            return [
                'found'   => false,
                'message' => 'Keine passenden Informationen in den analysierten Dokumenten gefunden.',
            ];
        }

        // ── Step 5: Optional reranking ────────────────────────────────────────
        if ($rerankerEnabled && getSetting('reranker_endpoint', '') !== '') {
            $fused = rerankDocuments($query, $fused, $rerankerTopK);
        } else {
            // No reranker – just take top N from fusion (default 5, or rerankerTopK).
            $fused = array_slice($fused, 0, $rerankerTopK);
        }

        // ── Step 6: Format output ─────────────────────────────────────────────
        $results = array_map(static function (array $row): array {
            return [
                'document'  => $row['document'],
                'chunk'     => $row['chunk_index'] + 1,
                'relevance' => round((float) ($row['rrf_score'] ?? $row['bm25_score'] ?? 0.0), 4),
                'content'   => $row['content'],
            ];
        }, $fused);

        $relevantDocumentCount = count(array_unique(array_map(
            static fn(array $row): int => (int) ($row['document_id'] ?? 0),
            $fused
        )));
        writeLog('info', 'Dokumentensuche lieferte ' . $relevantDocumentCount . ' relevante Dokumente.');

        return [
            'found'   => true,
            'results' => $results,
        ];
    } catch (Throwable $e) {
        writeLog('error', 'Dokumentensuche Fehler: ' . $e->getMessage());
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

function isOpenAiStrictMode(): bool
{
    return !empty($GLOBALS['LLMINT_OPENAI_STRICT_MODE']);
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
    writeLog('info', 'Streaming der Antwort an User gestartet.');

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
    if (!isOpenAiStrictMode()) {
        emitIntelligenceUpgradeSse($upgradeSuggestion);
        emitResponseDetailsSse($responseDetails);
    }
    emitSseData('[DONE]');
    if (!connection_aborted()) {
        writeLog('info', 'Antwort vollständig an User übertragen.');
    } else {
        writeLog('warning', 'Anfrage durch User abgebrochen.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

/**
 * Return the best-guess client IP address.
 * Checks X-Forwarded-For when a trusted proxy injects it, falls back to REMOTE_ADDR.
 */
function getClientIp(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = explode(',', $xff);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '–';
}

function getEndpointLogLabel(array $endpoint): string
{
    $alias = trim((string) ($endpoint['alias'] ?? ''));
    if ($alias !== '') {
        return $alias;
    }
    $baseUrl = trim((string) ($endpoint['base_url'] ?? ''));
    return $baseUrl !== '' ? $baseUrl : 'Unbekannter Endpunkt';
}

function elapsedMilliseconds(float $startedAt): int
{
    return max(0, (int) round((microtime(true) - $startedAt) * 1000));
}

function isTimeoutMessage(string $message): bool
{
    return preg_match('/timed out|timeout|zeitlimit/i', $message) === 1;
}

function logToolInvoked(string $toolName): void
{
    if ($toolName === '') {
        $toolName = 'Unbekanntes Tool';
    }
    writeLog('info', 'Tool ' . $toolName . ' durch Agent aufgerufen.');
}

function logToolResult(string $toolName, mixed $toolResult): void
{
    if ($toolName === '') {
        $toolName = 'Unbekanntes Tool';
    }
    $errorMessage = '';
    if (is_array($toolResult) && isset($toolResult['error']) && is_string($toolResult['error'])) {
        $errorMessage = trim($toolResult['error']);
    }

    if ($errorMessage === '') {
        writeLog('info', 'Tool ' . $toolName . ' erfolgreich beendet.');
        return;
    }

    if (isTimeoutMessage($errorMessage)) {
        writeLog('warning', 'Tool ' . $toolName . ' überschritt das konfigurierte Zeitlimit und wurde abgebrochen.');
    }
}

function logResponseFinished(float $requestStart, ?int $promptTokens, ?int $completionTokens): void
{
    writeLog('info', 'Antwortgenerierung abgeschlossen (Gesamtdauer: ' . elapsedMilliseconds($requestStart) . ' ms).');
    if ($promptTokens !== null && $completionTokens !== null) {
        writeLog('info', 'Promptgröße: ' . $promptTokens . ' Token, Antwortgröße: ' . $completionTokens . ' Token.');
    }
}

$raw     = isset($GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE']) && is_string($GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE'])
    ? $GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE']
    : file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ungültiger JSON-Body.']);
    exit;
}

if (($payload['action'] ?? '') === 'decline_intelligence_upgrade') {
    writeLog('info', 'Intelligence Upgrade durch User (' . getClientIp() . ') abgelehnt.');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
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
    if (!isset($msg['role'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Jede Nachricht muss "role" enthalten.']);
        exit;
    }
    $allowed = ['system', 'user', 'assistant', 'tool'];
    if (!in_array($msg['role'], $allowed, true)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Ungültige Rolle: ' . htmlspecialchars((string) $msg['role'])]);
        exit;
    }
    if (!array_key_exists('content', $msg) && !($msg['role'] === 'assistant' && isset($msg['tool_calls']))) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Jede Nachricht muss "content" enthalten (oder assistant.tool_calls).']);
        exit;
    }
}

// ── Select endpoint via load balancer ─────────────────────────────────────────

$model = $payload['model'];

// ── Prompt Security check ─────────────────────────────────────────────────────
// Every chat request is evaluated before reaching the LLM pipeline.
{
    $psLastUserText = '';
    foreach (array_reverse($payload['messages']) as $psMsg) {
        if (($psMsg['role'] ?? '') === 'user') {
            $psLastUserText = is_string($psMsg['content']) ? $psMsg['content'] : '';
            break;
        }
    }

    if ($psLastUserText !== '') {
        $psUserId    = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $psSessionId = isset($payload['session_id']) && is_string($payload['session_id'])
            ? substr($payload['session_id'], 0, 128) : '';
        $psIp        = getClientIp();

        $psResult = psEvaluate($psLastUserText, $psUserId, $psSessionId, $psIp);

        // Opportunistically purge old security logs (~1 % of requests).
        if (mt_rand(1, 100) === 1) {
            psPurgeLogs();
        }

        if ($psResult['decision'] === 'block') {
            writeLog('warning', 'Prompt-Security blockiert Anfrage von ' . $psIp
                . ' (Score: ' . $psResult['score'] . ').');
            if (isset($payload['stream']) && $payload['stream'] === true) {
                emitSseData(['error' => $psResult['message']]);
            } else {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $psResult['message']]);
            }
            exit;
        }
    }
}

// ── Rule-based model routing ──────────────────────────────────────────────────
// If a routing decision model is configured, classify the user's prompt and
// replace $model with the category-specific target model before dispatching.

$routingDecisionModel = trim(getSetting('routing_decision_model', ''));
$detectedCategory = '';
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
                    if ($detectedCategory !== '') {
                        writeLog('info', 'Entscheidungsmodell hat Prompt von Nutzer (' . getClientIp() . ') der Kategorie ' . $detectedCategory . ' zugeordnet.');
                    }
                }
            }
        }
    }
}

$intelligenceUpgrade = null;
try {
    $intelligenceUpgrade = getUpgradeModelSuggestionForRequestedModel($model, $detectedCategory);
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
    writeLog('info', 'Intelligence-Upgrade durch Nutzer (' . getClientIp() . ') akzeptiert (Modell: ' . $model . ').');
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

    if (isset($payload['stream']) && $payload['stream'] === true && !isOpenAiStrictMode()) {
        emitSseData([
            'status' => 'queued',
            'message' => 'Alle LLM-Ressourcen sind derzeit belegt. Die Bearbeitung beginnt automatisch, sobald ein Slot frei wird.',
        ]);
    }

    while ($slot === null) {
        if (connection_aborted()) {
            writeLog('warning', 'Anfrage durch User abgebrochen.');
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
                writeLog('warning', 'Für Modell ' . $model . ' ist kein aktiver Modellendpunkt mehr verfügbar.');
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
writeLog('info', 'Modell ' . $model . ' für die Bearbeitung des Prompts ausgewählt.');
writeLog('info', 'Antwortgenerierung gestartet.');

// ── Endpoint overload warning ─────────────────────────────────────────────────
// Log a warning when every active endpoint for this model has more than two
// running sessions, hinting that additional endpoints should be provided.
try {
    $overloadRows = getDb()->prepare("
        SELECT e.id, e.alias, e.default_model,
               COALESCE(r.running_count, 0) AS running_count
          FROM endpoints e
          LEFT JOIN (
              SELECT endpoint_id, COUNT(*) AS running_count
                FROM tasks
               WHERE status = 'running'
               GROUP BY endpoint_id
          ) r ON r.endpoint_id = e.id
         WHERE e.is_active = 1
           AND e.default_model = ?
    ");
    $overloadRows->execute([$model]);
    $epRows = $overloadRows->fetchAll();
    if (!empty($epRows)) {
        $allBusy = true;
        foreach ($epRows as $epRow) {
            if ((int) $epRow['running_count'] <= 2) {
                $allBusy = false;
                break;
            }
        }
        if ($allBusy) {
            $intelligenceLabel = $model;
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*b\b/i', $model, $ilm)) {
                $intelligenceLabel = $ilm[0];
            }
            writeLog('warning', 'Die Endpunkte mit der Intelligenz ' . $intelligenceLabel . ' sind stark ausgelastet (mehr als zwei laufende Sessions je Endpunkt). Bitte erwägen Sie, mehr Endpunkte zur Verfügung zu stellen, um Verzögerungen zu vermeiden.');
        }
    }
} catch (Throwable $_owEx) { /* best-effort */ }

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
    &$upgradeFailoverTried, &$intelligenceUpgrade, &$forwardPayload, &$payload, $detectedCategory
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
            $upgrade = getUpgradeModelSuggestionForRequestedModel($model, $detectedCategory);
            if (is_array($upgrade) && !empty($upgrade['model'])) {
                $upgradeModel = (string) $upgrade['model'];
                $newSlot = pickEndpointForModel($upgradeModel, 4);
                if ($newSlot !== null) {
                    $model = $upgradeModel;
                    $payload['model'] = $upgradeModel;
                    $forwardPayload['model'] = $upgradeModel;
                    $intelligenceUpgrade = null;
                    writeLog('info', 'Modell ' . $model . ' für die Bearbeitung des Prompts ausgewählt.');
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
$openAiToolMode = (string) ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] ?? 'auto');
$endpointSupportsToolCalling = (bool) ($endpoint['supports_tool_calling'] ?? true);
if ($openAiToolMode === 'enabled') {
    $endpointSupportsToolCalling = true;
}
$useSearchTool   = $searxngBaseUrl !== '' && $endpointSupportsToolCalling;
$useSdTool       = hasSdEndpoints() && $endpointSupportsToolCalling;
$useComfyTool    = hasComfyEndpoints() && $endpointSupportsToolCalling;
$useDocQueryTool = hasDocumentUploads($sessionUserId) && $endpointSupportsToolCalling;
$useTools        = $useSearchTool || $useSdTool || $useComfyTool || $useDocQueryTool;
if ($openAiToolMode === 'disabled') {
    $useSearchTool = false;
    $useSdTool = false;
    $useComfyTool = false;
    $useDocQueryTool = false;
    $useTools = false;
}
$stream = $clientRequestedStream && !$useTools;

// Forward only the fields LM Studio expects.
$forwardPayload = [
    'model'       => $model,
    'messages'    => $payload['messages'],
    'stream'      => $stream,
    'temperature' => $payload['temperature'] ?? 0.7,
    'max_tokens'  => $payload['max_tokens']  ?? -1,
    'top_p'       => $payload['top_p'] ?? 1.0,
    'stop'        => $payload['stop'] ?? null,
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
        $searchStartedAt = microtime(true);
        try {
            $searchResult = runSearxngSearch($searxngBaseUrl, substr($forceSearchQuery, 0, 400), min($timeout, 15));
            completeSearchLog($searchLogId, 'done');
            $searchQueryUsed = $forceSearchQuery;
            $searchElapsedMs = elapsedMilliseconds($searchStartedAt);
            writeLog('info', 'Websuche erfolgreich abgeschlossen.');
            writeLog('info', 'Websuche erhöhte Bearbeitungszeit um ' . $searchElapsedMs . ' ms.');
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
        // On the first iteration, force search_web when SearXNG is available and no
        // pre-fetched search result was already injected via force_search_query.
        // Many local OpenAI-compatible servers (e.g. llama.cpp / LM Studio) reject
        // the named-function tool_choice form ({"type":"function","function":{"name":...}})
        // with HTTP 400 for models without native "forced named tool" support. Using the
        // widely-supported "required" string instead, combined with a tools list that only
        // contains search_web, achieves the same forced-search behaviour without the 400.
        if ($useSearchTool && $iteration === 0 && $forceSearchQuery === '') {
            $toolPayload['tools'] = createSearchToolDefinition();
            $toolPayload['tool_choice'] = 'required';
        } else {
            $toolPayload['tools'] = $tools;
            $toolPayload['tool_choice'] = 'auto';
        }
        writeLog('info', 'Prompt an Modell ' . $model . ' weitergeleitet.');

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
            writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
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
            // Only 5xx / server-side failures warrant an endpoint failover: retrying an
            // identical payload against another endpoint after a 4xx (client/payload)
            // rejection just repeats the same malformed request and wastes the retry budget.
            if ($httpCode >= 500) {
                writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
                if ($switchEndpoint()) {
                    $url = $baseUrl . '/chat/completions';
                    $iteration--;  // redo this iteration with the new endpoint
                    continue;
                }
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
            logToolInvoked($toolName);

            if ($toolName === 'search_web' && $useSearchTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $query = trim((string) ($args['query'] ?? ''));

                if ($query === '') {
                    $toolResult = ['error' => 'Leere Suchanfrage.'];
                } else {
                    writeLog('info', 'Websuche durch Modell ' . $model . ' auf Prompt von Nutzer (' . getClientIp() . ') gestartet (Anfrage: ' . mb_substr($query, 0, 200, 'UTF-8') . ').');
                    $searchLogId = startSearchLog(substr($query, 0, 400));
                    $searchStartedAt = microtime(true);
                    try {
                        $toolResult = runSearxngSearch($searxngBaseUrl, substr($query, 0, 400), min($timeout, 15));
                        completeSearchLog($searchLogId, 'done');
                        $searchQueryUsed = $query;
                        $searchElapsedMs = elapsedMilliseconds($searchStartedAt);
                        writeLog('info', 'Websuche erfolgreich abgeschlossen.');
                        writeLog('info', 'Websuche erhöhte Bearbeitungszeit um ' . $searchElapsedMs . ' ms.');
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

            logToolResult($toolName, $toolResult);

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
    if (!isOpenAiStrictMode() && $intelligenceUpgrade !== null) {
        $finalData['intelligence_upgrade'] = buildIntelligenceUpgradePayload($intelligenceUpgrade);
    }
    if (!isOpenAiStrictMode()) {
        $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
        if ($searchQueryUsed !== '') {
            $responseDetails['search_query'] = $searchQueryUsed;
        }
        $finalData['response_details'] = $responseDetails;
    }

    $taskFinished = true;
    completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total']);
    logResponseFinished($requestStart, $usage['prompt'], $usage['completion']);

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
    $firstTokenLogged = false;
    $streamStartedLogged = false;
    $clientAborted = false;

    do {
        $tailBuffer      = '';
        $accumulatedText = '';
        $dataWritten     = false;
        $firstTokenLogged = false;
        $streamStartedLogged = false;
        $clientAborted = false;

        $chStream = curl_init($url);
        curl_setopt_array($chStream, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => 0,
        ]);
        writeLog('info', 'Prompt an Modell ' . $model . ' weitergeleitet.');

        curl_setopt($chStream, CURLOPT_WRITEFUNCTION,
            static function ($ch, $data) use (&$tailBuffer, &$accumulatedText, &$dataWritten, &$firstTokenLogged, &$streamStartedLogged, &$clientAborted, $requestStart): int {
                if (!$dataWritten) {
                    // Emit SSE headers on first data chunk (deferred so we can
                    // still switch the endpoint if the connection was refused).
                    ensureSseHeaders();
                    $dataWritten = true;
                    if (!$streamStartedLogged) {
                        writeLog('info', 'Streaming der Antwort an User gestartet.');
                        $streamStartedLogged = true;
                    }
                }
                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                if (connection_aborted()) {
                    if (!$clientAborted) {
                        writeLog('warning', 'Anfrage durch User abgebrochen.');
                        $clientAborted = true;
                    }
                    return 0;
                }
                $tailBuffer .= $data;
                if (strlen($tailBuffer) > 8192) {
                    $tailBuffer = substr($tailBuffer, -8192);
                }
                // Accumulate assistant content from delta events.
                if (preg_match_all('/^data:\s*(\{.+\})$/m', $data, $dm)) {
                    foreach ($dm[1] as $djson) {
                        $dobj = json_decode($djson, true);
                        if (is_array($dobj) && isset($dobj['choices'][0]['delta']['content'])) {
                            $deltaContent = (string) $dobj['choices'][0]['delta']['content'];
                            if ($deltaContent !== '' && !$firstTokenLogged) {
                                writeLog('info', 'Erste Antworttokens nach ' . elapsedMilliseconds($requestStart) . ' ms erzeugt.');
                                $firstTokenLogged = true;
                            }
                            $accumulatedText .= $deltaContent;
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

        // Retry with a different endpoint only if the failure occurred before we sent
        // anything to the client (headers not yet committed) AND it looks like a
        // transient/server-side failure (connection error or 5xx). A 4xx means the
        // request itself was rejected, so retrying the identical payload elsewhere
        // would just repeat the same error and waste the retry budget.
        if (($streamCurlErr !== '' || ($streamHttpCode !== 0 && $streamHttpCode !== 200)) && !$dataWritten) {
            if ($streamCurlErr !== '' || $streamHttpCode >= 500) {
                writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
                if ($switchEndpoint(true)) {
                    $forwardPayload['stream'] = true;
                    continue;
                }
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
    if ($streamStatus === 'done') {
        logResponseFinished($requestStart, $promptTokens, $completionTokens);
    }

    // Persist conversation on success.
    if ($streamStatus === 'done' && $sessionId !== '' && $accumulatedText !== '') {
        $sessionMessages = array_merge(
            $payload['messages'],
            [['role' => 'assistant', 'content' => $accumulatedText]]
        );
        saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
    }
    if ($streamStatus === 'done' && ($dataWritten || headers_sent())) {
        if (!isOpenAiStrictMode()) {
            $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
            emitIntelligenceUpgradeSse($intelligenceUpgrade);
            emitResponseDetailsSse($responseDetails);
        }
        if (!$clientAborted) {
            writeLog('info', 'Antwort vollständig an User übertragen.');
        }
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
    writeLog('info', 'Prompt an Modell ' . $model . ' weitergeleitet.');
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    header('Content-Type: application/json; charset=utf-8');

    if ($curlErr !== '') {
        writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
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
        // Only failover to another endpoint on server-side (5xx) failures. A 4xx means
        // the request payload itself was rejected, so resending it unchanged elsewhere
        // would just reproduce the same error and burn through the retry budget.
        if ($httpCode >= 500) {
            writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
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
logResponseFinished($requestStart, $promptTokens, $completionTokens);

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
if (is_array($data) && !isOpenAiStrictMode() && $intelligenceUpgrade !== null) {
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    $data['intelligence_upgrade'] = buildIntelligenceUpgradePayload($intelligenceUpgrade);
    $data['response_details'] = $responseDetails;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (is_array($data) && !isOpenAiStrictMode()) {
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    $data['response_details'] = $responseDetails;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (is_array($data)) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    echo $body;
}
