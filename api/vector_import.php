<?php
/**
 * api/vector_import.php
 *
 * Admin-only endpoint that imports a docvecwizard export archive (.tar.gz,
 * format version 1.x) into the local vector store:
 *
 *   • chunk texts + document metadata → MySQL (vector_documents, vector_chunks)
 *   • vectors                         → local Milvus (collection docvec_<model>)
 *
 * Only the current version (metadata.is_current == 1) of each document is
 * imported. Vectors are inserted unchanged (same ids, same schema) so the
 * local collection is a faithful copy of the docvecwizard one.
 *
 * Request (POST multipart/form-data or x-www-form-urlencoded):
 *   csrf_token    – CSRF token from the session
 *   archive       – uploaded .tar.gz   (or)
 *   server_file   – file name inside vector_imports/ (uploaded out-of-band,
 *                   useful for exports larger than the PHP upload limit)
 *   strategy      – skip (default) | overwrite   for documents already present
 *   action=list   – GET: list archives available in vector_imports/
 *
 * Response: { ok, message, import: {id, documents, chunks, vectors, skipped,
 *             collection, embedding_model, embedding_dimension} }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vector_store.php';
requireAdminOrJson403();

const VECTOR_IMPORT_DIR         = __DIR__ . '/../vector_imports';
const VECTOR_IMPORT_MAX_ENTRIES = 200000;
const VECTOR_IMPORT_MAX_BYTES   = 10 * 1024 * 1024 * 1024;
const VECTOR_IMPORT_BATCH       = 200;

function viRespond(array $payload, int $http = 200): never
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function viIsUuid(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}

function viIsSafeArchivePath(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '..')) {
        return false;
    }
    return (bool) preg_match('#^[A-Za-z0-9._\-/]+$#', $path);
}

function viRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? viRemoveTree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Extract the archive into a fresh temp directory after a safety inspection. */
function viExtract(string $archive): string
{
    $tmpBase = sys_get_temp_dir() . '/llmint_vector_import';
    if (!is_dir($tmpBase)) {
        @mkdir($tmpBase, 0700, true);
    }
    $id   = bin2hex(random_bytes(8));
    $tmp  = $tmpBase . '/x_' . $id;
    $copy = $tmpBase . '/a_' . $id . '.tar.gz';
    @mkdir($tmp, 0700, true);

    if (!copy($archive, $copy)) {
        viRemoveTree($tmp);
        throw new RuntimeException('Archiv konnte nicht zwischengespeichert werden.');
    }
    try {
        try {
            $phar = new PharData($copy);
        } catch (UnexpectedValueException) {
            throw new InvalidArgumentException('Ungültiges oder beschädigtes Archiv (erwartet: .tar.gz-Export von docvecwizard).');
        }
        $prefix  = 'phar://' . str_replace('\\', '/', $copy);
        $entries = 0;
        $bytes   = 0;
        foreach (new RecursiveIteratorIterator($phar) as $file) {
            /** @var PharFileInfo $file */
            $entries++;
            $bytes   += (int) $file->getSize();
            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($prefix)), '/');
            if (!viIsSafeArchivePath($relative)) {
                throw new InvalidArgumentException('Archiv enthält einen unsicheren Pfad: ' . $relative);
            }
            if ($entries > VECTOR_IMPORT_MAX_ENTRIES || $bytes > VECTOR_IMPORT_MAX_BYTES) {
                throw new InvalidArgumentException('Archiv überschreitet die Import-Limits.');
            }
        }
        $phar->extractTo($tmp, null, true);
    } catch (Throwable $e) {
        viRemoveTree($tmp);
        throw $e;
    } finally {
        @unlink($copy);
    }
    return $tmp;
}

/** Verify checksums/SHA256SUMS; returns a list of problems (empty = fine). */
function viVerifyChecksums(string $extract): array
{
    $errors   = [];
    $sumsFile = $extract . '/checksums/SHA256SUMS';
    if (!is_file($sumsFile)) {
        return ['checksums/SHA256SUMS fehlt'];
    }
    foreach (file($sumsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m) !== 1) {
            $errors[] = 'Ungültige SHA256SUMS-Zeile: ' . $line;
            continue;
        }
        if (!viIsSafeArchivePath($m[2])) {
            $errors[] = 'Unsicherer Pfad in SHA256SUMS: ' . $m[2];
            continue;
        }
        $target = $extract . '/' . $m[2];
        if (!is_file($target)) {
            $errors[] = 'Datei fehlt: ' . $m[2];
            continue;
        }
        if (hash_file('sha256', $target) !== $m[1]) {
            $errors[] = 'Prüfsumme falsch: ' . $m[2];
        }
        if (count($errors) >= 10) {
            $errors[] = '…';
            break;
        }
    }
    return $errors;
}

function viListServerFiles(): array
{
    $out = [];
    if (!is_dir(VECTOR_IMPORT_DIR)) {
        return $out;
    }
    foreach (scandir(VECTOR_IMPORT_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..' || !preg_match('/\.(tar\.gz|tgz)$/i', $f)) {
            continue;
        }
        $path = VECTOR_IMPORT_DIR . '/' . $f;
        if (!is_file($path)) {
            continue;
        }
        $out[] = ['name' => $f, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
    }
    usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    return $out;
}

// ── GET: list server-side archives ───────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    viRespond(['ok' => true, 'files' => viListServerFiles(), 'dir' => 'vector_imports/']);
}

// ── POST: run an import ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    viRespond(['ok' => false, 'message' => 'Method not allowed'], 405);
}
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $csrf)) {
    viRespond(['ok' => false, 'message' => 'Ungültiger CSRF-Token.'], 403);
}

$strategy = (($_POST['strategy'] ?? 'skip') === 'overwrite') ? 'overwrite' : 'skip';

// Resolve the archive: uploaded file or a file inside vector_imports/.
$archivePath  = '';
$archiveLabel = '';
$cleanupUpload = false;

if (isset($_FILES['archive']) && (int) $_FILES['archive']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['archive'];
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        $msg = match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Das Archiv überschreitet die maximale Upload-Größe. Große Exporte bitte in den Ordner vector_imports/ legen.',
            default                                   => 'Upload-Fehler (Code ' . (int) $f['error'] . ').',
        };
        viRespond(['ok' => false, 'message' => $msg]);
    }
    $archiveLabel = basename((string) $f['name']);
    if (!preg_match('/\.(tar\.gz|tgz)$/i', $archiveLabel)) {
        viRespond(['ok' => false, 'message' => 'Erwartet wird ein .tar.gz-Export von docvecwizard.']);
    }
    $archivePath = sys_get_temp_dir() . '/llmint_vi_' . bin2hex(random_bytes(6)) . '.tar.gz';
    if (!move_uploaded_file($f['tmp_name'], $archivePath)) {
        viRespond(['ok' => false, 'message' => 'Upload konnte nicht gespeichert werden.']);
    }
    $cleanupUpload = true;
} else {
    $serverFile = basename(trim((string) ($_POST['server_file'] ?? '')));
    if ($serverFile === '' || !preg_match('/^[A-Za-z0-9._\-]+\.(tar\.gz|tgz)$/i', $serverFile)) {
        viRespond(['ok' => false, 'message' => 'Kein Archiv ausgewählt.']);
    }
    $archivePath = VECTOR_IMPORT_DIR . '/' . $serverFile;
    if (!is_file($archivePath)) {
        viRespond(['ok' => false, 'message' => 'Datei nicht gefunden: ' . $serverFile]);
    }
    $archiveLabel = $serverFile;
}

@set_time_limit(0);
@ignore_user_abort(true);

$db = getDb();
$db->prepare('INSERT INTO vector_imports (filename, status, strategy) VALUES (?, "running", ?)')
   ->execute([$archiveLabel, $strategy]);
$importId = (int) $db->lastInsertId();

$finish = static function (string $status, array $counts, string $message, ?array $manifest = null) use ($db, $importId): void {
    $db->prepare(
        'UPDATE vector_imports SET status = ?, documents = ?, chunks = ?, vectors = ?, skipped = ?, message = ?, manifest = ?, finished_at = NOW(3) WHERE id = ?'
    )->execute([
        $status,
        (int) ($counts['documents'] ?? 0),
        (int) ($counts['chunks'] ?? 0),
        (int) ($counts['vectors'] ?? 0),
        (int) ($counts['skipped'] ?? 0),
        mb_substr($message, 0, 60000),
        $manifest !== null ? json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $importId,
    ]);
};

$counts  = ['documents' => 0, 'chunks' => 0, 'vectors' => 0, 'skipped' => 0];
$extract = '';
$touchedCollections = [];
$lastModel = '';
$lastDim   = 0;

try {
    if (!milvusHealth()) {
        throw new RuntimeException('Lokale Milvus-Instanz ist nicht erreichbar (' . milvusBaseUrl() . ').');
    }

    $extract  = viExtract($archivePath);
    $manifest = json_decode((string) @file_get_contents($extract . '/manifest.json'), true);
    if (!is_array($manifest)) {
        throw new InvalidArgumentException('manifest.json fehlt oder ist ungültig.');
    }
    $fmt = (string) ($manifest['export_format_version'] ?? '');
    if (!str_starts_with($fmt, '1.')) {
        throw new InvalidArgumentException('Nicht unterstützte Export-Formatversion: ' . ($fmt !== '' ? $fmt : 'unbekannt'));
    }
    $checksumErrors = viVerifyChecksums($extract);
    if ($checksumErrors !== []) {
        throw new InvalidArgumentException('Integritätsprüfung fehlgeschlagen: ' . implode('; ', $checksumErrors));
    }

    $docsDir = $extract . '/documents';
    if (!is_dir($docsDir)) {
        throw new InvalidArgumentException('Archiv enthält kein documents/-Verzeichnis.');
    }

    $manifestSummary = [
        'export_format_version' => $fmt,
        'export_id'             => $manifest['export_id'] ?? null,
        'created_at'            => $manifest['created_at'] ?? null,
        'documents'             => is_array($manifest['documents'] ?? null) ? count($manifest['documents']) : ($manifest['documents'] ?? null),
        'document_versions'     => $manifest['document_versions'] ?? null,
        'chunks'                => $manifest['chunks'] ?? null,
        'vectors'               => $manifest['vectors'] ?? null,
        'embedding_models'      => $manifest['embedding_models'] ?? [],
    ];

    $selDoc    = $db->prepare('SELECT document_version_id, collection_name FROM vector_documents WHERE document_id = ? LIMIT 1');
    $delDoc    = $db->prepare('DELETE FROM vector_documents WHERE document_id = ?');
    $insDoc    = $db->prepare(
        'INSERT INTO vector_documents (document_id, document_version_id, filename, source_path, mime_type, file_size, page_count,
            chunk_count, vector_count, embedding_model, embedding_dimension, collection_name, import_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insChunk  = $db->prepare(
        'INSERT INTO vector_chunks (chunk_id, vector_id, document_id, chunk_index, page_start, page_end, text)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach (scandir($docsDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !is_dir($docsDir . '/' . $entry)) {
            continue;
        }
        $dir  = $docsDir . '/' . $entry;
        $meta = json_decode((string) @file_get_contents($dir . '/metadata.json'), true);
        if (!is_array($meta)) {
            $counts['skipped']++;
            continue;
        }
        // Only the current version of each document is relevant for RAG.
        if ((int) ($meta['is_current'] ?? 1) !== 1) {
            continue;
        }
        $documentId = (string) ($meta['document_id'] ?? '');
        $versionId  = (string) ($meta['document_version_id'] ?? $entry);
        if (!viIsUuid($documentId) || !viIsUuid($versionId)) {
            $counts['skipped']++;
            continue;
        }
        $model = trim((string) ($meta['embedding_model'] ?? ''));
        $dim   = (int) ($meta['embedding_dimension'] ?? 0);

        $chunks  = json_decode((string) @file_get_contents($dir . '/chunks.json'), true);
        $chunks  = is_array($chunks) ? $chunks : [];
        $vectors = json_decode((string) @file_get_contents($dir . '/vectors.json'), true);
        $vectors = is_array($vectors) ? array_values(array_filter($vectors, 'is_array')) : [];

        if ($chunks === [] || $vectors === [] || $model === '' || $dim <= 0) {
            // Nothing searchable (e.g. processing failed in docvecwizard).
            $counts['skipped']++;
            continue;
        }
        if (!is_array($vectors[0]['vector'] ?? null)) {
            $counts['skipped']++;
            continue;
        }
        $dim = count($vectors[0]['vector']) ?: $dim;
        $collection = milvusCollectionForModel($model);

        $selDoc->execute([$documentId]);
        $existing = $selDoc->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($strategy === 'skip' || (string) $existing['document_version_id'] === $versionId) {
                $counts['skipped']++;
                continue;
            }
            $oldCollection = (string) $existing['collection_name'];
            if ($oldCollection !== '' && milvusHasCollection($oldCollection)) {
                milvusDeleteByFilter($oldCollection, sprintf('document_id == "%s"', $documentId));
            }
            $delDoc->execute([$documentId]);
        }

        if (!isset($touchedCollections[$collection])) {
            if (!milvusHasCollection($collection)) {
                $create = milvusCreateCollection($collection, $dim);
                if (!$create['ok']) {
                    throw new RuntimeException('Collection "' . $collection . '" konnte nicht angelegt werden: ' . $create['message']);
                }
            }
            milvusLoadCollection($collection);
            $touchedCollections[$collection] = true;
        }

        // Vectors first: if Milvus rejects them, MySQL stays untouched.
        $docVectors = 0;
        foreach (array_chunk($vectors, VECTOR_IMPORT_BATCH) as $batch) {
            $rows = [];
            foreach ($batch as $row) {
                if (!is_array($row['vector'] ?? null) || !isset($row['id'])) {
                    continue;
                }
                $rows[] = [
                    'id'                  => (string) $row['id'],
                    'document_id'         => (string) ($row['document_id'] ?? $documentId),
                    'document_version_id' => (string) ($row['document_version_id'] ?? $versionId),
                    'chunk_id'            => (string) ($row['chunk_id'] ?? ''),
                    'job_id'              => (string) ($row['job_id'] ?? ''),
                    'source_path'         => mb_substr((string) ($row['source_path'] ?? ($meta['source_path'] ?? '')), 0, 1024),
                    'filename'            => mb_substr((string) ($row['filename'] ?? ($meta['filename'] ?? '')), 0, 512),
                    'document_hash'       => (string) ($row['document_hash'] ?? ''),
                    'embedding_model'     => (string) ($row['embedding_model'] ?? $model),
                    'embedding_dimension' => (int) ($row['embedding_dimension'] ?? $dim),
                    'chunk_index'         => (int) ($row['chunk_index'] ?? 0),
                    'page_start'          => (int) ($row['page_start'] ?? 0),
                    'page_end'            => (int) ($row['page_end'] ?? 0),
                    'vector'              => array_map('floatval', $row['vector']),
                ];
            }
            if ($rows === []) {
                continue;
            }
            $ins = milvusInsert($collection, $rows);
            if (!$ins['ok']) {
                throw new RuntimeException('Milvus-Insert für "' . ($meta['filename'] ?? $documentId) . '" fehlgeschlagen: ' . $ins['message']);
            }
            $docVectors += count($rows);
        }

        $db->beginTransaction();
        try {
            $insDoc->execute([
                $documentId,
                $versionId,
                mb_substr((string) ($meta['filename'] ?? ''), 0, 512),
                mb_substr((string) ($meta['source_path'] ?? ''), 0, 1024),
                mb_substr((string) ($meta['mime_type'] ?? ''), 0, 191),
                (int) ($meta['file_size'] ?? 0),
                (int) ($meta['page_count'] ?? 0),
                count($chunks),
                $docVectors,
                mb_substr($model, 0, 191),
                $dim,
                $collection,
                $importId,
            ]);
            foreach ($chunks as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }
                $chunkId = (string) ($chunk['chunk_id'] ?? '');
                $text    = (string) ($chunk['text'] ?? '');
                if (!viIsUuid($chunkId) || trim($text) === '') {
                    continue;
                }
                $vectorId = (string) ($chunk['vector_id'] ?? '');
                $insChunk->execute([
                    $chunkId,
                    $vectorId !== '' ? $vectorId : null,
                    $documentId,
                    (int) ($chunk['chunk_index'] ?? 0),
                    (int) ($chunk['page_start'] ?? 0),
                    (int) ($chunk['page_end'] ?? 0),
                    $text,
                ]);
                $counts['chunks']++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            // Keep Milvus consistent with MySQL.
            milvusDeleteByFilter($collection, sprintf('document_id == "%s"', $documentId));
            throw $e;
        }

        $counts['documents']++;
        $counts['vectors'] += $docVectors;
        $lastModel = $model;
        $lastDim   = $dim;
    }

    foreach (array_keys($touchedCollections) as $collection) {
        milvusFlush($collection);
        milvusLoadCollection($collection);
    }

    // Point the local search at the imported collection and refresh the status cache.
    $activeCollection = trim(getSetting('milvus_collection', ''));
    if ($touchedCollections !== [] && ($activeCollection === '' || !isset($touchedCollections[$activeCollection]))) {
        $activeCollection = array_key_last($touchedCollections);
        setSetting('milvus_collection', $activeCollection);
    }
    setSetting('vector_store_status_cache', '');

    $message = sprintf(
        '%d Dokument%s, %d Chunks, %d Vektoren importiert%s.',
        $counts['documents'],
        $counts['documents'] !== 1 ? 'e' : '',
        $counts['chunks'],
        $counts['vectors'],
        $counts['skipped'] > 0 ? ' (' . $counts['skipped'] . ' übersprungen)' : ''
    );
    if ($counts['documents'] === 0 && $counts['skipped'] > 0 && $strategy === 'skip') {
        $message .= ' Alle Dokumente waren bereits vorhanden – Strategie "Überschreiben" wählen, um sie zu aktualisieren.';
    }
    if (count($touchedCollections) > 1) {
        $message .= ' Hinweis: Der Export enthält mehrere Embedding-Modelle (' . implode(', ', array_keys($touchedCollections)) . '); durchsucht wird "' . $activeCollection . '".';
    }

    $finish('done', $counts, $message, $manifestSummary);
    writeLog('info', 'Vektor-Import #' . $importId . ' (' . $archiveLabel . '): ' . $message);

    $response = [
        'ok'      => true,
        'message' => $message,
        'import'  => [
            'id'                  => $importId,
            'documents'           => $counts['documents'],
            'chunks'              => $counts['chunks'],
            'vectors'             => $counts['vectors'],
            'skipped'             => $counts['skipped'],
            'collection'          => $activeCollection,
            'embedding_model'     => $lastModel,
            'embedding_dimension' => $lastDim,
        ],
    ];
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $finish('error', $counts, $msg);
    writeLog('error', 'Vektor-Import #' . $importId . ' (' . $archiveLabel . ') fehlgeschlagen: ' . $msg);
    $response = ['ok' => false, 'message' => $msg, 'import' => ['id' => $importId] + $counts];
} finally {
    if ($extract !== '') {
        viRemoveTree($extract);
    }
    if ($cleanupUpload && $archivePath !== '') {
        @unlink($archivePath);
    }
}

viRespond($response);
