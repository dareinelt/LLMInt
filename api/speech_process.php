<?php

/**
 * api/speech_process.php
 *
 * Runs one dictated fragment through the dictation model (Qwen3.5-2B Q4) and
 * returns the cleaned, command-processed text that belongs into the input
 * field. The endpoint is called once per recognised fragment – the browser
 * keeps the accumulated text, the server stays stateless.
 *
 * The recognised text is never persisted here; it is only forwarded to the
 * model and logged as a length summary (see speechDictationLogText()).
 *
 * POST (JSON or form-encoded):
 *   fragment   – the newly dictated text from the Whisper step
 *   context    – the text already in the input field (read-only reference for
 *                the model, so it can avoid repeating or duplicating it)
 *   csrf_token – CSRF token from the session
 *
 * Returns JSON { ok, text, fallback, model, warning }.
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

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$csrf = (string) ($input['csrf_token'] ?? '');
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

$fragment = (string) ($input['fragment'] ?? '');
$context  = (string) ($input['context'] ?? '');

// Trimming to SPEECH_DICTATION_CONTEXT_CHARS happens inside the library; the
// context is a read-only reference so the model does not repeat what is
// already in the field.
$result = speechDictationProcessFragment($fragment, $context);

if ($result['fallback']) {
    writeLog('warning', 'Spracherkennung: Diktat-Modell nicht verfügbar, Regel-Fallback verwendet – ' . $result['warning']);
}

echo json_encode([
    'ok'       => true,
    'text'     => $result['text'],
    'fallback' => $result['fallback'],
    'model'    => $result['model'],
    'warning'  => $result['warning'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
