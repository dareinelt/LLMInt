<?php

/**
 * api/upload_document.php
 *
 * Handles document uploads and triggers vision-model analysis.
 *
 * POST multipart/form-data:
 *   file          – The uploaded file (image: PNG, JPG, JPEG, WEBP, GIF)
 *   csrf_token    – CSRF token from the session
 *
 * Requires an active user session with can_upload_documents = 1 (or is admin).
 * Returns JSON { ok, message, id? }.
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

    return array_values(array_filter(array_map(static fn($chunk) => trim($chunk), $chunks), static fn($chunk) => $chunk !== ''));
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
    foreach ($chunks as $index => $chunkText) {
        $insert->execute([$uploadId, $userId, $index, $chunkText]);
        $count++;
    }

    return $count;
}

$userId = (int) $_SESSION['admin_id'];
$db     = getDb();

// Check permission.
$stmt = $db->prepare('SELECT can_upload_documents FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !(int) ($user['can_upload_documents'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Keine Berechtigung zum Hochladen von Dokumenten.']);
    exit;
}

// CSRF check.
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || $csrf !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger CSRF-Token.']);
    exit;
}

// File upload check.
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

// Allowed MIME types.
$allowedMimes = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

// Detect MIME type from file content (more reliable than client-reported).
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);

if (!isset($allowedMimes[$mimeType])) {
    echo json_encode(['ok' => false, 'message' => 'Nicht unterstütztes Dateiformat. Erlaubt sind: PNG, JPG, WEBP, GIF.']);
    exit;
}

// Size limit: 20 MB.
if ($fileSize > 20 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'message' => 'Die Datei ist zu groß (max. 20 MB).']);
    exit;
}

// Store file.
$uploadDir  = __DIR__ . '/../doc_uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext        = $allowedMimes[$mimeType];
$storedName = 'doc_' . bin2hex(random_bytes(16)) . '.' . $ext;
$storedPath = $uploadDir . '/' . $storedName;

if (!move_uploaded_file($tmpPath, $storedPath)) {
    echo json_encode(['ok' => false, 'message' => 'Datei konnte nicht gespeichert werden.']);
    exit;
}

// Create DB record with status 'processing'.
$db->prepare(
    "INSERT INTO document_uploads (user_id, original_name, stored_name, mime_type, file_size, status, uploaded_at)
     VALUES (?, ?, ?, ?, ?, 'processing', NOW(3))"
)->execute([$userId, $originalName, $storedName, $mimeType, $fileSize]);

$uploadId = (int) $db->lastInsertId();

// Release session lock so other requests are not blocked during analysis.
session_write_close();

// ── Vision model analysis ─────────────────────────────────────────────────────

$visionModel = trim(getSetting('vision_model', ''));

if ($visionModel === '') {
    // No vision model configured – store file but skip analysis.
    $db->prepare(
        "UPDATE document_uploads
            SET status = 'error',
                error_message = 'Kein Vision-Modell konfiguriert. Bitte im Adminbereich unter Anfragenhandling ein Vision-Modell auswählen.',
                processed_at = NOW(3)
          WHERE id = ?"
    )->execute([$uploadId]);

    echo json_encode([
        'ok'      => false,
        'id'      => $uploadId,
        'message' => 'Kein Vision-Modell konfiguriert. Bitte im Adminbereich unter Anfragenhandling ein Vision-Modell auswählen.',
    ]);
    exit;
}

// Pick an endpoint that serves the vision model.
require_once __DIR__ . '/balancer.php';

try {
    $slot = pickEndpointForModel($visionModel);
} catch (Throwable $e) {
    $slot = null;
}

if ($slot === null) {
    $db->prepare(
        "UPDATE document_uploads
            SET status = 'error',
                error_message = 'Kein aktiver Endpunkt für das Vision-Modell verfügbar.',
                processed_at = NOW(3)
          WHERE id = ?"
    )->execute([$uploadId]);

    echo json_encode([
        'ok'      => false,
        'id'      => $uploadId,
        'message' => 'Kein aktiver Endpunkt für das Vision-Modell verfügbar.',
    ]);
    exit;
}

$endpoint  = $slot['endpoint'];
$taskId    = $slot['task_id'];
$baseUrl   = rtrim($endpoint['base_url'], '/');
$epTimeout = max(60, (int) $endpoint['timeout']);

// Encode image as base64.
$imageData   = file_get_contents($storedPath);
$imageBase64 = base64_encode($imageData);
$dataUrl     = 'data:' . $mimeType . ';base64,' . $imageBase64;

$visionPayload = [
    'model'      => $visionModel,
    'stream'     => false,
    'messages'   => [
        [
            'role'    => 'user',
            'content' => [
                [
                    'type'      => 'image_url',
                    'image_url' => ['url' => $dataUrl],
                ],
                [
                    'type' => 'text',
                    'text' => 'Extrahiere alle in diesem Dokument enthaltenen Informationen vollständig und strukturiert. '
                            . 'Gib den vollständigen Textinhalt wieder und liste alle relevanten Daten, Fakten und Details auf. '
                            . 'Verwende Deutsch.',
                ],
            ],
        ],
    ],
    'temperature' => 0.1,
    'max_tokens'  => -1,
];

$url = $baseUrl . '/chat/completions';
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($visionPayload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $epTimeout,
]);

$body     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    try { completeTask($taskId, 'error'); } catch (Throwable $_e) {}
    $db->prepare(
        "UPDATE document_uploads
            SET status = 'error',
                error_message = ?,
                processed_at = NOW(3)
          WHERE id = ?"
    )->execute(['Verbindungsfehler: ' . $curlErr, $uploadId]);

    echo json_encode([
        'ok'      => false,
        'id'      => $uploadId,
        'message' => 'Vision-Modell nicht erreichbar: ' . $curlErr,
    ]);
    exit;
}

$data = json_decode((string) $body, true);

if ($httpCode !== 200 || !is_array($data)) {
    $errMsg = isset($data['error']['message'])
        ? (string) $data['error']['message']
        : 'Vision-Modell Fehler (HTTP ' . $httpCode . ')';
    try { completeTask($taskId, 'error'); } catch (Throwable $_e) {}
    $db->prepare(
        "UPDATE document_uploads
            SET status = 'error',
                error_message = ?,
                processed_at = NOW(3)
          WHERE id = ?"
    )->execute([$errMsg, $uploadId]);

    echo json_encode([
        'ok'      => false,
        'id'      => $uploadId,
        'message' => $errMsg,
    ]);
    exit;
}

// Extract text from response.
$content = '';
$msgContent = $data['choices'][0]['message']['content'] ?? '';
if (is_string($msgContent)) {
    $content = $msgContent;
} elseif (is_array($msgContent)) {
    foreach ($msgContent as $part) {
        if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
            $content .= $part['text'];
        }
    }
}

// Update task usage.
$promptTokens     = (int) ($data['usage']['prompt_tokens']     ?? 0);
$completionTokens = (int) ($data['usage']['completion_tokens'] ?? 0);
$totalTokens      = (int) ($data['usage']['total_tokens']      ?? 0);
try {
    completeTask($taskId, 'done', $promptTokens, $completionTokens, $totalTokens);
} catch (Throwable $_e) {}

// Save result.
$content = normalizeDocumentText($content);
$chunks = buildDocumentChunks($content);
$chunkCount = 0;

try {
    $chunkCount = persistDocumentChunks($db, $uploadId, $userId, $chunks);
} catch (Throwable $_e) {
    $chunkCount = 0;
}

$db->prepare(
    "UPDATE document_uploads
        SET status = 'done',
            extracted_text = ?,
            chunk_count = ?,
            processed_at = NOW(3)
      WHERE id = ?"
)->execute([$content, $chunkCount, $uploadId]);

echo json_encode([
    'ok'      => true,
    'id'      => $uploadId,
    'message' => 'Dokument erfolgreich analysiert.',
]);
