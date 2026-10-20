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
 *   url     – base URL to probe instead of the configured one (optional)
 *   token   – shared secret for that URL (optional; only used together with url)
 *   quiet   – `1` suppresses the log line (used by the auto-refreshing panel)
 *   compact – `1` skips the `/v1/models` call (the panel only needs components)
 *   fresh   – `1` bypasses the ImageInt health cache (live progress bars)
 *
 * Returns JSON:
 *   { ok, loading, busy, permanent, ready, reachable, status, message,
 *     retry_after, latency_ms, http, url, models: {…}, components: {…}, host: {…} }
 *
 * `busy` means the host accepted the connection but the readiness probe ran
 * into its timeout – it is rendering, not unreachable.
 *
 * Every component carries `label`, `state_label`, `transient`, `since_seconds`
 * and a `progress` block (`known`, `percent`, bytes, files, ETA and
 * `rate_bytes_per_second`). While a model downloads, `known` is true and the
 * admin area renders a determinate bar with the current transfer rate. When
 * `bytes_downloaded` exceeds `bytes_total` (stale blobs in the HuggingFace
 * cache), `bytes_inconsistent` is true and the percentage falls back to the
 * file count.
 * ImageInt caches its health snapshot for a few seconds; `fresh=1` forces a new
 * probe so the bars move in near real time.
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
$quiet    = isset($_GET['quiet']) && (string) $_GET['quiet'] === '1';
$compact  = isset($_GET['compact']) && (string) $_GET['compact'] === '1';
$fresh    = isset($_GET['fresh']) && (string) $_GET['fresh'] === '1';

$probeOptions = ['timeout' => 15, 'connect_timeout' => 8, 'fresh' => $fresh];

// A URL supplied by the form is always probed with exactly the token from the
// form; the configured endpoint uses the stored secret.
$token = $urlParam !== ''
    ? trim((string) ($_GET['token'] ?? ''))
    : imageIntToken();

$health = imageIntReady($target, $token, $probeOptions);
$permanent = $health['error'] === 'host_unsupported';

// ── Detail of the service and its two model servers ──────────────────────────
// Only asked for when the service actually answered, so an unreachable host is
// never probed twice – and a busy one is not probed twice either, because the
// detail endpoints would run into the same timeout. /v1/models answers 200 even
// while the models load, which is why it is queried separately from the
// readiness gate.
$components = [];
$host       = null;
$limits     = [];
$quant      = '';
$modelsRaw  = [];
$authError  = '';

if ($health['reachable'] && !$health['busy']) {
    $detail = imageIntHealth($target, $token, $probeOptions);
    if ($detail['ok'] || $detail['models'] !== []) {
        $components = $detail['models'];
        $limits     = $detail['limits'];
        $quant      = $detail['quant'];
        if ($detail['host'] !== []) {
            $host = $detail['host'];
        }
    }
    // `/v1/ready` may carry the same component map; use it when `/v1/health`
    // answered without a body.
    if ($components === [] && $health['components'] !== []) {
        $components = $health['components'];
    }
    // `/health` and `/v1/ready` are unauthenticated by design, so a ready
    // service says nothing about the secret. A rejected token on the
    // authenticated detail endpoint is a permanent misconfiguration – waiting
    // never fixes it – and must not be reported as "bereit".
    if (($detail['error'] ?? '') === 'unauthorized') {
        $authError = 'ImageInt hat den Zugangsschlüssel abgelehnt. Bitte den Token in den '
            . 'Einstellungen prüfen – er muss zu IMAGEINT_TOKEN der Gegenseite passen.';
    }

    if (!$compact) {
        $models = imageIntModels($target, $token);
        if (is_array($models['models'])) {
            $modelsRaw = $models['models'];
        }
    }
}

$ok = $health['ready'] && $authError === '';
if ($authError !== '') {
    $health['message'] = $authError;
}
$permanent = $permanent || $authError !== '';

if (!$quiet) {
    writeLog($health['loading'] || $health['busy'] ? 'info' : ($ok ? 'info' : 'warning'),
        'Bildgenerierung: Verbindungstest '
        . ($target !== '' ? $target : '(nicht konfiguriert)') . ' – '
        . ($authError !== '' ? 'Zugangsschlüssel abgelehnt'
            : ($permanent ? 'Host nicht unterstützt'
                : ($health['loading'] ? 'Modelle werden noch geladen'
                    : ($health['busy'] ? 'Host rechnet noch (Zeitüberschreitung)'
                        : ($ok ? 'bereit' : ($health['message'] !== '' ? $health['message'] : 'nicht bereit'))))))
        . ' (' . $health['http'] . ', ' . $health['latency_ms'] . ' ms).');
}

echo json_encode([
    'ok'          => $ok,
    'loading'     => $health['loading'],
    'busy'        => $health['busy'],
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
