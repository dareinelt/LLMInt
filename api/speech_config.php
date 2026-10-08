<?php

/**
 * api/speech_config.php
 *
 * Read-only configuration handshake for the dictation feature. index.php calls
 * this once on load and again whenever the dictation state machine needs to
 * know whether the backend is ready.
 *
 * The endpoint deliberately performs no network requests: it only reports the
 * effective settings and whether the required services are configured at all.
 * Actual reachability is checked lazily by api/speech_transcribe.php and
 * api/speech_process.php, so a slow whisper/Qwen container can never delay the
 * chat UI. Administrators can probe both services explicitly through
 * api/speech_health.php.
 *
 * GET (or POST with CSRF):
 *   csrf_token – CSRF token from the session (optional for GET)
 *
 * Returns JSON:
 *   {
 *     ok, enabled, ready, configured,
 *     whisper_ready, dictation_model_ready,
 *     buffer_words, stop_timeout_seconds, max_segment_seconds, max_audio_bytes,
 *     whisper_timeout_seconds, qwen_timeout_seconds,
 *     language, whisper_model, qwen_model,
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

// The dictation step needs a model: either the dedicated llama.cpp server
// (QWEN_URL) or a usable endpoint in the pool.
$qwenUrl     = speechDictationQwenUrl();
$qwenModel   = speechDictationQwenModel();
$qwenReady   = $qwenUrl !== '';
if (!$qwenReady) {
    try {
        $qwenReady = pickEndpointForModel($qwenModel) !== null;
    } catch (Throwable $_e) {
        $qwenReady = false;
    }
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

echo json_encode([
    'ok'                   => true,
    'enabled'              => speechDictationEnabled(),
    'configured'           => speechDictationConfigured(),
    'whisper_ready'        => speechDictationWhisperUrl() !== '',
    'dictation_model_ready'=> $qwenReady,
    'ready'                => speechDictationEnabled() && speechDictationConfigured() && $qwenReady,
    'buffer_words'         => speechDictationBufferWords(),
    'stop_timeout_seconds' => speechDictationStopTimeoutSeconds(),
    'max_segment_seconds'  => speechDictationMaxSegmentSeconds(),
    'max_audio_bytes'      => speechDictationMaxAudioBytes(),
    'whisper_timeout_seconds' => speechDictationWhisperTimeout(),
    'qwen_timeout_seconds'    => speechDictationQwenTimeout(),
    'language'             => speechDictationLanguage(),
    'whisper_model'        => speechDictationWhisperModel(),
    'qwen_model'           => $qwenModel,
    'qwen_direct'          => $qwenUrl !== '',
    'pills'                => $pills,
    'commands'             => $commands,
    'csrf_token'           => (string) ($_SESSION['csrf_token'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
