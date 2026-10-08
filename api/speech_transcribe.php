<?php

/**
 * api/speech_transcribe.php
 *
 * Transcribes one recorded audio segment through the SpeechInt service
 * (POST /v1/audio/transcriptions) and returns the raw text. Called by the
 * dictation recorder in index.php after every segment, so the recogniser runs
 * on short slices instead of one long recording (whisper.cpp is not a streaming
 * recogniser).
 *
 * The recognised text is returned unchanged and is *not* stored: it only lives
 * in the browser until the dictation-command step (api/speech_process.php) has
 * turned it into final text for the input field. See §21 Datenschutz.
 *
 * While SpeechInt still loads its models it answers 503 `service_loading`. That
 * is not a failure: the answer carries `loading: true`, the German message and
 * `retry_after`, and the recorder keeps the segment and asks again.
 *
 * POST multipart/form-data:
 *   audio      – recorded segment (WAV/WebM/OGG, see speechDictationMaxAudioBytes)
 *   csrf_token – CSRF token from the session
 *
 * Returns JSON { ok, loading, retry_after, message, text, empty, duration_ms,
 *                bytes }.
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Nur POST erlaubt.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger CSRF-Token.']);
    exit;
}

if (!speechDictationEnabled()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Die Spracherkennung ist deaktiviert.']);
    exit;
}

$speechUrl = speechDictationUrl();
if ($speechUrl === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Es ist kein Speech-Endpunkt konfiguriert.']);
    exit;
}

if (!isset($_FILES['audio']) || !is_array($_FILES['audio'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Keine Audiodaten empfangen.']);
    exit;
}

$upload     = $_FILES['audio'];
$uploadErr  = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
$tmpPath    = (string) ($upload['tmp_name'] ?? '');
$size       = (int) ($upload['size'] ?? 0);
$maxBytes   = speechDictationMaxAudioBytes();

if ($uploadErr !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $message = $uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE
        ? 'Die Aufnahme ist zu groß für den Server.'
        : 'Die Aufnahme konnte nicht übertragen werden.';
    writeLog('warning', 'Spracherkennung: Upload fehlgeschlagen (Code ' . $uploadErr . ').');
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

if ($size <= 0) {
    // An empty segment is not an error: it simply carries no speech.
    echo json_encode(['ok' => true, 'loading' => false, 'text' => '', 'empty' => true, 'duration_ms' => 0, 'bytes' => 0]);
    exit;
}

if ($size > $maxBytes) {
    http_response_code(413);
    writeLog('warning', 'Spracherkennung: Segment überschreitet das Limit (' . $size . ' > ' . $maxBytes . ' Bytes).');
    echo json_encode([
        'ok'      => false,
        'message' => 'Die Aufnahme ist zu groß (max. ' . round($maxBytes / 1048576, 1) . ' MB).',
    ]);
    exit;
}

// The browser sends a Blob whose filename already carries a usable extension.
$originalName = (string) ($upload['name'] ?? 'segment.wav');
$safeName     = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
if ($safeName === '' || $safeName === null) {
    $safeName = 'segment.wav';
}

$mime = (string) ($upload['type'] ?? '');
if ($mime === '' && function_exists('mime_content_type')) {
    $detected = @mime_content_type($tmpPath);
    $mime = is_string($detected) ? $detected : '';
}

$result = speechDictationTranscribeFile($tmpPath, $safeName, $mime);

if (!empty($result['loading'])) {
    // The service is still downloading or loading its models. No error: the
    // recorder keeps the segment and asks again after `retry_after` seconds.
    writeLog('info', 'Spracherkennung: SpeechInt lädt noch – Segment wird erneut versucht.');
    echo json_encode([
        'ok'          => false,
        'loading'     => true,
        'retry_after' => (int) $result['retry_after'],
        'message'     => (string) $result['message'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$result['ok']) {
    http_response_code(502);
    writeLog('error', 'Spracherkennung: Transkription fehlgeschlagen – ' . $result['error']);
    echo json_encode([
        'ok'      => false,
        'message' => 'Die Spracherkennung ist fehlgeschlagen: ' . $result['error'],
    ]);
    exit;
}

$text = trim((string) $result['text']);

writeLog('info', 'Spracherkennung: Segment transkribiert (' . speechDictationLogText($text) . ').');

echo json_encode([
    'ok'          => true,
    'loading'     => false,
    'text'        => $text,
    'empty'       => $text === '',
    'duration_ms' => (int) $result['duration_ms'],
    'bytes'       => $size,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
