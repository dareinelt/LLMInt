<?php

/**
 * admin/load_stats.php
 *
 * Session-protected JSON endpoint that returns per-endpoint load statistics
 * for the live load-distribution tree visualization.
 *
 * Response shape:
 *   { ok: true, ts: <unix seconds>, endpoints: [ { id, alias, base_url,
 *     default_model, is_active, running, today_jobs, today_tokens }, … ],
 *     searxng: { enabled, running, today_jobs, avg_duration_seconds } }
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
// Release the session write lock – this endpoint only reads the session
// for auth and never writes to it. Releasing early prevents this polling
// request from blocking other same-session requests (e.g. a long-running
// chat.php call) that also hold the session lock.
session_write_close();

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $rows = getDb()->query("
        SELECT
            e.id,
            e.alias,
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
                         THEN COALESCE(t.prompt_tokens, 0) ELSE 0 END), 0)
                AS today_prompt_tokens,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.completion_tokens, 0) ELSE 0 END), 0)
                AS today_completion_tokens,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.total_tokens, 0) ELSE 0 END), 0)
                AS today_tokens
        FROM endpoints e
        LEFT JOIN tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.alias, e.base_url, e.default_model, e.is_active
        ORDER BY e.sort_order ASC, e.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['is_active']    = (int) $r['is_active'];
        $r['running']                 = (int) $r['running'];
        $r['today_jobs']              = (int) $r['today_jobs'];
        $r['today_prompt_tokens']     = (int) $r['today_prompt_tokens'];
        $r['today_completion_tokens'] = (int) $r['today_completion_tokens'];
        $r['today_tokens']            = (int) $r['today_tokens'];
    }
    unset($r);

    $searxngEnabled = trim(getSetting('searxng_base_url', '')) !== '';
    $searxng = ['enabled' => $searxngEnabled, 'running' => 0, 'today_jobs' => 0, 'avg_duration_seconds' => null];

    if ($searxngEnabled) {
        try {
            $sRow = getDb()->query("
                SELECT
                    COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running,
                    COALESCE(SUM(CASE WHEN status = 'done'
                                      AND DATE(started_at) = CURDATE()
                                 THEN 1 ELSE 0 END), 0) AS today_jobs,
                    AVG(CASE WHEN status = 'done'
                                  AND DATE(started_at) = CURDATE()
                                  AND finished_at IS NOT NULL
                             THEN TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) / 1000000.0
                             ELSE NULL END) AS avg_duration_seconds
                FROM search_logs
            ")->fetch(PDO::FETCH_ASSOC);
            $searxng['running']               = (int) $sRow['running'];
            $searxng['today_jobs']            = (int) $sRow['today_jobs'];
            $searxng['avg_duration_seconds']  = $sRow['avg_duration_seconds'] !== null
                                                    ? round((float) $sRow['avg_duration_seconds'], 1)
                                                    : null;
        } catch (PDOException $e) {
            // search_logs table may not exist on older installations
        }
    }

    // ── SD endpoint stats ─────────────────────────────────────────────────────

    $sdRows = [];
    try {
        $sdRows = getDb()->query("
            SELECT
                e.id,
                e.base_url,
                e.is_active,
                COALESCE(SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END), 0)
                    AS running,
                COALESCE(SUM(CASE WHEN t.status = 'done'
                                  AND DATE(t.started_at) = CURDATE()
                             THEN 1 ELSE 0 END), 0)
                    AS today_jobs
            FROM sd_endpoints e
            LEFT JOIN sd_tasks t ON t.endpoint_id = e.id
            GROUP BY e.id, e.base_url, e.is_active
            ORDER BY e.sort_order ASC, e.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sdRows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_active']  = (int) $r['is_active'];
            $r['running']    = (int) $r['running'];
            $r['today_jobs'] = (int) $r['today_jobs'];
        }
        unset($r);
    } catch (PDOException $e) {
        // sd_endpoints / sd_tasks tables may not exist yet
    }

    // ── ComfyUI endpoint stats ────────────────────────────────────────────────

    $comfyRows = [];
    try {
        $comfyRows = getDb()->query("
            SELECT
                e.id,
                e.base_url,
                e.is_active,
                COALESCE(SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END), 0)
                    AS running,
                COALESCE(SUM(CASE WHEN t.status = 'done'
                                  AND DATE(t.started_at) = CURDATE()
                             THEN 1 ELSE 0 END), 0)
                    AS today_jobs
            FROM comfy_endpoints e
            LEFT JOIN comfy_tasks t ON t.endpoint_id = e.id
            GROUP BY e.id, e.base_url, e.is_active
            ORDER BY e.sort_order ASC, e.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comfyRows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_active']  = (int) $r['is_active'];
            $r['running']    = (int) $r['running'];
            $r['today_jobs'] = (int) $r['today_jobs'];
        }
        unset($r);
    } catch (PDOException $e) {
        // comfy_endpoints / comfy_tasks tables may not exist yet
    }

    // ── Client stats ─────────────────────────────────────────────────────────

    $clientStats = ['current' => 0, 'today_min' => 0, 'today_max' => 0, 'today_avg' => 0.0];
    try {
        $current = (int) getDb()->query(
            "SELECT COUNT(*) FROM active_clients
              WHERE last_seen > DATE_SUB(NOW(), INTERVAL 90 SECOND)"
        )->fetchColumn();

        $sRow = getDb()->query(
            "SELECT MIN(cnt) AS min_cnt, MAX(cnt) AS max_cnt, AVG(cnt) AS avg_cnt
               FROM client_count_log
              WHERE DATE(recorded_at) = CURDATE()"
        )->fetch(PDO::FETCH_ASSOC);

        $clientStats = [
            'current'   => $current,
            'today_min' => ($sRow && $sRow['min_cnt'] !== null) ? (int) $sRow['min_cnt'] : $current,
            'today_max' => ($sRow && $sRow['max_cnt'] !== null) ? (int) $sRow['max_cnt'] : $current,
            'today_avg' => ($sRow && $sRow['avg_cnt'] !== null) ? round((float) $sRow['avg_cnt'], 1) : (float) $current,
        ];
    } catch (PDOException $ignored) {
        // Tables may not exist on older installations
    }

    echo json_encode(
        ['ok' => true, 'ts' => time(), 'endpoints' => $rows, 'searxng' => $searxng,
         'sd_endpoints' => $sdRows, 'comfy_endpoints' => $comfyRows, 'clients' => $clientStats],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
}
