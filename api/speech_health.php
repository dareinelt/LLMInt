<?php

/**
 * api/speech_health.php
 *
 * Readiness probe for one SpeechInt service. Used by the "Verbindung testen"
 * buttons in the admin card "Spracherkennung / Diktat".
 *
 * SpeechInt answers 503 `service_loading` while it downloads or loads the
 * whisper and Qwen models. That is not a failure: the German message of the
 * service is passed through together with `Retry-After`, and the caller simply
 * asks again later. Only `unreachable`, `error` or `unconfigured` are reported
 * as a failure (docs/api.md of the SpeechInt project).
 *
 * GET:
 *   url   – base URL to probe instead of the configured one (optional)
 *   token – shared secret for that URL (optional; only used together with url)
 *
 * Returns JSON:
 *   { ok, loading, ready, reachable, status, message, retry_after, latency_ms,
 *     http, url, components: { whisper: {…}, llm: {…} },
 *     host: { cores, memory_gb, avx2, minimum, meets_minimum, warnings } }
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
require_once __DIR__ . '/../lib/speech_dictation.php';

$urlParam = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
$target   = $urlParam !== '' ? $urlParam : speechDictationUrl();

// A URL supplied by the form is always probed with exactly the token from the
// form; the configured endpoint uses the stored secret.
$token = $urlParam !== ''
    ? trim((string) ($_GET['token'] ?? ''))
    : speechDictationToken();

$health = speechDictationHealth($target, $token, ['timeout' => 15, 'connect_timeout' => 8]);

// ── Dimensioning of the host (GET /v1/config) ────────────────────────────────
// Only asked for when the service actually answered, so an unreachable host is
// never probed twice.
$host = null;
if ($health['reachable']) {
    $remote = speechDictationRemoteConfig($target, $token);
    if ($remote['ok'] && isset($remote['config']['host']) && is_array($remote['config']['host'])) {
        $raw      = $remote['config']['host'];
        $warnings = [];
        foreach ((array) ($raw['warnings'] ?? []) as $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $warnings[] = trim($warning);
            }
        }
        $host = [
            'cores'         => isset($raw['cores']) ? (int) $raw['cores'] : null,
            'memory_gb'     => isset($raw['memory_gb']) ? (float) $raw['memory_gb'] : null,
            'avx2'          => isset($raw['avx2']) ? (bool) $raw['avx2'] : null,
            // /v1/config reports the minimum as an object
            // { cores, memory_gb, avx2 }; a plain string is tolerated as well.
            'minimum'       => is_array($raw['minimum'] ?? null)
                ? [
                    'cores'     => isset($raw['minimum']['cores']) ? (int) $raw['minimum']['cores'] : null,
                    'memory_gb' => isset($raw['minimum']['memory_gb']) ? (float) $raw['minimum']['memory_gb'] : null,
                    'avx2'      => isset($raw['minimum']['avx2']) ? (bool) $raw['minimum']['avx2'] : null,
                ]
                : (string) ($raw['minimum'] ?? ''),
            'meets_minimum' => (bool) ($raw['meets_minimum'] ?? false),
            'warnings'      => $warnings,
        ];
    }
}

$ok = $health['ready'];

writeLog($health['loading'] ? 'info' : ($ok ? 'info' : 'warning'),
    'Spracherkennung: Verbindungstest '
    . ($target !== '' ? $target : '(nicht konfiguriert)') . ' – '
    . ($health['loading'] ? 'Modelle werden noch geladen'
        : ($ok ? 'bereit' : ($health['message'] !== '' ? $health['message'] : 'nicht bereit')))
    . ' (' . $health['http'] . ', ' . $health['latency_ms'] . ' ms).');

echo json_encode([
    'ok'          => $ok,
    'loading'     => $health['loading'],
    'ready'       => $health['ready'],
    'reachable'   => $health['reachable'],
    'status'      => $health['status'],
    'message'     => $health['message'],
    'retry_after' => $health['retry_after'],
    'latency_ms'  => $health['latency_ms'],
    'http'        => $health['http'],
    'url'         => $health['url'],
    'components'  => $health['components'],
    'host'        => $host,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
