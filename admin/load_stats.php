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
            e.ssh_host,
            e.ssh_user,
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
        GROUP BY e.id, e.alias, e.base_url, e.default_model, e.is_active, e.ssh_host, e.ssh_user
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
        $r['ssh_configured']          = ($r['ssh_host'] ?? '') !== '' && ($r['ssh_user'] ?? '') !== '';
        // Don't expose credentials in the response
        unset($r['ssh_host'], $r['ssh_port'], $r['ssh_user'], $r['ssh_password']);
    }
    unset($r);

    // ── Attach cached SSH system stats ────────────────────────────────────────

    $sysStatsById = [];
    try {
        $sysRows = getDb()->query("
            SELECT endpoint_id, ram_total, ram_used, cpu_load_1m, cpu_load_5m, cpu_temp, fetch_ok, fetched_at
            FROM   endpoint_sys_stats
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sysRows as $sr) {
            $sysStatsById[(int) $sr['endpoint_id']] = [
                'ok'          => (bool) $sr['fetch_ok'],
                'ram_total'   => $sr['ram_total']   !== null ? (int)   $sr['ram_total']   : null,
                'ram_used'    => $sr['ram_used']    !== null ? (int)   $sr['ram_used']    : null,
                'cpu_load_1m' => $sr['cpu_load_1m'] !== null ? (float) $sr['cpu_load_1m'] : null,
                'cpu_load_5m' => $sr['cpu_load_5m'] !== null ? (float) $sr['cpu_load_5m'] : null,
                'cpu_temp'    => $sr['cpu_temp']    !== null ? (float) $sr['cpu_temp']    : null,
                'fetched_at'  => $sr['fetched_at'],
            ];
        }
    } catch (PDOException $_e) {
        // Table may not exist on very old installations – safe to ignore.
    }

    foreach ($rows as &$r) {
        $r['sys_stats'] = $sysStatsById[$r['id']] ?? null;
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

    // ── LLM aggregate totals ──────────────────────────────────────────────────

    $totals = [
        'total_running' => 0, 'total_done' => 0, 'total_error' => 0,
        'total_done_24h' => 0,
        'grand_prompt_tokens' => 0, 'grand_completion_tokens' => 0, 'grand_tokens' => 0,
    ];
    try {
        $tRow = getDb()->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS total_running,
                COALESCE(SUM(CASE WHEN status = 'done'    THEN 1 ELSE 0 END), 0) AS total_done,
                COALESCE(SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END), 0) AS total_error,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND started_at >= NOW() - INTERVAL 24 HOUR
                             THEN 1 ELSE 0 END), 0) AS total_done_24h,
                COALESCE(SUM(prompt_tokens), 0)     AS grand_prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) AS grand_completion_tokens,
                COALESCE(SUM(total_tokens), 0)      AS grand_tokens
            FROM tasks
        ")->fetch(PDO::FETCH_ASSOC);
        if ($tRow) {
            $totals = [
                'total_running'           => (int) $tRow['total_running'],
                'total_done'              => (int) $tRow['total_done'],
                'total_error'             => (int) $tRow['total_error'],
                'total_done_24h'          => (int) $tRow['total_done_24h'],
                'grand_prompt_tokens'     => (int) $tRow['grand_prompt_tokens'],
                'grand_completion_tokens' => (int) $tRow['grand_completion_tokens'],
                'grand_tokens'            => (int) $tRow['grand_tokens'],
            ];
        }
    } catch (PDOException $e) { }

    // ── SD aggregate totals ───────────────────────────────────────────────────

    $sdTotals = ['total_running' => 0, 'total_done' => 0, 'total_error' => 0, 'total_done_24h' => 0];
    try {
        $sdT = getDb()->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS total_running,
                COALESCE(SUM(CASE WHEN status = 'done'    THEN 1 ELSE 0 END), 0) AS total_done,
                COALESCE(SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END), 0) AS total_error,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND started_at >= NOW() - INTERVAL 24 HOUR
                             THEN 1 ELSE 0 END), 0) AS total_done_24h
            FROM sd_tasks
        ")->fetch(PDO::FETCH_ASSOC);
        if ($sdT) {
            $sdTotals = [
                'total_running'  => (int) $sdT['total_running'],
                'total_done'     => (int) $sdT['total_done'],
                'total_error'    => (int) $sdT['total_error'],
                'total_done_24h' => (int) $sdT['total_done_24h'],
            ];
        }
    } catch (PDOException $e) { }

    // ── ComfyUI aggregate totals ──────────────────────────────────────────────

    $comfyTotals = ['total_running' => 0, 'total_done' => 0, 'total_error' => 0, 'total_done_24h' => 0];
    try {
        $comfyT = getDb()->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS total_running,
                COALESCE(SUM(CASE WHEN status = 'done'    THEN 1 ELSE 0 END), 0) AS total_done,
                COALESCE(SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END), 0) AS total_error,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND started_at >= NOW() - INTERVAL 24 HOUR
                             THEN 1 ELSE 0 END), 0) AS total_done_24h
            FROM comfy_tasks
        ")->fetch(PDO::FETCH_ASSOC);
        if ($comfyT) {
            $comfyTotals = [
                'total_running'  => (int) $comfyT['total_running'],
                'total_done'     => (int) $comfyT['total_done'],
                'total_error'    => (int) $comfyT['total_error'],
                'total_done_24h' => (int) $comfyT['total_done_24h'],
            ];
        }
    } catch (PDOException $e) { }

    echo json_encode(
        ['ok' => true, 'ts' => time(), 'endpoints' => $rows, 'searxng' => $searxng,
         'sd_endpoints' => $sdRows, 'comfy_endpoints' => $comfyRows, 'clients' => $clientStats,
         'totals' => $totals, 'sd_totals' => $sdTotals, 'comfy_totals' => $comfyTotals],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
}
