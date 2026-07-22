<?php
/**
 * api/models.php
 *
 * Proxies GET /v1/models from an LM Studio endpoint and returns the list of
 * available models as JSON.
 *
 * Target endpoint resolution (first match wins):
 *   ?endpoint_url=<url>   – explicit URL, used by the admin panel
 *   (no param)            – first active endpoint from the DB, then legacy settings
 *
 * Response shape (success):
 *   { "models": [ { "id": "...", "object": "model" }, ... ] }
 *
 * Response shape (error):
 *   { "error": "Human-readable message" }
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Determine target endpoint ─────────────────────────────────────────────────

if (isset($_GET['endpoint_url']) && $_GET['endpoint_url'] !== '') {
    // Admin-supplied URL: validate before using.
    $baseUrl = filter_var(trim($_GET['endpoint_url']), FILTER_VALIDATE_URL);
    if ($baseUrl === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige endpoint_url.']);
        exit;
    }
    $baseUrl = rtrim($baseUrl, '/');
    $timeout = max(1, (int) ($_GET['timeout'] ?? 30));
} else {
    // Fall back to first active endpoint from the DB.
    try {
        $ep = getDb()->query(
            "SELECT base_url, timeout FROM endpoints WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1"
        )->fetch();
    } catch (PDOException $e) {
        $ep = null;
    }

    if ($ep) {
        $baseUrl = rtrim($ep['base_url'], '/');
        $timeout = max(1, (int) $ep['timeout']);
    } else {
        // Last resort: legacy settings.
        $baseUrl = rtrim(getSetting('lmstudio_base_url', 'http://localhost:1234/v1'), '/');
        $timeout = max(1, (int) getSetting('lmstudio_timeout', '30'));
    }
}

// ── Query LM Studio ───────────────────────────────────────────────────────────

$url = $baseUrl . '/models';

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
