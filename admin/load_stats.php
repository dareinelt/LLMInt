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
 *     searxng: { enabled, running, today_jobs } }
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
    $searxng = ['enabled' => $searxngEnabled, 'running' => 0, 'today_jobs' => 0];

    if ($searxngEnabled) {
        try {
            $sRow = getDb()->query("
                SELECT
                    COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running,
                    COALESCE(SUM(CASE WHEN status = 'done'
                                      AND DATE(started_at) = CURDATE()
                                 THEN 1 ELSE 0 END), 0) AS today_jobs
                FROM search_logs
            ")->fetch(PDO::FETCH_ASSOC);
            $searxng['running']    = (int) $sRow['running'];
            $searxng['today_jobs'] = (int) $sRow['today_jobs'];
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

    echo json_encode(
        ['ok' => true, 'ts' => time(), 'endpoints' => $rows, 'searxng' => $searxng, 'sd_endpoints' => $sdRows, 'comfy_endpoints' => $comfyRows],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
}
