<?php

/**
 * api/comfy_checkpoints.php
 *
 * Returns the list of available checkpoints from a ComfyUI instance.
 * Used by the admin UI for connection testing.
 *
 * GET parameters:
 *   endpoint_url  – Base URL of the ComfyUI instance (e.g. http://localhost:8188)
 *   timeout       – Request timeout in seconds (default: 10, max: 30)
 *
 * Response (success):
 *   { "checkpoints": ["model.safetensors", …] }
 *
 * Response (error):
 *   { "error": "…" }
 */

header('Content-Type: application/json; charset=utf-8');

$endpointUrl = trim($_GET['endpoint_url'] ?? '');
$timeout     = max(1, min(30, (int) ($_GET['timeout'] ?? 10)));

if ($endpointUrl === '') {
    http_response_code(400);
    echo json_encode(['error' => 'endpoint_url fehlt.']);
    exit;
}

if (filter_var($endpointUrl, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige URL.']);
    exit;
}

$baseUrl = rtrim($endpointUrl, '/');
$url     = $baseUrl . '/object_info/CheckpointLoaderSimple';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'ComfyUI nicht erreichbar: ' . $curlErr]);
    exit;
}

$data = json_decode($body, true);

if ($httpCode !== 200 || !is_array($data)) {
    http_response_code(502);
    echo json_encode(['error' => 'ComfyUI Fehler (HTTP ' . $httpCode . ')']);
    exit;
}

// The response is: { "CheckpointLoaderSimple": { "input": { "required": { "ckpt_name": [["model.safetensors", ...], "STRING"] } } } }
$checkpoints = [];
$ckptInput = $data['CheckpointLoaderSimple']['input']['required']['ckpt_name'] ?? null;
if (is_array($ckptInput) && isset($ckptInput[0]) && is_array($ckptInput[0])) {
    $checkpoints = $ckptInput[0];
}

echo json_encode([
    'checkpoints' => array_values(array_filter($checkpoints, 'is_string')),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
