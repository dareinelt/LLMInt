<?php

/**
 * admin/quickinfo_stats.php
 *
 * Session-protected JSON endpoint for the "Endpunkte technische Verwaltung"
 * page. Returns one row per LLM endpoint with the configured model, today's
 * average generation speed and – for endpoints paired with a quickinfo
 * instance – live system metrics (CPU/GPU load & temperature incl. 24h
 * min/max, RAM/VRAM usage) fetched in parallel via lib/quickinfo.php.
 *
 * Response shape:
 *   { ok: true, ts: <unix seconds>, endpoints: [ { id, alias, base_url,
 *     default_model, is_active, today_avg_tokens_per_second, paired,
 *     quickinfo_url, metrics: { … } | null }, … ] }
 */

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/quickinfo.php';
requireAdminOrJson403();
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
            e.quickinfo_url,
            e.quickinfo_api_key,
            e.quickinfo_verify_tls,
            COALESCE(SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END), 0) AS running,
            COALESCE(ROUND(AVG(CASE WHEN DATE(t.started_at) = CURDATE()
                                    AND t.tokens_per_second IS NOT NULL
                                    THEN t.tokens_per_second END), 1), 0) AS today_avg_tokens_per_second
        FROM endpoints e
        LEFT JOIN tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.alias, e.base_url, e.default_model, e.is_active,
                 e.quickinfo_url, e.quickinfo_api_key, e.quickinfo_verify_tls
        ORDER BY e.sort_order ASC, e.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $paired = [];
    foreach ($rows as $r) {
        if (trim((string) $r['quickinfo_url']) !== '' && trim((string) ($r['quickinfo_api_key'] ?? '')) !== '') {
            $paired[] = [
                'id'                   => (int) $r['id'],
                'quickinfo_url'        => (string) $r['quickinfo_url'],
                'quickinfo_api_key'    => (string) $r['quickinfo_api_key'],
                'quickinfo_verify_tls' => (bool) $r['quickinfo_verify_tls'],
            ];
        }
    }
    $metrics = $paired ? quickinfoCollectMetrics($paired) : [];

    $endpoints = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $isPaired = isset($metrics[$id]);
        $endpoints[] = [
            'id'                          => $id,
            'alias'                       => (string) $r['alias'],
            'base_url'                    => (string) $r['base_url'],
            'default_model'               => (string) $r['default_model'],
            'is_active'                   => (bool) $r['is_active'],
            'running'                     => (int) $r['running'],
            'today_avg_tokens_per_second' => (float) $r['today_avg_tokens_per_second'],
            'paired'                      => $isPaired,
            'quickinfo_url'               => $isPaired ? quickinfoNormalizeUrl((string) $r['quickinfo_url']) : '',
            'metrics'                     => $isPaired ? $metrics[$id] : null,
        ];
    }

    echo json_encode(['ok' => true, 'ts' => time(), 'endpoints' => $endpoints], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
