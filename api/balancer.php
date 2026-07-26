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
