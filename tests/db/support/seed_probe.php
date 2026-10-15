<?php
declare(strict_types=1);

/**
 * tests/db/support/seed_probe.php
 *
 * Hilfsprozess für tests/db/test_llm_healthcheck.php.
 *
 * ensureRuntimeSchema() baut das Laufzeitschema nur beim ersten getDb() eines
 * Prozesses auf (static $bootstrapped) und legt dabei genau einmal einen
 * Vorgabe-Endpunkt an. In-process ist dieser Erstaufbau deshalb nicht
 * wiederholbar; dieser Prozess übernimmt ihn und meldet den Zustand der
 * Endpunkt-Tabelle als JSON.
 *
 * Aufruf:  php tests/db/support/seed_probe.php
 * Ausgabe: {"endpoints":[...],"flag":"..."}
 */

require __DIR__ . '/../../../db.php';

$pdo = getDb();

echo json_encode(
    [
        'endpoints' => $pdo->query(
            'SELECT base_url, timeout, is_active, sort_order FROM endpoints ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC),
        'flag' => getSetting('endpoints_bootstrapped', ''),
    ],
    JSON_UNESCAPED_SLASHES
);
