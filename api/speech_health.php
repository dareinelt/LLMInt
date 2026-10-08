<?php

/**
 * api/speech_health.php
 *
 * Reachability probe for the two dictation services. Used by the test buttons
 * in the admin card "Spracherkennung / Diktat".
 *
 * Both probes are plain HTTP GETs with short timeouts, so the endpoint stays
 * responsive even when a container is down.
 *
 * GET:
 *   target – "whisper" | "qwen" | "both" (default "both")
 *
 * Returns JSON { ok, whisper: {ok,message}, qwen: {ok,message} }.
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

$target = strtolower(trim((string) ($_GET['target'] ?? 'both')));
if (!in_array($target, ['whisper', 'qwen', 'both'], true)) {
    $target = 'both';
}

$response = ['ok' => true];

if ($target === 'whisper' || $target === 'both') {
    $response['whisper'] = speechDictationWhisperHealth();
}

if ($target === 'qwen' || $target === 'both') {
    $response['qwen'] = speechDictationQwenHealth();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
