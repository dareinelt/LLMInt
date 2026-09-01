<?php

/**
 * api/heartbeat.php
 *
 * Lightweight client-presence heartbeat endpoint.
 *
 * Each browser tab that has the main app open sends a POST every 30 s with a
 * randomly-generated per-tab token.  This endpoint:
 *   1. Upserts the token in active_clients (resets last_seen, stores IP/hostname).
 *   2. Purges entries older than 90 s (tab is considered gone).
 *   3. Records a count sample in client_count_log at most once per 30 s
 *      (used for today's min / max / avg in the admin load tree).
 *
 * No authentication is required; apart from the network address of the client
 * (shown to admins only) no user-identifiable data is stored.
 *
 * Expected POST body (JSON):  { "token": "<32-128 hex chars>" }
 * Response (JSON):            { "ok": true, "count": <int> }
 */

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Return the best-guess client IP address.
 * Checks X-Forwarded-For when a proxy injects it, falls back to REMOTE_ADDR.
 */
function heartbeatClientIp(): ?string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $ip = trim(explode(',', $xff)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = isset($input['token']) ? (string) $input['token'] : '';

if (!preg_match('/^[0-9a-f]{32,128}$/i', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

try {
    $db = getDb();

    $ip = heartbeatClientIp();

    // Resolve the hostname only once per IP (reverse DNS can be slow) – reuse
    // an already-known hostname for the same address whenever possible.
    $hostname = null;
    if ($ip !== null) {
        $stmt = $db->prepare(
            'SELECT hostname FROM active_clients
              WHERE ip = ? AND hostname IS NOT NULL AND hostname <> \'\'
              LIMIT 1'
        );
        $stmt->execute([$ip]);
        $known = $stmt->fetchColumn();

        if (is_string($known) && $known !== '') {
            $hostname = $known;
        } else {
            $resolved = @gethostbyaddr($ip);
            if (is_string($resolved) && $resolved !== '' && $resolved !== $ip) {
                $hostname = mb_substr($resolved, 0, 255);
            }
        }
    }

    // 1. Upsert heartbeat
    $db->prepare(
        'INSERT INTO active_clients (token, ip, hostname, last_seen)
         VALUES (?, ?, ?, NOW(3))
         ON DUPLICATE KEY UPDATE ip = VALUES(ip),
                                 hostname = VALUES(hostname),
                                 last_seen = NOW(3)'
    )->execute([$token, $ip, $hostname]);

    // 2. Purge expired clients (no activity for > 90 s)
    $db->exec(
        "DELETE FROM active_clients WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 SECOND)"
    );

    // 3. Current active count
    $count = (int) $db->query(
        'SELECT COUNT(*) FROM active_clients'
    )->fetchColumn();

    // 4. Record a count sample – at most once per 30 s
    $sampleStmt = $db->prepare(
        "INSERT INTO client_count_log (cnt, recorded_at)
         SELECT ?, NOW(3)
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM client_count_log
             WHERE recorded_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
         )"
    );
    $sampleStmt->execute([$count]);

    // 5. Roll the sample up into the daily aggregate. client_count_log only
    //    keeps the current day, client_count_daily feeds the usage statistics.
    if ($sampleStmt->rowCount() > 0) {
        $db->prepare(
            "INSERT INTO client_count_daily (day, max_cnt, sum_cnt, samples)
             VALUES (CURDATE(), ?, ?, 1)
             ON DUPLICATE KEY UPDATE max_cnt = GREATEST(max_cnt, VALUES(max_cnt)),
                                     sum_cnt = sum_cnt + VALUES(sum_cnt),
                                     samples = samples + 1"
        )->execute([$count, $count]);
    }

    // 6. Purge samples older than today
    $db->exec(
        "DELETE FROM client_count_log WHERE DATE(recorded_at) < CURDATE()"
    );

    echo json_encode(['ok' => true, 'count' => $count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
