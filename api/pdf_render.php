<?php

/**
 * api/pdf_render.php
 *
 * Rasterises PDF pages into image files so they can be analysed by the vision
 * model (see api/vision.php). Uses poppler-utils, which is already installed in
 * the web container for pdftotext:
 *
 *   pdfinfo   – page count
 *   pdftoppm  – page → JPEG rendering
 *   pdftotext – optional text layer per page (used as a fallback)
 */

require_once __DIR__ . '/../db.php';

if (!defined('PDF_RENDER_DEFAULT_DPI')) {
    define('PDF_RENDER_DEFAULT_DPI', 150);
}
if (!defined('PDF_RENDER_DEFAULT_MAX_PAGES')) {
    define('PDF_RENDER_DEFAULT_MAX_PAGES', 30);
}

/**
 * Run an external command and capture its output.
 *
 * @return array{code:int,stdout:string,stderr:string}
 */
function pdfRunCommand(string $cmd): array
{
    if (!function_exists('proc_open')) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open ist deaktiviert.'];
    }

    $process = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'Prozess konnte nicht gestartet werden.'];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Whether PDF pages can be rasterised in this installation. */
function pdfRenderAvailable(): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }

    $res = pdfRunCommand('pdftoppm -v 2>&1');

    // pdftoppm -v prints its banner and exits with 0 on newer poppler builds
    // and with 99 on older ones – both mean the binary exists.
    return $res['code'] === 0 || stripos($res['stdout'] . $res['stderr'], 'pdftoppm') !== false;
}

/** Whether PDFs should be analysed by the vision model (admin setting). */
function pdfVisionEnabled(): bool
{
    return getSetting('pdf_vision_enabled', '1') === '1';
}

/** Rendering resolution in DPI (admin setting, clamped to a sane range). */
function pdfVisionDpi(): int
{
    return max(72, min(300, (int) getSetting('pdf_vision_dpi', (string) PDF_RENDER_DEFAULT_DPI)));
}

/** Maximum number of pages sent to the vision model (admin setting). */
function pdfVisionMaxPages(): int
{
    return max(1, min(200, (int) getSetting('pdf_vision_max_pages', (string) PDF_RENDER_DEFAULT_MAX_PAGES)));
}

/**
 * Number of pages in a PDF; 0 when it cannot be determined.
 */
function pdfPageCount(string $pdfPath): int
{
    $res = pdfRunCommand('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
    if ($res['code'] !== 0) {
        return 0;
    }

    if (preg_match('/^Pages:\s+(\d+)/mi', $res['stdout'], $m) === 1) {
        return (int) $m[1];
    }

    return 0;
}

/**
 * Extract the text layer of a single PDF page (empty string when there is none).
 */
function extractPdfPageText(string $pdfPath, int $page): string
{
    $cmd = 'pdftotext -enc UTF-8 -q -f ' . $page . ' -l ' . $page . ' '
         . escapeshellarg($pdfPath) . ' - 2>/dev/null';
    $res = pdfRunCommand($cmd);
    if ($res['code'] !== 0) {
        return '';
    }

    return trim((string) $res['stdout']);
}

/**
 * Render PDF pages to JPEG files inside a fresh temporary directory.
 *
 * The caller is responsible for removing the directory again – use
 * cleanupPdfRenderDir() for that.
 *
 * @param int $maxPages Hard cap on the number of rendered pages.
 * @param int $dpi      Rendering resolution.
 *
 * @return array{ok:bool,dir?:string,pages?:array<int,array{page:int,path:string}>,
 *               total_pages?:int,truncated?:bool,error?:string}
 */
function renderPdfPagesToImages(string $pdfPath, int $maxPages, int $dpi): array
{
    if (!pdfRenderAvailable()) {
        return ['ok' => false, 'error' => 'PDF-Rendering nicht verfügbar (pdftoppm fehlt).'];
    }

    $totalPages = pdfPageCount($pdfPath);
    $lastPage   = $totalPages > 0 ? min($totalPages, $maxPages) : $maxPages;

    $dir = sys_get_temp_dir() . '/pdfvision_' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Temporäres Verzeichnis konnte nicht angelegt werden.'];
    }

    // -jpeg keeps the payload small enough for base64 transport to the model,
    // -r sets the resolution and -f/-l limit the page range.
    $cmd = 'pdftoppm -jpeg -jpegopt quality=82 -r ' . $dpi
         . ' -f 1 -l ' . $lastPage . ' '
         . escapeshellarg($pdfPath) . ' ' . escapeshellarg($dir . '/page')
         . ' 2>&1';

    $res = pdfRunCommand($cmd);

    $files = glob($dir . '/page*.jpg') ?: [];
    sort($files, SORT_NATURAL);

    if (empty($files)) {
        cleanupPdfRenderDir($dir);
        $err = trim($res['stderr'] . ' ' . $res['stdout']);

        return [
            'ok'    => false,
            'error' => 'PDF konnte nicht in Bilder umgewandelt werden.' . ($err !== '' ? ' (' . $err . ')' : ''),
        ];
    }

    $pages = [];
    foreach ($files as $index => $path) {
        // pdftoppm numbers its output files sequentially starting at page 1.
        $page = $index + 1;
        if (preg_match('/page-?(\d+)\.jpg$/i', basename($path), $m) === 1) {
            $page = (int) $m[1];
        }
        $pages[] = ['page' => $page, 'path' => $path];
    }

    return [
        'ok'          => true,
        'dir'         => $dir,
        'pages'       => $pages,
        'total_pages' => $totalPages > 0 ? $totalPages : count($pages),
        'truncated'   => $totalPages > 0 && $totalPages > count($pages),
    ];
}

/**
 * Delete a temporary render directory including all rendered page images.
 */
function cleanupPdfRenderDir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    // Only ever touch our own temp directories.
    if (strpos(basename($dir), 'pdfvision_') !== 0) {
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}
