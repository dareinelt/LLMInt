<?php

/**
 * api/image_health.php
 *
 * Readiness probe for one ImageInt service. Used by the "Verbindung testen"
 * button in the admin card "Bildgenerierung (ImageInt)".
 *
 * ImageInt answers 503 `service_loading` with `transient: true` while it loads
 * the prompt enhancer and the renderer. That is not a failure: the German
 * message is passed through together with `Retry-After` and the caller simply
 * asks again later. A 503 `host_unsupported` is the opposite – a permanent
 * misconfiguration that no amount of waiting will fix – and is reported as
 * `permanent: true`.
 *
 * GET:
 *   url   – base URL to probe instead of the configured one (optional)
 *   token – shared secret for that URL (optional; only used together with url)
 *
 * Returns JSON:
 *   { ok, loading, permanent, ready, reachable, status, message, retry_after,
 *     latency_ms, http, url, models: {…}, components: {…}, host: {…} }
 *
 * The token is never echoed back.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nicht authentifiziert.']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/image_generation.php';

$urlParam = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$target   = $urlParam !== '' ? $urlParam : imageIntUrl();

// A URL supplied by the form is always probed with exactly the token from the
// form; the configured endpoint uses the stored secret.
$token = $urlParam !== ''
    ? trim((string) ($_GET['token'] ?? ''))
    : imageIntToken();

$health = imageIntReady($target, $token, ['timeout' => 15, 'connect_timeout' => 8]);
$permanent = $health['error'] === 'host_unsupported';

// ── Detail of the service and its two model servers ──────────────────────────
// Only asked for when the service actually answered, so an unreachable host is
// never probed twice. /v1/models answers 200 even while the models load, which
// is why it is queried separately from the readiness gate.
$components = [];
$host       = null;
$limits     = [];
$quant      = '';
$modelsRaw  = [];
$authError  = '';

if ($health['reachable']) {
    $detail = imageIntHealth($target, $token, ['timeout' => 15, 'connect_timeout' => 8]);
    if ($detail['ok'] || $detail['models'] !== []) {
        $components = $detail['models'];
        $limits     = $detail['limits'];
        $quant      = $detail['quant'];
        if ($detail['host'] !== []) {
            $host = $detail['host'];
        }
    }
    // `/health` and `/v1/ready` are unauthenticated by design, so a ready
    // service says nothing about the secret. A rejected token on the
    // authenticated detail endpoint is a permanent misconfiguration – waiting
    // never fixes it – and must not be reported as "bereit".
    if (($detail['error'] ?? '') === 'unauthorized') {
        $authError = 'ImageInt hat den Zugangsschlüssel abgelehnt. Bitte den Token in den '
            . 'Einstellungen prüfen – er muss zu IMAGEINT_TOKEN der Gegenseite passen.';
    }

    $models = imageIntModels($target, $token);
    if (is_array($models['models'])) {
        $modelsRaw = $models['models'];
    }
}

$ok = $health['ready'] && $authError === '';
if ($authError !== '') {
    $health['message'] = $authError;
}
$permanent = $permanent || $authError !== '';

writeLog($health['loading'] ? 'info' : ($ok ? 'info' : 'warning'),
    'Bildgenerierung: Verbindungstest '
    . ($target !== '' ? $target : '(nicht konfiguriert)') . ' – '
    . ($authError !== '' ? 'Zugangsschlüssel abgelehnt'
        : ($permanent ? 'Host nicht unterstützt'
            : ($health['loading'] ? 'Modelle werden noch geladen'
                : ($ok ? 'bereit' : ($health['message'] !== '' ? $health['message'] : 'nicht bereit')))))
    . ' (' . $health['http'] . ', ' . $health['latency_ms'] . ' ms).');

echo json_encode([
    'ok'          => $ok,
    'loading'     => $health['loading'],
    'permanent'   => $permanent,
    'ready'       => $health['ready'],
    'reachable'   => $health['reachable'],
    'status'      => $health['status'],
    'message'     => $health['message'],
    'retry_after' => $health['retry_after'],
    'latency_ms'  => $health['latency_ms'],
    'http'        => $health['http'],
    'url'         => $health['url'],
    'quant'       => $quant,
    'models'      => $modelsRaw,
    'components'  => $components,
    'limits'      => $limits,
    'host'        => $host,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
