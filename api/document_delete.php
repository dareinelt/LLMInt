<?php

/**
 * api/document_delete.php
 *
 * Removes one of the current user's document uploads: the stored file, the
 * database record and – through the ON DELETE CASCADE on document_chunks – all
 * chunks and their embeddings.
 *
 * POST (JSON or form-encoded):
 *   id         – document_uploads.id
 *   csrf_token – CSRF token from the session
 *
 * Returns JSON { ok, message }.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nicht authentifiziert.']);
    exit;
}

require_once __DIR__ . '/../db.php';

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

$uploadId = (int) ($input['id'] ?? 0);
if ($uploadId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Ungültige Dokument-ID.']);
    exit;
}

$userId = (int) $_SESSION['admin_id'];
$db     = getDb();

try {
    $stmt = $db->prepare('SELECT id, stored_name, original_name FROM document_uploads WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$uploadId, $userId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    $row = false;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Dokument nicht gefunden.']);
    exit;
}

// Remove the stored file; guard against path traversal via the stored name.
$storedName = basename((string) $row['stored_name']);
if ($storedName !== '') {
    $path = __DIR__ . '/../doc_uploads/' . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}

try {
    // document_chunks are removed by the ON DELETE CASCADE foreign key.
    $db->prepare('DELETE FROM document_uploads WHERE id = ? AND user_id = ?')->execute([$uploadId, $userId]);
} catch (Throwable $e) {
    writeLog('warning', 'Dokument ' . $uploadId . ' konnte nicht gelöscht werden: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Dokument konnte nicht gelöscht werden.']);
    exit;
}

writeLog('info', 'Dokument "' . (string) $row['original_name'] . '" wurde entfernt.');

echo json_encode(['ok' => true, 'message' => 'Dokument entfernt.'], JSON_UNESCAPED_UNICODE);
