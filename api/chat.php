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

function emitSyntheticStream(array $data): void
{
    $content = normalizeAssistantContent($data['choices'][0]['message']['content'] ?? '');
    $id = (string) ($data['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(8))));
    $created = (int) ($data['created'] ?? time());
    $model = (string) ($data['model'] ?? '');

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    if ($content !== '') {
        echo 'data: ' . json_encode([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => ['content' => $content],
                'finish_reason' => null,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    echo 'data: ' . json_encode([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'delta' => new stdClass(),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'stop',
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "data: [DONE]\n\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
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

try {
    $slot = pickEndpointForModel($model);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
    exit;
}

if ($slot === null) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Kein Endpunkt verfügbar. Alle Kapazitäten sind belegt oder kein passender Endpunkt konfiguriert.']);
    exit;
}

$endpoint = $slot['endpoint'];
$taskId   = $slot['task_id'];
$baseUrl  = rtrim($endpoint['base_url'], '/');
$timeout  = max(1, (int) $endpoint['timeout']);
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));

// Ensure the task is always marked finished, even on unexpected PHP termination.
$taskFinished = false;
register_shutdown_function(static function () use ($taskId, &$taskFinished): void {
    if (!$taskFinished) {
        try {
            completeTask($taskId, 'error');
        } catch (Throwable $e) {
            // Best-effort – nothing more we can do in a shutdown handler.
        }
    }
});

$clientRequestedStream = isset($payload['stream']) && $payload['stream'] === true;
$useSearchTool = $searxngBaseUrl !== '';
$useSdTool     = hasSdEndpoints();
$useComfyTool  = hasComfyEndpoints();
$useTools      = $useSearchTool || $useSdTool || $useComfyTool;
$stream = $clientRequestedStream && !$useTools;

// Forward only the fields LM Studio expects.
$forwardPayload = [
    'model'       => $payload['model'],
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

        header('Content-Type: application/json; charset=utf-8');

        if ($curlErr !== '') {
            $taskFinished = true;
            completeTask($taskId, 'error');
            http_response_code(502);
            echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
            exit;
        }

        $data = json_decode($body, true);
        if ($httpCode !== 200 || !is_array($data)) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
            $taskFinished = true;
            completeTask($taskId, 'error');
            http_response_code(502);
            echo json_encode(['error' => $msg]);
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
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Der Tool-Dialog mit dem Modell konnte nicht abgeschlossen werden.']);
        exit;
    }

    $finalData['usage'] = [
        'prompt_tokens' => $usage['prompt'],
        'completion_tokens' => $usage['completion'],
        'total_tokens' => $usage['total'],
    ];

    $taskFinished = true;
    completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total']);

    if ($clientRequestedStream) {
        emitSyntheticStream($finalData);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => json_encode($forwardPayload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

// ── Streaming path ────────────────────────────────────────────────────────────

if ($stream) {
    ignore_user_abort(true);
    @set_time_limit(0);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => 0,
    ]);

    // Tail buffer: keep the last 8 KB of SSE data so we can extract usage
    // information from the final event(s) once the stream has completed.
    $tailBuffer = '';

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use (&$tailBuffer): int {
        echo $data;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        $tailBuffer .= $data;
        if (strlen($tailBuffer) > 8192) {
            $tailBuffer = substr($tailBuffer, -8192);
        }
        return strlen($data);
    });

    curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Extract token usage from the last SSE data events.
    $promptTokens     = null;
    $completionTokens = null;
    $totalTokens      = null;
    if (preg_match_all('/^data:\s*(\{.+\})$/m', $tailBuffer, $matches)) {
        foreach (array_reverse($matches[1]) as $raw) {
            $obj = json_decode($raw, true);
            if (is_array($obj) && isset($obj['usage']['total_tokens'])) {
                $promptTokens     = isset($obj['usage']['prompt_tokens'])     ? (int) $obj['usage']['prompt_tokens']     : null;
                $completionTokens = isset($obj['usage']['completion_tokens']) ? (int) $obj['usage']['completion_tokens'] : null;
                $totalTokens      = (int) $obj['usage']['total_tokens'];
                break;
            }
        }
    }

    $taskFinished = true;
    completeTask($taskId, $curlErr !== '' ? 'error' : 'done', $promptTokens, $completionTokens, $totalTokens);

    if ($curlErr !== '') {
        echo "data: " . json_encode(['error' => $curlErr]) . "\n\n";
        flush();
    }
    exit;
}

// ── Non-streaming path ────────────────────────────────────────────────────────

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');

if ($curlErr !== '') {
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
    $taskFinished = true;
    completeTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => $msg]);
    exit;
}

// Extract token usage and complete the task.
$data             = json_decode($body, true);
$promptTokens     = isset($data['usage']['prompt_tokens'])     ? (int) $data['usage']['prompt_tokens']     : null;
$completionTokens = isset($data['usage']['completion_tokens']) ? (int) $data['usage']['completion_tokens'] : null;
$totalTokens      = isset($data['usage']['total_tokens'])      ? (int) $data['usage']['total_tokens']      : null;

$taskFinished = true;
completeTask($taskId, 'done', $promptTokens, $completionTokens, $totalTokens);

// Forward the raw LM Studio response.
echo $body;
