<?php

/**
 * lib/healthcheck.php
 *
 * Active liveness probing of the configured LLM endpoints, used to decide
 * whether the chat UI can be shown (see index.php) or whether the app must
 * fall back to a maintenance-mode page because no backend is reachable.
 *
 * Unlike the balancer's circuit breaker (lib/balancer_engine.php), which only
 * reacts to failures observed during real chat traffic, this performs an
 * active GET /models probe against every active endpoint, so a freshly
 * started / idle install still detects a downed backend before a user tries
 * to chat.
 *
 * Probe results are cached in the `settings` table for a short TTL so that
 * many concurrent page loads share a single round of probes instead of each
 * triggering its own set of outgoing requests.
 */

require_once __DIR__ . '/../db.php';

const LLM_HEALTHCHECK_CACHE_KEY             = 'llm_healthcheck_cache';
const LLM_HEALTHCHECK_CACHE_TTL_SECONDS     = 10;
const LLM_HEALTHCHECK_PROBE_TIMEOUT_SECONDS = 3;

/**
 * Actively probes every active LLM endpoint's `/models` route in parallel
 * and returns per-endpoint results. Bypasses the settings cache – callers
 * that only need a cached, cheap answer should use isAnyLlmEndpointHealthy().
 *
 * @return array<int, array{id:int, alias:string, ok:bool}>
 */
function probeLlmEndpoints(int $timeoutSeconds = LLM_HEALTHCHECK_PROBE_TIMEOUT_SECONDS): array
{
    try {
        $endpoints = getDb()->query(
            "SELECT id, alias, base_url FROM endpoints WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_e) {
        // endpoints table may not exist yet (before setup.php has run).
        return [];
    }

    if (empty($endpoints)) {
        return [];
    }

    $timeoutSeconds = max(1, $timeoutSeconds);
    $multi = curl_multi_init();
    $handles = [];

    foreach ($endpoints as $ep) {
        $url = rtrim((string) $ep['base_url'], '/') . '/models';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[(int) $ep['id']] = ['handle' => $ch, 'alias' => (string) $ep['alias']];
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            // A -1 return means the select() call itself failed (e.g. an
            // interrupted system call); avoid busy-looping in that case.
            if (curl_multi_select($multi, $timeoutSeconds) === -1) {
                usleep(1000);
            }
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $id => $h) {
        $httpCode = (int) curl_getinfo($h['handle'], CURLINFO_HTTP_CODE);
        $results[] = [
            'id'    => $id,
            'alias' => $h['alias'],
            'ok'    => $httpCode >= 200 && $httpCode < 300,
        ];
        curl_multi_remove_handle($multi, $h['handle']);
        curl_close($h['handle']);
    }
    curl_multi_close($multi);

    return $results;
}

/**
 * Returns whether at least one active LLM endpoint currently responds to a
 * health probe. Results are cached for LLM_HEALTHCHECK_CACHE_TTL_SECONDS so
 * that many concurrent requests (e.g. simultaneous page loads) share a
 * single round of probes instead of each blocking on their own curl calls.
 */
function isAnyLlmEndpointHealthy(): bool
{
    $cached = json_decode(getSetting(LLM_HEALTHCHECK_CACHE_KEY, ''), true);
    if (is_array($cached) && isset($cached['ts'], $cached['available'])
        && (time() - (int) $cached['ts']) < LLM_HEALTHCHECK_CACHE_TTL_SECONDS) {
        return (bool) $cached['available'];
    }

    $results = probeLlmEndpoints();
    $available = in_array(true, array_column($results, 'ok'), true);

    try {
        setSetting(LLM_HEALTHCHECK_CACHE_KEY, json_encode([
            'ts'        => time(),
            'available' => $available,
            'endpoints' => $results,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $_e) {
        // Caching is best-effort; a failure here must not block rendering.
    }

    return $available;
}
