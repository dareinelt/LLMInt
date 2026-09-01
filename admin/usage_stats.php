<?php

/**
 * admin/usage_stats.php
 *
 * Session-protected JSON endpoint that returns the daily usage statistics
 * used by the "Nutzungsstatistik" line chart in the admin area.
 *
 * Query parameters:
 *   days – length of the reported period (3, 7, 14, 30, 90, 180 or 365)
 *
 * Response shape:
 *   { ok: true, days: 30, ts: <unix seconds>,
 *     series: [ { date: 'YYYY-MM-DD', clients, users, tasks,
 *                 tasks_failed, searches }, … ] }
 *
 * Notes on the data sources:
 *   clients      – peak concurrent clients per day (client_count_daily, which
 *                  is written by api/heartbeat.php; the current day is
 *                  completed from the live samples in client_count_log)
 *   users        – distinct users with at least one login that day
 *   tasks        – LLM tasks started that day
 *   tasks_failed – subset of those tasks that ended with status 'error'
 *   searches     – web searches started that day
 */

session_start();

require_once __DIR__ . '/../db.php';
requireAdminOrJson403();

// This endpoint only reads the session for auth – release the lock early so
// that polling never blocks other requests of the same session.
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const USAGE_STATS_RANGES = [3, 7, 14, 30, 90, 180, 365];

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, USAGE_STATS_RANGES, true)) {
    $days = 30;
}

try {
    $db = getDb();

    // Housekeeping: keep a little more than one year of history.
    $db->exec("DELETE FROM user_login_log     WHERE logged_in_at < DATE_SUB(CURDATE(), INTERVAL 400 DAY)");
    $db->exec("DELETE FROM client_count_daily WHERE day          < DATE_SUB(CURDATE(), INTERVAL 400 DAY)");

    // Build the (gap-free) list of days, oldest first.
    $series = [];
    $start  = new DateTimeImmutable('today');
    for ($i = $days - 1; $i >= 0; $i--) {
        $key = $start->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
        $series[$key] = [
            'date'         => $key,
            'clients'      => 0,
            'users'        => 0,
            'tasks'        => 0,
            'tasks_failed' => 0,
            'searches'     => 0,
        ];
    }
    $firstDay = array_key_first($series);

    $fill = static function (PDOStatement $stmt, string $field) use (&$series): void {
        foreach ($stmt as $row) {
            $day = (string) $row['day'];
            if (isset($series[$day])) {
                $series[$day][$field] = (int) $row['val'];
            }
        }
    };

    // Peak concurrent clients per day.
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(day, '%Y-%m-%d') AS day, max_cnt AS val
           FROM client_count_daily
          WHERE day >= ?"
    );
    $stmt->execute([$firstDay]);
    $fill($stmt, 'clients');

    // The aggregate for the current day may lag behind by up to 30 seconds –
    // complete it from the live samples.
    $todayKey  = (new DateTimeImmutable('today'))->format('Y-m-d');
    $todayPeak = (int) $db->query(
        "SELECT COALESCE(MAX(cnt), 0) FROM client_count_log WHERE DATE(recorded_at) = CURDATE()"
    )->fetchColumn();
    if (isset($series[$todayKey]) && $todayPeak > $series[$todayKey]['clients']) {
        $series[$todayKey]['clients'] = $todayPeak;
    }

    // Distinct logged-in users per day.
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(logged_in_at, '%Y-%m-%d') AS day,
                COUNT(DISTINCT user_id)               AS val
           FROM user_login_log
          WHERE logged_in_at >= ?
          GROUP BY DATE_FORMAT(logged_in_at, '%Y-%m-%d')"
    );
    $stmt->execute([$firstDay . ' 00:00:00']);
    $fill($stmt, 'users');

    // Tasks per day (total and failed).
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(started_at, '%Y-%m-%d')                    AS day,
                COUNT(*)                                               AS val,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END)      AS failed
           FROM tasks
          WHERE started_at >= ?
          GROUP BY DATE_FORMAT(started_at, '%Y-%m-%d')"
    );
    $stmt->execute([$firstDay . ' 00:00:00']);
    foreach ($stmt as $row) {
        $day = (string) $row['day'];
        if (isset($series[$day])) {
            $series[$day]['tasks']        = (int) $row['val'];
            $series[$day]['tasks_failed'] = (int) $row['failed'];
        }
    }

    // Web searches per day.
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(started_at, '%Y-%m-%d') AS day, COUNT(*) AS val
           FROM search_logs
          WHERE started_at >= ?
          GROUP BY DATE_FORMAT(started_at, '%Y-%m-%d')"
    );
    $stmt->execute([$firstDay . ' 00:00:00']);
    $fill($stmt, 'searches');

    echo json_encode([
        'ok'     => true,
        'days'   => $days,
        'ts'     => time(),
        'series' => array_values($series),
    ]);
} catch (Throwable $e) {
    error_log('[usage-stats] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
