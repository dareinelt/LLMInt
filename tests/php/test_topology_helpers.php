<?php
declare(strict_types=1);

/**
 * tests/php/test_topology_helpers.php
 *
 * Unit tests for the pure helpers of lib/topology.php. No database, no HTTP,
 * no network: the file only loads the translation layer and the shared
 * functions it needs.
 *
 * Run from the repository root:  php tests/php/test_topology_helpers.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../lib/topology.php';

// ── State vocabulary ──────────────────────────────────────────────────────────

test_case('topology_kinds() kennt alle Knotenarten des Renderers', function (): void {
    $kinds = topology_kinds();
    assert_same([
        'clients', 'client', 'hub', 'llm', 'model', 'endpoint', 'search', 'vector', 'image', 'speech',
    ], $kinds);
    assert_same($kinds, array_values(array_unique($kinds)), 'Knotenarten sind eindeutig');
});

test_case('topology_state_labels() liefert die vier Zustände', function (): void {
    assert_same([
        'ok'    => 'In Ordnung',
        'warn'  => 'Eingeschränkt',
        'error' => 'Störung',
        'off'   => 'Nicht aktiv',
    ], topology_state_labels());
});

test_case('topology_state_label() fällt bei unbekanntem Zustand auf "Nicht aktiv" zurück', function (): void {
    assert_same('In Ordnung', topology_state_label('ok'));
    assert_same('Eingeschränkt', topology_state_label('warn'));
    assert_same('Störung', topology_state_label('error'));
    assert_same('Nicht aktiv', topology_state_label('off'));
    assert_same('Nicht aktiv', topology_state_label('quatsch'));
    assert_same('Nicht aktiv', topology_state_label(''));
});

test_case('topology_state_rank() ordnet Störung > Warnung > deaktiviert > unbekannt', function (): void {
    assert_same(3, topology_state_rank('error'));
    assert_same(2, topology_state_rank('warn'));
    assert_same(1, topology_state_rank('off'));
    assert_same(0, topology_state_rank('ok'));
    assert_same(0, topology_state_rank('unbekannt'));
});

// ── Aggregation ───────────────────────────────────────────────────────────────

test_case('topology_aggregate_state() meldet eine leere Liste als "ok"', function (): void {
    assert_same('ok', topology_aggregate_state([]));
});

test_case('topology_aggregate_state() ignoriert "off", solange etwas anderes läuft', function (): void {
    assert_same('ok', topology_aggregate_state(['ok', 'off', 'ok']));
    assert_same('warn', topology_aggregate_state(['ok', 'off', 'warn']));
    assert_same('error', topology_aggregate_state(['warn', 'off', 'error']));
    assert_same('error', topology_aggregate_state(['error', 'warn']));
});

test_case('topology_aggregate_state() meldet "off", wenn wirklich alles deaktiviert ist', function (): void {
    assert_same('off', topology_aggregate_state(['off']));
    assert_same('off', topology_aggregate_state(['off', 'off', 'off']));
});

// ── Flow intensity ────────────────────────────────────────────────────────────

test_case('topology_activity() klemmt auf 0 … 12', function (): void {
    assert_same(0, topology_activity(-5));
    assert_same(0, topology_activity(0));
    assert_same(5, topology_activity(5));
    assert_same(12, topology_activity(12));
    assert_same(12, topology_activity(13));
    assert_same(12, topology_activity(100));
});

test_case('topology_activity() beachtet eine abweichende Obergrenze', function (): void {
    assert_same(3, topology_activity(5, 3));
    assert_same(0, topology_activity(-1, 3));
    assert_same(3, topology_activity(3, 3));
});

// ── Number formatting ─────────────────────────────────────────────────────────

test_case('topology_format_num() kürzt Tausender und Millionen', function (): void {
    assert_same('999', topology_format_num(999));
    assert_same('1.0K', topology_format_num(1000));
    assert_same('3.5K', topology_format_num(3512));
    assert_same('340.0K', topology_format_num(340000));
    assert_same('1000.0K', topology_format_num(999999), 'kein Aufrunden in die nächste Einheit');
    assert_same('1.3M', topology_format_num(1250000));
    assert_same('1.6M', topology_format_num(1590000));
});

test_case('topology_format_num() verträgt 0, negative und leere Werte', function (): void {
    assert_same('0', topology_format_num(0));
    assert_same('-5', topology_format_num(-5));
    assert_same('0', topology_format_num(''));
    assert_same('0', topology_format_num(null));
    assert_same('7', topology_format_num('7'));
});

// ── Facts ─────────────────────────────────────────────────────────────────────

test_case('topology_fact() erzeugt eine Detailkarten-Zeile mit Standard-Ton', function (): void {
    assert_same(['icon' => '▶', 'label' => 'Laufend', 'value' => '4', 'tone' => 'plain'], topology_fact('▶', 'Laufend', '4'));
    assert_same(['icon' => '▶', 'label' => 'Laufend', 'value' => '4', 'tone' => 'running'], topology_fact('▶', 'Laufend', '4', 'running'));
});

test_case('topology_temp_tone() stuft Temperaturen ein', function (): void {
    assert_same('muted', topology_temp_tone(null));
    assert_same('muted', topology_temp_tone(''));
    assert_same('temp', topology_temp_tone(0));
    assert_same('temp', topology_temp_tone(64.9));
    assert_same('warn', topology_temp_tone(65));
    assert_same('warn', topology_temp_tone(79.9));
    assert_same('error', topology_temp_tone(80));
    assert_same('error', topology_temp_tone(80.1));
    assert_same('warn', topology_temp_tone('72.5'), 'numerische Strings werden akzeptiert');
    assert_same('temp', topology_temp_tone('kalt'), 'unlesbare Werte gelten als unkritisch');
});

test_case('topology_short_url() kürzt auf Host und Port', function (): void {
    assert_same('a:8080', topology_short_url('http://a:8080/v1'));
    assert_same('example.com', topology_short_url('https://example.com/p'));
    assert_same('example.com:9000', topology_short_url('example.com:9000'));
    assert_same('[::1]:8080', topology_short_url('http://[::1]:8080/v1'));
    assert_same('', topology_short_url('  '), 'reine Leerzeichen ergeben einen leeren Wert');
    assert_same('', topology_short_url(''));
    assert_same('nicht-parsebar', topology_short_url('nicht-parsebar'));
});

// ── Model parameter size ──────────────────────────────────────────────────────

test_case('topology_param_size_label() findet die größte Parameterangabe', function (): void {
    assert_same('27b', topology_param_size_label('gemma-3-27b-it-Q4_K_M'));
    assert_same('27b', topology_param_size_label('/models/gemma-3-27b-it-Q5_K_S.gguf'));
    assert_same('8b', topology_param_size_label('llama-3-8b'));
    assert_same('24b', topology_param_size_label('mistral-small-24b-instruct-2501'));
    assert_same('120b', topology_param_size_label('gpt-oss-120b'));
    assert_same('7.5b', topology_param_size_label('model-7,5b-x'), 'Dezimalkomma wird übernommen');
});

test_case('topology_param_size_label() nutzt den MoE-Marker nur als Rückfall', function (): void {
    assert_same('3b', topology_param_size_label('qwen3-a3b'));
    assert_same('17b', topology_param_size_label('A17B'));
    assert_same('27b', topology_param_size_label('gemma-3-27b-it-a4b-Q4_K_M'), 'Gesamtgröße schlägt Aktiv-Parameter');
});

test_case('topology_param_size_label() meldet Unbekanntes als Gedankenstrich', function (): void {
    assert_same('–', topology_param_size_label(''));
    assert_same('–', topology_param_size_label('   '));
    assert_same('–', topology_param_size_label('foo'));
    assert_same('–', topology_param_size_label('Q4_K_M'));
    assert_same('–', topology_param_size_label('a0b'), '0b ist keine sinnvolle Größe');
});

// ── Client labels ─────────────────────────────────────────────────────────────

test_case('topology_client_label() bevorzugt den Hostnamen', function (): void {
    assert_same('ws-anna', topology_client_label(['hostname' => 'ws-anna', 'ip' => '10.0.0.11'], 1));
    assert_same('short.example.internal', topology_client_label(['hostname' => 'short.example.internal', 'ip' => '10.0.0.11'], 1));
});

test_case('topology_client_label() kürzt nur lange, qualifizierte Hostnamen', function (): void {
    assert_same('ws-ein-sehr-langer-rechner', topology_client_label(['hostname' => 'ws-ein-sehr-langer-rechner.example.internal'], 1));
    assert_same('ein-sehr-langer-name-ohne-punkt', topology_client_label(['hostname' => 'ein-sehr-langer-name-ohne-punkt'], 1), 'ohne Punkt bleibt der Name');
    assert_same('a.b.c', topology_client_label(['hostname' => 'a.b.c'], 1), 'kurze Namen bleiben vollständig');
});

test_case('topology_client_label() fällt auf IP und Platzhalter zurück', function (): void {
    assert_same('10.0.0.9', topology_client_label(['hostname' => '   ', 'ip' => '10.0.0.9'], 1));
    assert_same('10.0.0.9', topology_client_label(['ip' => '10.0.0.9'], 1));
    assert_same('unbekannt', topology_client_label([], 1));
    assert_same('unbekannt 3', topology_client_label([], 3));
});

// ── Laufende Anfrage ──────────────────────────────────────────────────────────

test_case('topology_route_label() benennt jede Art von Anfrage', function (): void {
    assert_same('Laufende Anfrage', topology_route_label('llm'));
    assert_same('Laufende Bildgenerierung', topology_route_label('image'));
    assert_same('Laufende Spracherkennung', topology_route_label('speech'));
    assert_same('Laufende Websuche', topology_route_label('search'));
    assert_same('Laufende Anfrage', topology_route_label('unbekannt'), 'unbekannte Arten fallen auf den Standard zurück');
});

// ── Node defaults ─────────────────────────────────────────────────────────────

test_case('topology_node() füllt alle Felder, die der Renderer erwartet', function (): void {
    $node = topology_node([]);
    assert_same([
        'key'        => '',
        'kind'       => 'endpoint',
        'title'      => '',
        'subtitle'   => '',
        'state'      => 'off',
        'badge'      => '',
        'message'    => '',
        'activity'   => 0,
        'counters'   => null,
        'facts'      => [],
        'slots'      => null,
        'cloud'      => null,
        'members'    => null,
        'neighbours' => [],
        'route'      => null,
        'url'        => '',
        'synthetic'  => false,
    ], $node);
});

test_case('topology_node() behält gesetzte Werte und ergänzt nur Fehlendes', function (): void {
    $node = topology_node(['key' => 'ep:1', 'kind' => 'endpoint', 'state' => 'warn', 'facts' => [['icon' => '▶']], 'slots' => ['used' => 4, 'max' => 4]]);
    assert_same('ep:1', $node['key']);
    assert_same('warn', $node['state']);
    assert_same([['icon' => '▶']], $node['facts']);
    assert_same(['used' => 4, 'max' => 4], $node['slots']);
    assert_same('', $node['subtitle'], 'fehlende Felder werden ergänzt');
    assert_same(false, $node['synthetic']);
});

// ── Endpoint state ────────────────────────────────────────────────────────────

test_case('topology_endpoint_state() erkennt deaktivierte Endpunkte', function (): void {
    assert_same(['off', 'Endpunkt ist deaktiviert.'], topology_endpoint_state(false, 9, 4));
    assert_same(['off', 'Endpunkt ist deaktiviert.'], topology_endpoint_state(false, 0, 4));
});

test_case('topology_endpoint_state() warnt bei voll belegten Slots', function (): void {
    assert_same(['warn', 'Alle 4 Parallel-Slots belegt.'], topology_endpoint_state(true, 4, 4));
    assert_same(['warn', 'Alle 4 Parallel-Slots belegt.'], topology_endpoint_state(true, 5, 4));
    assert_same(['warn', 'Alle 2 Parallel-Slots belegt.'], topology_endpoint_state(true, 2, 2));
});

test_case('topology_endpoint_state() ist unauffällig bei freien Slots oder ohne Limit', function (): void {
    assert_same(['ok', ''], topology_endpoint_state(true, 3, 4));
    assert_same(['ok', ''], topology_endpoint_state(true, 0, 4));
    assert_same(['ok', ''], topology_endpoint_state(true, 1, 0), 'ein Limit von 0 bedeutet: nicht begrenzt');
    assert_same(['ok', ''], topology_endpoint_state(true, 99, 0));
});

// ── Initial payload ───────────────────────────────────────────────────────────

test_case('topology_initial_script() liefert ohne Knoten nichts', function (): void {
    assert_same('', topology_initial_script([]));
    assert_same('', topology_initial_script(['nodes' => []]));
});

test_case('topology_initial_script() bettet den Zustand als JSON-Insel ein', function (): void {
    $html = topology_initial_script(['nodes' => ['hub' => ['title' => 'LLMInt']]]);
    assert_contains('<script type="application/json" data-flow-initial>', $html);
    assert_contains('</script>', $html);
    assert_contains('"hub"', $html);
    assert_contains('LLMInt', $html);
});

test_case('topology_initial_script() maskiert HTML-Sonderzeichen', function (): void {
    $html = topology_initial_script(['nodes' => ['a' => ['title' => '</script>&<x>']]]);
    assert_not_contains('</script>&', $html, 'ein schließendes Script-Tag darf den Block nicht beenden');
    assert_contains('\u003C/script\u003E', $html);
    assert_contains('\u0026', $html);
    assert_same(1, substr_count($html, '<script'), 'nur der umschließende Block beginnt ein Script-Tag');
    assert_same(1, substr_count($html, '</script>'), 'nur der umschließende Block endet ein Script-Tag');
});

exit(test_summary('topology helpers'));
