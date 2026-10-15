<?php
declare(strict_types=1);

/**
 * tests/php/test_topology_build.php
 *
 * Tests the translation layer lib/topology.php: it turns a load_stats.php
 * response into the node/edge graph the canvas renderer draws. The fixtures
 * mirror the real payload, so these tests guard the topology hierarchy
 * (LLMInt → LLM/SpeechInt/ImageInt/SearXNG/Wissensdatenbank/Clients), the
 * in-canvas detail card contract and the option handling.
 *
 * Run from the repository root:  php tests/php/test_topology_build.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../db.php';
require __DIR__ . '/../../lib/topology.php';
require __DIR__ . '/fixtures.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

function topo(array $overrides = [], array $options = []): array
{
    return topology_build(topology_stats_fixture($overrides), array_merge(['base_url' => '', 'slot_limit' => 4], $options));
}

function topo_node(array $topology, string $key): array
{
    assert_key($key, $topology['nodes'], 'Knoten fehlt');
    return $topology['nodes'][$key];
}

/** All edges as "from->to" strings. */
function topo_edge_pairs(array $topology): array
{
    return array_map(static fn(array $edge): string => $edge['from'] . '->' . $edge['to'], $topology['edges']);
}

function topo_has_edge(array $topology, string $from, string $to): bool
{
    return in_array($from . '->' . $to, topo_edge_pairs($topology), true);
}

/** All node keys that are a child of $parent, sorted. */
function topo_children(array $topology, string $parent): array
{
    $children = [];
    foreach ($topology['edges'] as $edge) {
        if ($edge['from'] === $parent) {
            $children[] = $edge['to'];
        }
    }
    sort($children);
    return $children;
}

/** Fact labels of a node, in order. */
function topo_fact_labels(array $node): array
{
    return array_map(static fn(array $fact): string => (string) ($fact['label'] ?? ''), $node['facts']);
}

function topo_fact(array $node, string $label): array
{
    foreach ($node['facts'] as $fact) {
        if (($fact['label'] ?? null) === $label) {
            return $fact;
        }
    }
    test_fail('Fakt ' . test_describe($label) . ' fehlt, vorhanden: ' . test_describe(topo_fact_labels($node)));
}

/** Canonical key of a model group, matching the grouping in topology_build(). */
function topo_model_key(string $model): string
{
    return 'model:' . (function_exists('canonicalModelName') ? canonicalModelName($model) : strtolower($model));
}

// ── Payload contract ──────────────────────────────────────────────────────────

test_case('topology_build() liefert den vollständigen Payload', function (): void {
    $topology = topo();
    assert_keys(['generated', 'overall', 'nodes', 'edges', 'incidents'], $topology);
    assert_same(1750000000, $topology['generated'], 'der Zeitstempel wird durchgereicht');
    assert_keys(['state', 'label', 'message', 'errors', 'warnings', 'nodes'], $topology['overall']);
    assert_count(25, $topology['nodes']);
    assert_count(24, $topology['edges']);
    assert_same(count($topology['nodes']), $topology['overall']['nodes']);
});

test_case('jeder Knoten erfüllt den Vertrag des Renderers', function (): void {
    $topology = topo();
    assert_all($topology['nodes'], static function (array $node) {
        foreach (['key', 'kind', 'title', 'subtitle', 'state', 'badge', 'message', 'activity', 'counters', 'facts', 'slots', 'cloud', 'members', 'neighbours', 'url', 'synthetic'] as $field) {
            if (!array_key_exists($field, $node)) {
                return 'Feld ' . $field . ' fehlt';
            }
        }
        if (!in_array($node['kind'], topology_kinds(), true)) {
            return 'unbekannte Knotenart ' . $node['kind'];
        }
        if (!in_array($node['state'], ['ok', 'warn', 'error', 'off'], true)) {
            return 'unbekannter Zustand ' . $node['state'];
        }
        if (!is_int($node['activity']) || $node['activity'] < 0 || $node['activity'] > 12) {
            return 'activity außerhalb 0…12';
        }
        if (!is_bool($node['synthetic'])) {
            return 'synthetic ist kein Wahrheitswert';
        }
        if (substr_count($node['key'], ' ') > 0) {
            return 'Schlüssel enthält Leerzeichen';
        }
        return true;
    }, 'Knotenvertrag verletzt');
});

test_case('jede Kante zeigt auf existierende Knoten und ist eindeutig', function (): void {
    $topology = topo();
    $seen = [];
    assert_all($topology['edges'], static function (array $edge) use ($topology, &$seen) {
        assert_keys(['from', 'to', 'state'], $edge);
        if (!isset($topology['nodes'][$edge['from']]) || !isset($topology['nodes'][$edge['to']])) {
            return 'Kante zeigt auf unbekannten Knoten';
        }
        if ($edge['from'] === $edge['to']) {
            return 'Selbstkante';
        }
        $id = $edge['from'] . '>' . $edge['to'];
        if (isset($seen[$id])) {
            return 'doppelte Kante';
        }
        $seen[$id] = true;
        return true;
    }, 'Kantenvertrag verletzt');
});

// ── Hierarchy ─────────────────────────────────────────────────────────────────

test_case('LLMInt ist der Wurzelknoten und hängt an nichts', function (): void {
    $topology = topo();
    $hub = topo_node($topology, 'hub');
    assert_same('hub', $hub['kind']);
    assert_same('LLMInt', $hub['title']);
    assert_same([], array_values(array_filter($topology['edges'], static fn(array $e): bool => $e['to'] === 'hub')), 'LLMInt hat keinen Elternknoten');
});

test_case('LLMInt verbindet sich mit allen Zweigen erster Ebene', function (): void {
    $topology = topo();
    assert_same(
        ['api', 'clients', 'image', 'llm', 'search', 'speech', 'vector'],
        topo_children($topology, 'hub')
    );
});

test_case('LLM bündelt die Intelligenzgruppen, die Gruppen bündeln die Endpunkte', function (): void {
    $topology = topo();
    assert_same('llm', topo_node($topology, 'llm')['kind']);

    $groups = topo_children($topology, 'llm');
    assert_count(3, $groups);
    assert_all($groups, static fn(string $key) => str_starts_with($key, 'model:'), 'Intelligenzgruppen beginnen mit model:');

    $gemma = topo_model_key('gemma-3-27b-it-Q4_K_M');
    assert_same($gemma, topo_model_key('gemma-3-27b-it-Q5_K_S.gguf'), 'beide Gemma-Varianten fallen in dieselbe Gruppe');
    assert_same(['ep:1', 'ep:2'], topo_children($topology, $gemma), 'die Gruppen halten ihre Endpunkte');

    $qwen = topo_model_key('qwen2.5-32b-instruct');
    assert_same(['ep:3'], topo_children($topology, $qwen));

    assert_all($topology['nodes'], static function (array $node, $key) use ($topology) {
        if ($node['kind'] === 'endpoint' && str_starts_with((string) $key, 'ep:')) {
            $parents = array_values(array_filter($topology['edges'], static fn(array $e): bool => $e['to'] === $key));
            if (count($parents) !== 1 || !str_starts_with($parents[0]['from'], 'model:')) {
                return 'Endpunkt ' . $key . ' hängt nicht an genau einer Intelligenzgruppe';
            }
        }
        return true;
    }, 'Endpunkt-Hierarchie verletzt');
});

test_case('SpeechInt und ImageInt sind eigene Zweige unter LLMInt', function (): void {
    $topology = topo();
    $image = topo_node($topology, 'image');
    $speech = topo_node($topology, 'speech');

    assert_same('image', $image['kind']);
    assert_same('ImageInt', $image['title']);
    assert_same(['image-ep:1'], topo_children($topology, 'image'));

    assert_same('speech', $speech['kind']);
    assert_same('SpeechInt', $speech['title']);
    assert_same(['speech-ep:1'], topo_children($topology, 'speech'));

    assert_same('endpoint', topo_node($topology, 'image-ep:1')['kind']);
    assert_same('endpoint', topo_node($topology, 'speech-ep:1')['kind']);
});

test_case('SearXNG und die Wissensdatenbank hängen direkt an LLMInt', function (): void {
    $topology = topo();
    assert_same('search', topo_node($topology, 'search')['kind']);
    assert_same('SearXNG', topo_node($topology, 'search')['title']);
    assert_same('vector', topo_node($topology, 'vector')['kind']);
    assert_true(topo_has_edge($topology, 'hub', 'search'));
    assert_true(topo_has_edge($topology, 'hub', 'vector'));
    assert_same([], topo_children($topology, 'search'), 'SearXNG hat keine Kinder');
    assert_same([], topo_children($topology, 'vector'), 'die Wissensdatenbank hat keine Kinder');
});

test_case('ComfyUI und StableDiffusion kommen im Baum nicht mehr vor', function (): void {
    $topology = topo();
    $dump = json_encode($topology, JSON_UNESCAPED_UNICODE);
    assert_not_contains('ComfyUI', $dump);
    assert_not_contains('StableDiffusion', $dump);
    assert_not_contains('comfy', strtolower($dump));
    assert_not_contains('stable', strtolower($dump));
});

// ── Group nodes ───────────────────────────────────────────────────────────────

test_case('Gruppenknoten sind markiert und tauchen nicht in den Störungen auf', function (): void {
    $topology = topo();
    foreach (['llm', topo_model_key('gemma-3-27b-it-Q4_K_M'), 'image', 'speech'] as $key) {
        assert_true(!empty(topo_node($topology, $key)['group']), $key . ' ist ein Gruppenknoten');
    }
    foreach (['hub', 'clients', 'search', 'vector', 'ep:1', 'image-ep:1', 'speech-ep:1'] as $key) {
        assert_true(empty(topo_node($topology, $key)['group']), $key . ' ist kein Gruppenknoten');
    }

    $incidentKeys = array_column($topology['incidents'], 'key');
    assert_all($incidentKeys, static fn(string $key): bool => empty(topo_node($topology, $key)['group']), 'Gruppenknoten in den Störungen');
    assert_same(['ep:1', 'hub'], $incidentKeys, 'nur echte Knoten melden Störungen');
});

test_case('ImageInt- und SpeechInt-Gruppen erscheinen nur mit Endpunkten', function (): void {
    $withoutImage = topology_build(topology_stats_without_image(), ['base_url' => '']);
    assert_false(isset($withoutImage['nodes']['image']), 'ohne ImageInt-Endpunkte entfällt der Gruppenknoten');
    assert_false(isset($withoutImage['nodes']['image-ep:1']));
    assert_false(topo_has_edge($withoutImage, 'hub', 'image'));
    assert_same(22, count($withoutImage['edges']));

    $withoutSpeech = topology_build(topology_stats_without_speech(), ['base_url' => '']);
    assert_false(isset($withoutSpeech['nodes']['speech']));
    assert_false(topo_has_edge($withoutSpeech, 'hub', 'speech'));

    assert_true(isset($withoutImage['nodes']['speech']), 'SpeechInt bleibt von fehlendem ImageInt unberührt');
});

test_case('ohne LLM-Endpunkte entfällt der LLM-Zweig', function (): void {
    $topology = topology_build(topology_stats_all_endpoints_off(), ['base_url' => '', 'include_off' => false]);
    assert_false(isset($topology['nodes']['llm']));
    assert_false(topo_has_edge($topology, 'hub', 'llm'));
    assert_true(isset($topology['nodes']['hub']), 'LLMInt bleibt immer erhalten');
    assert_true(isset($topology['nodes']['clients']));
    assert_same('ok', $topology['overall']['state']);
    assert_same('Alle 17 Bausteine melden einen unauffälligen Zustand.', $topology['overall']['message']);
});

// ── States and aggregation ────────────────────────────────────────────────────

test_case('der Gesamtzustand fasst Endpunkte und Störungen zusammen', function (): void {
    $topology = topo();
    assert_same('warn', $topology['overall']['state']);
    assert_same('Eingeschränkt', $topology['overall']['label']);
    assert_same(0, $topology['overall']['errors']);
    assert_same(2, $topology['overall']['warnings']);
    assert_same('2 Einschränkungen erkannt.', $topology['overall']['message']);
});

test_case('voll belegte Slots erzeugen eine Einschränkung mit Erklärung', function (): void {
    $topology = topo();
    $endpoint = topo_node($topology, 'ep:1');
    assert_same('warn', $endpoint['state']);
    assert_same('Alle 4 Parallel-Slots belegt.', $endpoint['message']);
    assert_same(9, $endpoint['activity'], 'activity spiegelt Last und Durchsatz');
    assert_same(1, topo_node($topology, 'ep:2')['activity'], 'ruhende Endpunkte bleiben knapp über null');
    assert_true($endpoint['activity'] <= 12 && $endpoint['activity'] >= 0);
});

test_case('deaktivierte Endpunkte bleiben sichtbar, ohne den Gesamtzustand zu trüben', function (): void {
    $topology = topo();
    assert_same('off', topo_node($topology, 'ep:4')['state']);
    assert_same('Endpunkt ist deaktiviert.', topo_node($topology, 'ep:4')['message']);
    assert_same('aus', topo_node($topology, 'ep:4')['badge']);
    assert_same(0, topo_node($topology, 'ep:4')['activity']);
    assert_same('off', topo_node($topology, 'model:llama-3-8b')['state'], 'eine Gruppe ohne aktiven Endpunkt ist selbst nicht aktiv');
    assert_same('warn', topo_node($topology, topo_model_key('gemma-3-27b-it-Q4_K_M'))['state'], 'eine Gruppe mit einem belegten Endpunkt gilt als eingeschränkt');

    $allOff = topology_build(topology_stats_all_endpoints_off(), ['base_url' => '']);
    assert_same('ok', $allOff['overall']['state'], 'ein deaktivierter Endpunkt allein ist keine Einschränkung');
    assert_same(0, $allOff['overall']['warnings']);
});

test_case('ein nicht erreichbares ImageInt erzeugt eine Störung', function (): void {
    $topology = topology_build(topology_stats_with_image_error(), ['base_url' => '']);
    assert_same('error', topo_node($topology, 'image')['state']);
    assert_same('error', topo_node($topology, 'image-ep:1')['state']);
    assert_same('error', $topology['overall']['state']);
    assert_same('Störung', $topology['overall']['label']);
    assert_same(1, $topology['overall']['errors']);
    assert_same('1 Störung und 2 Einschränkungen erkannt.', $topology['overall']['message']);
    assert_same(['image-ep:1', 'ep:1', 'hub'], array_column($topology['incidents'], 'key'));
});

// ── Detail card contract ──────────────────────────────────────────────────────

test_case('LLM-Endpunkte liefern die sechs Kennzahlen der Detailkarte', function (): void {
    $topology = topo();
    $endpoint = topo_node($topology, 'ep:2');
    assert_same(['▶ Laufend', '✓ Jobs heute', '↑ Prompt heute', '↓ Completion', '⬡ Total Token', '⚡ Ø Geschwindigkeit'], array_map(
        static fn(array $fact): string => $fact['icon'] . ' ' . $fact['label'],
        $endpoint['facts']
    ));
    assert_same('0', topo_fact($endpoint, 'Laufend')['value']);
    assert_same('muted', topo_fact($endpoint, 'Laufend')['tone'], 'leerlaufende Endpunkte werden gedämpft dargestellt');
    assert_same('812', topo_fact(topo_node($topology, 'ep:1'), 'Jobs heute')['value']);
    assert_same('1.3M', topo_fact(topo_node($topology, 'ep:1'), 'Prompt heute')['value']);
    assert_same('340.0K', topo_fact(topo_node($topology, 'ep:1'), 'Completion')['value']);
    assert_same('1.6M', topo_fact(topo_node($topology, 'ep:1'), 'Total Token')['value']);
    assert_same('42,7 Tok/s', topo_fact(topo_node($topology, 'ep:1'), 'Ø Geschwindigkeit')['value']);
    assert_same('–', topo_fact($endpoint, 'Ø Geschwindigkeit')['value'], 'ohne Messwert steht ein Gedankenstrich');
});

test_case('Endpunkte mit SSH-Zugang zeigen Systemwerte, andere einen Platzhalter', function (): void {
    $topology = topo();

    $withStats = topo_node($topology, 'ep:1');
    assert_count(9, $withStats['facts']);
    assert_same('30.0 / 64.0 GB (47%)', topo_fact($withStats, 'RAM')['value']);
    assert_same('1,25 (5 min: 0,90)', topo_fact($withStats, 'CPU-Last')['value']);
    assert_same('71,5 °C', topo_fact($withStats, 'Temp')['value']);
    assert_same('warn', topo_fact($withStats, 'Temp')['tone']);

    $waiting = topo_node($topology, 'ep:3');
    assert_count(7, $waiting['facts']);
    assert_same('🔑 SSH', $waiting['facts'][6]['icon'] . ' ' . $waiting['facts'][6]['label']);
    assert_same('warte auf Daten …', $waiting['facts'][6]['value']);

    $withoutSsh = topo_node($topology, 'ep:2');
    assert_count(6, $withoutSsh['facts'], 'ohne SSH gibt es nur die sechs Kennzahlen');
});

test_case('die Detailkarte bekommt den Slot-Balken nur bei LLM-Endpunkten', function (): void {
    $topology = topo();
    assert_same(['used' => 4, 'max' => 4], topo_node($topology, 'ep:1')['slots']);
    assert_same(['used' => 0, 'max' => 4], topo_node($topology, 'ep:2')['slots']);
    assert_same(null, topo_node($topology, 'image-ep:1')['slots']);
    assert_same(null, topo_node($topology, 'hub')['slots']);
    assert_same(null, topo_node($topology, 'search')['slots']);
});

test_case('LLMInt zeigt die neun Summenkennzahlen', function (): void {
    $topology = topo();
    $hub = topo_node($topology, 'hub');
    assert_count(9, $hub['facts']);
    assert_same([
        'LLM laufend', 'LLM erledigt (24 h)', 'LLM erledigt (gesamt)', 'LLM Fehler (gesamt)',
        'Prompt Token (gesamt)', 'Completion Token (gesamt)', 'Total Token (gesamt)', 'Modelle', 'Endpunkte',
    ], topo_fact_labels($hub));
    assert_same('4', topo_fact($hub, 'LLM laufend')['value']);
    assert_same('972', topo_fact($hub, 'LLM erledigt (24 h)')['value']);
    assert_same('15.2K', topo_fact($hub, 'LLM erledigt (gesamt)')['value']);
    assert_same('3', topo_fact($hub, 'LLM Fehler (gesamt)')['value']);
    assert_same('error', topo_fact($hub, 'LLM Fehler (gesamt)')['tone']);
    assert_same('3', topo_fact($hub, 'Modelle')['value'], 'nur Intelligenzgruppen mit Endpunkten zählen');
    assert_same('4', topo_fact($hub, 'Endpunkte')['value']);
});

test_case('die Intelligenzgruppen fassen ihre Endpunkte zusammen', function (): void {
    $topology = topo();
    $gemma = topo_node($topology, topo_model_key('gemma-3-27b-it-Q4_K_M'));
    assert_same('2', topo_fact($gemma, 'Endpunkte')['value']);
    assert_same('4', topo_fact($gemma, 'Laufend')['value']);
    assert_same('932', topo_fact($gemma, 'Jobs heute')['value'], '812 + 120');
    assert_same('1.6M', topo_fact($gemma, 'Token heute')['value']);
    assert_same('27b', topo_fact($gemma, 'Parametergröße')['value']);
    assert_same('warn', $gemma['state']);
});

test_case('SearXNG und die Wissensdatenbank haben eigene Karten', function (): void {
    $topology = topo();
    $search = topo_node($topology, 'search');
    assert_same('1', topo_fact($search, 'Laufend')['value']);
    assert_same('55', topo_fact($search, 'Jobs heute')['value']);
    assert_same('1,4 s', topo_fact($search, 'Ø Antwortzeit')['value']);
    assert_same('http://searxng:8080', topo_fact($search, 'URL')['value']);

    $vector = topo_node($topology, 'vector');
    assert_same('online', topo_fact($vector, 'Status')['value']);
    assert_same('1.2K', topo_fact($vector, 'Dokumente')['value']);
    assert_same('48.0K', topo_fact($vector, 'Vektoren')['value']);
    assert_same('qdrant', topo_fact($vector, 'Modus')['value']);
});

// ── Clients ───────────────────────────────────────────────────────────────────

test_case('die Clients-Wolke sammelt alle Browser-Sitzungen', function (): void {
    $topology = topo();
    $clients = topo_node($topology, 'clients');
    assert_same('clients', $clients['kind']);
    assert_same('Clients', $clients['title']);
    assert_same('3 verbunden', $clients['subtitle']);
    assert_same('3', $clients['badge']);
    assert_same(3, $clients['activity']);

    $cloud = $clients['cloud'];
    assert_keys(['title', 'empty', 'items'], $cloud);
    assert_count(3, $cloud['items']);
    assert_same('3', topo_fact($clients, 'Jetzt')['value']);
    assert_same('8', topo_fact($clients, 'Heute max')['value']);
    assert_same('6', topo_fact($clients, 'Tabs')['value'], 'Summe aus 2 + 1 + 3');
});

test_case('jede Sitzung wird ein eigener Knoten unter der Wolke', function (): void {
    $topology = topo();
    $clients = topo_node($topology, 'clients');
    $keys = array_map(static fn(array $item): string => $item['key'], $clients['cloud']['items']);

    assert_same(3, count($keys));
    assert_all($keys, static fn(string $key) => str_starts_with($key, 'client:'), 'Wolken-Einträge verweisen auf client:-Knoten');
    $children = topo_children($topology, 'clients');
    sort($keys);
    assert_same($children, $keys, 'Kanten und Wolkeneinträge stimmen überein');
    assert_all($keys, static function (string $key) use ($topology) {
        $node = topo_node($topology, $key);
        return $node['kind'] === 'client' && !empty($node['title']);
    }, 'client-Knoten fehlen oder sind unvollständig');
});

test_case('Sitzungsschlüssel sind stabil und kollisionsfrei', function (): void {
    $topology = topo();
    $clients = topo_node($topology, 'clients');
    $keys = array_map(static fn(array $item): string => $item['key'], $clients['cloud']['items']);

    assert_true(in_array('client:' . md5('ws-anna|10.0.0.11'), $keys, true), 'Schlüssel leitet sich aus Hostname und IP ab');
    assert_true(in_array('client:' . md5('ws-ein-sehr-langer-rechnername|10.0.0.13'), $keys, true));
    assert_same($keys, array_values(array_unique($keys)), 'keine doppelten Schlüssel');
});

test_case('die Wolkeneinträge zeigen Name, Adresse und Tab-Anzahl', function (): void {
    $topology = topo();
    $items = topo_node($topology, 'clients')['cloud']['items'];
    $byTitle = [];
    foreach ($items as $item) {
        $byTitle[$item['title']] = $item;
    }

    assert_same('ws-anna (10.0.0.11) · 2 Tabs', $byTitle['ws-anna']['subtitle']);
    assert_same('2×', $byTitle['ws-anna']['badge']);
    assert_same('ok', $byTitle['ws-anna']['state']);
    assert_same('ws-bernd (10.0.0.12) · 1 Tab', $byTitle['ws-bernd']['subtitle'], 'Einzahl bei genau einem Tab');
});

test_case('die Sitzungen sind alphabetisch sortiert', function (): void {
    $topology = topo(['clients' => ['list' => [
        ['ip' => '10.0.0.3', 'hostname' => 'zeta', 'tabs' => 1],
        ['ip' => '10.0.0.2', 'hostname' => 'Alpha', 'tabs' => 1],
        ['ip' => '10.0.0.1', 'hostname' => 'mitte', 'tabs' => 1],
    ]]]);
    $titles = array_map(static fn(array $item): string => $item['title'], topo_node($topology, 'clients')['cloud']['items']);
    assert_same(['Alpha', 'mitte', 'zeta'], $titles);
});

// ── API ───────────────────────────────────────────────────────────────────────

test_case('der API-Zweig hängt direkt an LLMInt', function (): void {
    $topology = topo();
    $api = topo_node($topology, 'api');

    assert_same('api', $api['kind']);
    assert_same('API', $api['title']);
    assert_same('Reverse Proxy · 4 Schlüssel', $api['subtitle']);
    assert_same('41 heute', $api['badge']);
    assert_true(topo_has_edge($topology, 'hub', 'api'));
    assert_same('index.php#api-keys-card', $api['url']);
});

test_case('jede API-Nutzung wird ein eigener Knoten in der API-Wolke', function (): void {
    $topology = topo();
    $api = topo_node($topology, 'api');

    assert_keys(['title', 'empty', 'items'], $api['cloud']);
    assert_same('Aufrufe über die API', $api['cloud']['title']);
    assert_same('Keine API-Aufrufe.', $api['cloud']['empty']);

    $keys = array_map(static fn(array $item): string => $item['key'], $api['cloud']['items']);
    sort($keys);
    assert_same(['api-key:1', 'api-key:2', 'api-key:3', 'api-key:4', 'api-key:anon'], $keys);
    assert_same(topo_children($topology, 'api'), $keys, 'Kanten und Wolkeneinträge stimmen überein');

    assert_all($keys, static function (string $key) use ($topology) {
        $node = topo_node($topology, $key);
        return $node['kind'] === 'api-key'
            && $node['title'] !== ''
            && $node['facts'] !== []
            && $node['cloud'] === null;
    }, 'api-key-Knoten fehlen oder sind unvollständig');
});

test_case('die API-Wolke zeigt Aufrufe, Tageszähler und letztes Modell', function (): void {
    $topology = topo();
    $api = topo_node($topology, 'api');

    assert_same('41', topo_fact($api, 'Aufrufe heute')['value']);
    assert_same('jobs', topo_fact($api, 'Aufrufe heute')['tone']);
    assert_same('2.2K', topo_fact($api, 'Aufrufe gesamt')['value']);
    assert_same('2', topo_fact($api, 'Gerade aktiv')['value'], 'ein aktiver Schlüssel und die anonymen Aufrufe');
    assert_same('4', topo_fact($api, 'Schlüssel')['value']);
    assert_same('210', topo_fact($api, 'Ohne Schlüssel')['value']);

    $crawler = topo_node($topology, 'api-key:1');
    assert_same('1.8K', topo_fact($crawler, 'Aufrufe')['value']);
    assert_same('37', topo_fact($crawler, 'Aufrufe heute')['value']);
    assert_same('gemma-3-27b-it-Q4_K_M', topo_fact($crawler, 'Letztes Modell')['value']);
    assert_same('vor 12 s', topo_fact($crawler, 'Zuletzt benutzt')['value']);
});

test_case('ein laufender Aufruf markiert seinen Schlüssel, ruhende bleiben gedämpft', function (): void {
    $topology = topo();

    $calling = topo_node($topology, 'api-key:1');
    assert_same('ok', $calling['state']);
    assert_same('Mit diesem Schlüssel läuft gerade ein Aufruf.', $calling['message']);
    assert_same('running', topo_fact($calling, 'Zuletzt benutzt')['tone']);

    $quiet = topo_node($topology, 'api-key:2');
    assert_same('off', $quiet['state'], 'ein Schlüssel ohne frische Aufrufe wird gedämpft');
    assert_same('Zurzeit keine Aufrufe mit diesem Schlüssel.', $quiet['message']);
    assert_same('', $quiet['badge'], 'ohne Tagesaufrufe kein Zähler-Badge');
});

test_case('deaktivierte und abgelaufene Schlüssel erklären sich', function (): void {
    $topology = topo();

    $inactive = topo_node($topology, 'api-key:3');
    assert_same('off', $inactive['state']);
    assert_same('Der Schlüssel ist deaktiviert.', $inactive['message']);
    assert_same('inaktiv', $inactive['badge']);

    $expired = topo_node($topology, 'api-key:4');
    assert_same('off', $expired['state']);
    assert_same('Der Schlüssel ist abgelaufen und wird nicht mehr angenommen.', $expired['message']);
    assert_same('abgelaufen', topo_fact($expired, 'Gültig noch')['value']);
    assert_same('error', topo_fact($expired, 'Gültig noch')['tone']);
});

test_case('Aufrufe ohne gültigen Schlüssel sammeln sich in einem Eintrag', function (): void {
    $topology = topo();
    $anon = topo_node($topology, 'api-key:anon');

    assert_same('ohne Key', $anon['title']);
    assert_same('Aufrufe ohne gültigen Schlüssel', $anon['subtitle']);
    assert_same('ok', $anon['state'], 'die anonymen Aufrufe sind frisch');
    assert_same('4 heute', $anon['badge']);
    assert_same('210', topo_fact($anon, 'Aufrufe')['value']);
    assert_same('gemma-3-27b-it-Q4_K_M', topo_fact($anon, 'Letztes Modell')['value']);
});

test_case('ohne API-Nutzung bleibt nur der Gruppenknoten', function (): void {
    $topology = topology_build(topology_stats_without_api(), ['base_url' => '', 'slot_limit' => 4]);
    $api = topo_node($topology, 'api');

    assert_same('Reverse Proxy · 0 Schlüssel', $api['subtitle']);
    assert_same('', $api['badge']);
    assert_same([], $api['cloud']['items']);
    assert_same([], topo_children($topology, 'api'));
    assert_true(topo_has_edge($topology, 'hub', 'api'), 'der API-Zweig bleibt sichtbar');
    assert_same('0', topo_fact($api, 'Aufrufe gesamt')['value']);
    assert_same('Zurzeit keine Aufrufe über die API.', $api['message']);
});

test_case('fehlende API-Daten werden toleriert', function (): void {
    $topology = topo(['api' => null]);
    assert_true(isset($topology['nodes']['api']));
    assert_same([], topo_node($topology, 'api')['cloud']['items']);
});

// ── Laufende Anfrage ──────────────────────────────────────────────────────────

/** Key of a session node, matching the hashing in topology_build(). */
function topo_client_key(string $host, string $ip): string
{
    return 'client:' . md5($host . '|' . $ip);
}

/**
 * topology_build() over a fixture where the session $host reports $route as its
 * running request. The other sessions of the fixture stay idle.
 */
function topo_running(array $route, string $host = 'ws-anna', string $ip = '10.0.0.11'): array
{
    return topo(['clients' => ['list' => [array_merge(
        ['ip' => $ip, 'hostname' => $host, 'tabs' => 1],
        $route
    )]]]);
}

test_case('eine laufende Anfrage markiert den Weg der Sitzung', function (): void {
    $topology = topology_build(topology_stats_with_running_requests(), ['base_url' => '', 'slot_limit' => 4]);

    $busy = topo_node($topology, topo_client_key('ws-anna', '10.0.0.11'));
    assert_same([
        'kind'        => 'llm',
        'model'       => 'gemma-3-27b-it-Q4_K_M',
        'endpoint_id' => 1,
        'nodes'       => [topo_model_key('gemma-3-27b-it-Q4_K_M'), 'ep:1'],
    ], $busy['route'], 'Modell und Endpunkt der Anfrage gehören zum Weg');

    $search = topo_node($topology, topo_client_key('ws-ein-sehr-langer-rechnername', '10.0.0.13'));
    assert_same(['search'], $search['route']['nodes'], 'eine Websuche endet bei SearXNG');
});

test_case('ohne laufende Anfrage bleibt der Weg einer Sitzung offen', function (): void {
    $topology = topology_build(topology_stats_with_running_requests(), ['base_url' => '', 'slot_limit' => 4]);

    $idle = topo_node($topology, topo_client_key('ws-bernd', '10.0.0.12'));
    assert_same(null, $idle['route'], 'ohne Anfrage gibt es keinen Weg');
});

test_case('ohne Route-Daten bleibt der Weg jeder Sitzung offen', function (): void {
    $topology = topo();

    foreach (topo_node($topology, 'clients')['cloud']['items'] as $item) {
        assert_same(null, topo_node($topology, $item['key'])['route'], 'Sitzung ohne Anfrage hat keinen Weg');
    }
});

test_case('unbekannte Route-Arten werden ignoriert', function (): void {
    // Only CLIENT_ROUTE_KINDS (llm, image, speech, search) count as a request;
    // anything else must not light up a path.
    $topology = topo_running([
        'route_kind'        => 'vector',
        'route_model'       => 'irgendwas',
        'route_endpoint_id' => 1,
        'route_seconds'     => 3,
    ]);

    assert_same(null, topo_node($topology, topo_client_key('ws-anna', '10.0.0.11'))['route']);
});

test_case('der Weg einer laufenden Anfrage wird bis zum Ziel aufgelöst', function (): void {
    $cases = [
        [
            ['route_kind' => 'image', 'route_model' => '', 'route_endpoint_id' => 1, 'route_seconds' => 1],
            ['image-ep:1'],
        ],
        [
            ['route_kind' => 'speech', 'route_model' => '', 'route_endpoint_id' => 1, 'route_seconds' => 1],
            ['speech-ep:1'],
        ],
        [
            // The endpoint is gone: the way ends at the group it belongs to.
            ['route_kind' => 'image', 'route_model' => '', 'route_endpoint_id' => 99, 'route_seconds' => 1],
            ['image'],
        ],
        [
            // Model and endpoint unknown: at least the LLM branch stays marked.
            ['route_kind' => 'llm', 'route_model' => 'gibt-es-nicht', 'route_endpoint_id' => 99, 'route_seconds' => 1],
            ['llm'],
        ],
    ];

    foreach ($cases as [$route, $expected]) {
        $topology = topo_running($route);
        $node = topo_node($topology, topo_client_key('ws-anna', '10.0.0.11'));
        assert_same($expected, $node['route']['nodes'], test_describe($route['route_kind']));
    }
});

test_case('die laufende Anfrage einer Sitzung erscheint als Fakt', function (): void {
    $topology = topology_build(topology_stats_with_running_requests(), ['base_url' => '', 'slot_limit' => 4]);

    $busy = topo_node($topology, topo_client_key('ws-anna', '10.0.0.11'));
    $fact = topo_fact($busy, 'Laufende Anfrage');
    assert_same('gemma-3-27b-it-Q4_K_M · seit 7 s', $fact['value']);
    assert_same('running', $fact['tone'], 'eine laufende Anfrage wird hervorgehoben');
    assert_same(3, $busy['activity'], 'eine arbeitende Sitzung ist aktiver');

    $search = topo_node($topology, topo_client_key('ws-ein-sehr-langer-rechnername', '10.0.0.13'));
    assert_same('Laufende Websuche', topo_fact($search, 'Laufende Websuche')['label']);

    $idle = topo_node($topology, topo_client_key('ws-bernd', '10.0.0.12'));
    assert_same('keine', topo_fact($idle, 'Laufende Anfrage')['value']);
    assert_same('muted', topo_fact($idle, 'Laufende Anfrage')['tone']);
    assert_same(1, $idle['activity']);
});

test_case('die Sitzung ohne Route-Felder gilt als untätig', function (): void {
    // A load_stats.php response from before the columns existed must not break.
    $topology = topo(['clients' => ['list' => [
        ['ip' => '10.0.0.21', 'hostname' => 'ws-ohne', 'tabs' => 1],
    ]]]);
    $node = topo_node($topology, topo_client_key('ws-ohne', '10.0.0.21'));

    assert_same(null, $node['route']);
    assert_same('keine', topo_fact($node, 'Laufende Anfrage')['value']);
});

// ── Neighbours ────────────────────────────────────────────────────────────────

test_case('Nachbarlisten sind beidseitig und ohne Doppelte', function (): void {
    $topology = topo();
    $hub = topo_node($topology, 'hub');
    assert_same(['llm', 'clients', 'api', 'search', 'vector', 'image', 'speech'], $hub['neighbours']);
    assert_same($hub['neighbours'], array_values(array_unique($hub['neighbours'])));

    assert_same(['llm', 'ep:1', 'ep:2'], topo_node($topology, topo_model_key('gemma-3-27b-it-Q4_K_M'))['neighbours']);

    assert_all($topology['nodes'], static function (array $node, $key) use ($topology) {
        if ($node['neighbours'] !== array_values(array_unique($node['neighbours']))) {
            return 'Doppelte Nachbarn';
        }
        foreach ($node['neighbours'] as $other) {
            if (!isset($topology['nodes'][$other])) {
                return 'Nachbar ' . $other . ' existiert nicht';
            }
            if (!in_array((string) $key, $topology['nodes'][$other]['neighbours'], true)) {
                return 'Nachbarschaft zu ' . $other . ' ist nicht beidseitig';
            }
        }
        return true;
    }, 'Nachbarschaftsvertrag verletzt');
});

// ── Options ───────────────────────────────────────────────────────────────────

test_case('include_off=false lässt deaktivierte Endpunkte weg', function (): void {
    $topology = topo([], ['include_off' => false]);
    assert_false(isset($topology['nodes']['ep:4']));
    assert_false(topo_has_edge($topology, 'model:llama-3-8b', 'ep:4'));
    assert_false(isset($topology['nodes']['model:llama-3-8b']), 'leere Gruppen verschwinden mit');
    assert_same(23, count($topology['nodes']));
    assert_true(isset($topology['nodes']['ep:3']), 'aktive Endpunkte bleiben');
});

test_case('slot_limit steuert die Auslastungswarnung', function (): void {
    $topology = topo([], ['slot_limit' => 2]);
    assert_same('warn', topo_node($topology, 'ep:1')['state']);
    assert_same('Alle 2 Parallel-Slots belegt.', topo_node($topology, 'ep:1')['message']);
    assert_same(['used' => 4, 'max' => 2], topo_node($topology, 'ep:1')['slots']);

    $relaxed = topo([], ['slot_limit' => 8]);
    assert_same('ok', topo_node($relaxed, 'ep:1')['state']);
    assert_same('', topo_node($relaxed, 'ep:1')['message']);
});

test_case('base_url wird allen internen Verweisen vorangestellt', function (): void {
    $topology = topo([], ['base_url' => '/admin/']);
    assert_same('/admin/index.php#config-balancer-card', topo_node($topology, 'hub')['url']);
    assert_same('/admin/index.php#config-endpoints-card', topo_node($topology, 'ep:1')['url']);
    assert_same('/admin/index.php#dashboard-card', topo_node($topology, 'clients')['url']);
    assert_same('/admin/index.php#config-image-card', topo_node($topology, 'image')['url']);

    $relative = topo();
    assert_same('index.php#config-endpoints-card', topo_node($relative, 'ep:1')['url']);
});

// ── Robustness ────────────────────────────────────────────────────────────────

test_case('ein leerer Zustand ergibt eine gültige, minimale Topologie', function (): void {
    $topology = topology_build([], ['base_url' => '']);
    assert_keys(['generated', 'overall', 'nodes', 'edges', 'incidents'], $topology);
    assert_same(['clients', 'api', 'hub'], array_keys($topology['nodes']), 'ohne Daten bleiben LLMInt, die Clients-Wolke und der API-Zweig');
    assert_same([
        ['from' => 'hub', 'to' => 'clients', 'state' => 'ok', 'synthetic' => true, 'label' => ''],
        ['from' => 'hub', 'to' => 'api', 'state' => 'ok', 'synthetic' => true, 'label' => ''],
    ], $topology['edges']);
    assert_same([], $topology['incidents']);
    assert_same('ok', $topology['overall']['state']);
    assert_same('Alle 3 Bausteine melden einen unauffälligen Zustand.', $topology['overall']['message']);

    $clients = topo_node($topology, 'clients');
    assert_same('0 verbunden', $clients['subtitle']);
    assert_same('Zurzeit ist keine Chat-Sitzung geöffnet.', $clients['message']);
    assert_same([], $clients['cloud']['items']);
    assert_same('Keine aktiven Clients.', $clients['cloud']['empty']);
});

test_case('fehlende oder kaputte Teilbereiche werden toleriert', function (): void {
    $topology = topology_build([
        'ts'              => 'kein Zeitstempel',
        'endpoints'       => [['id' => 1, 'alias' => 'Solo', 'base_url' => 'http://solo:8080', 'default_model' => '', 'is_active' => 1, 'running' => 0]],
        'searxng'         => null,
        'vector_store'    => 'unfug',
        'clients'         => null,
        'speech_endpoints' => 'unfug',
        'image_endpoints' => null,
    ], ['base_url' => '']);

    assert_same(0, $topology['generated']);
    assert_true(isset($topology['nodes']['ep:1']));
    assert_same('–', topo_fact(topo_node($topology, 'ep:1'), 'Ø Geschwindigkeit')['value'], 'ein Endpunkt ohne Daten bleibt darstellbar');
    assert_false(isset($topology['nodes']['search']), 'ohne SearXNG-Konfiguration gibt es keinen Knoten');
    assert_false(isset($topology['nodes']['vector']));
    assert_false(isset($topology['nodes']['speech']));
    assert_true(isset($topology['nodes']['clients']), 'die Clients-Wolke existiert auch ohne Sitzungen');
    assert_same([], topo_node($topology, 'clients')['cloud']['items']);
});

test_case('die Detailkarte und das HTML-Panel schließen sich aus', function (): void {
    $topology = topo();
    $panelNodes = [];
    $cardNodes  = [];
    foreach ($topology['nodes'] as $node) {
        $usesPanel = $node['cloud'] !== null || $node['members'] !== null;
        $usesCard  = is_array($node['facts']) && $node['facts'] !== [];
        if ($usesPanel) {
            $panelNodes[] = $node['key'];
        } elseif ($usesCard) {
            $cardNodes[] = $node['key'];
        }
    }

    // The renderer prefers the HTML panel for nodes whose payload is a list;
    // every other node with facts renders the in-canvas detail card.
    sort($panelNodes);
    assert_same([
        'api', 'clients', 'image', 'llm',
        'model:gemma-3-27b', 'model:llama-3-8b', 'model:qwen2.5-32b-instruct',
        'speech',
    ], $panelNodes, 'Panel-Knoten');
    assert_same([], array_intersect($panelNodes, $cardNodes), 'Karten- und Panel-Knoten überschneiden sich');

    foreach ([
        'hub', 'ep:1', 'ep:4', 'search', 'vector', 'image-ep:1', 'speech-ep:1',
        'client:cbc8647ad6186b9d6b8a01e36c32adb8',
        'api-key:1', 'api-key:2', 'api-key:3', 'api-key:4', 'api-key:anon',
    ] as $key) {
        assert_true(in_array($key, $cardNodes, true), $key . ' muss eine Detailkarte rendern');
    }
    assert_all($topology['nodes'], static function (array $node) {
        if ($node['cloud'] !== null && $node['members'] !== null) {
            return 'Knoten trägt Wolke und Liste gleichzeitig';
        }
        return true;
    }, 'Panel-Vertrag verletzt');
});

test_case('Gruppen liefern die Daten für das HTML-Panel', function (): void {
    $topology = topo();

    $llm = topo_node($topology, 'llm')['members'];
    assert_keys(['title', 'empty', 'items'], $llm);
    assert_same('Intelligenzgruppen', $llm['title']);
    assert_same('Keine Intelligenzgruppe zugeordnet.', $llm['empty']);
    assert_same(['model:gemma-3-27b', 'model:qwen2.5-32b-instruct', 'model:llama-3-8b'], array_column($llm['items'], 'key'));
    assert_same('2 Endpunkte', $llm['items'][0]['subtitle']);
    assert_same('1 Endpunkt', $llm['items'][1]['subtitle'], 'Einzahl bei genau einem Endpunkt');
    assert_same('▶ 4', $llm['items'][0]['badge']);
    assert_same('warn', $llm['items'][0]['state']);

    $gemma = topo_node($topology, topo_model_key('gemma-3-27b-it-Q4_K_M'))['members'];
    assert_same('Endpunkte', $gemma['title']);
    assert_same(['ep:1', 'ep:2'], array_column($gemma['items'], 'key'));
    assert_same('4 laufend', $gemma['items'][0]['badge']);

    assert_same('Bild-Endpunkte', topo_node($topology, 'image')['members']['title']);
    assert_same('Dictation-Endpunkte', topo_node($topology, 'speech')['members']['title']);
    assert_same(null, topo_node($topology, 'hub')['members'], 'einfache Knoten tragen keine Liste');
});

test_case('die Clients-Wolke beschreibt ihren Leerzustand', function (): void {
    $topology = topo();
    $cloud = topo_node($topology, 'clients')['cloud'];
    assert_same('Verbundene Clients', $cloud['title']);
    assert_same('Keine aktiven Clients.', $cloud['empty']);
});

exit(test_summary('topology build'));