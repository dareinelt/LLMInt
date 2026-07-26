<?php

/**
 * api/heartbeat.php
 *
 * Lightweight client-presence heartbeat endpoint.
 *
 * Each browser tab that has the main app open sends a POST every 30 s with a
 * randomly-generated per-tab token.  This endpoint:
 *   1. Upserts the token in active_clients (resets last_seen).
 *   2. Purges entries older than 90 s (tab is considered gone).
 *   3. Records a count sample in client_count_log at most once per 30 s
 *      (used for today's min / max / avg in the admin load tree).
 *
 * No authentication is required; no user-identifiable data is stored.
 *
 * Expected POST body (JSON):  { "token": "<32-128 hex chars>" }
 * Response (JSON):            { "ok": true, "count": <int> }
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$input = json_decode(file_get_contents('php://input'), true);
$token = isset($input['token']) ? (string) $input['token'] : '';

if (!preg_match('/^[0-9a-f]{32,128}$/i', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

try {
    $db = getDb();

    // 1. Upsert heartbeat
    $db->prepare(
        'INSERT INTO active_clients (token, last_seen)
         VALUES (?, NOW(3))
         ON DUPLICATE KEY UPDATE last_seen = NOW(3)'
    )->execute([$token]);

    // 2. Purge expired clients (no activity for > 90 s)
    $db->exec(
        "DELETE FROM active_clients WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 SECOND)"
    );

    // 3. Current active count
    $count = (int) $db->query(
        'SELECT COUNT(*) FROM active_clients'
    )->fetchColumn();

    // 4. Record a count sample – at most once per 30 s
    $db->prepare(
        "INSERT INTO client_count_log (cnt, recorded_at)
         SELECT ?, NOW(3)
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM client_count_log
             WHERE recorded_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
         )"
    )->execute([$count]);

    // 5. Purge samples older than today
    $db->exec(
        "DELETE FROM client_count_log WHERE DATE(recorded_at) < CURDATE()"
    );

    echo json_encode(['ok' => true, 'count' => $count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
