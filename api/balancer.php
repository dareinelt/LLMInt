<?php

/**
 * api/balancer.php
 *
 * Load-balancing logic for multi-endpoint routing.
 *
 * Endpoints are grouped by their `default_model` value.
 * Selection rules (applied in order):
 *   1. Only active endpoints whose `default_model` matches the requested model.
 *   2. Only endpoints with fewer than 4 currently-running tasks.
 *   3. Prefer the endpoint with the fewest running tasks (least-loaded first).
 *   4. Among equally-loaded endpoints, prefer the one that received a task
 *      least recently (round-robin effect: after A was used, B goes next).
 *      Endpoints that have never received a task sort before used ones.
 *
 * Task lifecycle is recorded atomically inside a DB transaction to prevent
 * double-booking under concurrent requests.
 */

require_once __DIR__ . '/../db.php';

/**
 * Pick the best available endpoint for the given model, register a new task,
 * and return an associative array:
 *   ['endpoint' => <row>, 'task_id' => int]
 *
 * Returns null when no endpoint with available capacity exists for the model.
 *
 * @throws PDOException on database errors
 */
function pickEndpointForModel(string $model): ?array
{
    $db = getDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            SELECT e.id, e.base_url, e.timeout, e.default_model,
                   COALESCE(r.running_count, 0) AS running_count,
                   r.last_task_at
            FROM endpoints e
            LEFT JOIN (
                SELECT endpoint_id,
                       COUNT(*)        AS running_count,
                       MAX(started_at) AS last_task_at
                  FROM tasks
                 WHERE status = \'running\'
                 GROUP BY endpoint_id
            ) r ON r.endpoint_id = e.id
            WHERE e.is_active = 1
              AND e.default_model = ?
              AND COALESCE(r.running_count, 0) < 4
            ORDER BY
                COALESCE(r.running_count, 0) ASC,
                CASE WHEN r.last_task_at IS NULL THEN 0 ELSE 1 END ASC,
                r.last_task_at ASC
            LIMIT 1
        ');
        $stmt->execute([$model]);
        $endpoint = $stmt->fetch();

        if (!$endpoint) {
            $db->rollBack();
            return null;
        }

        $db->prepare(
            "INSERT INTO tasks (endpoint_id, model, status) VALUES (?, ?, 'running')"
        )->execute([$endpoint['id'], $model]);

        $taskId = (int) $db->lastInsertId();
        $db->commit();

        return ['endpoint' => $endpoint, 'task_id' => $taskId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Returns true when at least one active endpoint exists for the model.
 */
function hasActiveEndpointForModel(string $model): bool
{
    $stmt = getDb()->prepare('
        SELECT COUNT(*)
          FROM endpoints
         WHERE is_active = 1
           AND default_model = ?
    ');
    $stmt->execute([$model]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Extracts a model "intelligence" value from common model names (e.g. 1b, 4b, 11b, 27b).
 * Returns null when no such marker can be found.
 */
function extractModelIntelligenceB(string $model): ?float
{
    if ($model === '') {
        return null;
    }

    if (!preg_match_all('/(\d+(?:[.,]\d+)?)\s*b\b/i', $model, $matches) || empty($matches[1])) {
        return null;
    }

    $best = null;
    foreach ($matches[1] as $raw) {
        $num = (float) str_replace(',', '.', $raw);
        if ($num <= 0) {
            continue;
        }
        if ($best === null || $num > $best) {
            $best = $num;
        }
    }

    return $best;
}

/**
 * Returns a larger model with free capacity (if available) for a given requested model.
 * Shape:
 *   [
 *     'requested_model' => string,
 *     'requested_intelligence' => float,
 *     'model' => string,
 *     'suggested_intelligence' => float,
 *     'free_endpoints' => int
 *   ]
 */
function getUpgradeModelSuggestionForRequestedModel(string $requestedModel): ?array
{
    $requestedIntelligence = extractModelIntelligenceB($requestedModel);
    if ($requestedIntelligence === null) {
        return null;
    }

    $stmt = getDb()->query('
        SELECT
            e.default_model,
            SUM(CASE WHEN COALESCE(r.running_count, 0) < 4 THEN 1 ELSE 0 END) AS free_endpoints
        FROM endpoints e
        LEFT JOIN (
            SELECT endpoint_id, COUNT(*) AS running_count
            FROM tasks
            WHERE status = \'running\'
            GROUP BY endpoint_id
        ) r ON r.endpoint_id = e.id
        WHERE e.is_active = 1
          AND e.default_model <> \'\'
        GROUP BY e.default_model
    ');

    $bestModel = null;
    $bestIntelligence = null;
    $bestFreeEndpoints = 0;

    foreach ($stmt->fetchAll() as $row) {
        $candidateModel = (string) ($row['default_model'] ?? '');
        $freeEndpoints  = (int) ($row['free_endpoints'] ?? 0);
        if ($candidateModel === '' || $freeEndpoints <= 0) {
            continue;
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
 */
function completeTask(
    int    $taskId,
    string $status           = 'done',
    ?int   $promptTokens     = null,
    ?int   $completionTokens = null,
    ?int   $totalTokens      = null
): void {
    getDb()->prepare('
        UPDATE tasks
           SET status            = ?,
               finished_at       = NOW(3),
               prompt_tokens     = ?,
               completion_tokens = ?,
               total_tokens      = ?
         WHERE id = ?
    ')->execute([$status, $promptTokens, $completionTokens, $totalTokens, $taskId]);
}
