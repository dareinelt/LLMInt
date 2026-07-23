<?php

/**
 * api/sd_generate.php
 *
 * Generates an image via an AUTOMATIC1111 API endpoint.
 *
 * Accepts a POST JSON body:
 *   {
 *     "mode":            "txt2img" | "img2img"  (default: txt2img)
 *     "prompt":          string  (required)
 *     "negative_prompt": string  (optional)
 *     "width":           int     (optional, default 512)
 *     "height":          int     (optional, default 512)
 *     "steps":           int     (optional, default 20)
 *     "cfg_scale":       float   (optional, default 7)
 *     "init_images":     array   (required for img2img; base64-encoded images)
 *     "denoising_strength": float (optional, img2img only, default 0.75)
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
require_once __DIR__ . '/sd_balancer.php';

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

$mode = in_array($payload['mode'] ?? '', ['img2img'], true) ? 'img2img' : 'txt2img';

// ── Select SD endpoint ────────────────────────────────────────────────────────

try {
    $slot = pickSdEndpoint($mode);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interner Fehler beim SD-Endpunkt-Routing.']);
    exit;
}

if ($slot === null) {
    http_response_code(503);
    echo json_encode(['error' => 'Kein SD-Endpunkt verfügbar. Alle Kapazitäten sind belegt oder kein passender Endpunkt konfiguriert.']);
    exit;
}

$endpoint = $slot['endpoint'];
$taskId   = $slot['task_id'];
$baseUrl  = rtrim($endpoint['base_url'], '/');
$timeout  = max(1, (int) $endpoint['timeout']);

// Ensure the task is always marked finished, even on unexpected PHP termination.
$taskFinished = false;
register_shutdown_function(static function () use ($taskId, &$taskFinished): void {
    if (!$taskFinished) {
        try {
            completeSdTask($taskId, 'error');
        } catch (Throwable $e) {
            // Best-effort
        }
    }
});

// ── Build AUTOMATIC1111 request body ─────────────────────────────────────────

$sdPayload = [
    'prompt'          => $prompt,
    'negative_prompt' => (string) ($payload['negative_prompt'] ?? ''),
    'width'           => max(64, min(2048, (int) ($payload['width']  ?? 512))),
    'height'          => max(64, min(2048, (int) ($payload['height'] ?? 512))),
    'steps'           => max(1,  min(150,  (int) ($payload['steps']  ?? 20))),
    'cfg_scale'       => max(1.0, min(30.0, (float) ($payload['cfg_scale'] ?? 7.0))),
    'save_images'     => false,
    'send_images'     => true,
];

if ($mode === 'img2img') {
    $initImages = $payload['init_images'] ?? [];
    if (!is_array($initImages) || count($initImages) === 0) {
        $taskFinished = true;
        completeSdTask($taskId, 'error');
        http_response_code(400);
        echo json_encode(['error' => '"init_images" ist für img2img erforderlich.']);
        exit;
    }
    $sdPayload['init_images']        = $initImages;
    $sdPayload['denoising_strength'] = max(0.0, min(1.0, (float) ($payload['denoising_strength'] ?? 0.75)));
}

$apiPath = $mode === 'img2img' ? '/sdapi/v1/img2img' : '/sdapi/v1/txt2img';
$url     = $baseUrl . $apiPath;

// ── Call AUTOMATIC1111 ────────────────────────────────────────────────────────

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($sdPayload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    $taskFinished = true;
    completeSdTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'AUTOMATIC1111 nicht erreichbar: ' . $curlErr]);
    exit;
}

$data = json_decode($body, true);

if ($httpCode !== 200 || !is_array($data)) {
    $taskFinished = true;
    completeSdTask($taskId, 'error');
    http_response_code(502);
    $msg = isset($data['detail']) ? (string) $data['detail'] : 'AUTOMATIC1111 Fehler (HTTP ' . $httpCode . ')';
    echo json_encode(['error' => $msg]);
    exit;
}

$images = $data['images'] ?? [];
if (!is_array($images) || count($images) === 0) {
    $taskFinished = true;
    completeSdTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'AUTOMATIC1111 hat kein Bild zurückgegeben.']);
    exit;
}

// ── Save the first returned image ─────────────────────────────────────────────

$outputDir = __DIR__ . '/../sd_output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$imageData = base64_decode($images[0], true);
if ($imageData === false) {
    $taskFinished = true;
    completeSdTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'Ungültige Bilddaten von AUTOMATIC1111.']);
    exit;
}

$filename = 'sd_' . bin2hex(random_bytes(12)) . '.png';
$filePath = $outputDir . '/' . $filename;

if (file_put_contents($filePath, $imageData) === false) {
    $taskFinished = true;
    completeSdTask($taskId, 'error');
    http_response_code(500);
    echo json_encode(['error' => 'Bild konnte nicht gespeichert werden.']);
    exit;
}

// ── Finish task and respond ───────────────────────────────────────────────────

$taskFinished = true;
completeSdTask($taskId, 'done');

// Extract seed from A1111 info if available.
$seed = null;
if (isset($data['info'])) {
    $info = json_decode((string) $data['info'], true);
    if (is_array($info) && isset($info['seed'])) {
        $seed = (int) $info['seed'];
    }
}

echo json_encode([
    'image_url' => 'sd_output/' . $filename,
    'width'     => $sdPayload['width'],
    'height'    => $sdPayload['height'],
    'prompt'    => $prompt,
    'seed'      => $seed,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
