<?php

/**
 * admin/refresh_sys_stats.php
 *
 * Session-protected JSON endpoint that SSH-polls every LLM endpoint that
 * has SSH credentials configured, collects RAM, CPU load, and CPU
 * temperature (via lm-sensors), and stores the result in the
 * endpoint_sys_stats cache table.
 *
 * Called by the admin dashboard JavaScript every 60 seconds.
 *
 * Response shape:
 *   { ok: true, results: [ { id, fetch_ok, ram_total, ram_used,
 *     cpu_load_1m, cpu_load_5m, cpu_temp }, … ] }
 *
 * Requirements:
 *   - The PHP ext-ssh2 extension must be installed on the server
 *     (php-ssh2 package).  Without it the endpoint returns an error.
 *   - Remote hosts must have the 'sensors' utility (lm-sensors package)
 *     installed for temperature readings; a missing/failed sensors call
 *     results in cpu_temp = null.
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
session_write_close();

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('ssh2_connect')) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'PHP-Erweiterung ssh2 nicht verfügbar. Bitte php-ssh2 installieren.',
    ]);
    exit;
}

// ── Shell command to collect all metrics in one SSH session ───────────────────
//
// Output lines:
//   RAM:<total_bytes> <used_bytes>
//   CPU:<load_1m> <load_5m>
//   TEMP:<celsius_float>   (or TEMP: if sensors / thermal zone unavailable)
//
const COLLECT_CMD =
    'echo "RAM:$(free -b 2>/dev/null | awk \'NR==2{printf "%d %d",$2,$3}\')"; ' .
    'echo "CPU:$(awk \'{printf "%.2f %.2f",$1,$2}\' /proc/loadavg 2>/dev/null)"; ' .
    'echo "TEMP:$(sensors 2>/dev/null | grep -E \'(Core 0|Package id 0|Tdie|Tctl)\' ' .
        '| head -1 | grep -oP \'(?<=\\+)[0-9.]+(?=°C)\' ' .
        '|| cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null | awk \'{printf "%.1f",$1/1000}\' ' .
        '|| echo \'\')"';

// ── SSH helper ────────────────────────────────────────────────────────────────

/**
 * Open an SSH connection, run the collect command, and parse the output.
 *
 * @return array{ok:bool, ram_total:int|null, ram_used:int|null,
 *               cpu_load_1m:float|null, cpu_load_5m:float|null,
 *               cpu_temp:float|null, error:string|null}
 */
function fetchSysStats(string $host, int $port, string $user, string $password): array
{
    $null = ['ok' => false, 'ram_total' => null, 'ram_used' => null,
             'cpu_load_1m' => null, 'cpu_load_5m' => null, 'cpu_temp' => null,
             'error' => null];

    set_error_handler(function () {});   // suppress warnings from ssh2_*

    try {
        $session = @ssh2_connect($host, $port, ['hostkey' => 'ssh-rsa,ssh-dss,ecdsa-sha2-nistp256,ecdsa-sha2-nistp384,ecdsa-sha2-nistp521']);
        if ($session === false) {
            restore_error_handler();
            return array_merge($null, ['error' => 'Verbindung fehlgeschlagen']);
        }

        if (!@ssh2_auth_password($session, $user, $password)) {
            restore_error_handler();
            return array_merge($null, ['error' => 'Authentifizierung fehlgeschlagen']);
        }

        $stream = @ssh2_exec($session, COLLECT_CMD);
        if ($stream === false) {
            restore_error_handler();
            return array_merge($null, ['error' => 'Befehl konnte nicht ausgeführt werden']);
        }

        $errStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
        stream_set_blocking($stream,    true);
        stream_set_blocking($errStream, true);

        $output = stream_get_contents($stream);
        fclose($stream);
        fclose($errStream);

    } catch (Throwable $e) {
        restore_error_handler();
        return array_merge($null, ['error' => $e->getMessage()]);
    }

    restore_error_handler();

    // Parse lines
    $ramTotal = null; $ramUsed = null;
    $load1m   = null; $load5m  = null;
    $temp     = null;

    foreach (explode("\n", (string) $output) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'RAM:')) {
            $parts = explode(' ', substr($line, 4));
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $ramTotal = (int) $parts[0];
                $ramUsed  = (int) $parts[1];
            }
        } elseif (str_starts_with($line, 'CPU:')) {
            $parts = explode(' ', substr($line, 4));
            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $load1m = (float) $parts[0];
                $load5m = (float) $parts[1];
            }
        } elseif (str_starts_with($line, 'TEMP:')) {
            $val = substr($line, 5);
            if ($val !== '' && is_numeric($val)) {
                $temp = (float) $val;
            }
        }
    }

    return [
        'ok'         => true,
        'ram_total'  => $ramTotal,
        'ram_used'   => $ramUsed,
        'cpu_load_1m'=> $load1m,
        'cpu_load_5m'=> $load5m,
        'cpu_temp'   => $temp,
        'error'      => null,
    ];
}

// ── Main ──────────────────────────────────────────────────────────────────────

try {
    $db = getDb();

    $endpoints = $db->query("
        SELECT id, ssh_host, ssh_port, ssh_user, ssh_password
        FROM   endpoints
        WHERE  ssh_host != '' AND ssh_user != '' AND is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($endpoints as $ep) {
        $id   = (int) $ep['id'];
        $host = (string) $ep['ssh_host'];
        $port = max(1, min(65535, (int) $ep['ssh_port']));
        $user = (string) $ep['ssh_user'];
        $pass = (string) ($ep['ssh_password'] ?? '');

        $stats = fetchSysStats($host, $port, $user, $pass);

        // Update / insert cache row
        $db->prepare("
            INSERT INTO endpoint_sys_stats
                (endpoint_id, ram_total, ram_used, cpu_load_1m, cpu_load_5m, cpu_temp, fetch_ok)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ram_total   = VALUES(ram_total),
                ram_used    = VALUES(ram_used),
                cpu_load_1m = VALUES(cpu_load_1m),
                cpu_load_5m = VALUES(cpu_load_5m),
                cpu_temp    = VALUES(cpu_temp),
                fetch_ok    = VALUES(fetch_ok),
                fetched_at  = CURRENT_TIMESTAMP(3)
        ")->execute([
            $id,
            $stats['ram_total'],
            $stats['ram_used'],
            $stats['cpu_load_1m'],
            $stats['cpu_load_5m'],
            $stats['cpu_temp'],
            $stats['ok'] ? 1 : 0,
        ]);

        $results[] = [
            'id'         => $id,
            'fetch_ok'   => $stats['ok'],
            'error'      => $stats['error'],
            'ram_total'  => $stats['ram_total'],
            'ram_used'   => $stats['ram_used'],
            'cpu_load_1m'=> $stats['cpu_load_1m'],
            'cpu_load_5m'=> $stats['cpu_load_5m'],
            'cpu_temp'   => $stats['cpu_temp'],
        ];
    }

    echo json_encode(
        ['ok' => true, 'results' => $results],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Fehler: ' . $e->getMessage()]);
}
