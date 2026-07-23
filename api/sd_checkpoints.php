<?php

/**
 * api/sd_checkpoints.php
 *
 * Proxies GET /sdapi/v1/sd-models from an AUTOMATIC1111 instance and returns
 * the list of installed checkpoints as JSON.
 *
 * Query parameters:
 *   ?endpoint_url=<url>   – explicit A1111 base URL (used by the admin panel)
 *   ?timeout=<seconds>    – optional, default 15
 *
 * Response shape (success):
 *   { "checkpoints": [ { "title": "...", "model_name": "...", "hash": "..." }, ... ] }
 *
 * Response shape (error):
 *   { "error": "Human-readable message" }
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Determine target endpoint ─────────────────────────────────────────────────

if (isset($_GET['endpoint_url']) && $_GET['endpoint_url'] !== '') {
    $baseUrl = filter_var(trim($_GET['endpoint_url']), FILTER_VALIDATE_URL);
    if ($baseUrl === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige endpoint_url.']);
        exit;
    }
    $baseUrl = rtrim($baseUrl, '/');
    $timeout = max(1, (int) ($_GET['timeout'] ?? 15));
} else {
    try {
        $ep = getDb()->query(
            'SELECT base_url, timeout FROM sd_endpoints WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetch();
    } catch (PDOException $e) {
        $ep = null;
    }

    if (!$ep) {
        http_response_code(503);
        echo json_encode(['error' => 'Kein aktiver SD-Endpunkt konfiguriert.']);
        exit;
    }

    $baseUrl = rtrim($ep['base_url'], '/');
    $timeout = max(1, (int) $ep['timeout']);
}

// ── Query AUTOMATIC1111 ───────────────────────────────────────────────────────

$url = $baseUrl . '/sdapi/v1/sd-models';

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
    echo json_encode(['error' => 'AUTOMATIC1111 nicht erreichbar: ' . $curlErr]);
    exit;
}

$data = json_decode($body, true);

if ($httpCode !== 200 || !is_array($data)) {
    http_response_code(502);
    echo json_encode(['error' => 'Unerwartete Antwort von AUTOMATIC1111 (HTTP ' . $httpCode . ').']);
    exit;
}

// Extract only the fields the UI needs.
$checkpoints = array_map(static function (array $m): array {
    return [
        'title'      => $m['title']      ?? '',
        'model_name' => $m['model_name'] ?? '',
        'hash'       => $m['hash']       ?? '',
    ];
}, $data);

echo json_encode(['checkpoints' => $checkpoints], JSON_UNESCAPED_UNICODE);
