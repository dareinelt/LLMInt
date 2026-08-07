<?php

/**
 * lib/balancer_engine.php
 *
 * Shared load-balancing / routing engine used by api/balancer.php,
 * api/sd_balancer.php and api/comfy_balancer.php.
 *
 * Provides:
 *   - Configurable limits (max concurrent tasks per endpoint, circuit breaker
 *     thresholds, backoff, orphan-task timeout, routing weights) backed by
 *     the `settings` table so they can be tuned without a deploy.
 *   - Circuit breaker state machine (closed -> open -> half_open -> closed)
 *     with automatic resumption after a cooldown period.
 *   - Exponential backoff with jitter for client-side retry loops.
 *   - Configurable fallback chains (model -> ordered list of substitute models).
 *   - Cleanup of orphaned "running" tasks that never received a final status
 *     (e.g. because the PHP worker crashed or was killed).
 *   - A multi-factor routing score combining current load, latency, cost and
 *     capacity so the least loaded / fastest / cheapest endpoint is preferred.
 *
 * All functions are defensive: DB errors while reading settings fall back to
 * sane defaults so the balancer keeps working even if `settings` is briefly
 * unavailable.
 */

require_once __DIR__ . '/../db.php';

/**
 * Adds the health / circuit-breaker / latency / cost columns required by the
 * balancer engine to an endpoint table. Safe to call repeatedly (idempotent).
 */
function ensureBalancerHealthColumns(PDO $pdo, string $table): void
{
    $alters = [
        "ALTER TABLE {$table} ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active",
        "ALTER TABLE {$table} ADD COLUMN circuit_state ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed' AFTER consecutive_failures",
        "ALTER TABLE {$table} ADD COLUMN circuit_opened_at TIMESTAMP(3) NULL AFTER circuit_state",
        "ALTER TABLE {$table} ADD COLUMN cooldown_until TIMESTAMP(3) NULL AFTER circuit_opened_at",
        "ALTER TABLE {$table} ADD COLUMN avg_latency_ms INT UNSIGNED NULL AFTER cooldown_until",
        "ALTER TABLE {$table} ADD COLUMN last_latency_ms INT UNSIGNED NULL AFTER avg_latency_ms",
        "ALTER TABLE {$table} ADD COLUMN cost_weight DECIMAL(10,4) NOT NULL DEFAULT 1.0000 AFTER last_latency_ms",
        "ALTER TABLE {$table} ADD COLUMN capacity_weight DECIMAL(10,4) NOT NULL DEFAULT 1.0000 AFTER cost_weight",
    ];
    foreach ($alters as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $_e) {
            // Column already exists.
        }
    }
}

/**
 * Balancer-related settings, each with a documented default. All are stored
 * as plain strings in the `settings` table (see getSetting()/setSetting()).
 */
const BALANCER_SETTING_DEFAULTS = [
    // Maximum number of concurrently "running" tasks allowed per endpoint
    // before it is considered full. Previously hard-coded to 4.
    'balancer_max_concurrent'          => '4',
    // Consecutive failures before an endpoint's circuit breaker opens.
    'balancer_circuit_fail_threshold'  => '3',
    // Seconds an open circuit stays closed for new traffic before a single
    // half-open probe request is allowed through again.
    'balancer_circuit_cooldown_seconds' => '30',
    // Base delay (ms) for exponential backoff between retry attempts.
    'balancer_backoff_base_ms'         => '200',
    // Upper bound (ms) for the backoff delay, regardless of attempt count.
    'balancer_backoff_max_ms'          => '8000',
    // Whether to add random jitter to the computed backoff delay ('1'/'0').
    'balancer_backoff_jitter'          => '1',
    // Seconds after which a task still marked "running" is considered
    // orphaned (its worker probably crashed) and is force-completed as error.
    'balancer_orphan_timeout_seconds'  => '300',
    // Relative weights (0..1) used when scoring candidate endpoints.
    'balancer_weight_latency'          => '0.35',
    'balancer_weight_cost'             => '0.25',
    'balancer_weight_capacity'         => '0.40',
    // JSON object mapping a requested model name to an ordered array of
    // fallback model names, e.g. {"big-model:70b": ["big-model:34b", "big-model:8b"]}
    'balancer_fallback_chains'         => '{}',
];

/**
 * Reads a balancer setting, returning its documented default when unset.
 */
function getBalancerSetting(string $key): string
{
    $default = BALANCER_SETTING_DEFAULTS[$key] ?? '';
    try {
        return getSetting($key, $default);
    } catch (Throwable $_e) {
        return $default;
    }
}

function getBalancerMaxConcurrent(): int
{
    $value = (int) getBalancerSetting('balancer_max_concurrent');
    return $value > 0 ? $value : 4;
}

function getBalancerCircuitFailThreshold(): int
{
    $value = (int) getBalancerSetting('balancer_circuit_fail_threshold');
    return $value > 0 ? $value : 3;
}

function getBalancerCircuitCooldownSeconds(): int
{
    $value = (int) getBalancerSetting('balancer_circuit_cooldown_seconds');
    return $value > 0 ? $value : 30;
}

function getBalancerOrphanTimeoutSeconds(): int
{
    $value = (int) getBalancerSetting('balancer_orphan_timeout_seconds');
    return $value > 0 ? $value : 300;
}

/**
 * Computes an exponential backoff delay (milliseconds) with optional jitter,
 * suitable for use before retrying a failed request against another endpoint.
 *
 * @param int $attempt 1-based retry attempt number (1 = first retry).
 */
function computeBackoffDelayMs(int $attempt): int
{
    $base   = max(1, (int) getBalancerSetting('balancer_backoff_base_ms'));
    $max    = max($base, (int) getBalancerSetting('balancer_backoff_max_ms'));
    $jitter = getBalancerSetting('balancer_backoff_jitter') === '1';

    $attempt = max(1, $attempt);
    // Exponential growth, capped to avoid integer overflow on large attempt counts.
    $exponent = min($attempt - 1, 20);
    $delay = (int) min($max, $base * (2 ** $exponent));

    if ($jitter) {
        // Full jitter strategy: uniformly random between 0 and the computed delay.
        $delay = (int) random_int(0, max(1, $delay));
    }

    return max(0, $delay);
}

/**
 * Sleeps for the computed backoff delay of the given attempt. Split out from
 * computeBackoffDelayMs() so callers/tests can compute the delay without
 * actually blocking.
 */
function backoffSleep(int $attempt): void
{
    $ms = computeBackoffDelayMs($attempt);
    if ($ms > 0) {
        usleep($ms * 1000);
    }
}

/**
 * Returns the configured fallback chain for a model: an ordered list of
 * substitute model names to try, in order, when the original model has no
 * available capacity. Returns an empty array when nothing is configured.
 */
function getFallbackChain(string $model): array
{
    if ($model === '') {
        return [];
    }
    $json = getBalancerSetting('balancer_fallback_chains');
    $map = json_decode($json, true);
    if (!is_array($map) || !isset($map[$model]) || !is_array($map[$model])) {
        return [];
    }

    $chain = [];
    foreach ($map[$model] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && $candidate !== $model && !in_array($candidate, $chain, true)) {
            $chain[] = $candidate;
        }
    }

    return $chain;
}

/**
 * Persists the fallback-chain configuration (model => [fallback models...]).
 */
function saveFallbackChains(array $map): void
{
    $clean = [];
    foreach ($map as $model => $chain) {
        $model = trim((string) $model);
        if ($model === '' || !is_array($chain)) {
            continue;
        }
        $cleanChain = [];
        foreach ($chain as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && $candidate !== $model) {
                $cleanChain[] = $candidate;
            }
        }
        if (!empty($cleanChain)) {
            $clean[$model] = array_values(array_unique($cleanChain));
        }
    }
    setSetting('balancer_fallback_chains', json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Records the outcome (success/failure) of a request against an endpoint and
 * drives the circuit breaker state machine:
 *   - closed:    normal operation. A failure increments a counter; once the
 *                counter reaches the configured threshold the circuit opens.
 *   - open:      the endpoint is skipped by the router until the cooldown
 *                period elapses, at which point a single probe request is
 *                allowed through (transition to half_open happens lazily in
 *                the routing query, see engine query helpers below).
 *   - half_open: a probe request is in flight. Success closes the circuit
 *                (resets failure counter); failure re-opens it and restarts
 *                the cooldown timer.
 *
 * @param string     $table      Endpoint table name (endpoints|sd_endpoints|comfy_endpoints).
 * @param int        $endpointId Endpoint primary key.
 * @param bool       $success    Whether the request completed successfully.
 * @param float|null $latencyMs  Observed latency in milliseconds, if known.
 */
function recordEndpointOutcome(string $table, int $endpointId, bool $success, ?float $latencyMs = null): void
{
    if ($endpointId <= 0 || !in_array($table, ['endpoints', 'sd_endpoints', 'comfy_endpoints'], true)) {
        return;
    }

    $db = getDb();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT circuit_state, consecutive_failures, avg_latency_ms FROM {$table} WHERE id = ? FOR UPDATE");
        $stmt->execute([$endpointId]);
        $current = $stmt->fetch();
        if ($current === false) {
            $db->rollBack();
            return;
        }

        $failThreshold = getBalancerCircuitFailThreshold();
        $cooldownSeconds = getBalancerCircuitCooldownSeconds();

        $newLatencyAvg = null;
        if ($latencyMs !== null && $latencyMs >= 0) {
            $prevAvg = $current['avg_latency_ms'];
            // Exponential moving average (alpha = 0.3) smooths out spikes
            // while still reacting reasonably quickly to sustained changes.
            $newLatencyAvg = $prevAvg === null
                ? (int) round($latencyMs)
                : (int) round(0.3 * $latencyMs + 0.7 * (float) $prevAvg);
        }

        if ($success) {
            // Successful request: close the circuit and reset the failure streak.
            $sql = "UPDATE {$table}
                       SET consecutive_failures = 0,
                           circuit_state = 'closed',
                           circuit_opened_at = NULL,
                           cooldown_until = NULL"
                 . ($latencyMs !== null ? ", last_latency_ms = ?, avg_latency_ms = ?" : "")
                 . " WHERE id = ?";
            $params = $latencyMs !== null
                ? [(int) round($latencyMs), $newLatencyAvg, $endpointId]
                : [$endpointId];
            $db->prepare($sql)->execute($params);
        } else {
            $failures = (int) $current['consecutive_failures'] + 1;
            if ($failures >= $failThreshold) {
                $db->prepare("
                    UPDATE {$table}
                       SET consecutive_failures = ?,
                           circuit_state = 'open',
                           circuit_opened_at = NOW(3),
                           cooldown_until = DATE_ADD(NOW(3), INTERVAL ? SECOND)
                     WHERE id = ?
                ")->execute([$failures, $cooldownSeconds, $endpointId]);
            } else {
                $db->prepare("
                    UPDATE {$table} SET consecutive_failures = ? WHERE id = ?
                ")->execute([$failures, $endpointId]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // Health bookkeeping must never break the calling request.
    }
}

/**
 * SQL fragment (to be embedded in a WHERE clause on alias `e`) that excludes
 * endpoints whose circuit breaker is open and still within its cooldown
 * window. Endpoints in 'closed' or 'half_open' state, and endpoints whose
 * cooldown has elapsed (eligible for a half-open probe), are allowed through.
 */
function balancerCircuitWhereClause(): string
{
    return "(e.circuit_state <> 'open' OR e.cooldown_until IS NULL OR e.cooldown_until <= NOW(3))";
}

/**
 * Transitions an endpoint whose cooldown has elapsed from 'open' to
 * 'half_open' so that exactly one probe request is dispatched to verify
 * recovery before the circuit fully closes again.
 */
function maybeHalfOpenCircuit(string $table, int $endpointId): void
{
    if ($endpointId <= 0) {
        return;
    }
    try {
        getDb()->prepare("
            UPDATE {$table}
               SET circuit_state = 'half_open'
             WHERE id = ?
               AND circuit_state = 'open'
               AND cooldown_until IS NOT NULL
               AND cooldown_until <= NOW(3)
        ")->execute([$endpointId]);
    } catch (Throwable $_e) {
        // Best effort.
    }
}

/**
 * Marks tasks that have been stuck in 'running' state for longer than the
 * configured orphan timeout as 'error', freeing up the slot they occupy.
 * This handles crashed PHP workers / killed requests that never reached
 * their completeTask()/completeSdTask()/completeComfyTask() call.
 *
 * Runs probabilistically (not on every call) to keep the overhead low; pass
 * $force = true to always run (e.g. from a maintenance script).
 *
 * @return int Number of orphaned tasks cleaned up.
 */
function cleanupOrphanedTasks(string $tasksTable, bool $force = false): int
{
    if (!$force && mt_rand(1, 20) !== 1) {
        return 0;
    }
    if (!in_array($tasksTable, ['tasks', 'sd_tasks', 'comfy_tasks'], true)) {
        return 0;
    }

    $timeoutSeconds = getBalancerOrphanTimeoutSeconds();

    $endpointsTable = [
        'tasks'       => 'endpoints',
        'sd_tasks'    => 'sd_endpoints',
        'comfy_tasks' => 'comfy_endpoints',
    ][$tasksTable];

    try {
        $db = getDb();
        $stmt = $db->prepare("
            SELECT id, endpoint_id FROM {$tasksTable}
             WHERE status = 'running'
               AND started_at < DATE_SUB(NOW(3), INTERVAL ? SECOND)
        ");
        $stmt->execute([$timeoutSeconds]);
        $orphans = $stmt->fetchAll();
        if (empty($orphans)) {
            return 0;
        }

        $ids = array_map(static fn($row) => (int) $row['id'], $orphans);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("
            UPDATE {$tasksTable} SET status = 'error', finished_at = NOW(3)
             WHERE id IN ({$placeholders})
        ")->execute($ids);

        foreach ($orphans as $row) {
            recordEndpointOutcome($endpointsTable, (int) $row['endpoint_id'], false);
        }

        return count($ids);
    } catch (Throwable $_e) {
        return 0;
    }
}
