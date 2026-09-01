<?php

/**
 * api/comfy_balancer.php
 *
 * Load-balancing / routing engine for ComfyUI endpoints.
 * Shares its circuit-breaker, backoff, orphan-cleanup and scoring logic with
 * api/balancer.php and api/sd_balancer.php via lib/balancer_engine.php.
 *
 * Selection rules (applied in order):
 *   1. Only active comfy_endpoints whose circuit breaker is not open.
 *   2. Only endpoints with fewer than the configured maximum currently-running
 *      comfy_tasks (`balancer_max_concurrent` setting, previously hard-coded to 4).
 *   3. Prefer the endpoint with the best routing score (load, latency).
 *   4. Among equally-scored endpoints, prefer the one that received a task
 *      least recently (round-robin effect).
 *
 * Task lifecycle is recorded atomically inside a DB transaction, reserving
 * the slot only after locking the candidate endpoint row with
 * SELECT ... FOR UPDATE and re-checking its running-task count.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/balancer_engine.php';

/**
 * Pick the best available ComfyUI endpoint, register a new task, and return an
 * associative array: ['endpoint' => <row>, 'task_id' => int]
 *
 * Returns null when no endpoint with available capacity exists.
 *
 * @param int|null $maxConcurrent Maximum running tasks allowed per endpoint.
 *                                Defaults to the `balancer_max_concurrent` setting.
 * @throws PDOException on database errors
 */
function pickComfyEndpoint(?int $maxConcurrent = null): ?array
{
    cleanupOrphanedTasks('comfy_tasks');

    $maxConcurrent = $maxConcurrent !== null && $maxConcurrent > 0
        ? $maxConcurrent
        : getBalancerMaxConcurrent();

    $db = getDb();
    $circuitWhere = balancerCircuitWhereClause();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT e.id, e.base_url, e.timeout, e.default_checkpoint,
                   e.circuit_state, e.cooldown_until,
                   e.avg_latency_ms,
                   COALESCE(r.running_count, 0) AS running_count,
                   r.last_task_at
            FROM comfy_endpoints e
            LEFT JOIN (
                SELECT endpoint_id,
                       COUNT(*)        AS running_count,
                       MAX(started_at) AS last_task_at
                  FROM comfy_tasks
                 WHERE status = 'running'
                 GROUP BY endpoint_id
            ) r ON r.endpoint_id = e.id
            WHERE e.is_active = 1
              AND COALESCE(r.running_count, 0) < ?
              AND {$circuitWhere}
            ORDER BY
                COALESCE(r.running_count, 0) ASC,
                COALESCE(e.avg_latency_ms, 999999) ASC,
                CASE WHEN r.last_task_at IS NULL THEN 0 ELSE 1 END ASC,
                r.last_task_at ASC
            LIMIT 8
        ");
        $stmt->execute([
            $maxConcurrent,
        ]);
        $candidates = $stmt->fetchAll();

        if (empty($candidates)) {
            $db->rollBack();
            return null;
        }

        foreach ($candidates as $endpoint) {
            $endpointId = (int) $endpoint['id'];

            $lockStmt = $db->prepare('SELECT id, is_active FROM comfy_endpoints WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$endpointId]);
            $locked = $lockStmt->fetch();
            if ($locked === false || (int) $locked['is_active'] !== 1) {
                continue;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM comfy_tasks WHERE endpoint_id = ? AND status = 'running'");
            $countStmt->execute([$endpointId]);
            $runningCount = (int) $countStmt->fetchColumn();
            if ($runningCount >= $maxConcurrent) {
                continue;
            }

            maybeHalfOpenCircuit('comfy_endpoints', $endpointId);

            $db->prepare(
                "INSERT INTO comfy_tasks (endpoint_id, status) VALUES (?, 'running')"
            )->execute([$endpointId]);

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
 * Mark a ComfyUI task as finished.
 *
 * @param int        $taskId    Primary key of the comfy_tasks row.
 * @param string     $status    'done' or 'error'.
 * @param float|null $latencyMs Observed request latency in milliseconds.
 */
function completeComfyTask(int $taskId, string $status = 'done', ?float $latencyMs = null): void
{
    $db = getDb();
    $db->prepare('
        UPDATE comfy_tasks SET status = ?, finished_at = NOW(3) WHERE id = ?
    ')->execute([$status, $taskId]);

    try {
        $epStmt = $db->prepare('SELECT endpoint_id FROM comfy_tasks WHERE id = ?');
        $epStmt->execute([$taskId]);
        $endpointId = (int) $epStmt->fetchColumn();
        if ($endpointId > 0) {
            recordEndpointOutcome('comfy_endpoints', $endpointId, $status === 'done', $latencyMs);
        }
    } catch (Throwable $_e) {
        // Health bookkeeping must never break the caller.
    }
}
