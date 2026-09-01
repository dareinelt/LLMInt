<?php

/**
 * api/healthcheck.php
 *
 * Public JSON endpoint reporting whether at least one active LLM endpoint
 * currently responds to a health probe (see lib/healthcheck.php). Used by
 * the maintenance-mode page (index.php) to periodically poll for recovery
 * without requiring a full page reload.
 *
 * No authentication is required; only aggregate availability (no endpoint
 * URLs or credentials) is exposed.
 *
 * Response (JSON): { "ok": true, "available": <bool> }
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/healthcheck.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(['ok' => true, 'available' => isAnyLlmEndpointHealthy()], JSON_UNESCAPED_UNICODE);
