<?php

/**
 * api/balancer.php
 *
 * Load-balancing / routing engine for multi-endpoint LLM routing.
 *
 * Endpoints are grouped by their `default_model` value. Endpoints whose
 * `default_model` differs only in path/extension/quantisation (see
 * canonicalModelName() / equivalentActiveModelNames() in db.php) are treated
 * as serving the same model and pooled together, so differently deployed but
 * functionally identical endpoints share load instead of being routed
 * independently.
 * Selection rules (applied in order):
 *   1. Only active endpoints whose `default_model` is equivalent to the
 *      requested model (exact match or same canonical model name) and whose
 *      circuit breaker is not currently open (see lib/balancer_engine.php).
 *   2. Optionally, only endpoints that support tool-calling ($requireToolCalling).
 *   3. Only endpoints with fewer than $maxConcurrent currently-running tasks
 *      (configurable via the `balancer_max_concurrent` setting; previously
 *      hard-coded to 4).
 *   4. Prefer the endpoint with the best routing score, combining current
 *      load (running task count) and average latency.
 *   5. Among equally-scored endpoints, prefer the one that received a task
 *      least recently (round-robin effect). Endpoints that have never
 *      received a task sort before used ones.
 *
 * Task lifecycle is recorded atomically inside a DB transaction. The chosen
 * endpoint row is locked with SELECT ... FOR UPDATE before the reservation is
 * finalised, and the running-task count is re-checked under that lock so
 * concurrent requests can never double-book the same slot (resilient,
 * race-free reservation instead of relying purely on transaction isolation).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/balancer_engine.php';

/**
 * Pick the best available endpoint for the given model, register a new task,
 * and return an associative array:
 *   ['endpoint' => <row>, 'task_id' => int]
 *
 * Returns null when no endpoint with available capacity exists for the model.
 *
 * @param string   $model             The model name to route to.
 * @param int|null $maxConcurrent     Maximum number of running tasks allowed per
 *                                    endpoint before it is considered full.
 *                                    Defaults to the `balancer_max_concurrent`
 *                                    setting (4 unless configured otherwise).
 *                                    Pass a lower value to reserve slots per
 *                                    endpoint (e.g. for routing-decision calls
 *                                    when the decision model is also used for
 *                                    regular user requests).
 * @param bool     $requireToolCalling When true, only endpoints whose model
 *                                    supports OpenAI-style tool calling are
 *                                    considered (model-capability routing).
 * @param bool     $requireVision     When true, only endpoints marked as
 *                                    vision-capable (accepting "image_url"
 *                                    content parts) are considered.
 *
 * @throws PDOException on database errors
 */
function pickEndpointForModel(
    string $model,
    ?int $maxConcurrent = null,
    bool $requireToolCalling = false,
    bool $requireVision = false
): ?array {
    cleanupOrphanedTasks('tasks');

    $maxConcurrent = $maxConcurrent !== null && $maxConcurrent > 0
        ? $maxConcurrent
        : getBalancerMaxConcurrent();

    $db = getDb();
    $circuitWhere = balancerCircuitWhereClause();
    $toolCallingWhere = $requireToolCalling ? 'AND e.supports_tool_calling = 1' : '';
    $visionWhere = $requireVision ? 'AND e.supports_vision = 1' : '';

    // Endpoints serving the same model under a different label (e.g. a short
    // repo-style name vs. a full quantised file name) are functionally
    // equivalent and pooled together for routing, not just for display.
    $modelPool = equivalentActiveModelNames($model);
    $modelPlaceholders = implode(',', array_fill(0, count($modelPool), '?'));

    $db->beginTransaction();
    try {
        // Step 1: gather scored candidates (read-committed snapshot).
        $stmt = $db->prepare("
            SELECT e.id, e.alias, e.base_url, e.timeout, e.default_model,
                   e.supports_tool_calling, e.is_llamacpp, e.reasoning_effort, e.supports_vision,
                   e.circuit_state, e.cooldown_until,
                   e.avg_latency_ms,
                   e.max_context, e.context_limit_per_slot,
                   COALESCE(r.running_count, 0) AS running_count,
                   r.last_task_at
            FROM endpoints e
            LEFT JOIN (
                SELECT endpoint_id,
                       COUNT(*)        AS running_count,
                       MAX(started_at) AS last_task_at
                  FROM tasks
                 WHERE status = 'running'
                 GROUP BY endpoint_id
            ) r ON r.endpoint_id = e.id
            WHERE e.is_active = 1
              AND e.default_model IN ({$modelPlaceholders})
              AND COALESCE(r.running_count, 0) < ?
              AND {$circuitWhere}
              {$toolCallingWhere}
              {$visionWhere}
            ORDER BY
                COALESCE(r.running_count, 0) ASC,
                COALESCE(e.avg_latency_ms, 999999) ASC,
                CASE WHEN r.last_task_at IS NULL THEN 0 ELSE 1 END ASC,
                r.last_task_at ASC
            LIMIT 8
        ");
        $stmt->execute([
            ...$modelPool,
            $maxConcurrent,
        ]);
        $candidates = $stmt->fetchAll();

        if (empty($candidates)) {
            $db->rollBack();
            return null;
        }

        // Step 2: atomically reserve a slot by locking each candidate row in
        // turn and re-checking its running-task count under that lock. The
        // first candidate that still has room wins; INSERT happens while the
        // lock is held so no other transaction can race us for the same slot.
        foreach ($candidates as $endpoint) {
            $endpointId = (int) $endpoint['id'];

            $lockStmt = $db->prepare('SELECT id, is_active FROM endpoints WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$endpointId]);
            $locked = $lockStmt->fetch();
            if ($locked === false || (int) $locked['is_active'] !== 1) {
                continue;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE endpoint_id = ? AND status = 'running'");
            $countStmt->execute([$endpointId]);
            $runningCount = (int) $countStmt->fetchColumn();
            if ($runningCount >= $maxConcurrent) {
                continue;
            }

            maybeHalfOpenCircuit('endpoints', $endpointId);

            $db->prepare(
                "INSERT INTO tasks (endpoint_id, model, status) VALUES (?, ?, 'running')"
            )->execute([$endpointId, $model]);

            $taskId = (int) $db->lastInsertId();
            $db->commit();

            return ['endpoint' => $endpoint, 'task_id' => $taskId];
        }

        $db->rollBack();
        return null;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Returns true when at least one active endpoint exists for the model.
 *
 * @param bool $requireVision When true, only vision-capable endpoints count.
 */
function hasActiveEndpointForModel(string $model, bool $requireVision = false): bool
{
    $visionWhere = $requireVision ? 'AND supports_vision = 1' : '';
    $modelPool = equivalentActiveModelNames($model);
    $modelPlaceholders = implode(',', array_fill(0, count($modelPool), '?'));
    $stmt = getDb()->prepare("
        SELECT COUNT(*)
          FROM endpoints
         WHERE is_active = 1
           AND default_model IN ({$modelPlaceholders})
           {$visionWhere}
    ");
    $stmt->execute($modelPool);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Extracts a model "intelligence" value from common model names (e.g. 1b, 4b, 11b, 27b).
 * MoE active-parameter markers (A17B, A3B, A4B, …) are ignored – only the total
 * parameter count is used. Returns null when no such marker can be found.
 */
function extractModelIntelligenceB(string $model): ?float
{
    return modelIntelligenceScore($model);
}

/**
 * Returns a larger model with free capacity (if available) for a given requested model.
 *
 * When $detectedCategory is non-empty, endpoints specialised for a *different*
 * category are excluded so that, e.g., a dedicated coding model is never
 * suggested as an intelligence upgrade for a general-conversation request.
 * When $detectedCategory is empty, all specialised endpoints are excluded and
 * only general-purpose endpoints (specialized_for_category = '') are considered.
 *
 * Shape:
 *   [
 *     'requested_model' => string,
 *     'requested_intelligence' => float,
 *     'model' => string,
 *     'suggested_intelligence' => float,
 *     'free_endpoints' => int
 *   ]
 */
function getUpgradeModelSuggestionForRequestedModel(string $requestedModel, string $detectedCategory = ''): ?array
{
    $requestedIntelligence = extractModelIntelligenceB($requestedModel);
    if ($requestedIntelligence === null) {
        return null;
    }

    $maxConcurrent = getBalancerMaxConcurrent();
    $circuitWhere = balancerCircuitWhereClause();

    $stmt = getDb()->prepare("
        SELECT
            e.default_model,
            MAX(CASE WHEN e.specialized_for_category <> '' THEN e.specialized_for_category ELSE NULL END) AS specialized_for_category,
            SUM(CASE WHEN COALESCE(r.running_count, 0) < ? AND {$circuitWhere} THEN 1 ELSE 0 END) AS free_endpoints
        FROM endpoints e
        LEFT JOIN (
            SELECT endpoint_id, COUNT(*) AS running_count
            FROM tasks
            WHERE status = 'running'
            GROUP BY endpoint_id
        ) r ON r.endpoint_id = e.id
        WHERE e.is_active = 1
          AND e.default_model <> ''
        GROUP BY e.default_model
    ");
    $stmt->execute([$maxConcurrent]);

    $bestModel = null;
    $bestIntelligence = null;
    $bestFreeEndpoints = 0;

    foreach ($stmt->fetchAll() as $row) {
        $candidateModel    = (string) ($row['default_model'] ?? '');
        $freeEndpoints     = (int) ($row['free_endpoints'] ?? 0);
        $specializedFor    = (string) ($row['specialized_for_category'] ?? '');
        if ($candidateModel === '' || $freeEndpoints <= 0) {
            continue;
        }

        // Skip models that are specialised for a different category than the
        // one detected for the current request.  When no category was detected,
        // skip every specialised model (only general-purpose models are eligible).
        if ($specializedFor !== '') {
            if ($detectedCategory === '' || $specializedFor !== $detectedCategory) {
                continue;
            }
        }

        $candidateIntelligence = extractModelIntelligenceB($candidateModel);
        if ($candidateIntelligence === null || $candidateIntelligence <= $requestedIntelligence) {
            continue;
        }

        if (
            $bestModel === null
            || $candidateIntelligence < $bestIntelligence
            || ($candidateIntelligence === $bestIntelligence && $freeEndpoints > $bestFreeEndpoints)
        ) {
            $bestModel = $candidateModel;
            $bestIntelligence = $candidateIntelligence;
            $bestFreeEndpoints = $freeEndpoints;
        }
    }

    if ($bestModel === null) {
        return null;
    }

    return [
        'requested_model' => $requestedModel,
        'requested_intelligence' => $requestedIntelligence,
        'model' => $bestModel,
        'suggested_intelligence' => $bestIntelligence,
        'free_endpoints' => $bestFreeEndpoints,
    ];
}

/**
 * Mark a task as finished and store optional token usage counts.
 *
 * @param int    $taskId           Primary key of the task row.
 * @param string $status           'done' or 'error'.
 * @param int|null $promptTokens   Tokens used for the prompt.
 * @param int|null $completionTokens Tokens in the completion.
 * @param int|null $totalTokens    Total tokens (prompt + completion).
 * @param float|null $latencyMs    Observed request latency in milliseconds,
 *                                 used to update the endpoint's rolling
 *                                 average latency for latency-based routing.
 * @param float|null $tokensPerSecond Token generation speed (tokens/sec),
 *                                    measured from the first streamed token
 *                                    to completion. NULL when not measurable
 *                                    (e.g. non-streaming requests).
 */
function completeTask(
    int    $taskId,
    string $status           = 'done',
    ?int   $promptTokens     = null,
    ?int   $completionTokens = null,
    ?int   $totalTokens      = null,
    ?float $latencyMs        = null,
    ?float $tokensPerSecond  = null
): void {
    $db = getDb();
    $db->prepare('
        UPDATE tasks
           SET status            = ?,
               finished_at       = NOW(3),
               prompt_tokens     = ?,
               completion_tokens = ?,
               total_tokens      = ?,
               tokens_per_second = ?
         WHERE id = ?
    ')->execute([$status, $promptTokens, $completionTokens, $totalTokens, $tokensPerSecond, $taskId]);

    try {
        $epStmt = $db->prepare('SELECT endpoint_id FROM tasks WHERE id = ?');
        $epStmt->execute([$taskId]);
        $endpointId = (int) $epStmt->fetchColumn();
        if ($endpointId > 0) {
            recordEndpointOutcome('endpoints', $endpointId, $status === 'done', $latencyMs);
        }
    } catch (Throwable $_e) {
        // Health bookkeeping must never break the caller.
    }
}
