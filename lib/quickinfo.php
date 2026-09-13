<?php

/**
 * lib/quickinfo.php
 *
 * Client for the read-only Management-Board API (/api/v1) of quickinfo
 * (https://github.com/dareinelt/quickinfo). Each LLM endpoint can be paired
 * with one quickinfo instance (URL + Bearer API key). The admin page
 * admin/endpoint_tech.php uses this library for pairing tests and the JSON
 * endpoint admin/quickinfo_stats.php uses it to collect live metrics for
 * all paired endpoints in parallel.
 */

declare(strict_types=1);

/** Metrics requested from /api/v1/history for the 24h min/max temperatures. */
const QUICKINFO_HISTORY_METRICS = 'temp.max,gpu.0.temp,gpu.1.temp,gpu.2.temp,gpu.3.temp,gpu.4.temp,gpu.5.temp,gpu.6.temp,gpu.7.temp';

/**
 * Normalises a user-supplied quickinfo URL: adds https:// when no scheme is
 * given, strips trailing slashes and a trailing "/api/v1" path.
 * Returns '' when the URL is not usable.
 */
function quickinfoNormalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    $url = rtrim($url, '/');
    $url = preg_replace('~/api(/v1)?$~i', '', $url) ?? $url;
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return '';
    }
    return $url;
}

/**
 * Builds a configured curl handle for a quickinfo API v1 request.
 */
function quickinfoCurlHandle(string $baseUrl, string $apiKey, string $path, bool $verifyTls, int $timeout)
{
    $ch = curl_init($baseUrl . '/api/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    return $ch;
}

/**
 * Interprets a finished curl handle.
 *
 * @return array{ok:bool, http:int, data:?array, error:string}
 */
function quickinfoReadHandle($ch, $body): array
{
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    if ($body === false || $body === null || $http === 0) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => $curlErr !== '' ? $curlErr : 'Keine Verbindung / keine Antwort'];
    }
    $json = json_decode((string) $body, true);
    if ($http === 401) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'API-Schlüssel ungültig (401)'];
    }
    if ($http === 429) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'Zu viele Fehlversuche – quickinfo blockiert vorübergehend (429)'];
    }
    if ($http < 200 || $http >= 300) {
        $msg = is_array($json) && isset($json['error']) ? (string) $json['error'] : ($curlErr !== '' ? $curlErr : 'HTTP ' . $http);
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => $msg];
    }
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'Antwort ist kein JSON'];
    }
    return ['ok' => true, 'http' => $http, 'data' => $json, 'error' => ''];
}

/**
 * Performs a single synchronous request against one quickinfo instance.
 *
 * @return array{ok:bool, http:int, data:?array, error:string}
 */
function quickinfoRequest(string $baseUrl, string $apiKey, string $path, bool $verifyTls = false, int $timeout = 6): array
{
    $ch = quickinfoCurlHandle($baseUrl, $apiKey, $path, $verifyTls, max(1, $timeout));
    $body = curl_exec($ch);
    return quickinfoReadHandle($ch, $body);
}

/**
 * Tests a pairing: fetches /api/v1/info and returns a human-readable result.
 *
 * @return array{ok:bool, message:string, info:?array}
 */
function quickinfoTestPairing(string $baseUrl, string $apiKey, bool $verifyTls = false): array
{
    $baseUrl = quickinfoNormalizeUrl($baseUrl);
    if ($baseUrl === '') {
        return ['ok' => false, 'message' => 'Ungültige quickinfo-URL.', 'info' => null];
    }
    if (trim($apiKey) === '') {
        return ['ok' => false, 'message' => 'API-Schlüssel fehlt.', 'info' => null];
    }
    $res = quickinfoRequest($baseUrl, $apiKey, 'info', $verifyTls);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => 'Verbindung fehlgeschlagen: ' . $res['error'], 'info' => null];
    }
    $info = $res['data'];
    $host = (string) ($info['hostname'] ?? '?');
    $ver  = (string) ($info['version'] ?? '');
    return [
        'ok'      => true,
        'message' => sprintf('Gekoppelt mit %s%s.', $host, $ver !== '' ? ' (quickinfo ' . $ver . ')' : ''),
        'info'    => $info,
    ];
}

/**
 * Computes min/max of a quickinfo history series ([[ts, value], …]).
 *
 * @return array{min:?float, max:?float}
 */
function quickinfoSeriesMinMax($series): array
{
    $min = null;
    $max = null;
    if (!is_array($series)) {
        return ['min' => null, 'max' => null];
    }
    foreach ($series as $point) {
        $v = is_array($point) ? ($point[1] ?? null) : $point;
        if ($v === null || !is_numeric($v)) {
            continue;
        }
        $v = (float) $v;
        $min = $min === null ? $v : min($min, $v);
        $max = $max === null ? $v : max($max, $v);
    }
    return ['min' => $min, 'max' => $max];
}

/**
 * Collects live metrics (status, info, 24h temperature history) from all
 * paired quickinfo instances in parallel.
 *
 * @param array<int, array{id:int, quickinfo_url:string, quickinfo_api_key:string, quickinfo_verify_tls:bool}> $endpoints
 * @return array<int, array> keyed by endpoint id
 */
function quickinfoCollectMetrics(array $endpoints, int $timeout = 6): array
{
    $timeout = max(1, $timeout);
    $multi = curl_multi_init();
    $handles = []; // [epId][kind] => handle

    foreach ($endpoints as $ep) {
        $url = quickinfoNormalizeUrl((string) $ep['quickinfo_url']);
        $key = (string) ($ep['quickinfo_api_key'] ?? '');
        if ($url === '' || $key === '') {
            continue;
        }
        $verify = !empty($ep['quickinfo_verify_tls']);
        $id = (int) $ep['id'];
        foreach ([
            'status'  => 'status',
            'info'    => 'info',
            'history' => 'history?range=24h&metrics=' . QUICKINFO_HISTORY_METRICS,
        ] as $kind => $path) {
            $ch = quickinfoCurlHandle($url, $key, $path, $verify, $timeout);
            curl_multi_add_handle($multi, $ch);
            $handles[$id][$kind] = $ch;
        }
    }

    if ($handles) {
        $running = null;
        do {
            $mstat = curl_multi_exec($multi, $running);
            if ($running && curl_multi_select($multi, 1.0) === -1) {
                usleep(1000);
            }
        } while ($running && $mstat === CURLM_OK);
    }

    $out = [];
    foreach ($handles as $id => $byKind) {
        $res = [];
        foreach ($byKind as $kind => $ch) {
            $res[$kind] = quickinfoReadHandle($ch, curl_multi_getcontent($ch));
            curl_multi_remove_handle($multi, $ch);
        }
        $out[$id] = quickinfoBuildMetricRow($res['status'], $res['info'], $res['history']);
    }
    curl_multi_close($multi);

    return $out;
}

/**
 * Merges the three API responses into one flat metrics row for the UI.
 */
function quickinfoBuildMetricRow(array $status, array $info, array $history): array
{
    $row = [
        'ok'           => $status['ok'],
        'error'        => $status['ok'] ? '' : $status['error'],
        'hostname'     => null,
        'stale'        => null,
        'snapshot_age' => null,
        'cpu_util'     => null,
        'cpu_temp'     => null,
        'cpu_temp_min' => null,
        'cpu_temp_max' => null,
        'cpu_model'    => null,
        'cpu_cores'    => null,
        'ram_used'     => null,
        'ram_total'    => null,
        'ram_pct'      => null,
        'gpu_model'    => null,
        'gpu_count'    => 0,
        'gpu_util'     => null,
        'gpu_temp'     => null,
        'gpu_temp_min' => null,
        'gpu_temp_max' => null,
        'vram_used_mb' => null,
        'vram_total_mb'=> null,
        'vram_pct'     => null,
        'gpu_power_w'  => null,
        'gpus'         => [],
        'uptime'       => null,
        'load'         => null,
        'services_up'  => null,
        'services_total' => null,
    ];

    if ($status['ok'] && is_array($status['data'])) {
        $s = $status['data'];
        $row['hostname']     = $s['hostname'] ?? null;
        $row['stale']        = (bool) ($s['stale'] ?? false);
        $row['snapshot_age'] = isset($s['snapshot_age']) ? (int) $s['snapshot_age'] : null;
        $row['cpu_util']     = isset($s['cpu']['utilization']) ? (float) $s['cpu']['utilization'] : null;
        $row['cpu_temp']     = isset($s['cpu']['temperature']) ? (float) $s['cpu']['temperature'] : null;
        $row['cpu_cores']    = isset($s['cpu']['count']) ? (int) $s['cpu']['count'] : null;
        if (!empty($s['memory']) && is_array($s['memory'])) {
            $row['ram_used']  = isset($s['memory']['used'])  ? (int) $s['memory']['used']  : null;
            $row['ram_total'] = isset($s['memory']['total']) ? (int) $s['memory']['total'] : null;
            $row['ram_pct']   = isset($s['memory']['pct'])   ? (float) $s['memory']['pct'] : null;
            if ($row['ram_pct'] === null && $row['ram_total'] > 0 && $row['ram_used'] !== null) {
                $row['ram_pct'] = round($row['ram_used'] / $row['ram_total'] * 100, 1);
            }
        }
        $row['uptime'] = isset($s['uptime']) ? (int) $s['uptime'] : null;
        $row['load']   = $s['load'] ?? null;
        if (!empty($s['services']) && is_array($s['services'])) {
            $row['services_up']    = isset($s['services']['up'])    ? (int) $s['services']['up']    : null;
            $row['services_total'] = isset($s['services']['total']) ? (int) $s['services']['total'] : null;
        }

        $gpus = is_array($s['gpus'] ?? null) ? $s['gpus'] : [];
        $row['gpu_count'] = count($gpus);
        if ($gpus) {
            $utils = [];
            $temps = [];
            $power = 0.0;
            $hasPower = false;
            $vramUsed = 0.0;
            $vramTotal = 0.0;
            foreach ($gpus as $g) {
                $row['gpus'][] = [
                    'index'        => (int) ($g['index'] ?? 0),
                    'name'         => (string) ($g['name'] ?? ''),
                    'utilization'  => isset($g['utilization']) ? (float) $g['utilization'] : null,
                    'temperature'  => isset($g['temperature']) ? (float) $g['temperature'] : null,
                    'memory_used_mb'  => isset($g['memory_used_mb'])  ? (float) $g['memory_used_mb']  : null,
                    'memory_total_mb' => isset($g['memory_total_mb']) ? (float) $g['memory_total_mb'] : null,
                    'memory_pct'   => isset($g['memory_pct']) ? (float) $g['memory_pct'] : null,
                    'power_w'      => isset($g['power_w']) ? (float) $g['power_w'] : null,
                ];
                if (isset($g['utilization'])) { $utils[] = (float) $g['utilization']; }
                if (isset($g['temperature'])) { $temps[] = (float) $g['temperature']; }
                if (isset($g['power_w'])) { $power += (float) $g['power_w']; $hasPower = true; }
                if (isset($g['memory_used_mb']))  { $vramUsed  += (float) $g['memory_used_mb']; }
                if (isset($g['memory_total_mb'])) { $vramTotal += (float) $g['memory_total_mb']; }
            }
            $row['gpu_model']   = (string) ($gpus[0]['name'] ?? '') ?: null;
            $row['gpu_util']    = $utils ? round(array_sum($utils) / count($utils), 1) : null;
            $row['gpu_temp']    = $temps ? max($temps) : null;
            $row['gpu_power_w'] = $hasPower ? round($power, 1) : null;
            if ($vramTotal > 0) {
                $row['vram_used_mb']  = round($vramUsed);
                $row['vram_total_mb'] = round($vramTotal);
                $row['vram_pct']      = round($vramUsed / $vramTotal * 100, 1);
            }
        }
    }

    if ($info['ok'] && is_array($info['data'])) {
        $i = $info['data'];
        $row['hostname']  = $row['hostname'] ?? ($i['hostname'] ?? null);
        $row['cpu_model'] = $i['cpu']['model'] ?? null;
        $row['cpu_cores'] = $row['cpu_cores'] ?? (isset($i['cpu']['cores']) ? (int) $i['cpu']['cores'] : null);
        if ($row['gpu_model'] === null && !empty($i['gpu_model'])) {
            $row['gpu_model'] = (string) $i['gpu_model'];
        }
        $row['uptime'] = $row['uptime'] ?? (isset($i['uptime']) ? (int) $i['uptime'] : null);
    }

    if ($history['ok'] && is_array($history['data'])) {
        $series = is_array($history['data']['series'] ?? null) ? $history['data']['series'] : [];
        $cpu = quickinfoSeriesMinMax($series['temp.max'] ?? null);
        $row['cpu_temp_min'] = $cpu['min'];
        $row['cpu_temp_max'] = $cpu['max'];

        $gMin = null;
        $gMax = null;
        foreach ($series as $metric => $points) {
            if (!preg_match('~^gpu\.\d+\.temp$~', (string) $metric)) {
                continue;
            }
            $mm = quickinfoSeriesMinMax($points);
            if ($mm['min'] !== null) { $gMin = $gMin === null ? $mm['min'] : min($gMin, $mm['min']); }
            if ($mm['max'] !== null) { $gMax = $gMax === null ? $mm['max'] : max($gMax, $mm['max']); }
        }
        $row['gpu_temp_min'] = $gMin;
        $row['gpu_temp_max'] = $gMax;
    }

    // Include the live value in the 24h range so min/max never contradict the current reading.
    foreach (['cpu', 'gpu'] as $k) {
        $cur = $row[$k . '_temp'];
        if ($cur === null) {
            continue;
        }
        $row[$k . '_temp_min'] = $row[$k . '_temp_min'] === null ? $cur : min($row[$k . '_temp_min'], $cur);
        $row[$k . '_temp_max'] = $row[$k . '_temp_max'] === null ? $cur : max($row[$k . '_temp_max'], $cur);
    }

    return $row;
}
