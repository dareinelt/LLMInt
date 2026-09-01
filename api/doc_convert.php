<?php

/**
 * api/doc_convert.php
 *
 * Client for the "docconvert" microservice (see ./docconvert), which turns
 * Office documents and text files into plain text plus structure-aware chunks.
 *
 * Configuration is read from the environment first (docker-compose) and falls
 * back to the settings table so an administrator can override it at runtime:
 *   DOCCONVERT_URL      / setting docconvert_url      – base URL of the service
 *   DOCCONVERT_TOKEN    / setting docconvert_token    – optional shared secret
 *   DOCCONVERT_TIMEOUT  / setting docconvert_timeout  – request timeout (s)
 *                         setting docconvert_enabled  – '0' disables the feature
 *
 * When the service is unreachable, plain-text formats are still handled by the
 * local fallback (convertPlainTextLocally()), so uploads of .txt/.md/.csv keep
 * working without the extra container.
 */

require_once __DIR__ . '/../db.php';

/**
 * MIME type → file extension map for everything the converter handles.
 *
 * @return array<string,string>
 */
function docConvertMimeMap(): array
{
    return [
        // Word
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        // Excel
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'application/vnd.ms-excel.sheet.macroEnabled.12'                          => 'xlsm',
        'application/vnd.ms-excel'                                                => 'xls',
        // PowerPoint
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        // OpenDocument
        'application/vnd.oasis.opendocument.text'         => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'  => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        // Rich text
        'application/rtf' => 'rtf',
        'text/rtf'        => 'rtf',
        // Text & data
        'text/plain'                    => 'txt',
        'text/markdown'                 => 'md',
        'text/csv'                      => 'csv',
        'text/tab-separated-values'     => 'tsv',
        'application/csv'               => 'csv',
        'application/json'              => 'json',
        'text/json'                     => 'json',
        'application/xml'               => 'xml',
        'text/xml'                      => 'xml',
        'text/html'                     => 'html',
        'application/yaml'              => 'yaml',
        'text/yaml'                     => 'yaml',
        'application/x-yaml'            => 'yaml',
        // Legacy Office formats produce application/x-ole-storage on some
        // systems; .doc is not supported, .xls is (handled via extension).
        'application/vnd.ms-office'     => 'xls',
    ];
}

/**
 * Extensions that can be parsed locally in PHP when the service is down.
 *
 * @return string[]
 */
function docConvertLocalFallbackExtensions(): array
{
    return ['txt', 'md', 'csv', 'tsv', 'json', 'xml', 'html', 'yaml'];
}

/**
 * File extensions accepted by the converter, for UI hints and validation.
 *
 * @return string[]
 */
function docConvertExtensions(): array
{
    return array_values(array_unique(array_values(docConvertMimeMap())));
}

function docConvertBaseUrl(): string
{
    $url = trim((string) (getenv('DOCCONVERT_URL') ?: ''));
    if ($url === '') {
        $url = trim(getSetting('docconvert_url', ''));
    }
    return rtrim($url, '/');
}

function docConvertToken(): string
{
    $token = trim((string) (getenv('DOCCONVERT_TOKEN') ?: ''));
    if ($token === '') {
        $token = trim(getSetting('docconvert_token', ''));
    }
    return $token;
}

function docConvertTimeout(): int
{
    $timeout = (int) (getenv('DOCCONVERT_TIMEOUT') ?: 0);
    if ($timeout <= 0) {
        $timeout = (int) getSetting('docconvert_timeout', '120');
    }
    return max(10, min(600, $timeout ?: 120));
}

/**
 * True when Office/text uploads are offered at all.
 *
 * Deliberately configuration-only (no network call) so it can be used to render
 * the upload UI without slowing down page loads. Plain-text formats work even
 * without the container thanks to the local fallback.
 */
function docConvertEnabled(): bool
{
    if (getSetting('docconvert_enabled', '1') !== '1') {
        return false;
    }
    return docConvertBaseUrl() !== '';
}

/**
 * Live health check against the converter service.
 *
 * @return array{ok:bool,message:string,extensions:string[]}
 */
function docConvertHealth(): array
{
    $baseUrl = docConvertBaseUrl();
    if ($baseUrl === '') {
        return ['ok' => false, 'message' => 'Keine Konverter-URL konfiguriert.', 'extensions' => []];
    }

    $ch = curl_init($baseUrl . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body    = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '' || $code !== 200) {
        return [
            'ok'         => false,
            'message'    => $curlErr !== '' ? $curlErr : 'HTTP ' . $code,
            'extensions' => [],
        ];
    }

    $data = json_decode((string) $body, true);
    return [
        'ok'         => is_array($data) && ($data['ok'] ?? false) === true,
        'message'    => 'Konverter erreichbar.',
        'extensions' => is_array($data['extensions'] ?? null) ? $data['extensions'] : [],
    ];
}

/**
 * Send a file to the converter service.
 *
 * @param string $path      Absolute path of the stored upload.
 * @param string $filename  Original file name (drives format detection).
 * @param string $mimeType  Detected MIME type.
 *
 * @return array{ok:bool,message?:string,text?:string,chunks?:array,meta?:array,format?:string,cached?:bool}
 */
function convertDocumentViaService(string $path, string $filename, string $mimeType): array
{
    $baseUrl = docConvertBaseUrl();
    if ($baseUrl === '') {
        return ['ok' => false, 'message' => 'Dokumentkonverter ist nicht konfiguriert.'];
    }
    if (!is_readable($path)) {
        return ['ok' => false, 'message' => 'Datei konnte nicht gelesen werden.'];
    }

    $postFields = [
        'file'      => new CURLFile($path, $mimeType !== '' ? $mimeType : 'application/octet-stream', $filename),
        'mime_type' => $mimeType,
        'max_chars' => (string) max(200, (int) getSetting('rag_chunk_chars', '1800')),
        'overlap'   => (string) max(0, (int) getSetting('rag_chunk_overlap', '250')),
    ];

    $headers = ['Accept: application/json', 'Expect:'];
    $token   = docConvertToken();
    if ($token !== '') {
        $headers[] = 'X-Auth-Token: ' . $token;
    }

    $startMs = (int) round(microtime(true) * 1000);
    $ch = curl_init($baseUrl . '/convert');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => docConvertTimeout(),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body    = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;

    if ($curlErr !== '') {
        writeLog('warning', 'Dokumentkonverter nicht erreichbar: ' . $curlErr);
        return ['ok' => false, 'message' => 'Dokumentkonverter nicht erreichbar: ' . $curlErr];
    }

    $data = json_decode((string) $body, true);

    if ($code !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true) {
        $message = is_array($data) && isset($data['message'])
            ? (string) $data['message']
            : 'Konvertierung fehlgeschlagen (HTTP ' . $code . ').';
        writeLog('warning', 'Dokumentkonvertierung fehlgeschlagen: ' . $message);
        return ['ok' => false, 'message' => $message];
    }

    $chunks = [];
    foreach ((array) ($data['chunks'] ?? []) as $chunk) {
        $text = trim((string) ($chunk['text'] ?? ''));
        if ($text !== '') {
            $chunks[] = $text;
        }
    }

    writeLog('info', sprintf(
        'Dokument "%s" konvertiert (%s, %d Chunks, %d ms%s).',
        $filename,
        (string) ($data['format'] ?? '?'),
        count($chunks),
        $durationMs,
        ($data['cached'] ?? false) ? ', aus Cache' : ''
    ));

    return [
        'ok'     => true,
        'text'   => (string) ($data['text'] ?? ''),
        'chunks' => $chunks,
        'format' => (string) ($data['format'] ?? ''),
        'label'  => (string) ($data['label'] ?? ''),
        'meta'   => is_array($data['meta'] ?? null) ? $data['meta'] : [],
        'cached' => (bool) ($data['cached'] ?? false),
    ];
}

/**
 * Local fallback for plain-text formats when the converter is unavailable.
 *
 * @return array{ok:bool,message?:string,text?:string}
 */
function convertPlainTextLocally(string $path, string $extension): array
{
    if (!in_array($extension, docConvertLocalFallbackExtensions(), true)) {
        return ['ok' => false, 'message' => 'Für dieses Format wird der Dokumentkonverter benötigt.'];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'message' => 'Datei konnte nicht gelesen werden.'];
    }

    // Strip a UTF-8 BOM and transcode anything that is not valid UTF-8.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1');
        if (is_string($converted)) {
            $raw = $converted;
        }
    }

    if ($extension === 'html') {
        $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
        $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['ok' => false, 'message' => 'Die Datei enthält keinen auslesbaren Text.'];
    }

    return ['ok' => true, 'text' => $raw];
}
