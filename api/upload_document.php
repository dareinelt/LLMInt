<?php

/**
 * api/upload_document.php
 *
 * Handles document uploads and turns them into RAG chunks.
 *
 * POST multipart/form-data:
 *   file          – The uploaded file (see the format table below)
 *   csrf_token    – CSRF token from the session
 *   global_rag    – "1" for globally shareable RAG usage, else "0"
 *   retain        – "1" to keep the file in the knowledge base ("Bibliothek")
 *                   for later RAG searches. Uploads attached to a chat session
 *                   default to "0": they are processed for the running
 *                   conversation only and the stored file is discarded right
 *                   after the analysis.
 *   session_id    – Optional chat session ID; the upload is then attached to
 *                   that conversation and usable inside it right away
 *
 * Supported formats:
 *   Images (PNG/JPG/WEBP/GIF)  → vision model analysis
 *   PDF                        → pages rendered to images (pdftoppm) and analysed
 *                                page by page with the vision model; falls back
 *                                to the pdftotext text layer
 *   Office & text documents    → docconvert service (see api/doc_convert.php)
 *
 * Requires an active user session with can_upload_documents = 1.
 * Returns JSON { ok, message, id?, chunk_count?, document? }.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Auth: must be logged in.
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nicht authentifiziert.']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/embedding.php';
require_once __DIR__ . '/doc_convert.php';
require_once __DIR__ . '/pdf_render.php';
require_once __DIR__ . '/vision.php';

// ── Text helpers ──────────────────────────────────────────────────────────────

function normalizeDocumentText(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function buildDocumentChunks(string $text, int $maxChars = 1800, int $overlapChars = 250): array
{
    $text = normalizeDocumentText($text);
    if ($text === '') {
        return [];
    }

    $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];
    $chunks = [];
    $current = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }

        $candidate = $current === '' ? $paragraph : ($current . "\n\n" . $paragraph);
        if (mb_strlen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
            $tail = mb_substr($current, max(0, mb_strlen($current) - $overlapChars));
            $current = trim($tail . "\n\n" . $paragraph);
            while (mb_strlen($current) > $maxChars) {
                $chunks[] = mb_substr($current, 0, $maxChars);
                $tail = mb_substr($current, max(0, $maxChars - $overlapChars), $overlapChars);
                $current = trim($tail . mb_substr($current, $maxChars));
            }
            continue;
        }

        $remaining = $paragraph;
        while (mb_strlen($remaining) > $maxChars) {
            $chunks[] = mb_substr($remaining, 0, $maxChars);
            $tail = mb_substr($remaining, max(0, $maxChars - $overlapChars), $overlapChars);
            $remaining = trim($tail . mb_substr($remaining, $maxChars));
        }
        $current = $remaining;
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return array_values(array_filter(
        array_map(static fn($chunk) => trim($chunk), $chunks),
        static fn($chunk) => $chunk !== ''
    ));
}

function persistDocumentChunks(PDO $db, int $uploadId, int $userId, array $chunks): int
{
    $db->prepare('DELETE FROM document_chunks WHERE document_upload_id = ?')->execute([$uploadId]);
    if (empty($chunks)) {
        return 0;
    }

    $insert = $db->prepare(
        'INSERT INTO document_chunks (document_upload_id, user_id, chunk_index, chunk_text, created_at)
         VALUES (?, ?, ?, ?, NOW(3))'
    );

    $count = 0;
    foreach (array_values($chunks) as $index => $chunkText) {
        $insert->execute([$uploadId, $userId, $index, $chunkText]);
        $count++;
    }

    return $count;
}

/**
 * Extract the text layer of a PDF using poppler's pdftotext.
 *
 * @return array{ok:bool,text?:string,error?:string}
 */
function extractPdfText(string $pdfPath): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'error' => 'PDF-Verarbeitung nicht verfügbar (proc_open deaktiviert).'];
    }

    $cmd = 'pdftotext -enc UTF-8 -q ' . escapeshellarg($pdfPath) . ' -';
    $descriptorspec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'PDF-Verarbeitung fehlgeschlagen (pdftotext nicht verfügbar).'];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $err = trim((string) $stderr);
        if ($err === '') {
            $err = 'PDF konnte nicht gelesen werden.';
        }
        return ['ok' => false, 'error' => $err];
    }

    $text = normalizeDocumentText((string) $stdout);
    if ($text === '') {
        return ['ok' => false, 'error' => 'PDF enthält keinen auslesbaren Text.'];
    }

    return ['ok' => true, 'text' => $text];
}

/**
 * Convert a PDF into page images and let the vision model read every page.
 *
 * Scanned PDFs and PDFs whose layout carries the meaning (tables, forms,
 * diagrams) cannot be handled by pdftotext, so each page is rasterised with
 * pdftoppm and sent to the vision model individually. The text layer of a page
 * – when present – is used as a fallback whenever the vision call fails, so a
 * single failing page never loses the whole document.
 *
 * @return array{ok:bool,text?:string,chunks?:string[],pages?:int,
 *               analyzed?:int,truncated?:bool,error?:string}
 */
function analyzePdfWithVision(string $pdfPath): array
{
    if (!visionModelConfigured()) {
        return ['ok' => false, 'error' => 'Kein Vision-Modell konfiguriert.'];
    }

    $render = renderPdfPagesToImages($pdfPath, pdfVisionMaxPages(), pdfVisionDpi());
    if (!($render['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($render['error'] ?? 'PDF-Rendering fehlgeschlagen.')];
    }

    $dir        = (string) $render['dir'];
    $pages      = (array) $render['pages'];
    $totalPages = (int) $render['total_pages'];
    $truncated  = (bool) ($render['truncated'] ?? false);

    $sections   = [];
    $chunks     = [];
    $analyzed   = 0;
    $lastError  = '';

    try {
        foreach ($pages as $entry) {
            $pageNo = (int) $entry['page'];
            $label  = 'Seite ' . $pageNo . ' von ' . $totalPages;

            $prompt = 'Dies ist ' . $label . ' eines PDF-Dokuments. '
                    . 'Gib den gesamten Inhalt dieser Seite vollständig und strukturiert wieder: '
                    . 'sämtlichen Text wortgetreu, Tabellen als Markdown-Tabelle, '
                    . 'Diagramme und Abbildungen als sachliche Beschreibung inklusive aller ablesbaren Werte. '
                    . 'Erfinde nichts und kommentiere nicht. Verwende Deutsch.';

            $result = analyzeImageWithVision($entry['path'], 'image/jpeg', $prompt);

            if ($result['ok'] ?? false) {
                $pageText = normalizeDocumentText((string) ($result['text'] ?? ''));
                $analyzed++;
            } else {
                $lastError = (string) ($result['error'] ?? 'Vision-Analyse fehlgeschlagen.');
                writeLog('warning', 'Vision-Analyse für ' . $label . ' fehlgeschlagen: ' . $lastError);
                // Fall back to the embedded text layer of this page.
                $pageText = normalizeDocumentText(extractPdfPageText($pdfPath, $pageNo));
                if ($pageText !== '') {
                    $pageText = "(Vision-Analyse nicht möglich – Textebene der Seite)\n" . $pageText;
                }
            }

            if ($pageText === '') {
                continue;
            }

            $sections[] = '[' . $label . ']' . "\n" . $pageText;

            // One or more chunks per page, each carrying its page reference so
            // the source stays visible in RAG answers.
            foreach (buildDocumentChunks($pageText) as $chunk) {
                $chunks[] = '[' . $label . ']' . "\n" . $chunk;
            }
        }
    } finally {
        cleanupPdfRenderDir($dir);
    }

    if (empty($sections)) {
        return [
            'ok'    => false,
            'error' => $lastError !== ''
                ? 'PDF-Analyse fehlgeschlagen: ' . $lastError
                : 'Aus dem PDF konnten keine Inhalte gewonnen werden.',
        ];
    }

    $text = implode("\n\n", $sections);
    if ($truncated) {
        $text .= "\n\n[Hinweis: Es wurden nur die ersten " . count($pages) . ' von ' . $totalPages
               . ' Seiten analysiert (Limit im Adminbereich konfigurierbar).]';
    }

    return [
        'ok'        => true,
        'text'      => $text,
        'chunks'    => $chunks,
        'pages'     => $totalPages,
        'analyzed'  => $analyzed,
        'truncated' => $truncated,
    ];
}

/**
 * Decide how an upload has to be processed.
 *
 * finfo reports many text-based formats as text/plain, so for everything the
 * converter handles the original file name decides; the detected MIME type is
 * used as a plausibility check and as a fallback.
 *
 * @return array{ok:bool,kind?:string,ext?:string,message?:string}
 */
function resolveUploadKind(string $mimeType, string $originalName): array
{
    $imageMimes = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (isset($imageMimes[$mimeType])) {
        return ['ok' => true, 'kind' => 'image', 'ext' => $imageMimes[$mimeType]];
    }
    if ($mimeType === 'application/pdf') {
        return ['ok' => true, 'kind' => 'pdf', 'ext' => 'pdf'];
    }

    $nameExt   = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeMap   = docConvertMimeMap();
    $knownExts = docConvertExtensions();

    // Generic container types that legitimately carry Office or text payloads.
    $genericMimes = [
        'application/zip',
        'application/octet-stream',
        'application/x-ole-storage',
        'application/vnd.ms-office',
        'application/CDFV2',
    ];

    $mimeIsPlausible = isset($mimeMap[$mimeType])
        || str_starts_with($mimeType, 'text/')
        || in_array($mimeType, $genericMimes, true);

    if (in_array($nameExt, $knownExts, true) && $mimeIsPlausible) {
        return ['ok' => true, 'kind' => 'document', 'ext' => $nameExt];
    }

    if (isset($mimeMap[$mimeType])) {
        return ['ok' => true, 'kind' => 'document', 'ext' => $mimeMap[$mimeType]];
    }

    if ($nameExt === 'doc' || $nameExt === 'ppt') {
        return [
            'ok'      => false,
            'message' => 'Alte Office-Formate (.doc/.ppt) werden nicht unterstützt. '
                       . 'Bitte als .docx/.pptx oder PDF speichern.',
        ];
    }

    return [
        'ok'      => false,
        'message' => 'Nicht unterstütztes Dateiformat. Erlaubt sind: PNG, JPG, WEBP, GIF, PDF, '
                   . 'DOCX, XLSX, XLS, PPTX, ODT, ODS, ODP, RTF, CSV, TSV, TXT, MD, JSON, XML, HTML, YAML.',
    ];
}

/**
 * Remove the stored file of an ephemeral upload (chat attachments that the
 * user did not add to the knowledge base). The extracted text and chunks stay
 * available for the running conversation.
 */
function discardStoredFileIfEphemeral(PDO $db, int $uploadId): void
{
    global $retainFile, $storedPath;

    if (!empty($retainFile)) {
        return;
    }

    if (is_string($storedPath) && $storedPath !== '' && is_file($storedPath)) {
        @unlink($storedPath);
    }

    try {
        $db->prepare("UPDATE document_uploads SET stored_name = '' WHERE id = ?")->execute([$uploadId]);
    } catch (Throwable $e) {
        // Best-effort: the row stays usable even when the update fails.
    }
}

/**
 * Mark an upload as failed and answer the request.
 */
function failUpload(PDO $db, int $uploadId, string $message): void
{
    $db->prepare(
        "UPDATE document_uploads
            SET status = 'error',
                error_message = ?,
                processed_at = NOW(3)
          WHERE id = ?"
    )->execute([$message, $uploadId]);

    discardStoredFileIfEphemeral($db, $uploadId);

    writeLog('warning', 'Dokument-Upload ' . $uploadId . ' fehlgeschlagen: ' . $message);

    echo json_encode(['ok' => false, 'id' => $uploadId, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Store extracted text + chunks, kick off embeddings and answer the request.
 *
 * @param string[] $chunks Chunks provided by the converter; when empty, the
 *                         local paragraph chunker is used.
 */
function finishUpload(PDO $db, int $uploadId, int $userId, string $text, array $chunks, string $message): void
{
    $text = normalizeDocumentText($text);
    if (empty($chunks)) {
        $chunks = buildDocumentChunks($text);
    }

    if ($text === '' && empty($chunks)) {
        failUpload($db, $uploadId, 'Die Datei enthält keinen auslesbaren Text.');
    }

    $chunkCount = 0;
    try {
        $chunkCount = persistDocumentChunks($db, $uploadId, $userId, $chunks);
    } catch (Throwable $e) {
        writeLog('warning', 'Chunks konnten nicht gespeichert werden (Upload ' . $uploadId . '): ' . $e->getMessage());
        $chunkCount = 0;
    }

    $db->prepare(
        "UPDATE document_uploads
            SET status = 'done',
                extracted_text = ?,
                chunk_count = ?,
                error_message = NULL,
                processed_at = NOW(3)
          WHERE id = ?"
    )->execute([$text, $chunkCount, $uploadId]);

    discardStoredFileIfEphemeral($db, $uploadId);

    // Embeddings are best-effort: an unavailable embedding server must never
    // make the upload itself fail (BM25 search still works).
    try {
        generateAndStoreChunkEmbeddings($db, $uploadId);
    } catch (Throwable $e) {
        writeLog('warning', 'Embedding-Erzeugung nach Upload fehlgeschlagen: ' . $e->getMessage());
    }

    $row = null;
    try {
        $stmt = $db->prepare(
            'SELECT id, original_name, mime_type, file_size, status, chunk_count, is_global_rag,
                    is_library, chat_session_id, error_message, uploaded_at, processed_at
               FROM document_uploads WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;
    }

    echo json_encode([
        'ok'          => true,
        'id'          => $uploadId,
        'message'     => $message,
        'chunk_count' => $chunkCount,
        'document'    => $row,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Request validation ────────────────────────────────────────────────────────

$userId = (int) $_SESSION['admin_id'];
$db     = getDb();

$stmt = $db->prepare('SELECT can_upload_documents FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !(int) ($user['can_upload_documents'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Keine Berechtigung zum Hochladen von Dokumenten.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger CSRF-Token.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    $errMsg  = match ($errCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei überschreitet die maximale Upload-Größe.',
        UPLOAD_ERR_NO_FILE                        => 'Keine Datei ausgewählt.',
        default                                   => 'Upload-Fehler (Code ' . $errCode . ').',
    };
    echo json_encode(['ok' => false, 'message' => $errMsg]);
    exit;
}

$file         = $_FILES['file'];
$originalName = basename($file['name']);
$tmpPath      = $file['tmp_name'];
$fileSize     = (int) $file['size'];
$globalRag    = isset($_POST['global_rag']) && (string) $_POST['global_rag'] === '1' ? 1 : 0;

// Whether the document is kept in the knowledge base for later searches.
// Chat attachments are ephemeral unless the user explicitly opts in; uploads
// from the dedicated upload dialog (RAG workflow) are always retained.
$isChatUpload = isset($_POST['session_id']) && (string) $_POST['session_id'] !== '';
$retainFile   = $isChatUpload
    ? (isset($_POST['retain']) && (string) $_POST['retain'] === '1')
    : true;

if (!$retainFile) {
    // Ephemeral documents must never leak into other conversations.
    $globalRag = 0;
}

// Optional chat session the document is attached to, so it can be used inside
// that conversation immediately (see queryDocuments() in api/chat.php).
$chatSessionId = null;
if (isset($_POST['session_id']) && is_string($_POST['session_id'])
    && preg_match('/^[a-f0-9]{8,128}$/', $_POST['session_id'])) {
    $chatSessionId = $_POST['session_id'];
}

// Detect the MIME type from the file content (more reliable than client-reported).
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string) $finfo->file($tmpPath);

$kind = resolveUploadKind($mimeType, $originalName);
if (!($kind['ok'] ?? false)) {
    echo json_encode(['ok' => false, 'message' => (string) $kind['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($kind['kind'] === 'document' && !docConvertEnabled()
    && !in_array($kind['ext'], docConvertLocalFallbackExtensions(), true)) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Der Dokumentkonverter ist nicht konfiguriert. Office-Dokumente können derzeit nicht verarbeitet werden.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxBytes = max(1, (int) getSetting('upload_max_mb', '20')) * 1024 * 1024;
if ($fileSize > $maxBytes) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Die Datei ist zu groß (max. ' . (int) ($maxBytes / 1024 / 1024) . ' MB).',
    ]);
    exit;
}

// ── Store the file ────────────────────────────────────────────────────────────

$uploadDir = __DIR__ . '/../doc_uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$storedName = 'doc_' . bin2hex(random_bytes(16)) . '.' . $kind['ext'];
$storedPath = $uploadDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $storedPath)) {
    echo json_encode(['ok' => false, 'message' => 'Datei konnte nicht gespeichert werden.']);
    exit;
}

$db->prepare(
    "INSERT INTO document_uploads
        (user_id, original_name, stored_name, mime_type, file_size, is_global_rag, is_library, chat_session_id, status, uploaded_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'processing', NOW(3))"
)->execute([$userId, $originalName, $storedName, $mimeType, $fileSize, $globalRag, $retainFile ? 1 : 0, $chatSessionId]);

$uploadId = (int) $db->lastInsertId();

// Release the session lock so other requests are not blocked during analysis.
session_write_close();

// ── Office & text documents ───────────────────────────────────────────────────

if ($kind['kind'] === 'document') {
    $result = ['ok' => false, 'message' => 'Der Dokumentkonverter ist nicht konfiguriert.'];
    if (docConvertEnabled()) {
        $result = convertDocumentViaService($storedPath, $originalName, $mimeType);
    }

    if (!($result['ok'] ?? false)) {
        // Plain-text formats can still be handled without the converter service.
        $fallback = convertPlainTextLocally($storedPath, (string) $kind['ext']);
        if ($fallback['ok'] ?? false) {
            writeLog('info', 'Dokument "' . $originalName . '" über den lokalen Text-Fallback verarbeitet.');
            finishUpload($db, $uploadId, $userId, (string) $fallback['text'], [], 'Dokument erfolgreich verarbeitet.');
        }
        failUpload($db, $uploadId, (string) ($result['message'] ?? 'Konvertierung fehlgeschlagen.'));
    }

    $label = trim((string) ($result['label'] ?? ''));
    finishUpload(
        $db,
        $uploadId,
        $userId,
        (string) ($result['text'] ?? ''),
        (array) ($result['chunks'] ?? []),
        ($label !== '' ? $label : 'Dokument') . ' erfolgreich verarbeitet.'
    );
}

// ── PDF: rendered to page images and read by the vision model ─────────────────

if ($kind['kind'] === 'pdf') {
    // Analysing many pages with a vision model takes minutes rather than
    // seconds – the request must not be cut short halfway through.
    @set_time_limit(0);

    $visionOk = pdfVisionEnabled() && visionModelConfigured() && pdfRenderAvailable();

    if ($visionOk) {
        $pdfResult = analyzePdfWithVision($storedPath);

        if ($pdfResult['ok'] ?? false) {
            $message = 'PDF erfolgreich analysiert ('
                     . (int) ($pdfResult['analyzed'] ?? 0) . ' von '
                     . (int) ($pdfResult['pages'] ?? 0) . ' Seiten per Vision-Modell).';

            finishUpload(
                $db,
                $uploadId,
                $userId,
                (string) ($pdfResult['text'] ?? ''),
                (array) ($pdfResult['chunks'] ?? []),
                $message
            );
        }

        writeLog(
            'warning',
            'PDF-Vision-Analyse fehlgeschlagen, Rückfall auf pdftotext: '
            . (string) ($pdfResult['error'] ?? 'unbekannter Fehler')
        );
    }

    // Fallback: plain text layer (also used when no vision model is configured
    // or PDF rendering is unavailable in this installation).
    $textResult = extractPdfText($storedPath);
    if (!($textResult['ok'] ?? false)) {
        $reason = (string) ($textResult['error'] ?? 'PDF-Verarbeitung fehlgeschlagen.');
        if ($visionOk && isset($pdfResult['error'])) {
            $reason = (string) $pdfResult['error'];
        }
        failUpload($db, $uploadId, $reason);
    }

    finishUpload($db, $uploadId, $userId, (string) ($textResult['text'] ?? ''), [], 'PDF erfolgreich verarbeitet.');
}

// ── Images: vision model analysis ─────────────────────────────────────────────

$visionResult = analyzeImageWithVision($storedPath, $mimeType);

if (!($visionResult['ok'] ?? false)) {
    failUpload($db, $uploadId, (string) ($visionResult['error'] ?? 'Vision-Analyse fehlgeschlagen.'));
}

finishUpload($db, $uploadId, $userId, (string) ($visionResult['text'] ?? ''), [], 'Dokument erfolgreich analysiert.');


