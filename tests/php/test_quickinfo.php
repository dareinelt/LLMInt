<?php
declare(strict_types=1);

/**
 * tests/php/test_quickinfo.php
 *
 * Unit tests for the pure helpers of lib/quickinfo.php: URL normalisation,
 * 24h min/max extraction and the merge of the three quickinfo API responses
 * (/status, /info, /history) into one metrics row for the admin UI.
 *
 * The HTTP layer (quickinfoCurlHandle()/quickinfoRequest()/quickinfoCollectMetrics())
 * needs a reachable quickinfo instance and is deliberately not covered here.
 * quickinfoReadHandle() is only exercised for the "no response" branch, because
 * the HTTP status code cannot be injected without a real request.
 *
 * Run from the repository root:  php tests/php/test_quickinfo.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/quickinfo.php';

/** Vollständige Schlüsselliste einer Metrik-Zeile (Reihenfolge aus quickinfoBuildMetricRow()). */
const QUICKINFO_ROW_KEYS = [
    'ok', 'error', 'hostname', 'stale', 'snapshot_age',
    'cpu_util', 'cpu_temp', 'cpu_temp_min', 'cpu_temp_max', 'cpu_model', 'cpu_cores',
    'ram_used', 'ram_total', 'ram_pct',
    'gpu_model', 'gpu_count', 'gpu_util', 'gpu_temp', 'gpu_temp_min', 'gpu_temp_max',
    'vram_used_mb', 'vram_total_mb', 'vram_pct', 'gpu_power_w', 'gpus',
    'uptime', 'load', 'services_up', 'services_total',
];

/** Antwort-Helfer: erfolgreiche API-Antwort mit Nutzdaten. */
function qiOk(array $data): array
{
    return ['ok' => true, 'http' => 200, 'data' => $data, 'error' => ''];
}

/** Antwort-Helfer: fehlgeschlagene API-Antwort. */
function qiFail(string $error, int $http = 0): array
{
    return ['ok' => false, 'http' => $http, 'data' => null, 'error' => $error];
}

// ── quickinfoNormalizeUrl() ───────────────────────────────────────────────────

test_case('quickinfoNormalizeUrl() ergänzt das https-Schema', function (): void {
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('quickinfo.example'));
});

test_case('quickinfoNormalizeUrl() entfernt abschließende Schrägstriche', function (): void {
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('https://quickinfo.example/'));
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('https://quickinfo.example///'));
});

test_case('quickinfoNormalizeUrl() entfernt einen /api- bzw. /api/v1-Pfad', function (): void {
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('https://quickinfo.example/api'));
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('https://quickinfo.example/api/v1'));
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('https://quickinfo.example/api/v1/'));
});

test_case('quickinfoNormalizeUrl() ist bei Schema und Pfad unabhängig von der Schreibweise', function (): void {
    assert_same('HTTPS://Quick.Example', quickinfoNormalizeUrl('HTTPS://Quick.Example/API/V1'));
});

test_case('quickinfoNormalizeUrl() behält Port und Pfad-Präfixe', function (): void {
    assert_same('https://quickinfo.example:8080', quickinfoNormalizeUrl('https://quickinfo.example:8080/'));
    assert_same('https://quickinfo.example:8080', quickinfoNormalizeUrl('https://quickinfo.example:8080/api'));
    assert_same('https://quickinfo.example/board', quickinfoNormalizeUrl('https://quickinfo.example/board'));
});

test_case('quickinfoNormalizeUrl() trimmt umgebende Leerzeichen', function (): void {
    assert_same('https://quickinfo.example', quickinfoNormalizeUrl('  quickinfo.example  '));
});

test_case('quickinfoNormalizeUrl() liefert einen leeren String ohne verwertbare URL', function (): void {
    assert_same('', quickinfoNormalizeUrl(''));
    assert_same('', quickinfoNormalizeUrl('   '));
    assert_same('', quickinfoNormalizeUrl('https://'));
    assert_same('', quickinfoNormalizeUrl('/relative/path'));
});

test_case('quickinfoNormalizeUrl() ist idempotent', function (): void {
    foreach (['quickinfo.example', 'https://quickinfo.example/api/v1/', 'http://quickinfo.example:9000'] as $input) {
        $once = quickinfoNormalizeUrl($input);
        assert_same($once, quickinfoNormalizeUrl($once), 'Zweiter Durchlauf ändert nichts: ' . $input);
    }
});

// ── quickinfoSeriesMinMax() ───────────────────────────────────────────────────

test_case('quickinfoSeriesMinMax() liest Wertepaare [Zeitstempel, Wert]', function (): void {
    $mm = quickinfoSeriesMinMax([[1, 3.2], [2, 9]]);
    assert_same(3.2, $mm['min']);
    assert_same(9.0, $mm['max']);
});

test_case('quickinfoSeriesMinMax() akzeptiert auch eine flache Werteliste', function (): void {
    $mm = quickinfoSeriesMinMax([1, 2, 3]);
    assert_same(1.0, $mm['min']);
    assert_same(3.0, $mm['max']);
});

test_case('quickinfoSeriesMinMax() überspringt Punkte ohne numerischen Wert', function (): void {
    $mm = quickinfoSeriesMinMax([[1, 'heiß'], [2, 5], 'kaputt', [3, null], [4, true]]);
    assert_same(5.0, $mm['min']);
    assert_same(5.0, $mm['max']);
});

test_case('quickinfoSeriesMinMax() akzeptiert numerische Strings', function (): void {
    $mm = quickinfoSeriesMinMax([[1, '12.5'], [2, '40']]);
    assert_same(12.5, $mm['min']);
    assert_same(40.0, $mm['max']);
});

test_case('quickinfoSeriesMinMax() liefert null ohne verwertbare Punkte', function (): void {
    $empty = quickinfoSeriesMinMax([]);
    assert_same(null, $empty['min']);
    assert_same(null, $empty['max']);

    $invalid = quickinfoSeriesMinMax('kein Array');
    assert_same(null, $invalid['min']);
    assert_same(null, $invalid['max']);
});

// ── quickinfoReadHandle() ─────────────────────────────────────────────────────

if (function_exists('curl_init')) {
    test_case('quickinfoReadHandle() meldet eine fehlende Antwort', function (): void {
        $ch = curl_init('https://quickinfo.example/api/v1/status');
        try {
            // Der Handle wurde nie ausgeführt: HTTP-Code 0 und leere curl-Fehlermeldung.
            $res = quickinfoReadHandle($ch, false);
            assert_keys(['ok', 'http', 'data', 'error'], $res);
            assert_false($res['ok']);
            assert_same(0, $res['http']);
            assert_same(null, $res['data']);
            assert_same('Keine Verbindung / keine Antwort', $res['error']);
        } finally {
            curl_close($ch);
        }
    });
} else {
    // Ohne curl-Extension ist das HTTP-Layer von quickinfo ohnehin nicht nutzbar.
    echo "# Hinweis: curl-Extension fehlt, quickinfoReadHandle()-Test übersprungen\n";
}

// ── quickinfoBuildMetricRow(): Fehlerfall ─────────────────────────────────────

test_case('quickinfoBuildMetricRow() liefert eine vollständige leere Zeile bei Fehlern', function (): void {
    $row = quickinfoBuildMetricRow(
        qiFail('API-Schlüssel ungültig (401)', 401),
        qiFail('Timeout'),
        qiFail('Timeout')
    );

    assert_keys(QUICKINFO_ROW_KEYS, $row);
    assert_count(count(QUICKINFO_ROW_KEYS), $row);
    assert_false($row['ok']);
    assert_same('API-Schlüssel ungültig (401)', $row['error']);
    assert_same(null, $row['hostname']);
    assert_same(0, $row['gpu_count']);
    assert_same([], $row['gpus']);
});

// ── quickinfoBuildMetricRow(): Statusdaten ────────────────────────────────────

test_case('quickinfoBuildMetricRow() übernimmt Host-, CPU- und RAM-Daten', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk([
            'hostname'     => 'gpu-node-1',
            'stale'        => true,
            'snapshot_age' => 42,
            'cpu'          => ['utilization' => 12.5, 'temperature' => 55.0, 'count' => 8],
            'memory'       => ['used' => 8000, 'total' => 16000, 'pct' => 50.0],
            'uptime'       => 3600,
            'load'         => [0.5, 0.4, 0.3],
            'services'     => ['up' => 3, 'total' => 4],
        ]),
        qiFail('kein /info'),
        qiFail('kein /history')
    );

    assert_true($row['ok']);
    assert_same('', $row['error']);
    assert_same('gpu-node-1', $row['hostname']);
    assert_same(true, $row['stale']);
    assert_same(42, $row['snapshot_age']);
    assert_same(12.5, $row['cpu_util']);
    assert_same(55.0, $row['cpu_temp']);
    assert_same(8, $row['cpu_cores']);
    assert_same(8000, $row['ram_used']);
    assert_same(16000, $row['ram_total']);
    assert_same(50.0, $row['ram_pct']);
    assert_same(3600, $row['uptime']);
    assert_same([0.5, 0.4, 0.3], $row['load']);
    assert_same(3, $row['services_up']);
    assert_same(4, $row['services_total']);
    assert_same(0, $row['gpu_count']);
});

test_case('quickinfoBuildMetricRow() berechnet den RAM-Anteil nach, wenn er fehlt', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk(['memory' => ['used' => 3000, 'total' => 6000]]),
        qiFail('kein /info'),
        qiFail('kein /history')
    );

    assert_same(50.0, $row['ram_pct']);
});

test_case('quickinfoBuildMetricRow() lässt den RAM-Anteil ohne Gesamtwert leer', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk(['memory' => ['used' => 3000]]),
        qiFail('kein /info'),
        qiFail('kein /history')
    );

    assert_same(null, $row['ram_pct']);
});

test_case('quickinfoBuildMetricRow() fasst mehrere GPUs zusammen', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk([
            'gpus' => [
                ['index' => 0, 'name' => 'RTX 4090', 'utilization' => 10, 'temperature' => 60, 'memory_used_mb' => 4000, 'memory_total_mb' => 8000, 'power_w' => 100],
                ['index' => 1, 'name' => 'RTX 4090', 'utilization' => 30, 'temperature' => 70, 'memory_used_mb' => 8000, 'memory_total_mb' => 16000, 'power_w' => 150],
            ],
        ]),
        qiFail('kein /info'),
        qiFail('kein /history')
    );

    assert_same(2, $row['gpu_count']);
    assert_same('RTX 4090', $row['gpu_model']);
    assert_close(20.0, $row['gpu_util'], 0.001, 'Mittelwert der Auslastungen');
    assert_same(70.0, $row['gpu_temp'], 'Die Temperatur ist das Maximum');
    assert_close(250.0, $row['gpu_power_w'], 0.001, 'Summe der Leistungsaufnahme');
    assert_close(12000.0, $row['vram_used_mb'], 0.001);
    assert_close(24000.0, $row['vram_total_mb'], 0.001);
    assert_close(50.0, $row['vram_pct'], 0.001);
    assert_count(2, $row['gpus']);
    assert_same(1, $row['gpus'][1]['index']);
    assert_same(30.0, $row['gpus'][1]['utilization']);
});

test_case('quickinfoBuildMetricRow() lässt GPU-Kennzahlen ohne Werte leer', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk(['gpus' => [['index' => 0, 'name' => 'Tesla']]]),
        qiFail('kein /info'),
        qiFail('kein /history')
    );

    assert_same(1, $row['gpu_count']);
    assert_same('Tesla', $row['gpu_model']);
    assert_same(null, $row['gpu_util']);
    assert_same(null, $row['gpu_temp']);
    assert_same(null, $row['gpu_power_w']);
    assert_same(null, $row['vram_pct'], 'Ohne Gesamt-VRAM gibt es keinen Anteil');
});

test_case('quickinfoBuildMetricRow() ignoriert ein leeres GPU-Array', function (): void {
    $row = quickinfoBuildMetricRow(qiOk(['gpus' => []]), qiFail('x'), qiFail('y'));

    assert_same(0, $row['gpu_count']);
    assert_same([], $row['gpus']);
    assert_same(null, $row['gpu_model']);
});

// ── quickinfoBuildMetricRow(): /info-Ergänzungen ──────────────────────────────

test_case('quickinfoBuildMetricRow() ergänzt fehlende Angaben aus /info', function (): void {
    $row = quickinfoBuildMetricRow(
        qiFail('kein /status'),
        qiOk([
            'hostname'  => 'gpu-node-2',
            'cpu'       => ['model' => 'Ryzen 9', 'cores' => 16],
            'gpu_model' => 'A100',
            'uptime'    => 7200,
        ]),
        qiFail('kein /history')
    );

    assert_same('gpu-node-2', $row['hostname']);
    assert_same('Ryzen 9', $row['cpu_model']);
    assert_same(16, $row['cpu_cores']);
    assert_same('A100', $row['gpu_model']);
    assert_same(7200, $row['uptime']);
});

test_case('quickinfoBuildMetricRow() bevorzugt Live-Werte aus /status vor /info', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk(['hostname' => 'live', 'cpu' => ['count' => 8], 'uptime' => 100]),
        qiOk(['hostname' => 'info', 'cpu' => ['model' => 'X', 'cores' => 16], 'uptime' => 999]),
        qiFail('kein /history')
    );

    assert_same('live', $row['hostname']);
    assert_same(8, $row['cpu_cores']);
    assert_same(100, $row['uptime']);
    assert_same('X', $row['cpu_model'], 'Der CPU-Name kommt nur aus /info');
});

// ── quickinfoBuildMetricRow(): /history-Min/Max ───────────────────────────────

test_case('quickinfoBuildMetricRow() übernimmt die 24h-Spanne aus /history', function (): void {
    $row = quickinfoBuildMetricRow(
        qiFail('kein /status'),
        qiFail('kein /info'),
        qiOk(['series' => [
            'temp.max'   => [[1, 40], [2, 50]],
            'gpu.0.temp' => [[1, 30], [2, 50]],
            'gpu.1.temp' => [[1, 45]],
            'cpu.load'   => [[1, 99]],
        ]])
    );

    assert_same(40.0, $row['cpu_temp_min']);
    assert_same(50.0, $row['cpu_temp_max']);
    assert_same(30.0, $row['gpu_temp_min'], 'Minimum über alle GPUs');
    assert_same(50.0, $row['gpu_temp_max'], 'Maximum über alle GPUs');
});

test_case('quickinfoBuildMetricRow() bezieht den Live-Wert in die 24h-Spanne ein', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk(['cpu' => ['temperature' => 55.0], 'gpus' => [['temperature' => 70]]]),
        qiFail('kein /info'),
        qiOk(['series' => [
            'temp.max'   => [[1, 40], [2, 50]],
            'gpu.0.temp' => [[1, 30], [2, 50]],
        ]])
    );

    assert_same(40.0, $row['cpu_temp_min']);
    assert_same(55.0, $row['cpu_temp_max'], 'Der aktuelle Wert erweitert die Spanne');
    assert_same(30.0, $row['gpu_temp_min']);
    assert_same(70.0, $row['gpu_temp_max'], 'Der aktuelle Wert erweitert die Spanne');
});

test_case('quickinfoBuildMetricRow() setzt die Spanne auf den Live-Wert ohne Historie', function (): void {
    $row = quickinfoBuildMetricRow(
        qiOk(['cpu' => ['temperature' => 55.0], 'gpus' => [['temperature' => 70]]]),
        qiFail('kein /info'),
        qiOk(['series' => 'kein Array'])
    );

    assert_same(55.0, $row['cpu_temp_min']);
    assert_same(55.0, $row['cpu_temp_max']);
    assert_same(70.0, $row['gpu_temp_min']);
    assert_same(70.0, $row['gpu_temp_max']);
});

test_case('quickinfoBuildMetricRow() ignoriert andere Metrik-Reihen in /history', function (): void {
    $row = quickinfoBuildMetricRow(
        qiFail('kein /status'),
        qiFail('kein /info'),
        qiOk(['series' => [
            'gpu.temp'  => [[1, 80]],
            'gpu.0.usage' => [[1, 90]],
            'gpu.12.temp' => [[1, 33]],
        ]])
    );

    assert_same(null, $row['cpu_temp_min']);
    assert_same(null, $row['cpu_temp_max']);
    assert_same(33.0, $row['gpu_temp_min'], 'Nur gpu.<n>.temp zählt');
    assert_same(33.0, $row['gpu_temp_max']);
});

exit(test_summary('test_quickinfo'));
