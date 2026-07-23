<?php

/**
 * api/comfy_balancer.php
 *
 * Load-balancing logic for ComfyUI endpoints.
 *
 * Selection rules (applied in order):
 *   1. Only active comfy_endpoints.
 *   2. Only endpoints with fewer than 4 currently-running comfy_tasks.
 *   3. Prefer the endpoint with the fewest running tasks (least-loaded first).
 *   4. Among equally-loaded endpoints, prefer the one that received a task
 *      least recently (round-robin effect).
 *
 * Task lifecycle is recorded atomically inside a DB transaction.
 */

require_once __DIR__ . '/../db.php';

/**
 * Pick the best available ComfyUI endpoint, register a new task, and return an
 * associative array: ['endpoint' => <row>, 'task_id' => int]
 *
 * Returns null when no endpoint with available capacity exists.
 *
 * @throws PDOException on database errors
 */
function pickComfyEndpoint(): ?array
{
    $db = getDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            SELECT e.id, e.base_url, e.timeout, e.default_checkpoint,
                   COALESCE(r.running_count, 0) AS running_count,
                   r.last_task_at
            FROM comfy_endpoints e
            LEFT JOIN (
                SELECT endpoint_id,
                       COUNT(*)        AS running_count,
                       MAX(started_at) AS last_task_at
                  FROM comfy_tasks
                 WHERE status = \'running\'
                 GROUP BY endpoint_id
            ) r ON r.endpoint_id = e.id
            WHERE e.is_active = 1
              AND COALESCE(r.running_count, 0) < 4
            ORDER BY
                COALESCE(r.running_count, 0) ASC,
                CASE WHEN r.last_task_at IS NULL THEN 0 ELSE 1 END ASC,
                r.last_task_at ASC
            LIMIT 1
        ');
        $stmt->execute();
        $endpoint = $stmt->fetch();

        if (!$endpoint) {
            $db->rollBack();
            return null;
        }

        $db->prepare(
            "INSERT INTO comfy_tasks (endpoint_id, status) VALUES (?, 'running')"
        )->execute([$endpoint['id']]);

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
 * Mark a ComfyUI task as finished.
 *
 * @param int    $taskId  Primary key of the comfy_tasks row.
 * @param string $status  'done' or 'error'.
 */
function completeComfyTask(int $taskId, string $status = 'done'): void
{
    getDb()->prepare('
        UPDATE comfy_tasks SET status = ?, finished_at = NOW(3) WHERE id = ?
    ')->execute([$status, $taskId]);
}
