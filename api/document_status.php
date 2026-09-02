<?php

/**
 * api/document_status.php
 *
 * Returns the list of document uploads belonging to the currently logged-in
 * user, together with their processing status.
 *
 * GET parameters:
 *   session_id – Optional chat session ID. When given, only documents attached
 *                to that conversation are returned (used by the paperclip
 *                upload chips in the chat composer).
 *   scope      – "library" returns only the documents the user decided to keep
 *                in the knowledge base (used by the library overlay), including
 *                globally shared documents of other users.
 *
 * GET  → JSON { ok, uploads: [...] }
 *
 * Requires an active user session.
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

$userId = (int) $_SESSION['admin_id'];
$db     = getDb();

// Optional chat session filter (paperclip uploads inside a conversation).
$sessionId = '';
if (isset($_GET['session_id']) && is_string($_GET['session_id'])
    && preg_match('/^[a-f0-9]{8,128}$/', $_GET['session_id'])) {
    $sessionId = $_GET['session_id'];
}

$columns = 'id, original_name, mime_type, file_size, status, chunk_count, is_global_rag,
            is_library, chat_session_id, error_message, uploaded_at, processed_at';

$libraryOnly = ($_GET['scope'] ?? '') === 'library';

try {
    if ($libraryOnly) {
        // Own retained documents plus everything other users shared globally.
        $stmt = $db->prepare(
            "SELECT {$columns}, (user_id = ?) AS is_own
               FROM document_uploads
              WHERE is_library = 1
                AND (user_id = ? OR is_global_rag = 1)
              ORDER BY uploaded_at DESC
              LIMIT 200"
        );
        $stmt->execute([$userId, $userId]);
    } elseif ($sessionId !== '') {
        $stmt = $db->prepare(
            "SELECT {$columns}
               FROM document_uploads
              WHERE user_id = ? AND chat_session_id = ?
              ORDER BY uploaded_at ASC
              LIMIT 100"
        );
        $stmt->execute([$userId, $sessionId]);
    } else {
        $stmt = $db->prepare(
            "SELECT {$columns}
               FROM document_uploads
              WHERE user_id = ?
              ORDER BY uploaded_at DESC
              LIMIT 100"
        );
        $stmt->execute([$userId]);
    }
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

echo json_encode(['ok' => true, 'uploads' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
