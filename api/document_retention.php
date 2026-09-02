<?php

/**
 * api/document_retention.php
 *
 * Toggles whether one of the current user's documents is kept in the knowledge
 * base ("Bibliothek") for later RAG searches, and how it is shared.
 *
 * Files uploaded inside a chat are ephemeral by default: they are analysed for
 * the running conversation only. This endpoint is called from the overlay that
 * opens when a user clicks an attached file in the chat.
 *
 * POST (JSON or form-encoded):
 *   id         – document_uploads.id
 *   retain     – "1" to keep the document, "0" to limit it to the chat session
 *   scope      – "global" for all users, anything else for "private"
 *   csrf_token – CSRF token from the session
 *
 * Returns JSON { ok, message, document }.
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

$retain = isset($input['retain']) && (string) $input['retain'] === '1';
$global = $retain && (string) ($input['scope'] ?? 'private') === 'global';

$userId = (int) $_SESSION['admin_id'];
$db     = getDb();

try {
    $stmt = $db->prepare('SELECT id, original_name FROM document_uploads WHERE id = ? AND user_id = ? LIMIT 1');
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

try {
    $db->prepare('UPDATE document_uploads SET is_library = ?, is_global_rag = ? WHERE id = ? AND user_id = ?')
       ->execute([$retain ? 1 : 0, $global ? 1 : 0, $uploadId, $userId]);
} catch (Throwable $e) {
    writeLog('warning', 'Aufbewahrung von Dokument ' . $uploadId . ' konnte nicht geändert werden: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Einstellung konnte nicht gespeichert werden.']);
    exit;
}

$document = null;
try {
    $stmt = $db->prepare(
        'SELECT id, original_name, mime_type, file_size, status, chunk_count, is_global_rag,
                is_library, chat_session_id, error_message, uploaded_at, processed_at
           FROM document_uploads WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uploadId]);
    $document = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    $document = null;
}

writeLog(
    'info',
    'Dokument "' . (string) $row['original_name'] . '" '
    . ($retain
        ? ('in die Wissensdatenbank aufgenommen (' . ($global ? 'für alle Nutzer' : 'nur für mich') . ').')
        : 'wird nicht mehr aufbewahrt (nur noch in der Chat-Sitzung nutzbar).')
);

echo json_encode([
    'ok'       => true,
    'message'  => $retain
        ? ('Datei wird aufbewahrt (' . ($global ? 'für alle Nutzer' : 'nur für mich') . ').')
        : 'Datei wird nicht aufbewahrt.',
    'document' => $document,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
