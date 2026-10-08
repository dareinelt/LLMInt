<?php

/**
 * api/speech_config.php
 *
 * Read-only configuration handshake for the dictation feature. index.php calls
 * this once on load and again whenever the dictation state machine needs to
 * know whether the backend is ready.
 *
 * The endpoint deliberately performs no network requests of its own: it only
 * reports the effective settings and whether a SpeechInt endpoint is configured
 * at all, so a slow or stopped speech host can never delay the chat UI. The
 * last known readiness (cached for 20 s by the admin dashboard) is attached as
 * `status`; actual reachability is checked lazily by api/speech_transcribe.php
 * and api/speech_process.php, which report the SpeechInt loading contract
 * (`loading` + `retry_after`) to the browser. Administrators can probe an
 * endpoint explicitly through api/speech_health.php.
 *
 * GET (or POST with CSRF):
 *   csrf_token – CSRF token from the session (optional for GET)
 *
 * Returns JSON:
 *   {
 *     ok, enabled, ready, configured,
 *     speech_url, url_source, endpoint_id, endpoint_label, timeout_seconds,
 *     buffer_words, stop_timeout_seconds, max_segment_seconds, max_audio_bytes,
 *     language,
 *     status: { ready, loading, reachable, message, retry_after, checked_at },
 *     pills: [{id,label,emoji,instruction}],
 *     commands: [{phrase,preview,type,value}],
 *     csrf_token
 *   }
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

// Mirror the token handling of the other authenticated entry points so the
// documented `csrf_token` field is populated even when the client asks for the
// config before index.php has run.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$commands = [];
foreach (speechDictationCommands() as $cmd) {
    $commands[] = [
        'phrase'  => (string) $cmd['phrase'],
        'preview' => speechDictationCommandPreview($cmd),
        'type'    => (string) ($cmd['type'] ?? 'insert'),
        'value'   => (string) ($cmd['value'] ?? ''),
    ];
}

$pills = [];
foreach (speechDictationPills() as $pill) {
    $pills[] = [
        'id'          => (string) $pill['id'],
        'label'       => (string) $pill['label'],
        'emoji'       => (string) $pill['emoji'],
        'instruction' => (string) $pill['instruction'],
    ];
}

$activeEndpoint = speechDictationActiveEndpoint();

// Cached readiness only – never a live probe (see the file docblock).
$status = speechDictationDashboardStatus(false);

echo json_encode([
    'ok'                   => true,
    'enabled'              => speechDictationEnabled(),
    'configured'           => speechDictationConfigured(),
    'ready'                => speechDictationConfigured(),
    'speech_url'           => speechDictationUrl(),
    'url_source'           => speechDictationUrlSource(),
    'endpoint_id'          => speechDictationEndpointId(),
    'endpoint_label'       => $activeEndpoint !== null
        ? speechDictationEndpointLabel($activeEndpoint)
        : '',
    'timeout_seconds'      => speechDictationTimeout(),
    'buffer_words'         => speechDictationBufferWords(),
    'stop_timeout_seconds' => speechDictationStopTimeoutSeconds(),
    'max_segment_seconds'  => speechDictationMaxSegmentSeconds(),
    'max_audio_bytes'      => speechDictationMaxAudioBytes(),
    'language'             => speechDictationLanguage(),
    'status'               => [
        'ready'       => (bool) $status['ready'],
        'loading'     => (bool) $status['loading'],
        'reachable'   => (bool) $status['reachable'],
        'message'     => (string) $status['message'],
        'retry_after' => (int) $status['retry_after'],
        'checked_at'  => (int) $status['checked_at'],
    ],
    'pills'                => $pills,
    'commands'             => $commands,
    'csrf_token'           => (string) ($_SESSION['csrf_token'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
