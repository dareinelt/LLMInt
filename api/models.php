<?php
/**
 * api/models.php
 *
 * Proxies GET /v1/models from LM Studio and returns the list of
 * available models as JSON.
 *
 * Response shape (success):
 *   { "models": [ { "id": "...", "object": "model", ... }, ... ] }
 *
 * Response shape (error):
 *   { "error": "Human-readable message" }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// Allow the UI to override the base URL (e.g. when the user changes the endpoint field).
$baseUrl = isset($_GET['endpoint']) && $_GET['endpoint'] !== ''
    ? rtrim(filter_var($_GET['endpoint'], FILTER_SANITIZE_URL), '/')
    : LMSTUDIO_BASE_URL;

$url = $baseUrl . '/models';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => LMSTUDIO_TIMEOUT,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
    exit;
}

$data = json_decode($body, true);

if ($httpCode !== 200 || !isset($data['data'])) {
    http_response_code(502);
    $msg = isset($data['error']['message'])
        ? $data['error']['message']
        : 'Unerwartete Antwort von LM Studio (HTTP ' . $httpCode . ')';
    echo json_encode(['error' => $msg]);
    exit;
}

// Extract only the fields the UI needs.
$models = array_map(static function (array $m): array {
    return [
        'id'     => $m['id']     ?? '',
        'object' => $m['object'] ?? 'model',
    ];
}, $data['data']);

echo json_encode(['models' => $models], JSON_UNESCAPED_UNICODE);
