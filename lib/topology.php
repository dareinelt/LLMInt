<?php

/**
 * lib/topology.php
 *
 * Translation layer between the live dashboard statistics (the JSON contract of
 * admin/load_stats.php) and the node/edge payload rendered by the message-flow
 * topology (assets/js/flow-topology.js). Used by the dashboard card in
 * admin/index.php and by the full-screen view in admin/topology.php.
 *
 * The topology is a pure *view*: this library performs no database access and
 * no HTTP requests of its own, so the same payload can be produced from a
 * cached snapshot as from a fresh poll.
 *
 * Payload contract
 * ----------------
 *   [
 *     'generated' => <unix ts>,
 *     'overall'   => ['state','label','message','errors','warnings','nodes'],
 *     'nodes'     => [ key => [
 *         'key','kind','title','subtitle','state','badge','message',
 *         'activity'  => int 0..12 (flow intensity driving the particles),
 *         'counters'  => ['current'=>int,'peak'=>int] | null,
 *         'facts'     => [['label'=>..,'value'=>..], …],
 *         'cloud'     => ['title','empty','items'=>[…]],
 *         'members'   => ['title','empty','items'=>[…]],
 *         'neighbours'=> [key, …],
 *         'url'       => internal link, empty when there is nothing to open,
 *         'synthetic' => true for derived grouping nodes,
 *         'x','y','z' => layout hint (optional, preserved across refreshes),
 *     ] ],
 *     'edges'     => [ ['from','to','state','synthetic','label'], … ],
 *     'incidents' => [ ['key','title','state','message','url'], … ],
 *   ]
 *
 * Hierarchy
 * ---------
 * LLMInt sits at the centre and every edge points from parent to child, so the
 * front end can lay the graph out as a ring tree around the core:
 *
 *   hub (LLMInt)
 *     ├─ llm      → model (Intelligenzgruppe) → endpoint (LLM-Endpunkt)
 *     ├─ speech   → endpoint (Dictation-Endpunkt)
 *     ├─ image    → endpoint (Bild-Endpunkt)
 *     ├─ search   (SearXNG)
 *     ├─ vector   (Wissensdatenbank)
 *     └─ clients  → client (einzelne Browser-Sitzung)
 *
 * The three service groups (llm, speech, image) are the direct children of the
 * core; only the LLM group carries the additional intelligence groups, because
 * only LLM endpoints are grouped by model. Group nodes carry 'group' => true and
 * are excluded from the incident list so problems are reported on the concrete
 * endpoint that caused them.
 *
 * Node kinds: clients, client, hub, llm, model, endpoint, search, vector, image,
 * speech (see topology_kinds()). States: ok, warn, error, off.
 */

/** All node kinds known to the front end. */
function topology_kinds(): array
{
    return ['clients', 'client', 'hub', 'llm', 'model', 'endpoint', 'search', 'vector', 'image', 'speech'];
}

/** Human-readable state labels (mirrors STATE_LABELS in flow-topology.js). */
function topology_state_labels(): array
{
    return [
        'ok'    => 'In Ordnung',
        'warn'  => 'Eingeschränkt',
        'error' => 'Störung',
        'off'   => 'Nicht aktiv',
    ];
}

function topology_state_label(string $state): string
{
    $labels = topology_state_labels();
    return $labels[$state] ?? $labels['off'];
}

/**
 * Severity of a state for aggregation. "off" deliberately ranks below "warn" so
 * that a single deactivated endpoint never turns the whole topology yellow.
 */
function topology_state_rank(string $state): int
{
    switch ($state) {
        case 'error':
            return 3;
        case 'warn':
            return 2;
        case 'off':
            return 1;
        default:
            return 0;
    }
}

/** Worst state of a set, ignoring "off" unless every element is off. */
function topology_aggregate_state(array $states): string
{
    if ($states === []) {
        return 'ok';
    }
    $worst = null;
    foreach ($states as $state) {
        $state = (string) $state;
        if ($state === 'off') {
            continue;
        }
        if ($worst === null || topology_state_rank($state) > topology_state_rank($worst)) {
            $worst = $state;
        }
    }
    return $worst ?? 'off';
}

/** Clamp a flow intensity to the range the particle renderer expects. */
function topology_activity(int $value, int $max = 12): int
{
    return max(0, min($max, $value));
}

/** "https://host:8000/v1" -> "host:8000" for compact node titles. */
function topology_short_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $port = parse_url($url, PHP_URL_PORT);
        return $port !== null ? $host . ':' . $port : $host;
    }
    return $url;
}

/**
 * Parameter size marker of a model name ("gemma-3-27b-it" -> "27b", "qwen3-a3b"
 * -> "3b"). Mirrors extractIntelligenceLabel() in admin/index.php so the
 * topology shows the same label as the legacy load diagram.
 */
function topology_param_size_label(string $model): string
{
    $model = trim($model);
    if ($model === '') {
        return '–';
    }
    if (!preg_match_all('/(?:\b(a))?(\d+(?:[.,]\d+)?)\s*b\b/i', $model, $matches, PREG_SET_ORDER)) {
        return '–';
    }
    $best     = null;
    $fallback = null;
    foreach ($matches as $m) {
        $value = (float) str_replace(',', '.', $m[2]);
        if ($value <= 0) {
            continue;
        }
        if (!empty($m[1])) {
            // MoE active-parameter marker (A17B, A3B, …) – only a fallback.
            if ($fallback === null || $value > $fallback) {
                $fallback = $value;
            }
            continue;
        }
        if ($best === null || $value > $best) {
            $best = $value;
        }
    }
    if ($best === null) {
        $best = $fallback;
    }
    if ($best === null) {
        return '–';
    }
    $rounded = (int) round($best);
    if (abs($best - $rounded) < 1e-6) {
        return $rounded . 'b';
    }
    return rtrim(rtrim(number_format($best, 2, '.', ''), '0'), '.') . 'b';
}

/** Client label: hostname, else IP, else a numbered placeholder. */
function topology_client_label(array $client, int $unknownNo): string
{
    $host = trim((string) ($client['hostname'] ?? ''));
    $ip   = trim((string) ($client['ip'] ?? ''));
    if ($host !== '') {
        $short = explode('.', $host)[0];
        return strlen($host) > 26 && $short !== '' ? $short : $host;
    }
    if ($ip !== '') {
        return $ip;
    }
    return $unknownNo > 1 ? 'unbekannt ' . $unknownNo : 'unbekannt';
}

/** Fill in the defaults every node shares. */
function topology_node(array $node): array
{
    return array_merge([
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
        'cloud'      => null,
        'members'    => null,
        'neighbours' => [],
        'url'        => '',
        'synthetic'  => false,
    ], $node);
}

/** Node state of a single LLM endpoint. */
function topology_endpoint_state(bool $active, int $running, int $slotLimit): array
{
    if (!$active) {
        return ['off', 'Endpunkt ist deaktiviert.'];
    }
    if ($slotLimit > 0 && $running >= $slotLimit) {
        return ['warn', sprintf('Alle %d Parallel-Slots belegt.', $slotLimit)];
    }
    return ['ok', ''];
}

/**
 * Build the topology payload from a load_stats.php response.
 *
 * @param array $stats   Decoded load_stats.php JSON (or an equivalent snapshot).
 * @param array $options base_url    – prefix for internal links ('' for admin/)
 *                       slot_limit  – parallel slots per LLM endpoint (default 4)
 *                       include_off – keep disabled endpoints as nodes (default true)
 */
function topology_build(array $stats, array $options = []): array
{
    $base       = rtrim((string) ($options['base_url'] ?? ''), '/');
    $linkBase   = $base === '' ? '' : $base . '/';
    $slotLimit  = (int) ($options['slot_limit'] ?? 4);
    $includeOff = !array_key_exists('include_off', $options) || $options['include_off'];

    $endpoints      = is_array($stats['endpoints'] ?? null) ? $stats['endpoints'] : [];
    $imageEndpoints = is_array($stats['image_endpoints'] ?? null) ? $stats['image_endpoints'] : [];
    $speechEndpoints = is_array($stats['speech_endpoints'] ?? null) ? $stats['speech_endpoints'] : [];
    $searxng        = is_array($stats['searxng'] ?? null) ? $stats['searxng'] : null;
    $clients        = is_array($stats['clients'] ?? null) ? $stats['clients'] : null;
    $vectorStore    = is_array($stats['vector_store'] ?? null) ? $stats['vector_store'] : null;
    $speechStatus   = is_array($stats['speech_status'] ?? null) ? $stats['speech_status'] : null;
    $imageStatus    = is_array($stats['image_status'] ?? null) ? $stats['image_status'] : null;
    $totals         = is_array($stats['totals'] ?? null) ? $stats['totals'] : [];

    $nodes = [];
    $edges = [];
    $addEdge = function (string $from, string $to, string $state, bool $synthetic = false, string $label = '') use (&$edges): void {
        if ($from === '' || $to === '') {
            return;
        }
        $edges[] = [
            'from'      => $from,
            'to'        => $to,
            'state'     => $state,
            'synthetic' => $synthetic,
            'label'     => $label,
        ];
    };
    /** Compact reference to an already built node, used inside member lists. */
    $memberRef = function (string $key) use (&$nodes): array {
        $node = $nodes[$key] ?? null;
        return [
            'key'      => $key,
            'title'    => $node['title'] ?? $key,
            'subtitle' => $node['subtitle'] ?? '',
            'state'    => $node['state'] ?? 'off',
            'badge'    => $node['badge'] ?? '',
            'facts'    => [],
        ];
    };

    // ── Clients cloud ─────────────────────────────────────────────────────────

    $clientCurrent = $clients !== null ? (int) ($clients['current'] ?? 0) : 0;
    $clientList    = is_array($clients['list'] ?? null) ? $clients['list'] : [];
    $clientItems   = [];
    $unknownNo     = 0;
    foreach ($clientList as $client) {
        if (!is_array($client)) {
            continue;
        }
        $host = trim((string) ($client['hostname'] ?? ''));
        $ip   = trim((string) ($client['ip'] ?? ''));
        if ($host === '' && $ip === '') {
            $unknownNo++;
        }
        $tabs = max(1, (int) ($client['tabs'] ?? 1));
        $who  = $host !== '' ? ($ip !== '' ? $host . ' (' . $ip . ')' : $host)
                             : ($ip !== '' ? $ip : 'Unbekannte Adresse');
        $clientItems[] = [
            'key'      => 'client:' . md5($host . '|' . $ip),
            'title'    => topology_client_label($client, $unknownNo),
            'subtitle' => $who . ' · ' . $tabs . ' Tab' . ($tabs !== 1 ? 's' : ''),
            'state'    => 'ok',
            'badge'    => $tabs . '×',
            'facts'    => [],
        ];
    }
    usort($clientItems, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));

    // One node per connected browser session, orbiting the clients element.
    $clientKeys = [];
    foreach ($clientItems as $item) {
        $key          = (string) $item['key'];
        $clientKeys[] = $key;
        $nodes[$key]  = topology_node([
            'key'      => $key,
            'kind'     => 'client',
            'title'    => (string) $item['title'],
            'subtitle' => (string) $item['subtitle'],
            'state'    => 'ok',
            'badge'    => (string) $item['badge'],
            'message'  => '',
            'activity' => topology_activity(1),
            'facts'    => [
                ['label' => 'Tabs', 'value' => (string) $item['badge']],
            ],
            'url'      => $linkBase . 'index.php#dashboard-card',
        ]);
    }

    $clientFacts = [];
    if ($clients !== null) {
        $clientFacts[] = ['label' => 'Jetzt', 'value' => (string) $clientCurrent];
        $clientFacts[] = ['label' => 'Heute min', 'value' => (string) (int) ($clients['today_min'] ?? 0)];
        $clientFacts[] = ['label' => 'Heute max', 'value' => (string) (int) ($clients['today_max'] ?? 0)];
        $clientFacts[] = ['label' => 'Heute Ø', 'value' => (string) round((float) ($clients['today_avg'] ?? 0), 1)];
        $clientFacts[] = ['label' => 'Tabs', 'value' => (string) array_sum(array_map(
            static fn($c) => max(1, (int) ($c['tabs'] ?? 1)),
            array_filter($clientList, 'is_array')
        ))];
    }

    $clientsKey = 'clients';
    $nodes[$clientsKey] = topology_node([
        'key'        => $clientsKey,
        'kind'       => 'clients',
        'title'      => 'Clients',
        'subtitle'   => $clientCurrent . ' verbunden',
        'state'      => 'ok',
        'badge'      => (string) $clientCurrent,
        'message'    => $clientCurrent === 0
            ? 'Zurzeit ist keine Chat-Sitzung geöffnet.'
            : $clientCurrent . ' aktive Browser-Sitzung' . ($clientCurrent !== 1 ? 'en' : '') . '.',
        'activity'   => topology_activity(max(1, $clientCurrent)),
        'counters'   => [
            'current' => $clientCurrent,
            'peak'    => (int) ($clients['today_max'] ?? $clientCurrent),
            'peak_label' => 'Max heute',
        ],
        'facts'      => $clientFacts,
        'cloud'      => [
            'title' => 'Verbundene Clients',
            'empty' => 'Keine aktiven Clients.',
            'items' => $clientItems,
        ],
        'url'        => $linkBase . 'index.php#dashboard-card',
    ]);
    foreach ($clientKeys as $clientKey) {
        $addEdge($clientsKey, $clientKey, 'ok', true);
    }

    // ── LLM endpoints, grouped by canonical model name ────────────────────────

    $modelGroups = [];
    $llmStates   = [];
    $epKeys      = [];
    foreach ($endpoints as $ep) {
        if (!is_array($ep)) {
            continue;
        }
        $id       = (int) ($ep['id'] ?? 0);
        $active   = !empty($ep['is_active']);
        $running  = (int) ($ep['running'] ?? 0);
        $jobs     = (int) ($ep['today_jobs'] ?? 0);
        $prompt   = (int) ($ep['today_prompt_tokens'] ?? 0);
        $complet  = (int) ($ep['today_completion_tokens'] ?? 0);
        $tokens   = (int) ($ep['today_tokens'] ?? ($prompt + $complet));
        $tps      = (float) ($ep['today_avg_tokens_per_second'] ?? 0.0);
        $sysStats = is_array($ep['sys_stats'] ?? null) ? $ep['sys_stats'] : null;
        $ssh      = !empty($ep['ssh_configured']);

        if (!$active && !$includeOff) {
            continue;
        }

        [$state, $message] = topology_endpoint_state($active, $running, $slotLimit);
        $llmStates[] = $state;

        $facts = [
            ['label' => 'Laufend', 'value' => (string) $running],
            ['label' => 'Jobs heute', 'value' => number_format($jobs, 0, ',', '.')],
            ['label' => 'Prompt Token heute', 'value' => number_format($prompt, 0, ',', '.')],
            ['label' => 'Completion Token heute', 'value' => number_format($complet, 0, ',', '.')],
            ['label' => 'Total Token heute', 'value' => number_format($tokens, 0, ',', '.')],
            ['label' => 'Ø Geschwindigkeit', 'value' => $tps > 0 ? number_format($tps, 1, ',', '.') . ' Tok/s' : '–'],
            ['label' => 'Slots', 'value' => $running . ' / ' . $slotLimit],
        ];
        if ($ssh) {
            if (is_array($sysStats) && !empty($sysStats['ok'])) {
                $ramTotal = (float) ($sysStats['ram_total'] ?? 0);
                $ramUsed  = $sysStats['ram_used'] !== null ? (float) $sysStats['ram_used'] : null;
                $facts[]  = ['label' => 'RAM', 'value' => ($ramTotal > 0 && $ramUsed !== null)
                    ? sprintf('%.1f / %.1f GB (%d%%)', $ramUsed / 1073741824, $ramTotal / 1073741824, (int) round($ramUsed / $ramTotal * 100))
                    : '–'];
                $facts[]  = ['label' => 'CPU-Last 1/5 min', 'value' => sprintf(
                    '%s / %s',
                    $sysStats['cpu_load_1m'] !== null ? number_format((float) $sysStats['cpu_load_1m'], 2, ',', '.') : '–',
                    $sysStats['cpu_load_5m'] !== null ? number_format((float) $sysStats['cpu_load_5m'], 2, ',', '.') : '–'
                )];
                $facts[]  = ['label' => 'CPU-Temperatur', 'value' => $sysStats['cpu_temp'] !== null
                    ? number_format((float) $sysStats['cpu_temp'], 1, ',', '.') . ' °C' : '–'];
            } else {
                $facts[] = ['label' => 'SSH-Systemwerte', 'value' => 'warte auf Daten …'];
            }
        }

        $alias  = trim((string) ($ep['alias'] ?? ''));
        $epUrl  = trim((string) ($ep['base_url'] ?? ''));
        $model  = (string) ($ep['default_model'] ?? '');
        $key    = 'ep:' . $id;
        $epKeys[] = $key;

        $nodes[$key] = topology_node([
            'key'      => $key,
            'kind'     => 'endpoint',
            'title'    => $alias !== '' ? $alias : topology_short_url($epUrl),
            'subtitle' => $epUrl,
            'state'    => $state,
            'badge'    => $running > 0 ? $running . ' laufend' : ($active ? 'bereit' : 'aus'),
            'message'  => $message,
            'activity' => topology_activity($active ? max(1, $running * 2 + ($jobs > 0 ? 1 : 0)) : 0),
            'counters' => [
                'current' => $running,
                'peak'    => $jobs,
                'peak_label' => 'Jobs heute',
            ],
            'facts'    => $facts,
            'url'      => $linkBase . 'index.php#config-endpoints-card',
        ]);

        $canonical = function_exists('canonicalModelName') ? canonicalModelName($model) : strtolower($model);
        if ($canonical === '') {
            $canonical = '–';
        }
        $modelGroups[$canonical]['model'] = $model !== '' ? $model : '–';
        $modelGroups[$canonical]['keys'][] = $key;
        $modelGroups[$canonical]['running'] = ($modelGroups[$canonical]['running'] ?? 0) + $running;
        $modelGroups[$canonical]['jobs'] = ($modelGroups[$canonical]['jobs'] ?? 0) + $jobs;
        $modelGroups[$canonical]['tokens'] = ($modelGroups[$canonical]['tokens'] ?? 0) + $tokens;
        $modelGroups[$canonical]['states'][] = $state;
    }

    // ── Hub (LLMInt core) ─────────────────────────────────────────────────────

    $totalRunning = (int) ($totals['total_running'] ?? 0);
    $hubState     = topology_aggregate_state($llmStates);
    $hubKey       = 'hub';
    $nodes[$hubKey] = topology_node([
        'key'      => $hubKey,
        'kind'     => 'hub',
        'title'    => 'LLMInt',
        'subtitle' => 'System',
        'state'    => $hubState,
        'badge'    => $totalRunning > 0 ? $totalRunning . ' laufend' : '',
        'message'  => $totalRunning > 0
            ? $totalRunning . ' Aufgabe' . ($totalRunning !== 1 ? 'n' : '') . ' in Bearbeitung.'
            : 'Keine laufenden Aufgaben.',
        'activity' => topology_activity(max(1, $totalRunning * 2 + 1)),
        'facts'    => [
            ['label' => 'LLM laufend', 'value' => number_format($totalRunning, 0, ',', '.')],
            ['label' => 'LLM erledigt (24 h)', 'value' => number_format((int) ($totals['total_done_24h'] ?? 0), 0, ',', '.')],
            ['label' => 'LLM erledigt (gesamt)', 'value' => number_format((int) ($totals['total_done'] ?? 0), 0, ',', '.')],
            ['label' => 'LLM Fehler (gesamt)', 'value' => number_format((int) ($totals['total_error'] ?? 0), 0, ',', '.')],
            ['label' => 'Prompt Token (gesamt)', 'value' => number_format((int) ($totals['grand_prompt_tokens'] ?? 0), 0, ',', '.')],
            ['label' => 'Completion Token (gesamt)', 'value' => number_format((int) ($totals['grand_completion_tokens'] ?? 0), 0, ',', '.')],
            ['label' => 'Total Token (gesamt)', 'value' => number_format((int) ($totals['grand_tokens'] ?? 0), 0, ',', '.')],
            ['label' => 'Modelle', 'value' => (string) count($modelGroups)],
            ['label' => 'Endpunkte', 'value' => (string) count($epKeys)],
        ],
        'synthetic' => true,
        'url'       => $linkBase . 'index.php#config-balancer-card',
    ]);

    // ── LLM group (direct child of the core, carries the intelligence groups) ─

    $llmKey      = 'llm';
    $llmDoneJobs = 0;
    $llmTokens   = 0;
    foreach ($modelGroups as $group) {
        $llmDoneJobs += (int) $group['jobs'];
        $llmTokens   += (int) $group['tokens'];
    }
    if ($epKeys !== []) {
        $nodes[$llmKey] = topology_node([
            'key'      => $llmKey,
            'kind'     => 'llm',
            'title'    => 'LLM',
            'subtitle' => count($modelGroups) . ' Intelligenzgruppe' . (count($modelGroups) !== 1 ? 'n' : '')
                . ' · ' . count($epKeys) . ' Endpunkt' . (count($epKeys) !== 1 ? 'e' : ''),
            'state'    => topology_aggregate_state($llmStates),
            'badge'    => $totalRunning > 0 ? $totalRunning . ' laufend' : count($epKeys) . ' Endpunkte',
            'message'  => $totalRunning > 0
                ? $totalRunning . ' laufende Anfrage' . ($totalRunning !== 1 ? 'n' : '') . ' über '
                    . count($modelGroups) . ' Intelligenzgruppe' . (count($modelGroups) !== 1 ? 'n' : '') . '.'
                : 'Keine laufenden Anfragen.',
            'activity' => topology_activity(max(1, $totalRunning * 2 + 1)),
            'counters' => [
                'current'    => $totalRunning,
                'peak'       => $llmDoneJobs,
                'peak_label' => 'Jobs heute',
            ],
            'facts'    => [
                ['label' => 'Intelligenzgruppen', 'value' => (string) count($modelGroups)],
                ['label' => 'Endpunkte', 'value' => (string) count($epKeys)],
                ['label' => 'Laufend', 'value' => number_format($totalRunning, 0, ',', '.')],
                ['label' => 'Jobs heute', 'value' => number_format($llmDoneJobs, 0, ',', '.')],
                ['label' => 'Token heute', 'value' => number_format($llmTokens, 0, ',', '.')],
            ],
            'members'  => [
                'title' => 'Intelligenzgruppen',
                'empty' => 'Keine Intelligenzgruppe zugeordnet.',
                'items' => array_map(static function (string $canonical) use ($modelGroups): array {
                    return [
                        'key'      => 'model:' . $canonical,
                        'title'    => (string) $modelGroups[$canonical]['model'],
                        'subtitle' => count($modelGroups[$canonical]['keys']) . ' Endpunkt'
                            . (count($modelGroups[$canonical]['keys']) !== 1 ? 'e' : ''),
                        'state'    => topology_aggregate_state($modelGroups[$canonical]['states']),
                        'badge'    => $modelGroups[$canonical]['running'] > 0
                            ? '▶ ' . $modelGroups[$canonical]['running'] : '',
                        'facts'    => [],
                    ];
                }, array_keys($modelGroups)),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . 'index.php#config-endpoints-card',
        ]);
        $addEdge($hubKey, $llmKey, $nodes[$llmKey]['state'], true);
    }
    $addEdge($hubKey, $clientsKey, 'ok', true);

    // ── Model groups ──────────────────────────────────────────────────────────

    foreach ($modelGroups as $canonical => $group) {
        $key   = 'model:' . $canonical;
        $state = topology_aggregate_state($group['states']);
        $count = count($group['keys']);
        $nodes[$key] = topology_node([
            'key'      => $key,
            'kind'     => 'model',
            'title'    => $group['model'],
            'subtitle' => $count . ' Endpunkt' . ($count !== 1 ? 'e' : '') . ' · ' . topology_param_size_label($group['model']),
            'state'    => $state,
            'badge'    => $group['running'] > 0 ? '▶ ' . $group['running'] . ' laufend' : '',
            'message'  => $group['running'] > 0
                ? $group['running'] . ' laufende Anfrage' . ($group['running'] !== 1 ? 'n' : '') . ' auf diesem Modell.'
                : 'Keine laufenden Anfragen.',
            'activity' => topology_activity($group['running'] > 0 ? max(1, $group['running'] * 2 + 1) : 1),
            'counters' => [
                'current' => $group['running'],
                'peak'    => $group['jobs'],
                'peak_label' => 'Jobs heute',
            ],
            'facts'    => [
                ['label' => 'Endpunkte', 'value' => (string) $count],
                ['label' => 'Laufend', 'value' => (string) $group['running']],
                ['label' => 'Jobs heute', 'value' => number_format($group['jobs'], 0, ',', '.')],
                ['label' => 'Token heute', 'value' => number_format($group['tokens'], 0, ',', '.')],
                ['label' => 'Parametergröße', 'value' => topology_param_size_label($group['model'])],
            ],
            'members'  => [
                'title' => 'Endpunkte',
                'empty' => 'Keine Endpunkte zugeordnet.',
                'items' => array_map($memberRef, $group['keys']),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . 'index.php#config-endpoints-card',
        ]);
        $addEdge($llmKey, $key, $state, true);
        foreach ($group['keys'] as $epKey) {
            $addEdge($key, $epKey, $nodes[$epKey]['state'] ?? 'ok', true);
        }
    }

    // ── SearXNG ───────────────────────────────────────────────────────────────

    $searxngEnabled = $searxng !== null && (!array_key_exists('enabled', $searxng) || !empty($searxng['enabled']));
    if ($searxngEnabled) {
        $sxRunning = (int) ($searxng['running'] ?? 0);
        $sxJobs    = (int) ($searxng['today_jobs'] ?? 0);
        $sxAvg     = $searxng['avg_duration_seconds'] ?? null;
        $sxState   = 'ok';
        $sxMessage = '';
        if ($sxRunning >= $slotLimit) {
            $sxState   = 'warn';
            $sxMessage = sprintf('Alle %d Parallel-Slots belegt.', $slotLimit);
        }
        $key = 'search';
        $nodes[$key] = topology_node([
            'key'      => $key,
            'kind'     => 'search',
            'title'    => 'SearXNG',
            'subtitle' => (string) ($searxng['base_url'] ?? ''),
            'state'    => $sxState,
            'badge'    => $sxRunning > 0 ? $sxRunning . ' laufend' : 'bereit',
            'message'  => $sxMessage,
            'activity' => topology_activity(max(1, $sxRunning * 2 + ($sxJobs > 0 ? 1 : 0))),
            'counters' => ['current' => $sxRunning, 'peak' => $sxJobs, 'peak_label' => 'Suchen heute'],
            'facts'    => [
                ['label' => 'Laufend', 'value' => (string) $sxRunning],
                ['label' => 'Suchen heute', 'value' => number_format($sxJobs, 0, ',', '.')],
                ['label' => 'Ø Dauer', 'value' => $sxAvg !== null ? number_format((float) $sxAvg, 1, ',', '.') . ' s' : '–'],
                ['label' => 'URL', 'value' => (string) ($searxng['base_url'] ?? '–')],
            ],
            'url'      => $linkBase . 'index.php#config-searxng-card',
        ]);
        $addEdge($hubKey, $key, $sxState);
    }

    // ── Vector store / knowledge base ─────────────────────────────────────────

    $vectorEnabled = $vectorStore !== null
        && (!array_key_exists('enabled', $vectorStore) || !empty($vectorStore['enabled']));
    if ($vectorEnabled) {
        $vOnline = !empty($vectorStore['online']);
        $vQueries = (int) ($vectorStore['today_queries'] ?? 0);
        $vErrors  = (int) ($vectorStore['today_errors'] ?? 0);
        $vState   = $vOnline ? ($vErrors > 0 ? 'warn' : 'ok') : 'error';
        $vMessage = $vOnline
            ? ($vErrors > 0 ? $vErrors . ' fehlgeschlagene Abfrage' . ($vErrors !== 1 ? 'n' : '') . ' heute.' : '')
            : (string) ($vectorStore['detail'] ?? 'Vektordatenbank nicht erreichbar.');
        $key = 'vector';
        $nodes[$key] = topology_node([
            'key'      => $key,
            'kind'     => 'vector',
            'title'    => (string) ($vectorStore['label'] ?? 'Vektordatenbank'),
            'subtitle' => (string) ($vectorStore['base_url'] ?? ''),
            'state'    => $vState,
            'badge'    => $vOnline ? 'online' : 'offline',
            'message'  => $vMessage,
            'activity' => topology_activity($vOnline ? ($vQueries > 0 ? 3 : 1) : 0),
            'counters' => ['current' => $vQueries, 'peak' => $vQueries, 'peak_label' => 'Abfragen heute'],
            'facts'    => [
                ['label' => 'Status', 'value' => $vOnline ? 'online' : 'offline'],
                ['label' => 'Modus', 'value' => (string) ($vectorStore['mode'] ?? '–')],
                ['label' => 'Abfragen heute', 'value' => number_format($vQueries, 0, ',', '.')],
                ['label' => 'Fehler heute', 'value' => number_format($vErrors, 0, ',', '.')],
                ['label' => 'Ø Dauer', 'value' => isset($vectorStore['avg_duration_ms']) && $vectorStore['avg_duration_ms'] !== null
                    ? number_format((float) $vectorStore['avg_duration_ms'], 0, ',', '.') . ' ms' : '–'],
                ['label' => 'Dokumente', 'value' => isset($vectorStore['documents']) && $vectorStore['documents'] !== null
                    ? number_format((int) $vectorStore['documents'], 0, ',', '.') : '–'],
                ['label' => 'Vektoren', 'value' => isset($vectorStore['vectors']) && $vectorStore['vectors'] !== null
                    ? number_format((int) $vectorStore['vectors'], 0, ',', '.') : '–'],
                ['label' => 'Collection', 'value' => (string) ($vectorStore['collection'] ?? '–')],
                ['label' => 'URL', 'value' => (string) ($vectorStore['base_url'] ?? '–')],
            ],
            'url'      => $linkBase . 'index.php#config-vector-store-card',
        ]);
        $addEdge($hubKey, $key, $vState);
    }

    // ── ImageInt (image generation endpoint group) ────────────────────────────

    $imageConfigured = $imageStatus !== null && !empty($imageStatus['configured']);
    $imageReady      = $imageStatus !== null && !empty($imageStatus['ready']);
    $imageLoading    = $imageStatus !== null && !empty($imageStatus['loading']);
    $imageReachable  = $imageStatus !== null && !empty($imageStatus['reachable']);
    $imageProbed     = $imageStatus === null || !array_key_exists('probed', $imageStatus) || !empty($imageStatus['probed']);

    $imageKeys   = [];
    $imageStates = [];
    foreach ($imageEndpoints as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id     = (int) ($row['id'] ?? 0);
        $active = !empty($row['is_active']);
        $url    = rtrim((string) ($row['base_url'] ?? ''), '/');
        $isEffective = $imageConfigured
            && rtrim((string) ($imageStatus['url'] ?? ''), '/') === $url;

        $state   = 'off';
        $message = 'Bild-Endpunkt ist deaktiviert.';
        $badge   = 'aus';
        if ($active) {
            if (!$isEffective) {
                $state   = 'warn';
                $badge   = 'inaktiv';
                $message = 'Endpunkt ist aktiv, aber nicht der wirksame Bild-Endpunkt.';
            } elseif (!$imageProbed) {
                $state   = 'warn';
                $badge   = 'ungeprüft';
                $message = 'Zustand wurde noch nicht geprüft.';
            } elseif ($imageReady) {
                $state   = 'ok';
                $badge   = 'bereit';
                $message = (string) ($imageStatus['message'] ?? '');
            } elseif ($imageLoading) {
                $state   = 'warn';
                $badge   = 'lädt';
                $message = (string) ($imageStatus['message'] ?? 'Modell wird geladen.');
            } elseif (!$imageReachable) {
                $state   = 'error';
                $badge   = 'offline';
                $message = (string) ($imageStatus['message'] ?? 'Endpunkt nicht erreichbar.');
            } else {
                $state   = 'warn';
                $badge   = (string) ($imageStatus['status'] ?? 'warn');
                $message = (string) ($imageStatus['message'] ?? '');
            }
        }

        $facts = [
            ['label' => 'Alias', 'value' => trim((string) ($row['alias'] ?? '')) !== '' ? (string) $row['alias'] : '–'],
            ['label' => 'URL', 'value' => (string) ($row['base_url'] ?? '–')],
            ['label' => 'Timeout', 'value' => (int) ($row['timeout'] ?? 0) . ' s'],
            ['label' => 'Wirksam', 'value' => $isEffective ? 'ja' : 'nein'],
        ];
        if ($isEffective && $imageStatus !== null) {
            $facts[] = ['label' => 'Status', 'value' => (string) ($imageStatus['status'] ?? '–')];
            $facts[] = ['label' => 'Quelle', 'value' => (string) ($imageStatus['source'] ?? '–')];
            if (!empty($imageStatus['retry_after'])) {
                $facts[] = ['label' => 'Erneut in', 'value' => (int) $imageStatus['retry_after'] . ' s'];
            }
        }

        $key = 'image-ep:' . $id;
        $nodes[$key] = topology_node([
            'key'      => $key,
            'kind'     => 'endpoint',
            'title'    => trim((string) ($row['alias'] ?? '')) !== '' ? (string) $row['alias'] : topology_short_url($url),
            'subtitle' => (string) ($row['base_url'] ?? ''),
            'state'    => $state,
            'badge'    => $badge,
            'message'  => $message,
            'activity' => topology_activity($state === 'ok' ? 2 : 0),
            'facts'    => $facts,
            'url'      => $linkBase . 'index.php#config-image-card',
        ]);
        $imageKeys[]   = $key;
        $imageStates[] = $state;
    }

    if ($imageKeys !== []) {
        $imageKey = 'image';
        $imageOk  = count(array_filter($imageStates, static fn(string $s): bool => $s === 'ok'));
        $nodes[$imageKey] = topology_node([
            'key'      => $imageKey,
            'kind'     => 'image',
            'title'    => 'ImageInt',
            'subtitle' => count($imageKeys) . ' Endpunkt' . (count($imageKeys) !== 1 ? 'e' : ''),
            'state'    => topology_aggregate_state($imageStates),
            'badge'    => $imageOk > 0 ? $imageOk . ' bereit' : '',
            'message'  => $imageOk > 0
                ? $imageOk . ' von ' . count($imageKeys) . ' Bild-Endpunkt' . (count($imageKeys) !== 1 ? 'en' : '') . ' bereit.'
                : 'Kein Bild-Endpunkt ist einsatzbereit.',
            'activity' => topology_activity($imageOk > 0 ? max(1, $imageOk * 2) : 0),
            'facts'    => [
                ['label' => 'Endpunkte', 'value' => (string) count($imageKeys)],
                ['label' => 'Bereit', 'value' => (string) $imageOk],
                ['label' => 'Wirksam', 'value' => (string) ($imageStatus['url'] ?? '–')],
            ],
            'members'  => [
                'title' => 'Bild-Endpunkte',
                'empty' => 'Keine Bild-Endpunkte konfiguriert.',
                'items' => array_map($memberRef, $imageKeys),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . 'index.php#config-image-card',
        ]);
        $addEdge($hubKey, $imageKey, $nodes[$imageKey]['state'], true);
        foreach ($imageKeys as $imageEpKey) {
            $addEdge($imageKey, $imageEpKey, $nodes[$imageEpKey]['state'] ?? 'ok', true);
        }
    }

    // ── SpeechInt (dictation) ─────────────────────────────────────────────────

    $speechConfigured = $speechStatus !== null && !empty($speechStatus['configured']);
    $speechReady      = $speechStatus !== null && !empty($speechStatus['ready']);
    $speechLoading    = $speechStatus !== null && !empty($speechStatus['loading']);
    $speechReachable  = $speechStatus !== null && !empty($speechStatus['reachable']);
    $speechProbed     = $speechStatus === null || !array_key_exists('probed', $speechStatus) || !empty($speechStatus['probed']);
    $speechKeys       = [];
    $speechStates     = [];
    foreach ($speechEndpoints as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id     = (int) ($row['id'] ?? 0);
        $active = !empty($row['is_active']);
        $url    = rtrim((string) ($row['base_url'] ?? ''), '/');
        $isEffective = $speechConfigured
            && rtrim((string) ($speechStatus['url'] ?? ''), '/') === $url;

        $state   = 'off';
        $message = 'Dictation-Endpunkt ist deaktiviert.';
        $badge   = 'aus';
        if ($active) {
            if (!$isEffective) {
                $state   = 'warn';
                $badge   = 'inaktiv';
                $message = 'Endpunkt ist aktiv, aber nicht der wirksame Dictation-Endpunkt.';
            } elseif (!$speechProbed) {
                $state   = 'warn';
                $badge   = 'ungeprüft';
                $message = 'Zustand wurde noch nicht geprüft.';
            } elseif ($speechReady) {
                $state   = 'ok';
                $badge   = 'bereit';
                $message = (string) ($speechStatus['message'] ?? '');
            } elseif ($speechLoading) {
                $state   = 'warn';
                $badge   = 'lädt';
                $message = (string) ($speechStatus['message'] ?? 'Modell wird geladen.');
            } elseif (!$speechReachable) {
                $state   = 'error';
                $badge   = 'offline';
                $message = (string) ($speechStatus['message'] ?? 'Endpunkt nicht erreichbar.');
            } else {
                $state   = 'warn';
                $badge   = (string) ($speechStatus['status'] ?? 'warn');
                $message = (string) ($speechStatus['message'] ?? '');
            }
        }

        $facts = [
            ['label' => 'Alias', 'value' => (string) ($row['alias'] ?? '–')],
            ['label' => 'URL', 'value' => (string) ($row['base_url'] ?? '–')],
            ['label' => 'Timeout', 'value' => (int) ($row['timeout'] ?? 0) . ' s'],
            ['label' => 'Wirksam', 'value' => $isEffective ? 'ja' : 'nein'],
        ];
        if ($isEffective && $speechStatus !== null) {
            $facts[] = ['label' => 'Status', 'value' => (string) ($speechStatus['status'] ?? '–')];
            $facts[] = ['label' => 'Quelle', 'value' => (string) ($speechStatus['source'] ?? '–')];
            if (!empty($speechStatus['retry_after'])) {
                $facts[] = ['label' => 'Erneut in', 'value' => (int) $speechStatus['retry_after'] . ' s'];
            }
        }

        $key = 'speech-ep:' . $id;
        $nodes[$key] = topology_node([
            'key'      => $key,
            'kind'     => 'endpoint',
            'title'    => trim((string) ($row['alias'] ?? '')) !== '' ? (string) $row['alias'] : topology_short_url($url),
            'subtitle' => (string) ($row['base_url'] ?? ''),
            'state'    => $state,
            'badge'    => $badge,
            'message'  => $message,
            'activity' => topology_activity($state === 'ok' ? 2 : 0),
            'facts'    => $facts,
            'url'      => $linkBase . 'index.php#config-speech-card',
        ]);
        $speechKeys[]   = $key;
        $speechStates[] = $state;
    }

    if ($speechKeys !== []) {
        $speechKey = 'speech';
        $speechOk  = count(array_filter($speechStates, static fn(string $s): bool => $s === 'ok'));
        $nodes[$speechKey] = topology_node([
            'key'      => $speechKey,
            'kind'     => 'speech',
            'title'    => 'SpeechInt',
            'subtitle' => count($speechKeys) . ' Endpunkt' . (count($speechKeys) !== 1 ? 'e' : ''),
            'state'    => topology_aggregate_state($speechStates),
            'badge'    => $speechOk > 0 ? $speechOk . ' bereit' : '',
            'message'  => $speechOk > 0
                ? $speechOk . ' von ' . count($speechKeys) . ' Dictation-Endpunkt' . (count($speechKeys) !== 1 ? 'en' : '') . ' bereit.'
                : 'Kein Dictation-Endpunkt ist einsatzbereit.',
            'activity' => topology_activity($speechOk > 0 ? max(1, $speechOk * 2) : 0),
            'facts'    => [
                ['label' => 'Endpunkte', 'value' => (string) count($speechKeys)],
                ['label' => 'Bereit', 'value' => (string) $speechOk],
                ['label' => 'Wirksam', 'value' => (string) ($speechStatus['url'] ?? '–')],
            ],
            'members'  => [
                'title' => 'Dictation-Endpunkte',
                'empty' => 'Keine Dictation-Endpunkte konfiguriert.',
                'items' => array_map($memberRef, $speechKeys),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . 'index.php#config-speech-card',
        ]);
        $addEdge($hubKey, $speechKey, $nodes[$speechKey]['state'], true);
        foreach ($speechKeys as $speechEpKey) {
            $addEdge($speechKey, $speechEpKey, $nodes[$speechEpKey]['state'] ?? 'ok', true);
        }
    }

    // ── Neighbour lists (used by the detail panel) ────────────────────────────

    foreach ($edges as $edge) {
        if (isset($nodes[$edge['from']])) {
            $nodes[$edge['from']]['neighbours'][] = $edge['to'];
        }
        if (isset($nodes[$edge['to']])) {
            $nodes[$edge['to']]['neighbours'][] = $edge['from'];
        }
    }
    foreach ($nodes as $key => $node) {
        $nodes[$key]['neighbours'] = array_values(array_unique($node['neighbours']));
    }

    // ── Overall state + incidents ─────────────────────────────────────────────

    $errorKeys    = [];
    $warningKeys  = [];
    $incidents    = [];
    foreach ($nodes as $key => $node) {
        if (!empty($node['group'])) {
            // Group containers aggregate their children; the concrete child
            // already reports the problem, so they would only duplicate it.
            continue;
        }
        if ($node['state'] === 'error') {
            $errorKeys[] = $key;
        } elseif ($node['state'] === 'warn') {
            $warningKeys[] = $key;
        }
    }
    foreach (array_merge($errorKeys, $warningKeys) as $key) {
        $node = $nodes[$key];
        $incidents[] = [
            'key'     => $key,
            'title'   => $node['title'] . ' – ' . topology_state_label($node['state']),
            'state'   => $node['state'],
            'message' => $node['message'] !== '' ? $node['message'] : $node['subtitle'],
            'url'     => $node['url'],
        ];
    }

    $overallState = topology_aggregate_state(array_map(static fn(array $n) => $n['state'], $nodes));
    if ($incidents === []) {
        $overallMessage = 'Alle ' . count($nodes) . ' Bausteine melden einen unauffälligen Zustand.';
    } else {
        $parts = [];
        if ($errorKeys !== []) {
            $parts[] = count($errorKeys) . ' Störung' . (count($errorKeys) !== 1 ? 'en' : '');
        }
        if ($warningKeys !== []) {
            $parts[] = count($warningKeys) . ' Einschränkung' . (count($warningKeys) !== 1 ? 'en' : '');
        }
        $overallMessage = implode(' und ', $parts) . ' erkannt.';
    }

    return [
        'generated' => (int) ($stats['ts'] ?? time()),
        'overall'   => [
            'state'    => $overallState,
            'label'    => topology_state_label($overallState),
            'message'  => $overallMessage,
            'errors'   => count($errorKeys),
            'warnings' => count($warningKeys),
            'nodes'    => count($nodes),
        ],
        'nodes'     => $nodes,
        'edges'     => $edges,
        'incidents' => $incidents,
    ];
}

/**
 * Render the initial payload as the JSON island consumed by the front end
 * (see parseInitial() in assets/js/flow-topology.js). Returns an empty string
 * for an empty payload so the front end falls back to an immediate fetch.
 */
function topology_initial_script(array $topology): string
{
    if (($topology['nodes'] ?? []) === []) {
        return '';
    }
    $json = json_encode(
        $topology,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return '<script type="application/json" data-flow-initial>' . $json . '</script>';
}
