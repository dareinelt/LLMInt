<?php

/**
 * admin/partials/data.php
 *
 * Read-only data loading of the admin area: log viewer query, endpoints,
 * users, API keys, statistics, vector store status and the edit records.
 * Shared by every admin page, so each page can render any card.
 */

// ── Log viewer query ──────────────────────────────────────────────────────────

$logFilterDateFrom = trim($_GET['log_from']     ?? '');
$logFilterDateTo   = trim($_GET['log_to']       ?? '');
$logFilterKeyword  = trim($_GET['log_keyword']  ?? '');
$logFilterLevel    = trim($_GET['log_lv']       ?? '');
$logSortDir        = ($_GET['log_sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$logPage           = max(1, (int) ($_GET['log_page'] ?? 1));
$logPerPage        = 50;

$logRows   = [];
$logTotal  = 0;
try {
    $logWhere  = ['1=1'];
    $logParams = [];

    if ($logFilterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFilterDateFrom)) {
        $logWhere[]  = 'created_at >= ?';
        $logParams[] = $logFilterDateFrom . ' 00:00:00';
    }
    if ($logFilterDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFilterDateTo)) {
        $logWhere[]  = 'created_at <= ?';
        $logParams[] = $logFilterDateTo . ' 23:59:59';
    }
    if ($logFilterKeyword !== '') {
        $logWhere[]  = 'message LIKE ?';
        $logParams[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $logFilterKeyword) . '%';
    }
    if (in_array($logFilterLevel, ['info', 'warning', 'error'], true)) {
        $logWhere[]  = 'level = ?';
        $logParams[] = $logFilterLevel;
    }

    $logWhereClause = implode(' AND ', $logWhere);
    $logOrderDir    = strtoupper($logSortDir);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM app_logs WHERE {$logWhereClause}");
    $countStmt->execute($logParams);
    $logTotal = (int) $countStmt->fetchColumn();

    $logOffset = ($logPage - 1) * $logPerPage;
    $dataStmt  = $db->prepare(
        "SELECT id, level, message, created_at
           FROM app_logs
          WHERE {$logWhereClause}
          ORDER BY created_at {$logOrderDir}, id {$logOrderDir}
          LIMIT {$logPerPage} OFFSET {$logOffset}"
    );
    $dataStmt->execute($logParams);
    $logRows = $dataStmt->fetchAll();
} catch (Throwable $_logEx) {
    // Table may not exist on first request before migration runs.
}

$logTotalPages = $logTotal > 0 ? (int) ceil($logTotal / $logPerPage) : 1;

// ── Load data ─────────────────────────────────────────────────────────────────

$endpoints = $db->query(
    'SELECT * FROM endpoints ORDER BY sort_order ASC, id ASC'
)->fetchAll();

// Safe edit data for every endpoint (never expose ssh_password) — used both by
// the "✏ Bearbeiten" button in the Endpunkte table and by clicking an endpoint
// node in the Dashboard mindmap, so both open the identical edit overlay.
$epEditDataMap = [];
foreach ($endpoints as $ep) {
    $epEditDataMap[(int) $ep['id']] = [
        'id'                       => $ep['id'],
        'alias'                    => $ep['alias'],
        'base_url'                 => $ep['base_url'],
        'timeout'                  => $ep['timeout'],
        'max_context'              => (int) ($ep['max_context'] ?? 0),
        'context_limit_per_slot'   => (int) ($ep['context_limit_per_slot'] ?? 0),
        'default_model'            => $ep['default_model'],
        'specialized_for_category' => $ep['specialized_for_category'] ?? '',
        'supports_tool_calling'    => (int) ($ep['supports_tool_calling'] ?? 1),
        'is_llamacpp'              => (int) ($ep['is_llamacpp'] ?? 0),
        'reasoning_effort'         => $ep['reasoning_effort'] ?? 'high',
        'supports_vision'          => (int) ($ep['supports_vision'] ?? 0),
        'is_active'                => $ep['is_active'],
        'ssh_host'                 => $ep['ssh_host'] ?? '',
        'ssh_port'                 => $ep['ssh_port'] ?? 22,
        'ssh_user'                 => $ep['ssh_user'] ?? '',
    ];
}

$users = $db->query(
    'SELECT id, username, email, email_verified, default_model, can_upload_documents, role, auth_source, created_at, last_login
       FROM users ORDER BY id'
)->fetchAll();

// ── API keys of the signed-in admin ───────────────────────────────────────────
$availableModels = listActiveEndpointModels();
$apiKeys = [];
try {
    $apiKeyStmt = $db->prepare(
        'SELECT id, name, description, model, key_prefix, created_at, last_used_at, expires_at, is_active
           FROM api_keys
          WHERE user_id = ?
          ORDER BY id DESC'
    );
    $apiKeyStmt->execute([$_SESSION['admin_id']]);
    $apiKeys = $apiKeyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table may not exist yet on installations predating the api_keys migration.
}

// ── Load statistics ───────────────────────────────────────────────────────────

try {
    $epStats = $db->query('
        SELECT
            e.id,
            e.alias,
            e.base_url,
            e.default_model,
            e.is_active,
            e.ssh_host,
            e.ssh_user,
            e.circuit_state,
            e.consecutive_failures,
            e.cooldown_until,
            e.avg_latency_ms,
            COALESCE(SUM(CASE WHEN t.status = \'running\' THEN 1 ELSE 0 END), 0) AS cnt_running,
            COALESCE(SUM(CASE WHEN t.status = \'done\'    THEN 1 ELSE 0 END), 0) AS cnt_done,
            COALESCE(SUM(CASE WHEN t.status = \'error\'   THEN 1 ELSE 0 END), 0) AS cnt_error,
            COALESCE(SUM(CASE WHEN t.status = \'done\'
                              AND t.started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS cnt_done_24h,
            COALESCE(SUM(t.prompt_tokens), 0)     AS sum_prompt_tokens,
            COALESCE(SUM(t.completion_tokens), 0) AS sum_completion_tokens,
            COALESCE(SUM(t.total_tokens), 0)      AS sum_tokens,
            COALESCE(ROUND(AVG(CASE WHEN t.total_tokens IS NOT NULL
                                    THEN t.total_tokens END)), 0) AS avg_tokens,
            COALESCE(SUM(CASE WHEN t.status = \'done\'
                              AND DATE(t.started_at) = CURDATE()
                         THEN 1 ELSE 0 END), 0) AS today_jobs,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.prompt_tokens, 0) ELSE 0 END), 0)     AS today_prompt_tokens,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.completion_tokens, 0) ELSE 0 END), 0) AS today_completion_tokens,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.total_tokens, 0) ELSE 0 END), 0)      AS today_tokens,
            COALESCE(ROUND(AVG(CASE WHEN DATE(t.started_at) = CURDATE()
                                    AND t.tokens_per_second IS NOT NULL
                                    THEN t.tokens_per_second END), 1), 0) AS today_avg_tokens_per_second
        FROM endpoints e
        LEFT JOIN tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.alias, e.base_url, e.default_model, e.is_active, e.ssh_host, e.ssh_user,
                 e.circuit_state, e.consecutive_failures, e.cooldown_until, e.avg_latency_ms
        ORDER BY e.sort_order ASC, e.id ASC
    ')->fetchAll();

    // Attach cached SSH system stats
    $sysStatsByEpId = [];
    try {
        $sysRows = $db->query("
            SELECT endpoint_id, ram_total, ram_used, cpu_load_1m, cpu_load_5m, cpu_temp, fetch_ok, fetched_at
            FROM   endpoint_sys_stats
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sysRows as $sr) {
            $sysStatsByEpId[(int) $sr['endpoint_id']] = [
                'ok'          => (bool) $sr['fetch_ok'],
                'ram_total'   => $sr['ram_total']   !== null ? (int)   $sr['ram_total']   : null,
                'ram_used'    => $sr['ram_used']    !== null ? (int)   $sr['ram_used']    : null,
                'cpu_load_1m' => $sr['cpu_load_1m'] !== null ? (float) $sr['cpu_load_1m'] : null,
                'cpu_load_5m' => $sr['cpu_load_5m'] !== null ? (float) $sr['cpu_load_5m'] : null,
                'cpu_temp'    => $sr['cpu_temp']    !== null ? (float) $sr['cpu_temp']    : null,
                'fetched_at'  => $sr['fetched_at'],
            ];
        }
    } catch (PDOException $_sse) { /* Table may not exist yet – safe to ignore */ }

    foreach ($epStats as &$epRow) {
        $epRow['ssh_configured'] = trim((string) ($epRow['ssh_host'] ?? '')) !== ''
                                && trim((string) ($epRow['ssh_user'] ?? '')) !== '';
        $epRow['sys_stats'] = $sysStatsByEpId[(int) $epRow['id']] ?? null;
    }
    unset($epRow);

    $totals = $db->query('
        SELECT
            COALESCE(SUM(CASE WHEN status = \'running\' THEN 1 ELSE 0 END), 0) AS total_running,
            COALESCE(SUM(CASE WHEN status = \'done\'    THEN 1 ELSE 0 END), 0) AS total_done,
            COALESCE(SUM(CASE WHEN status = \'error\'   THEN 1 ELSE 0 END), 0) AS total_error,
            COALESCE(SUM(CASE WHEN status = \'done\'
                              AND started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS total_done_24h,
            COALESCE(SUM(prompt_tokens), 0)     AS grand_prompt_tokens,
            COALESCE(SUM(completion_tokens), 0) AS grand_completion_tokens,
            COALESCE(SUM(total_tokens), 0) AS grand_tokens
        FROM tasks
    ')->fetch();
} catch (PDOException $e) {
    $epStats = [];
    $totals  = [
        'total_running' => 0, 'total_done' => 0, 'total_error' => 0,
        'total_done_24h' => 0,
        'grand_prompt_tokens' => 0, 'grand_completion_tokens' => 0, 'grand_tokens' => 0,
    ];
}

// ── SearXNG search statistics ─────────────────────────────────────────────────

$searxngStats = ['running' => 0, 'today_jobs' => 0, 'total_done' => 0, 'avg_duration_seconds' => null];
if ($searxngBaseUrl !== '') {
    try {
        $sRow = $db->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND DATE(started_at) = CURDATE()
                             THEN 1 ELSE 0 END), 0) AS today_jobs,
                COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) AS total_done,
                AVG(CASE WHEN status = 'done'
                              AND DATE(started_at) = CURDATE()
                              AND finished_at IS NOT NULL
                         THEN TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) / 1000000.0
                         ELSE NULL END) AS avg_duration_seconds
            FROM search_logs
        ")->fetch();
        $searxngStats = [
            'running'              => (int) $sRow['running'],
            'today_jobs'           => (int) $sRow['today_jobs'],
            'total_done'           => (int) $sRow['total_done'],
            'avg_duration_seconds' => $sRow['avg_duration_seconds'] !== null
                                        ? round((float) $sRow['avg_duration_seconds'], 1)
                                        : null,
        ];
    } catch (PDOException $e) {
        // search_logs table may not exist on older installations
    }
}

// ── Vector store status & statistics ─────────────────────────────────────────
// Status probing is cached for 20 s inside vectorStoreStatus(); the dashboard
// polls admin/load_stats.php for live updates.
$vectorStatus = array_merge(vectorStoreStatus(), vectorQueryStats());
$vectorImports = [];
$vectorLocalDocs = ['documents' => 0, 'chunks' => 0, 'models' => []];
try {
    $vectorImports = $db->query('SELECT id, filename, status, strategy, documents, chunks, vectors, skipped, message, created_at, finished_at FROM vector_imports ORDER BY id DESC LIMIT 8')->fetchAll();
    $vRow = $db->query('SELECT COUNT(*) AS documents, COALESCE(SUM(chunk_count),0) AS chunks FROM vector_documents')->fetch();
    $vectorLocalDocs['documents'] = (int) ($vRow['documents'] ?? 0);
    $vectorLocalDocs['chunks']    = (int) ($vRow['chunks'] ?? 0);
    $vectorLocalDocs['models']    = $db->query('SELECT embedding_model, embedding_dimension, collection_name, COUNT(*) AS n FROM vector_documents GROUP BY embedding_model, embedding_dimension, collection_name ORDER BY n DESC')->fetchAll();
} catch (PDOException $e) {
    // Tables may not exist yet
}

// ── Embedding endpoints and statistics ────────────────────────────────────────

$embeddingEndpoints = [];
$editEmbEp          = null;
$embeddingStatsRow  = [
    'total_chunks'       => 0,
    'chunks_with_embed'  => 0,
    'chunks_missing'     => 0,
    'uploads_done'       => 0,
    'uploads_pending'    => 0,
    'uploads_error'      => 0,
    'cache_entries'      => 0,
    'avg_duration_ms'    => null,
    'avg_rerank_ms'      => null,
    'error_count'        => 0,
];

try {
    $embeddingEndpoints = $db->query(
        'SELECT * FROM embedding_endpoints ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet – migrations run on first getDb() call.
}

try {
    $totalChunks = (int) $db->query('SELECT COUNT(*) FROM document_chunks')->fetchColumn();
    $chunksWithEmbed = (int) $db->query("SELECT COUNT(*) FROM document_chunks WHERE embedding IS NOT NULL AND embedding != ''")->fetchColumn();
    $embeddingStatsRow['total_chunks']      = $totalChunks;
    $embeddingStatsRow['chunks_with_embed'] = $chunksWithEmbed;
    $embeddingStatsRow['chunks_missing']    = max(0, $totalChunks - $chunksWithEmbed);
    $embeddingStatsRow['uploads_done']    = (int) $db->query("SELECT COUNT(*) FROM document_uploads WHERE embedding_status = 'done'")->fetchColumn();
    $embeddingStatsRow['uploads_pending'] = (int) $db->query("SELECT COUNT(*) FROM document_uploads WHERE embedding_status IN ('pending','processing')")->fetchColumn();
    $embeddingStatsRow['uploads_error']   = (int) $db->query("SELECT COUNT(*) FROM document_uploads WHERE embedding_status = 'error'")->fetchColumn();
} catch (PDOException $e) { /* table may not exist */ }

try {
    $embeddingStatsRow['cache_entries'] = (int) $db->query('SELECT COUNT(*) FROM embedding_cache')->fetchColumn();
} catch (PDOException $e) { /* table may not exist */ }

try {
    $elRow = $db->query("
        SELECT
            AVG(CASE WHEN type = 'query' AND status = 'ok' THEN duration_ms END) AS avg_query_ms,
            AVG(CASE WHEN type = 'rerank' AND status = 'ok' THEN duration_ms END) AS avg_rerank_ms,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_count
        FROM embedding_logs
        WHERE created_at >= NOW() - INTERVAL 24 HOUR
    ")->fetch();
    if ($elRow) {
        $embeddingStatsRow['avg_duration_ms'] = $elRow['avg_query_ms'] !== null ? round((float) $elRow['avg_query_ms'], 1) : null;
        $embeddingStatsRow['avg_rerank_ms']   = $elRow['avg_rerank_ms'] !== null ? round((float) $elRow['avg_rerank_ms'], 1) : null;
        $embeddingStatsRow['error_count']     = (int) ($elRow['error_count'] ?? 0);
    }
} catch (PDOException $e) { /* table may not exist */ }

// Populate $editEmbEp if the URL requests editing a specific embedding endpoint.
if (isset($_GET['edit_emb']) && (int) $_GET['edit_emb'] > 0) {
    $editEmbId = (int) $_GET['edit_emb'];
    foreach ($embeddingEndpoints as $embEp) {
        if ((int) $embEp['id'] === $editEmbId) {
            $editEmbEp = $embEp;
            break;
        }
    }
}

$clientStats = ['current' => 0, 'today_min' => 0, 'today_max' => 0, 'today_avg' => 0.0, 'list' => []];
try {
    $current = (int) $db->query(
        "SELECT COUNT(*) FROM active_clients
          WHERE last_seen > DATE_SUB(NOW(), INTERVAL 90 SECOND)"
    )->fetchColumn();

    $cRow = $db->query(
        "SELECT MIN(cnt) AS min_cnt, MAX(cnt) AS max_cnt, AVG(cnt) AS avg_cnt
           FROM client_count_log
          WHERE DATE(recorded_at) = CURDATE()"
    )->fetch(PDO::FETCH_ASSOC);

    $clientStats = [
        'current'   => $current,
        'today_min' => ($cRow && $cRow['min_cnt'] !== null) ? (int) $cRow['min_cnt'] : $current,
        'today_max' => ($cRow && $cRow['max_cnt'] !== null) ? (int) $cRow['max_cnt'] : $current,
        'today_avg' => ($cRow && $cRow['avg_cnt'] !== null) ? round((float) $cRow['avg_cnt'], 1) : (float) $current,
        'list'      => listActiveClients($db),
    ];
} catch (PDOException $e) {
    // Tables may not exist on older installations
}

$apiUsage = ['keys' => [], 'anonymous' => null, 'total_calls' => 0, 'calls_today' => 0];
try {
    $apiUsage = listApiKeyUsage($db);
} catch (Throwable $e) {
    // api_keys / api_anonymous_usage may not exist yet
}

// Assign a stable colour to each distinct model name.
$palette       = ['#6c63ff', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#f97316', '#84cc16'];
$modelColorMap = [];
$colorIdx      = 0;

/**
 * Extracts the intelligence label (e.g. "7b", "27b") from a model name.
 * MoE active-parameter markers (A17B, A3B, A4B, …) are ignored.
 */
function modelIntelligenceLabel(string $model): string
{
    if ($model === '') {
        return '–';
    }
    $best = modelIntelligenceScore($model);
    if ($best === null) {
        return '–';
    }
    if (abs($best - round($best)) < 0.00001) {
        return ((string) ((int) round($best))) . 'b';
    }
    return rtrim(rtrim(number_format($best, 2, '.', ''), '0'), '.') . 'b';
}

foreach ($endpoints as $ep) {
    $m = canonicalModelName((string) $ep['default_model']);
    if ($m !== '' && !isset($modelColorMap[$m])) {
        $modelColorMap[$m] = $palette[$colorIdx % count($palette)];
        $colorIdx++;
    }
}

$availableGuestModels = [];
foreach ($endpoints as $ep) {
    $model = trim((string) ($ep['default_model'] ?? ''));
    if ((int) ($ep['is_active'] ?? 0) !== 1 || $model === '' || isset($availableGuestModels[$model])) {
        continue;
    }
    $availableGuestModels[$model] = $model;
}

// Populate $editEp if the URL requests editing a specific endpoint.
if (isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
    $epId = (int) $_GET['edit'];
    foreach ($endpoints as $ep) {
        if ($ep['id'] === $epId) {
            $editEp = $ep;
            break;
        }
    }
}

