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
 *     searxng: { enabled, running, today_jobs, avg_duration_seconds },
 *     vector_store: { enabled, mode, label, base_url, online, documents, vectors, today_queries, … },
 *     speech_endpoints: [ { id, alias, base_url, timeout, is_active }, … ],
 *     speech_status: { configured, url, ready, loading, status, message, retry_after, probed },
 *     image_endpoints: [ { id, alias, base_url, timeout, is_active }, … ],
 *     image_status: { configured, url, ready, loading, status, message, retry_after, probed } }
 *
 * With `?topology=1` the response additionally contains a `topology` object:
 * the same data translated into the message-flow graph consumed by
 * assets/js/flow-topology.js (see lib/topology.php). Building it server side
 * keeps the polling front end down to a single request.
 */

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/balancer_engine.php';
require_once __DIR__ . '/../lib/speech_dictation.php';
require_once __DIR__ . '/../lib/image_generation.php';
require_once __DIR__ . '/../lib/topology.php';
require_once __DIR__ . '/../api/vector_store.php';
requireAdminOrJson403();

// Release the session write lock – this endpoint only reads the session
// for auth and never writes to it. Releasing early prevents this polling
// request from blocking other same-session requests (e.g. a long-running
// chat.php call) that also hold the session lock.
session_write_close();

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
                AS today_tokens,
            COALESCE(ROUND(AVG(CASE WHEN DATE(t.started_at) = CURDATE()
                                    AND t.tokens_per_second IS NOT NULL
                                    THEN t.tokens_per_second END), 1), 0)
                AS today_avg_tokens_per_second
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
        $r['today_avg_tokens_per_second'] = (float) $r['today_avg_tokens_per_second'];
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
    $searxng = [
        'enabled'  => $searxngEnabled,
        'base_url' => trim(getSetting('searxng_base_url', '')),
        'running'  => 0,
        'today_jobs' => 0,
        'avg_duration_seconds' => null,
    ];

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

    // ── ImageInt endpoints (image generation service) ─────────────────────────

    $imageRows = [];
    try {
        foreach (imageIntEndpoints() as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Deliberately field-by-field: the endpoint token must never end up
            // in this payload.
            $imageRows[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'alias'     => (string) ($row['alias'] ?? ''),
                'base_url'  => (string) ($row['base_url'] ?? ''),
                'timeout'   => (int) ($row['timeout'] ?? 0),
                'is_active' => (int) ($row['is_active'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        // image_endpoints table may not exist yet
    }

    // ── Client stats ─────────────────────────────────────────────────────────

    $clientStats = ['current' => 0, 'today_min' => 0, 'today_max' => 0, 'today_avg' => 0.0, 'list' => []];
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
            'list'      => listActiveClients(getDb()),
        ];
    } catch (PDOException $ignored) {
        // Tables may not exist on older installations
    }

    // ── API usage (calls through the OpenAI-compatible reverse proxy) ─────────

    $apiUsage = ['keys' => [], 'anonymous' => null, 'total_calls' => 0, 'calls_today' => 0];
    try {
        $apiUsage = listApiKeyUsage(getDb());
    } catch (Throwable $e) {
        // api_keys / api_anonymous_usage may not exist yet
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

    // Readiness of the endpoint that is actually in effect. Cached for 20 s in
    // imageIntDashboardStatus(), so the 15 s dashboard poll does not hammer the
    // remote service. Tokens are never part of this payload.
    $imageStatus = [];
    try {
        $imageStatus = imageIntDashboardStatus();
    } catch (Throwable $e) {
        $imageStatus = [];
    }

    // ── Vector store (docvecwizard API / local Milvus) ────────────────────────

    $vectorStore = ['enabled' => false, 'mode' => 'off', 'online' => false];
    try {
        // Status is cached for 20 s inside vectorStoreStatus(), so the 15 s
        // dashboard poll does not hammer the remote API / Milvus.
        $vectorStore = array_merge(vectorStoreStatus(), vectorQueryStats());
    } catch (Throwable $e) {
        $vectorStore['detail'] = $e->getMessage();
    }

    // ── SpeechInt endpoints (dictation service) ───────────────────────────────

    $speechRows = [];
    try {
        $speechRows = getDb()->query("
            SELECT id, alias, base_url, timeout, is_active
            FROM speech_endpoints
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($speechRows as &$sr) {
            $sr['id']        = (int) $sr['id'];
            $sr['timeout']   = (int) $sr['timeout'];
            $sr['is_active'] = (int) $sr['is_active'];
        }
        unset($sr);
    } catch (PDOException $e) {
        // speech_endpoints table may not exist yet
    }

    // Readiness of the endpoint that is actually in effect. Cached for 20 s in
    // speechDictationDashboardStatus(), so the 15 s dashboard poll does not
    // hammer the remote service. Tokens are never part of this payload.
    $speechStatus = [];
    try {
        $speechStatus = speechDictationDashboardStatus();
    } catch (Throwable $e) {
        $speechStatus = [];
    }

    $payload = [
        'ok'               => true,
        'ts'               => time(),
        'endpoints'        => $rows,
        'searxng'          => $searxng,
        'clients'          => $clientStats,
        'api'              => $apiUsage,
        'totals'           => $totals,
        'image_endpoints'  => $imageRows,
        'image_status'     => $imageStatus,
        'vector_store'     => $vectorStore,
        'speech_endpoints' => $speechRows,
        'speech_status'    => $speechStatus,
    ];

    // Optional extra representation of the same data: the message-flow
    // topology consumed by assets/js/flow-topology.js. Only the consumers that
    // actually render the graph (dashboard card, admin/topology.php) ask for it.
    if (($_GET['topology'] ?? '') === '1') {
        $payload['topology'] = topology_build($payload, ['base_url' => '', 'slot_limit' => getBalancerMaxConcurrent()]);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Datenbankfehler']);
}
