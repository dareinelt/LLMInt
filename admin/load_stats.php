<?php

/**
 * admin/load_stats.php
 *
 * Session-protected JSON endpoint that returns per-endpoint load statistics
 * for the live load-distribution tree visualization.
 *
 * Response shape:
 *   { ok: true, ts: <unix seconds>, endpoints: [ { id, base_url,
 *     default_model, is_active, running, today_jobs, today_tokens }, … ],
 *     search: { configured: bool, running: int, today_jobs: int } }
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = getDb();

    $rows = $db->query("
        SELECT
            e.id,
            e.base_url,
            e.default_model,
            e.is_active,
            COALESCE(SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END), 0)
                AS running,
            COALESCE(SUM(CASE WHEN t.status = 'done'
                              AND DATE(t.started_at) = CURDATE()
                         THEN 1 ELSE 0 END), 0)
                AS today_jobs,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.total_tokens, 0) ELSE 0 END), 0)
                AS today_tokens
        FROM endpoints e
        LEFT JOIN tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.base_url, e.default_model, e.is_active
        ORDER BY e.sort_order ASC, e.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['is_active']    = (int) $r['is_active'];
        $r['running']      = (int) $r['running'];
        $r['today_jobs']   = (int) $r['today_jobs'];
        $r['today_tokens'] = (int) $r['today_tokens'];
    }
    unset($r);

    $searxngConfigured = trim(getSetting('searxng_base_url', '')) !== '';
    $searchData = ['configured' => false, 'running' => 0, 'today_jobs' => 0];
    if ($searxngConfigured) {
        $sRow = $db->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0)
                    AS running,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND DATE(started_at) = CURDATE()
                             THEN 1 ELSE 0 END), 0)
                    AS today_jobs
            FROM search_jobs
        ")->fetch(PDO::FETCH_ASSOC);
        $searchData = [
            'configured' => true,
            'running'    => (int) $sRow['running'],
            'today_jobs' => (int) $sRow['today_jobs'],
        ];
    }

    echo json_encode(
        ['ok' => true, 'ts' => time(), 'endpoints' => $rows, 'search' => $searchData],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
}
