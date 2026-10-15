<?php
declare(strict_types=1);

/**
 * tests/php/test_balancer_engine.php
 *
 * Unit tests for the pure configuration and SQL-fragment helpers of
 * lib/balancer_engine.php. The balancer spreads requests over the endpoints of
 * a model, so a regression in the defaults, in the fairness ordering or in the
 * backoff calculation affects every routed request.
 *
 * Without a database getBalancerSetting() returns the documented defaults;
 * exactly that state is verified here. No database, no HTTP, no network.
 *
 * Run from the repository root:  php tests/php/test_balancer_engine.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/balancer_engine.php';

// ── Standardwerte ─────────────────────────────────────────────────────────────

test_case('BALANCER_SETTING_DEFAULTS enthält alle dokumentierten Schlüssel', function (): void {
    assert_keys([
        'balancer_max_concurrent',
        'balancer_circuit_fail_threshold',
        'balancer_circuit_cooldown_seconds',
        'balancer_backoff_base_ms',
        'balancer_backoff_max_ms',
        'balancer_backoff_jitter',
        'balancer_orphan_timeout_seconds',
        'balancer_fallback_chains',
    ], BALANCER_SETTING_DEFAULTS);
});

test_case('getBalancerMaxConcurrent() liefert 4 als Standard', function (): void {
    assert_same(4, getBalancerMaxConcurrent());
});

test_case('getBalancerCircuitFailThreshold() liefert 3 als Standard', function (): void {
    assert_same(3, getBalancerCircuitFailThreshold());
});

test_case('getBalancerCircuitCooldownSeconds() liefert 30 als Standard', function (): void {
    assert_same(30, getBalancerCircuitCooldownSeconds());
});

test_case('getBalancerOrphanTimeoutSeconds() liefert 300 als Standard', function (): void {
    assert_same(300, getBalancerOrphanTimeoutSeconds());
});

test_case('getBalancerFairnessWindowSeconds() liefert 900 als Standard', function (): void {
    assert_same(900, getBalancerFairnessWindowSeconds());
});

test_case('getBalancerSetting() liefert für unbekannte Schlüssel einen leeren String', function (): void {
    assert_same('', getBalancerSetting('gibt_es_nicht'));
});

test_case('getBalancerSetting() gibt die Standardwerte unverändert zurück', function (): void {
    foreach (BALANCER_SETTING_DEFAULTS as $key => $default) {
        assert_same($default, getBalancerSetting($key), 'Schlüssel ' . $key);
    }
});

// ── SQL-Bausteine ─────────────────────────────────────────────────────────────

test_case('balancerLoadJoinSql() akzeptiert nur die bekannten Aufgabentabellen', function (): void {
    foreach (['tasks', 'sd_tasks', 'comfy_tasks'] as $table) {
        $sql = balancerLoadJoinSql($table);
        assert_contains($table, $sql, 'Die Tabelle ' . $table . ' steht in der Abfrage');
    }
});

test_case('balancerLoadJoinSql() wirft bei unbekannter Tabelle eine InvalidArgumentException', function (): void {
    try {
        balancerLoadJoinSql('tasks; DROP TABLE users');
        assert_true(false, 'Unbekannte Tabellennamen müssen abgelehnt werden');
    } catch (InvalidArgumentException $e) {
        assert_contains('Unknown tasks table', $e->getMessage());
    }
});

test_case('balancerLoadJoinSql() verwendet das Fairness-Fenster aus den Einstellungen', function (): void {
    $sql = balancerLoadJoinSql('tasks');
    assert_contains('INTERVAL ' . getBalancerFairnessWindowSeconds() . ' SECOND', $sql);
    assert_contains('running_count', $sql);
    assert_contains('recent_task_count', $sql);
    assert_contains('last_task_at', $sql);
    assert_contains('ON r.endpoint_id = e.id', $sql);
});

test_case('balancerFairnessOrderBySql() sortiert nach Last, Fairness, Alter und ID', function (): void {
    $order = balancerFairnessOrderBySql();

    // Reihenfolge der Kriterien ist die eigentliche Zusicherung dieser Funktion.
    $expected = [
        'COALESCE(r.running_count, 0) ASC',
        'COALESCE(r.recent_task_count, 0) ASC',
        'CASE WHEN r.last_task_at IS NULL THEN 0 ELSE 1 END ASC',
        'r.last_task_at ASC',
        'e.id ASC',
    ];
    $previous = -1;
    foreach ($expected as $criterion) {
        $position = strpos($order, $criterion);
        assert_true($position !== false, 'Kriterium fehlt: ' . $criterion);
        assert_true($position > $previous, 'Kriterium steht an falscher Stelle: ' . $criterion);
        $previous = $position;
    }
});

test_case('balancerFairnessOrderBySql() enthält keine Latenz mehr', function (): void {
    // Die Latenz wurde bewusst aus der Sortierung entfernt (siehe Kommentar in
    // lib/balancer_engine.php); sie darf nicht zurückkehren.
    assert_not_contains('latency', balancerFairnessOrderBySql());
});

test_case('balancerCircuitWhereClause() lässt offene Schaltkreise nach Ablauf des Cooldowns zu', function (): void {
    $where = balancerCircuitWhereClause();
    assert_contains("e.circuit_state <> 'open'", $where);
    assert_contains('e.cooldown_until IS NULL', $where);
    assert_contains('e.cooldown_until <= NOW(3)', $where);
    assert_contains(' OR ', $where, 'Die Bedingungen sind ODER-verknüpft');
});

// ── computeBackoffDelayMs() ───────────────────────────────────────────────────

test_case('computeBackoffDelayMs() respektiert die Obergrenze für jeden Versuch', function (): void {
    $base = (int) BALANCER_SETTING_DEFAULTS['balancer_backoff_base_ms'];
    $max  = (int) BALANCER_SETTING_DEFAULTS['balancer_backoff_max_ms'];

    foreach ([1, 2, 3, 5, 10, 20, 50, 1000] as $attempt) {
        $upper = min($max, $base * (2 ** min($attempt - 1, 20)));
        for ($i = 0; $i < 20; $i++) {
            $delay = computeBackoffDelayMs($attempt);
            assert_true($delay >= 0, 'Verzögerung ist nie negativ');
            assert_true($delay <= $upper, 'Versuch ' . $attempt . ': ' . $delay . ' > ' . $upper);
        }
    }
});

test_case('computeBackoffDelayMs() wächst exponentiell und bleibt gedeckelt', function (): void {
    $max = (int) BALANCER_SETTING_DEFAULTS['balancer_backoff_max_ms'];

    // Voll-Jitter: die Obergrenze selbst ist nicht monoton messbar, aber die
    // Deckelung darf nie überschritten werden – auch nicht bei sehr vielen
    // Versuchen (Schutz vor Überlauf).
    for ($i = 0; $i < 50; $i++) {
        assert_true(computeBackoffDelayMs(1000) <= $max);
    }
});

test_case('computeBackoffDelayMs() behandelt Versuch 0 wie Versuch 1', function (): void {
    $base = (int) BALANCER_SETTING_DEFAULTS['balancer_backoff_base_ms'];
    for ($i = 0; $i < 20; $i++) {
        assert_true(computeBackoffDelayMs(0) <= $base);
    }
});

test_case('computeBackoffDelayMs() liefert einen ganzzahligen Wert', function (): void {
    assert_true(is_int(computeBackoffDelayMs(3)));
});

exit(test_summary('test_balancer_engine'));
