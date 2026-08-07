<?php

/**
 * api/comfy_generate.php
 *
 * Generates an image via a ComfyUI API endpoint using a default
 * KSampler text-to-image workflow.
 *
 * Accepts a POST JSON body:
 *   {
 *     "prompt":          string  (required)
 *     "negative_prompt": string  (optional)
 *     "width":           int     (optional, default 512)
 *     "height":          int     (optional, default 512)
 *     "steps":           int     (optional, default 20)
 *     "cfg_scale":       float   (optional, default 7)
 *     "seed":            int     (optional, random if omitted)
 *   }
 *
 * On success returns JSON:
 *   { "image_url": "<relative URL to saved PNG>", "width": int, "height": int,
 *     "prompt": "...", "seed": int }
 *
 * On error returns JSON:
 *   { "error": "..." }
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/comfy_balancer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger JSON-Body.']);
    exit;
}

$prompt = trim((string) ($payload['prompt'] ?? ''));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => '"prompt" ist ein Pflichtfeld.']);
    exit;
}

// ── Select ComfyUI endpoint ───────────────────────────────────────────────────

try {
    $slot = pickComfyEndpoint();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interner Fehler beim ComfyUI-Endpunkt-Routing.']);
    exit;
}

if ($slot === null) {
    http_response_code(503);
    echo json_encode(['error' => 'Kein ComfyUI-Endpunkt verfügbar. Alle Kapazitäten sind belegt oder kein passender Endpunkt konfiguriert.']);
    exit;
}

$endpoint   = $slot['endpoint'];
$taskId     = $slot['task_id'];
$baseUrl    = rtrim($endpoint['base_url'], '/');
$timeout    = max(1, (int) $endpoint['timeout']);
$checkpoint = (string) ($endpoint['default_checkpoint'] ?: '');
$requestStart = microtime(true);

// Ensure the task is always marked finished, even on unexpected PHP termination.
$taskFinished = false;
register_shutdown_function(static function () use ($taskId, &$taskFinished): void {
    if (!$taskFinished) {
        try {
            completeComfyTask($taskId, 'error');
        } catch (Throwable $e) {
            // Best-effort
        }
    }
});

// ── Build ComfyUI workflow ────────────────────────────────────────────────────

$negativePrompt = (string) ($payload['negative_prompt'] ?? '');
$width          = max(64, min(2048, (int) ($payload['width']  ?? 512)));
$height         = max(64, min(2048, (int) ($payload['height'] ?? 512)));
$steps          = max(1,  min(150,  (int) ($payload['steps']  ?? 20)));
$cfgScale       = max(1.0, min(30.0, (float) ($payload['cfg_scale'] ?? 7.0)));
$seed           = isset($payload['seed']) ? (int) $payload['seed'] : random_int(0, PHP_INT_MAX);

$clientId = bin2hex(random_bytes(8));

// Default KSampler txt2img workflow.
$workflow = [
    '4' => [
        'class_type' => 'CheckpointLoaderSimple',
        'inputs'     => ['ckpt_name' => $checkpoint],
    ],
    '5' => [
        'class_type' => 'EmptyLatentImage',
        'inputs'     => [
            'batch_size' => 1,
            'height'     => $height,
            'width'      => $width,
        ],
    ],
    '6' => [
        'class_type' => 'CLIPTextEncode',
        'inputs'     => [
            'clip' => ['4', 1],
            'text' => $prompt,
        ],
    ],
    '7' => [
        'class_type' => 'CLIPTextEncode',
        'inputs'     => [
            'clip' => ['4', 1],
            'text' => $negativePrompt,
        ],
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
        'inputs'     => [
            'samples' => ['3', 0],
            'vae'     => ['4', 2],
        ],
    ],
    '9' => [
        'class_type' => 'SaveImage',
        'inputs'     => [
            'filename_prefix' => 'ComfyUI',
            'images'          => ['8', 0],
        ],
    ],
];

// If no checkpoint configured, remove CheckpointLoaderSimple and use
// the first available one by querying object_info.
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
        $workflow['4']['inputs']['ckpt_name'] = $checkpoint;
    } else {
        $taskFinished = true;
        completeComfyTask($taskId, 'error');
        http_response_code(400);
        echo json_encode(['error' => 'Kein Checkpoint konfiguriert und kein Checkpoint auf dem ComfyUI-Server gefunden. Bitte Default-Checkpoint im Admin-Bereich eintragen.']);
        exit;
    }
}

// ── Queue the prompt ──────────────────────────────────────────────────────────

$queuePayload = json_encode(['client_id' => $clientId, 'prompt' => $workflow]);

$ch = curl_init($baseUrl . '/prompt');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $queuePayload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    $taskFinished = true;
    completeComfyTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'ComfyUI nicht erreichbar: ' . $curlErr]);
    exit;
}

$queueData = json_decode($body, true);

if ($httpCode !== 200 || !is_array($queueData) || empty($queueData['prompt_id'])) {
    $taskFinished = true;
    completeComfyTask($taskId, 'error');
    http_response_code(502);
    $errMsg = isset($queueData['error']) ? (string) $queueData['error'] : 'ComfyUI Fehler beim Einreihen (HTTP ' . $httpCode . ')';
    echo json_encode(['error' => $errMsg]);
    exit;
}

$promptId = (string) $queueData['prompt_id'];

// ── Poll /history until the prompt is finished ────────────────────────────────

$deadline    = time() + $timeout;
$pollInterval = 1;  // seconds between polls
$historyData  = null;

while (time() < $deadline) {
    sleep($pollInterval);

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

    // Check for error status in the history entry.
    if (!empty($entry['status']['status_str']) && $entry['status']['status_str'] === 'error') {
        $taskFinished = true;
        completeComfyTask($taskId, 'error');
        http_response_code(502);
        $msgs = $entry['status']['messages'] ?? [];
        $errMsg = 'ComfyUI Generierungsfehler.';
        foreach ((array) $msgs as $m) {
            if (is_array($m) && isset($m[0]) && $m[0] === 'execution_error' && isset($m[1]['exception_message'])) {
                $errMsg = (string) $m[1]['exception_message'];
                break;
            }
        }
        echo json_encode(['error' => $errMsg]);
        exit;
    }

    // Check whether outputs are ready.
    if (!empty($entry['outputs'])) {
        $historyData = $entry;
        break;
    }
}

if ($historyData === null) {
    $taskFinished = true;
    completeComfyTask($taskId, 'error');
    http_response_code(504);
    echo json_encode(['error' => 'ComfyUI Timeout: Bild wurde nicht rechtzeitig fertiggestellt.']);
    exit;
}

// ── Download the generated image ──────────────────────────────────────────────

$imageInfo = null;
foreach ($historyData['outputs'] as $nodeId => $nodeOutput) {
    if (!is_array($nodeOutput)) {
        continue;
    }
    $images = $nodeOutput['images'] ?? [];
    if (is_array($images) && !empty($images)) {
        $imageInfo = $images[0];
        break;
    }
}

if (!is_array($imageInfo) || empty($imageInfo['filename'])) {
    $taskFinished = true;
    completeComfyTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'ComfyUI hat kein Bild zurückgegeben.']);
    exit;
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
    $taskFinished = true;
    completeComfyTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'Bild konnte nicht von ComfyUI heruntergeladen werden.']);
    exit;
}

// ── Save image ────────────────────────────────────────────────────────────────

$outputDir = __DIR__ . '/../sd_output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$filename = 'comfy_' . bin2hex(random_bytes(12)) . '.png';
$filePath = $outputDir . '/' . $filename;

if (file_put_contents($filePath, $imageData) === false) {
    $taskFinished = true;
    completeComfyTask($taskId, 'error');
    http_response_code(500);
    echo json_encode(['error' => 'Bild konnte nicht gespeichert werden.']);
    exit;
}

// ── Finish task and respond ───────────────────────────────────────────────────

$taskFinished = true;
completeComfyTask($taskId, 'done', (microtime(true) - $requestStart) * 1000);

echo json_encode([
    'image_url' => 'sd_output/' . $filename,
    'width'     => $width,
    'height'    => $height,
    'prompt'    => $prompt,
    'seed'      => $seed,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
