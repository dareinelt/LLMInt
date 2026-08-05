<?php

/**
 * api/rebuild_embeddings.php
 *
 * Admin endpoint: re-generate embeddings for document chunks.
 *
 * Processes chunks that are missing embeddings or belong to uploads
 * whose embedding_status is not 'done'.
 *
 * POST parameters:
 *   csrf_token   – CSRF token from the admin session.
 *   upload_id    – (optional) Limit rebuild to a specific document upload ID.
 *   force_all    – (optional) "1" to regenerate even existing embeddings.
 *   batch_size   – (optional) Number of chunks to process (default 100, max 500).
 *
 * Returns JSON:
 *   { ok: bool, processed: int, errors: int, remaining: int, message: string }
 *
 * Requires admin session ($_SESSION['admin_user']).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

// Auth: admin only.
requireAdminOrJson403();

require_once __DIR__ . '/embedding.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

// CSRF check.
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger CSRF-Token.']);
    exit;
}

// Check that embedding is configured.
if (getSetting('embedding_enabled', '0') !== '1') {
    echo json_encode(['ok' => false, 'message' => 'Embedding ist deaktiviert. Bitte zunächst in den Einstellungen aktivieren.']);
    exit;
}

if (!hasActiveEmbeddingEndpoint()) {
    echo json_encode(['ok' => false, 'message' => 'Kein aktiver Embedding-Endpunkt konfiguriert.']);
    exit;
}

$db        = getDb();
$uploadId  = isset($_POST['upload_id']) && (int) $_POST['upload_id'] > 0 ? (int) $_POST['upload_id'] : null;
$forceAll  = isset($_POST['force_all']) && $_POST['force_all'] === '1';
$batchSize = max(1, min(500, (int) ($_POST['batch_size'] ?? 100)));

// Pick an endpoint to use for this rebuild run.
$endpoint = pickEmbeddingEndpoint();
if ($endpoint === null) {
    echo json_encode(['ok' => false, 'message' => 'Kein aktiver Embedding-Endpunkt verfügbar.']);
    exit;
}

$model = (string) ($endpoint['model'] ?? '');

// ── Build query for chunks to process ────────────────────────────────────────

$conditions = ["du.status = 'done'"];
$params     = [];

if ($uploadId !== null) {
    $conditions[] = 'dc.document_upload_id = ?';
    $params[]     = $uploadId;
}

if (!$forceAll) {
    // Only process chunks without an embedding (or with a different model).
    $conditions[] = '(dc.embedding IS NULL OR dc.embedding_model != ?)';
    $params[]     = $model;
}

$where = implode(' AND ', $conditions);

// Count remaining for progress feedback.
$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM document_chunks dc
     JOIN document_uploads du ON du.id = dc.document_upload_id
     WHERE {$where}"
);
$countStmt->execute($params);
$totalRemaining = (int) $countStmt->fetchColumn();

// Fetch the batch.
$batchParams   = $params;
$batchParams[] = $batchSize;
$batchStmt     = $db->prepare(
    "SELECT dc.id AS chunk_id, dc.document_upload_id, dc.chunk_text
       FROM document_chunks dc
       JOIN document_uploads du ON du.id = dc.document_upload_id
      WHERE {$where}
      ORDER BY dc.document_upload_id ASC, dc.chunk_index ASC
      LIMIT ?"
);
$batchStmt->execute($batchParams);
$chunks = $batchStmt->fetchAll();

if (empty($chunks)) {
    echo json_encode([
        'ok'        => true,
        'processed' => 0,
        'errors'    => 0,
        'remaining' => 0,
        'message'   => 'Keine Chunks zum Verarbeiten gefunden.',
    ]);
    exit;
}

$updateChunk = $db->prepare(
    'UPDATE document_chunks
        SET embedding = ?, embedding_dimension = ?, embedding_model = ?, embedding_created_at = NOW(3)
      WHERE id = ?'
);

$processed = 0;
$errors    = 0;
$uploadIds = [];

foreach ($chunks as $chunk) {
    $chunkId   = (int) $chunk['chunk_id'];
    $chunkText = trim((string) ($chunk['chunk_text'] ?? ''));
    $upId      = (int) $chunk['document_upload_id'];

    if ($chunkText === '') {
        $errors++;
        continue;
    }

    $embedding = generateEmbedding($chunkText, $endpoint);
    if ($embedding === null) {
        $errors++;
        continue;
    }

    $dim = count($embedding);
    $updateChunk->execute([
        json_encode($embedding),
        $dim,
        $model,
        $chunkId,
    ]);

    $processed++;
    $uploadIds[$upId] = true;
}

// Update embedding_status for affected uploads.
foreach (array_keys($uploadIds) as $upId) {
    // Check if all chunks for this upload now have embeddings.
    $chkStmt = $db->prepare(
        'SELECT COUNT(*) FROM document_chunks
          WHERE document_upload_id = ? AND (embedding IS NULL OR embedding_model != ?)'
    );
    $chkStmt->execute([$upId, $model]);
    $missing = (int) $chkStmt->fetchColumn();

    $status = ($missing === 0) ? 'done' : (($errors > 0) ? 'error' : 'processing');
    $db->prepare(
        'UPDATE document_uploads SET embedding_status = ? WHERE id = ?'
    )->execute([$status, $upId]);
}

$remaining = max(0, $totalRemaining - $processed);

$msg = $processed . ' Chunks verarbeitet';
if ($errors > 0) {
    $msg .= ', ' . $errors . ' Fehler';
}
if ($remaining > 0) {
    $msg .= ', ' . $remaining . ' ausstehend';
}
$msg .= '.';

writeLog('info', 'Embedding-Neuerstellung: ' . $msg);

echo json_encode([
    'ok'        => $errors === 0 || $processed > 0,
    'processed' => $processed,
    'errors'    => $errors,
    'remaining' => $remaining,
    'message'   => $msg,
]);
