<?php

/**
 * api/image_notify_worker.php
 *
 * Delivers the "your image is ready" mails for image jobs the user asked to be
 * notified about. This is the primary delivery path: a mail that only arrives
 * while the browser tab is open would defeat the purpose of the feature.
 *
 * Run it from cron, e.g. every minute:
 *
 *     * * * * * php /var/www/html/api/image_notify_worker.php --limit=20 >/dev/null
 *
 * ImageInt keeps a finished job for IMAGEINT_JOB_RETENTION_SECONDS (default
 * 24 h) only. The worker therefore has to deliver inside that window; once the
 * job is gone, ImageInt answers 404 `not_found` and the row is marked
 * `expired` instead of mailing a dead link.
 *
 * CLI options:
 *   --limit=N   how many pending rows to look at in this run (default 20)
 *   --quiet     suppress per-row output
 *
 * It can also be called over HTTP by an administrator (session required), which
 * makes manual delivery possible without shell access.
 *
 * Exit codes: 0 = run finished, 1 = another run holds the lock.
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Nicht authentifiziert.']);
        exit;
    }
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/image_generation.php';

$limit = 20;
$quiet = false;

if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $limit = max(1, min(500, (int) $m[1]));
        } elseif ($arg === '--quiet' || $arg === '-q') {
            $quiet = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Verwendung: php api/image_notify_worker.php [--limit=N] [--quiet]\n");
            exit(0);
        }
    }
} else {
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 20)));
}

// One run at a time: two overlapping crons would both see the same pending row
// and send the mail twice.
$lockPath = rtrim(sys_get_temp_dir(), '/') . '/llmint_image_notify.lock';
$lock     = @fopen($lockPath, 'c');
$locked   = $lock !== false && @flock($lock, LOCK_EX | LOCK_NB);

if (!$locked) {
    if ($isCli) {
        if (!$quiet) {
            fwrite(STDERR, "Ein anderer Lauf des Benachrichtigungs-Workers ist noch aktiv.\n");
        }
        exit(1);
    }
    echo json_encode([
        'ok'      => true,
        'skipped' => true,
        'message' => 'Ein anderer Lauf des Benachrichtigungs-Workers ist noch aktiv.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$log = static function (string $message) use ($quiet, $isCli): void {
    if ($quiet) {
        return;
    }
    if ($isCli) {
        fwrite(STDOUT, $message . "\n");
    }
};

try {
    $stats = imageIntProcessNotifications($limit, static function (string $message) use ($log): void {
        writeLog('info', '[image_notify_worker] ' . $message);
        $log($message);
    });
} catch (Throwable $e) {
    writeLog('error', '[image_notify_worker] Lauf abgebrochen: ' . $e->getMessage());
    if ($lock !== false) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    if ($isCli) {
        fwrite(STDERR, 'Lauf abgebrochen: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Der Benachrichtigungs-Worker wurde abgebrochen.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($lock !== false) {
    @flock($lock, LOCK_UN);
    @fclose($lock);
}

if (!$isCli) {
    echo json_encode([
        'ok'    => true,
        'stats' => $stats,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$quiet) {
    fwrite(STDOUT, sprintf(
        "Geprüft: %d, versendet: %d, wartend: %d, fehlgeschlagen: %d, abgelaufen: %d\n",
        $stats['checked'],
        $stats['sent'],
        $stats['waiting'],
        $stats['failed'],
        $stats['expired']
    ));
}

exit(0);
