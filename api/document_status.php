<?php

/**
 * api/document_status.php
 *
 * Returns the list of document uploads belonging to the currently logged-in
 * user, together with their processing status.
 *
 * GET  → JSON array of upload records
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

try {
    $stmt = $db->prepare(
        'SELECT id, original_name, mime_type, file_size, status, chunk_count, is_global_rag, error_message, uploaded_at, processed_at
           FROM document_uploads
          WHERE user_id = ?
          ORDER BY uploaded_at DESC
          LIMIT 100'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

echo json_encode(['ok' => true, 'uploads' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
