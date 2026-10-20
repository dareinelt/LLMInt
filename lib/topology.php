<?php

/**
 * lib/topology.php
 *
 * Translation layer between the live dashboard statistics (the JSON contract of
 * admin/load_stats.php) and the node/edge payload rendered by the message-flow
 * topology (assets/js/flow-topology.js). Used by the dashboard card in
 * admin/index.php, by the full-screen view in admin/topology.php and by the
 * deep links of every admin page that hosts one of the referenced cards
 * (see topology_admin_link()).
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
 *         'facts'     => [['icon'=>..,'label'=>..,'value'=>..,'tone'=>..], …],
 *         'slots'     => ['used'=>int,'max'=>int] | null (parallel slots bar),
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
 *     ├─ clients  → client (einzelne Browser-Sitzung)
 *     └─ api      → api-key (Aufruf über die Reverse-Proxy-Schnittstelle)
 *
 * The three service groups (llm, speech, image) are the direct children of the
 * core; only the LLM group carries the additional intelligence groups, because
 * only LLM endpoints are grouped by model. Group nodes carry 'group' => true and
 * are excluded from the incident list so problems are reported on the concrete
 * endpoint that caused them.
 *
 * Callers
 * -------
 * The two branches that *use* the system – clients and api – each carry a cloud
 * of their concrete callers: one node per open browser session and one node per
 * API key (plus a shared node for calls without a usable key). Their cloud items
 * are a list for the side panel; the nodes themselves are regular children of
 * their group and are placed as a volumetric swarm by the front end (see
 * cloudAround() in flow-topology.js).
 *
 * Node kinds: clients, client, hub, llm, model, endpoint, search, vector, image,
 * speech, api, api-key (see topology_kinds()). States: ok, warn, error, off.
 *
 * Detail cards
 * ------------
 * The front end turns a clicked node into an in-canvas detail card. Nodes that
 * carry a 'cloud' or 'members' list (clients, api, llm, model, image, speech)
 * keep the HTML side panel instead, because their payload is a list and not a
 * set of key/value rows. Every other node renders 'facts' as "icon  label: value"
 * rows in the colour named by 'tone' (see FACT_TONES in flow-topology.js) plus
 * the 'slots' bar when present.
 */

/**
 * Seconds after its last call in which an API key still counts as active and is
 * drawn as a "present" (green) node. Mirrors the presence window of the browser
 * sessions (see listActiveClients() in db.php).
 */
const TOPOLOGY_API_KEY_PRESENCE_SECONDS = 90;

/** All node kinds known to the front end. */
function topology_kinds(): array
{
    return ['clients', 'client', 'hub', 'llm', 'model', 'endpoint', 'search', 'vector', 'image', 'speech', 'api', 'api-key'];
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

/**
 * Compact counter used by the endpoint detail cards. Mirrors formatNum() of the
 * previous dashboard graphic: 3512 -> "3.5K", 1250000 -> "1.2M".
 */
function topology_format_num($value): string
{
    $n = (float) $value;
    if ($n >= 1000000) {
        return number_format($n / 1000000, 1, '.', '') . 'M';
    }
    if ($n >= 1000) {
        return number_format($n / 1000, 1, '.', '') . 'K';
    }
    return (string) (int) $n;
}

/**
 * One row of a node detail card.
 *
 * The front end renders "icon  label: value" in the colour of $tone; an empty
 * label collapses to "icon  value". See FACT_TONES in flow-topology.js.
 */
function topology_fact(string $icon, string $label, string $value, string $tone = 'plain'): array
{
    return ['icon' => $icon, 'label' => $label, 'value' => $value, 'tone' => $tone];
}

/** Colour tone for a CPU temperature reading (°C). */
function topology_temp_tone($temp): string
{
    if ($temp === null || $temp === '') {
        return 'muted';
    }
    $value = (float) $temp;
    if ($value >= 80) {
        return 'error';
    }
    return $value >= 65 ? 'warn' : 'temp';
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
 * -> "3b"), used as the label of the intelligence groups in the topology. A MoE
 * active-parameter marker (A3B, A17B) is only a fallback so that "qwen3-a3b"
 * still yields a readable size.
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

/**
 * Age of a timestamp in seconds as a short German phrase ("vor 12 s"), or "–"
 * when the entry was never used. Also used for the remaining lifetime of an
 * expiring API key.
 */
function topology_age_label(?int $seconds): string
{
    if ($seconds === null) {
        return '–';
    }
    if ($seconds < 60) {
        return $seconds <= 1 ? 'gerade eben' : 'vor ' . $seconds . ' s';
    }
    if ($seconds < 3600) {
        return 'vor ' . (int) floor($seconds / 60) . ' min';
    }
    if ($seconds < 86400) {
        return 'vor ' . (int) floor($seconds / 3600) . ' h';
    }
    return 'vor ' . (int) floor($seconds / 86400) . ' d';
}

/**
 * Human label of a running request kind (see CLIENT_ROUTE_KINDS in db.php).
 */
function topology_route_label(string $kind): string
{
    switch ($kind) {
        case 'llm':
            return 'Laufende Anfrage';
        case 'image':
            return 'Laufende Bildgenerierung';
        case 'speech':
            return 'Laufende Spracherkennung';
        case 'search':
            return 'Laufende Websuche';
    }
    return 'Laufende Anfrage';
}

/**
 * Resolve a client's running request into the node keys of the path it walks
 * (see the "Path of the running request" section in topology_build()).
 *
 * @param array<string,array> $nodes All nodes built so far.
 * @return array<int,string> Target node keys – empty when nothing matches.
 */
function topology_route_targets(array $route, array $nodes): array
{
    $kind = (string) ($route['kind'] ?? '');
    $id   = (int) ($route['endpoint_id'] ?? 0);
    $model = trim((string) ($route['model'] ?? ''));

    $targets = [];
    switch ($kind) {
        case 'llm':
            $canonical = function_exists('canonicalModelName')
                ? canonicalModelName($model)
                : strtolower($model);
            if ($canonical !== '' && isset($nodes['model:' . $canonical])) {
                $targets[] = 'model:' . $canonical;
            }
            if ($id > 0 && isset($nodes['ep:' . $id])) {
                $targets[] = 'ep:' . $id;
            }
            if ($targets === [] && isset($nodes['llm'])) {
                $targets[] = 'llm';
            }
            break;
        case 'image':
            if ($id > 0 && isset($nodes['image-ep:' . $id])) {
                $targets[] = 'image-ep:' . $id;
            } elseif (isset($nodes['image'])) {
                $targets[] = 'image';
            }
            break;
        case 'speech':
            if ($id > 0 && isset($nodes['speech-ep:' . $id])) {
                $targets[] = 'speech-ep:' . $id;
            } elseif (isset($nodes['speech'])) {
                $targets[] = 'speech';
            }
            break;
        case 'search':
            if (isset($nodes['search'])) {
                $targets[] = 'search';
            }
            break;
    }
    return $targets;
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
        'slots'      => null,
        'cloud'      => null,
        'members'    => null,
        'neighbours' => [],
        'route'      => null,
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
 * Admin page that hosts the card with $anchor, as a page-relative deep link.
 *
 * The admin one-pager was split into one page per navigation entry, so an
 * anchor on its own no longer identifies a URL. Unknown anchors fall back to
 * the dashboard page, which is where the topology view itself is rendered.
 */
function topology_admin_link(string $anchor): string
{
    static $pages = [
        'dashboard-card'            => 'index.php',
        'config-smtp-card'          => 'smtp.php',
        'config-speech-card'        => 'speech.php',
        'config-image-card'         => 'image.php',
        'config-searxng-card'       => 'search.php',
        'config-endpoints-card'     => 'endpoints.php',
        'config-balancer-card'      => 'balancer.php',
        'config-vector-store-card'  => 'vector_store.php',
        'api-keys-card'             => 'api_keys.php',
    ];

    return ($pages[$anchor] ?? 'index.php') . '#' . $anchor;
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

        // The request this tab is waiting for right now (empty while idle).
        // The path it belongs to is resolved further down, once every node of
        // the graph exists – see "Path of the running request".
        $routeKind = strtolower(trim((string) ($client['route_kind'] ?? '')));
        $busy      = in_array($routeKind, CLIENT_ROUTE_KINDS, true);

        $clientItems[] = [
            'key'      => 'client:' . md5($host . '|' . $ip),
            'title'    => topology_client_label($client, $unknownNo),
            'subtitle' => $who . ' · ' . $tabs . ' Tab' . ($tabs !== 1 ? 's' : ''),
            'state'    => 'ok',
            'badge'    => $tabs . '×',
            'facts'    => [],
            'route'    => $busy ? [
                'kind'        => $routeKind,
                'model'       => trim((string) ($client['route_model'] ?? '')),
                'endpoint_id' => (int) ($client['route_endpoint_id'] ?? 0),
                'seconds'     => isset($client['route_seconds']) && $client['route_seconds'] !== null
                    ? (int) $client['route_seconds'] : null,
            ] : null,
        ];
    }
    usort($clientItems, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));

    // One node per connected browser session, orbiting the clients element.
    $clientKeys = [];
    foreach ($clientItems as $item) {
        $key          = (string) $item['key'];
        $clientKeys[] = $key;
        $route        = is_array($item['route'] ?? null) ? $item['route'] : null;

        $facts = [topology_fact('🗔', 'Tabs', (string) $item['badge'], 'plain')];
        if ($route === null) {
            $facts[] = topology_fact('⚡', 'Laufende Anfrage', 'keine', 'muted');
        } else {
            $running = (string) $route['model'];
            if ($running !== '' && $route['seconds'] !== null) {
                $running .= ' · seit ' . $route['seconds'] . ' s';
            }
            $facts[] = topology_fact('⚡', topology_route_label((string) $route['kind']),
                $running !== '' ? $running : '–', 'running');
        }

        $nodes[$key]  = topology_node([
            'key'      => $key,
            'kind'     => 'client',
            'title'    => (string) $item['title'],
            'subtitle' => (string) $item['subtitle'],
            'state'    => 'ok',
            'badge'    => (string) $item['badge'],
            'message'  => '',
            'activity' => topology_activity($route !== null ? 3 : 1),
            'facts'    => $facts,
            'url'      => $linkBase . topology_admin_link('dashboard-card'),
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
        'url'        => $linkBase . topology_admin_link('dashboard-card'),
    ]);
    foreach ($clientKeys as $clientKey) {
        $addEdge($clientsKey, $clientKey, 'ok', true);
    }

    // ── API cloud (Aufrufe über die Reverse-Proxy-Funktion) ───────────────────
    // Every API key is a caller of its own: it stands for the external clients
    // that identify themselves with that key (see recordApiCallUsage() in
    // db.php). Calls without a usable key are collected in one shared entry.

    $apiUsage = is_array($stats['api'] ?? null) ? $stats['api'] : null;
    $apiKeys  = is_array($apiUsage['keys'] ?? null) ? $apiUsage['keys'] : [];
    $apiAnon  = is_array($apiUsage['anonymous'] ?? null) ? $apiUsage['anonymous'] : null;

    /** Counters and facts shared by a key entry and the anonymous entry. */
    $apiCallerFacts = function (int $calls, int $today, string $lastModel, ?int $age, bool $present): array {
        return [
            topology_fact('✓', 'Aufrufe', topology_format_num($calls), $calls > 0 ? 'jobs' : 'muted'),
            topology_fact('✓', 'Aufrufe heute', topology_format_num($today), $today > 0 ? 'jobs' : 'muted'),
            topology_fact('⬡', 'Letztes Modell', $lastModel !== '' ? $lastModel : '–', 'total'),
            topology_fact('⏱', 'Zuletzt benutzt', topology_age_label($age), $present ? 'running' : 'muted'),
        ];
    };

    $apiItems = [];
    foreach ($apiKeys as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id        = (int) ($entry['id'] ?? 0);
        $name      = trim((string) ($entry['name'] ?? ''));
        $usable    = !empty($entry['usable']);
        $age       = isset($entry['last_used_age']) && $entry['last_used_age'] !== null
            ? (int) $entry['last_used_age'] : null;
        $present   = $usable && $age !== null && $age <= TOPOLOGY_API_KEY_PRESENCE_SECONDS;
        $calls     = (int) ($entry['calls'] ?? 0);
        $today     = (int) ($entry['calls_today'] ?? 0);
        $lastModel = trim((string) ($entry['last_model'] ?? ''));
        $owner     = trim((string) ($entry['username'] ?? ''));
        $prefix    = trim((string) ($entry['key_prefix'] ?? ''));
        $pinned    = trim((string) ($entry['model'] ?? ''));

        if (!$usable) {
            $state   = 'off';
            $message = !empty($entry['is_active'])
                ? 'Der Schlüssel ist abgelaufen und wird nicht mehr angenommen.'
                : 'Der Schlüssel ist deaktiviert.';
        } elseif ($present) {
            $state   = 'ok';
            $message = 'Mit diesem Schlüssel läuft gerade ein Aufruf.';
        } else {
            $state   = 'off';
            $message = 'Zurzeit keine Aufrufe mit diesem Schlüssel.';
        }

        $facts = $apiCallerFacts($calls, $today, $lastModel, $age, $present);
        $facts[] = topology_fact('🔑', 'Präfix', $prefix !== '' ? $prefix . '…' : '–', 'plain');
        $facts[] = topology_fact('👤', 'Besitzer', $owner !== '' ? $owner : '–', 'plain');
        if ($pinned !== '') {
            $facts[] = topology_fact('📌', 'Festes Modell', $pinned, 'total');
        }
        if (isset($entry['expires_in']) && $entry['expires_in'] !== null) {
            $expiresIn = (int) $entry['expires_in'];
            $facts[] = $expiresIn > 0
                ? topology_fact('⌛', 'Gültig noch', topology_age_label($expiresIn), 'plain')
                : topology_fact('⌛', 'Gültig noch', 'abgelaufen', 'error');
        }

        $apiItems[] = [
            'key'      => 'api-key:' . $id,
            'title'    => $name !== '' ? $name : 'Schlüssel #' . $id,
            'subtitle' => ($prefix !== '' ? $prefix . '…' : 'API-Schlüssel')
                . ($owner !== '' ? ' · ' . $owner : ''),
            'state'    => $state,
            'badge'    => $today > 0 ? $today . ' heute' : ($usable ? '' : 'inaktiv'),
            'message'  => $message,
            'activity' => $state === 'ok' ? 4 : 1,
            'facts'    => $facts,
        ];
    }

    if ($apiAnon !== null) {
        $anonCalls = (int) ($apiAnon['calls'] ?? 0);
        $anonToday = (int) ($apiAnon['calls_today'] ?? 0);
        $anonAge   = isset($apiAnon['last_used_age']) && $apiAnon['last_used_age'] !== null
            ? (int) $apiAnon['last_used_age'] : null;
        $anonModel = trim((string) ($apiAnon['last_model'] ?? ''));
        $present   = $anonAge !== null && $anonAge <= TOPOLOGY_API_KEY_PRESENCE_SECONDS;

        $apiItems[] = [
            'key'      => 'api-key:anon',
            'title'    => 'ohne Key',
            'subtitle' => 'Aufrufe ohne gültigen Schlüssel',
            'state'    => $present ? 'ok' : 'off',
            'badge'    => $anonToday > 0 ? $anonToday . ' heute' : '',
            'message'  => $present
                ? 'Gerade ruft jemand ohne gültigen Schlüssel auf.'
                : 'Anonyme Aufrufe ohne gültigen Schlüssel.',
            'activity' => $present ? 4 : 1,
            'facts'    => $apiCallerFacts($anonCalls, $anonToday, $anonModel, $anonAge, $present),
        ];
    }

    usort($apiItems, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));

    $apiKeyNodes = [];
    foreach ($apiItems as $item) {
        $key           = (string) $item['key'];
        $apiKeyNodes[] = $key;
        $nodes[$key]   = topology_node([
            'key'      => $key,
            'kind'     => 'api-key',
            'title'    => (string) $item['title'],
            'subtitle' => (string) $item['subtitle'],
            'state'    => (string) $item['state'],
            'badge'    => (string) $item['badge'],
            'message'  => (string) $item['message'],
            'activity' => topology_activity((int) $item['activity']),
            'facts'    => $item['facts'],
            'url'      => $linkBase . topology_admin_link('api-keys-card'),
        ]);
    }

    $apiCalls      = (int) ($apiUsage['total_calls'] ?? 0);
    $apiCallsToday = (int) ($apiUsage['calls_today'] ?? 0);
    $apiPresent    = count(array_filter($apiItems, static fn(array $i): bool => $i['state'] === 'ok'));

    $apiFacts = [
        topology_fact('✓', 'Aufrufe heute', topology_format_num($apiCallsToday), $apiCallsToday > 0 ? 'jobs' : 'muted'),
        topology_fact('✓', 'Aufrufe gesamt', topology_format_num($apiCalls), $apiCalls > 0 ? 'jobs' : 'muted'),
        topology_fact('●', 'Gerade aktiv', (string) $apiPresent, $apiPresent > 0 ? 'running' : 'muted'),
        topology_fact('🔑', 'Schlüssel', (string) count($apiKeys), 'plain'),
    ];
    if ($apiAnon !== null) {
        $apiFacts[] = topology_fact('👤', 'Ohne Schlüssel', topology_format_num((int) ($apiAnon['calls'] ?? 0)), 'plain');
    }

    $apiNodeKey = 'api';
    $nodes[$apiNodeKey] = topology_node([
        'key'      => $apiNodeKey,
        'kind'     => 'api',
        'title'    => 'API',
        'subtitle' => 'Reverse Proxy · ' . count($apiKeys) . ' Schlüssel',
        'state'    => 'ok',
        'badge'    => $apiCallsToday > 0 ? $apiCallsToday . ' heute' : '',
        'message'  => $apiCallsToday > 0
            ? $apiCallsToday . ' Aufruf' . ($apiCallsToday !== 1 ? 'e' : '') . ' über die API heute.'
            : 'Zurzeit keine Aufrufe über die API.',
        'activity' => topology_activity(max(1, $apiCallsToday)),
        'facts'    => $apiFacts,
        'cloud'    => [
            'title' => 'Aufrufe über die API',
            'empty' => 'Keine API-Aufrufe.',
            'items' => $apiItems,
        ],
        'url'      => $linkBase . topology_admin_link('api-keys-card'),
    ]);
    foreach ($apiKeyNodes as $apiKeyNode) {
        $addEdge($apiNodeKey, $apiKeyNode, 'ok', true);
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
            topology_fact('▶', 'Laufend', (string) $running, $running > 0 ? 'running' : 'muted'),
            topology_fact('✓', 'Jobs heute', topology_format_num($jobs), 'jobs'),
            topology_fact('↑', 'Prompt heute', topology_format_num($prompt), 'prompt'),
            topology_fact('↓', 'Completion', topology_format_num($complet), 'completion'),
            topology_fact('⬡', 'Total Token', topology_format_num($tokens), 'total'),
            topology_fact('⚡', 'Ø Geschwindigkeit', $tps > 0 ? number_format($tps, 1, ',', '.') . ' Tok/s' : '–', 'speed'),
        ];
        if ($ssh) {
            if (is_array($sysStats) && !empty($sysStats['ok'])) {
                $ramTotal = (float) ($sysStats['ram_total'] ?? 0);
                $ramUsed  = $sysStats['ram_used'] !== null ? (float) $sysStats['ram_used'] : null;
                $facts[]  = topology_fact('🧠', 'RAM', ($ramTotal > 0 && $ramUsed !== null)
                    ? sprintf('%.1f / %.1f GB (%d%%)', $ramUsed / 1073741824, $ramTotal / 1073741824, (int) round($ramUsed / $ramTotal * 100))
                    : '–', 'ram');
                $facts[]  = topology_fact('📊', 'CPU-Last', sprintf(
                    '%s (5 min: %s)',
                    $sysStats['cpu_load_1m'] !== null ? number_format((float) $sysStats['cpu_load_1m'], 2, ',', '.') : '–',
                    $sysStats['cpu_load_5m'] !== null ? number_format((float) $sysStats['cpu_load_5m'], 2, ',', '.') : '–'
                ), 'cpu');
                $facts[]  = topology_fact('🌡', 'Temp', $sysStats['cpu_temp'] !== null
                    ? number_format((float) $sysStats['cpu_temp'], 1, ',', '.') . ' °C' : '–', topology_temp_tone($sysStats['cpu_temp'] ?? null));
            } else {
                $facts[] = topology_fact('🔑', 'SSH', 'warte auf Daten …', 'muted');
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
            'slots'    => ['used' => $running, 'max' => $slotLimit],
            'url'      => $linkBase . topology_admin_link('config-endpoints-card'),
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
            topology_fact('▶', 'LLM laufend', topology_format_num($totalRunning), $totalRunning > 0 ? 'running' : 'muted'),
            topology_fact('✓', 'LLM erledigt (24 h)', topology_format_num((int) ($totals['total_done_24h'] ?? 0)), 'jobs'),
            topology_fact('✓', 'LLM erledigt (gesamt)', topology_format_num((int) ($totals['total_done'] ?? 0)), 'jobs'),
            topology_fact('⚠', 'LLM Fehler (gesamt)', topology_format_num((int) ($totals['total_error'] ?? 0)),
                (int) ($totals['total_error'] ?? 0) > 0 ? 'error' : 'muted'),
            topology_fact('↑', 'Prompt Token (gesamt)', topology_format_num((int) ($totals['grand_prompt_tokens'] ?? 0)), 'prompt'),
            topology_fact('↓', 'Completion Token (gesamt)', topology_format_num((int) ($totals['grand_completion_tokens'] ?? 0)), 'completion'),
            topology_fact('⬡', 'Total Token (gesamt)', topology_format_num((int) ($totals['grand_tokens'] ?? 0)), 'total'),
            topology_fact('🧩', 'Modelle', (string) count($modelGroups), 'plain'),
            topology_fact('🔌', 'Endpunkte', (string) count($epKeys), 'plain'),
        ],
        'synthetic' => true,
        'url'       => $linkBase . topology_admin_link('config-balancer-card'),
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
                topology_fact('🧩', 'Intelligenzgruppen', (string) count($modelGroups), 'plain'),
                topology_fact('🔌', 'Endpunkte', (string) count($epKeys), 'plain'),
                topology_fact('▶', 'Laufend', (string) $totalRunning, $totalRunning > 0 ? 'running' : 'muted'),
                topology_fact('✓', 'Jobs heute', topology_format_num($llmDoneJobs), 'jobs'),
                topology_fact('⬡', 'Token heute', topology_format_num($llmTokens), 'total'),
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
            'url'       => $linkBase . topology_admin_link('config-endpoints-card'),
        ]);
        $addEdge($hubKey, $llmKey, $nodes[$llmKey]['state'], true);
    }
    $addEdge($hubKey, $clientsKey, 'ok', true);
    $addEdge($hubKey, $apiNodeKey, 'ok', true);

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
                topology_fact('🔌', 'Endpunkte', (string) $count, 'plain'),
                topology_fact('▶', 'Laufend', (string) $group['running'], $group['running'] > 0 ? 'running' : 'muted'),
                topology_fact('✓', 'Jobs heute', topology_format_num($group['jobs']), 'jobs'),
                topology_fact('⬡', 'Token heute', topology_format_num($group['tokens']), 'total'),
                topology_fact('🧮', 'Parametergröße', topology_param_size_label($group['model']), 'muted'),
            ],
            'members'  => [
                'title' => 'Endpunkte',
                'empty' => 'Keine Endpunkte zugeordnet.',
                'items' => array_map($memberRef, $group['keys']),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . topology_admin_link('config-endpoints-card'),
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
                topology_fact('▶', 'Laufend', (string) $sxRunning, $sxRunning > 0 ? 'running' : 'muted'),
                topology_fact('✓', 'Jobs heute', topology_format_num($sxJobs), 'jobs'),
                topology_fact('⏱', 'Ø Antwortzeit', $sxAvg !== null ? number_format((float) $sxAvg, 1, ',', '.') . ' s' : '–', 'plain'),
                topology_fact('🔗', 'URL', (string) ($searxng['base_url'] ?? '–'), 'muted'),
            ],
            'url'      => $linkBase . topology_admin_link('config-searxng-card'),
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
                topology_fact('●', 'Status', $vOnline ? 'online' : 'offline', $vOnline ? 'ok' : 'error'),
                topology_fact('⚙', 'Modus', (string) ($vectorStore['mode'] ?? '–'), 'muted'),
                topology_fact('🔍', 'Abfragen heute', topology_format_num($vQueries), 'plain'),
                topology_fact('⚠', 'Fehler heute', topology_format_num($vErrors), $vErrors > 0 ? 'warn' : 'muted'),
                topology_fact('⏱', 'Ø Dauer', isset($vectorStore['avg_duration_ms']) && $vectorStore['avg_duration_ms'] !== null
                    ? number_format((float) $vectorStore['avg_duration_ms'], 0, ',', '.') . ' ms' : '–', 'plain'),
                topology_fact('📚', 'Dokumente', isset($vectorStore['documents']) && $vectorStore['documents'] !== null
                    ? topology_format_num((int) $vectorStore['documents']) : '–', 'plain'),
                topology_fact('🧮', 'Vektoren', isset($vectorStore['vectors']) && $vectorStore['vectors'] !== null
                    ? topology_format_num((int) $vectorStore['vectors']) : '–', 'plain'),
                topology_fact('🗂', 'Collection', (string) ($vectorStore['collection'] ?? '–'), 'muted'),
                topology_fact('🔗', 'URL', (string) ($vectorStore['base_url'] ?? '–'), 'muted'),
            ],
            'url'      => $linkBase . topology_admin_link('config-vector-store-card'),
        ]);
        $addEdge($hubKey, $key, $vState);
    }

    // ── ImageInt (image generation endpoint group) ────────────────────────────

    $imageConfigured = $imageStatus !== null && !empty($imageStatus['configured']);
    $imageReady      = $imageStatus !== null && !empty($imageStatus['ready']);
    $imageLoading    = $imageStatus !== null && !empty($imageStatus['loading']);
    // A busy host accepted the connection but did not answer the readiness
    // probe in time – it is rendering, not gone. That is an Einschränkung, and
    // turning it into a Störung is what made a working render look like an
    // outage.
    $imageBusy       = $imageStatus !== null && !empty($imageStatus['busy']);
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
            } elseif ($imageBusy) {
                $state   = 'warn';
                $badge   = 'beschäftigt';
                $message = (string) ($imageStatus['message'] ?? 'ImageInt rechnet noch.');
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
            topology_fact('🖼', 'Alias', trim((string) ($row['alias'] ?? '')) !== '' ? (string) $row['alias'] : '–', 'plain'),
            topology_fact('🔗', 'URL', (string) ($row['base_url'] ?? '–'), 'muted'),
            topology_fact('⏳', 'Timeout', (int) ($row['timeout'] ?? 0) . ' s', 'muted'),
            topology_fact('★', 'Wirksam', $isEffective ? 'ja' : 'nein', $isEffective ? 'ok' : 'muted'),
        ];
        if ($isEffective && $imageStatus !== null) {
            $facts[] = topology_fact('●', 'Status', (string) ($imageStatus['status'] ?? '–'), $state);
            $facts[] = topology_fact('⚙', 'Quelle', (string) ($imageStatus['source'] ?? '–'), 'muted');
            if (!empty($imageStatus['retry_after'])) {
                $facts[] = topology_fact('↻', 'Erneut in', (int) $imageStatus['retry_after'] . ' s', 'warn');
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
            'url'      => $linkBase . topology_admin_link('config-image-card'),
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
                : ($imageBusy
                    ? 'ImageInt rechnet gerade an einem Bild; kein Endpunkt ist derzeit einsatzbereit.'
                    : 'Kein Bild-Endpunkt ist einsatzbereit.'),
            'activity' => topology_activity($imageOk > 0 ? max(1, $imageOk * 2) : 0),
            'facts'    => [
                topology_fact('🔌', 'Endpunkte', (string) count($imageKeys), 'plain'),
                topology_fact('✓', 'Bereit', (string) $imageOk, $imageOk > 0 ? 'ok' : 'muted'),
                topology_fact('★', 'Wirksam', (string) ($imageStatus['url'] ?? '–'), 'muted'),
            ],
            'members'  => [
                'title' => 'Bild-Endpunkte',
                'empty' => 'Keine Bild-Endpunkte konfiguriert.',
                'items' => array_map($memberRef, $imageKeys),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . topology_admin_link('config-image-card'),
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
            topology_fact('🎙', 'Alias', (string) ($row['alias'] ?? '–'), 'plain'),
            topology_fact('🔗', 'URL', (string) ($row['base_url'] ?? '–'), 'muted'),
            topology_fact('⏳', 'Timeout', (int) ($row['timeout'] ?? 0) . ' s', 'muted'),
            topology_fact('★', 'Wirksam', $isEffective ? 'ja' : 'nein', $isEffective ? 'ok' : 'muted'),
        ];
        if ($isEffective && $speechStatus !== null) {
            $facts[] = topology_fact('●', 'Status', (string) ($speechStatus['status'] ?? '–'), $state);
            $facts[] = topology_fact('⚙', 'Quelle', (string) ($speechStatus['source'] ?? '–'), 'muted');
            if (!empty($speechStatus['retry_after'])) {
                $facts[] = topology_fact('↻', 'Erneut in', (int) $speechStatus['retry_after'] . ' s', 'warn');
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
            'url'      => $linkBase . topology_admin_link('config-speech-card'),
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
                topology_fact('🔌', 'Endpunkte', (string) count($speechKeys), 'plain'),
                topology_fact('✓', 'Bereit', (string) $speechOk, $speechOk > 0 ? 'ok' : 'muted'),
                topology_fact('★', 'Wirksam', (string) ($speechStatus['url'] ?? '–'), 'muted'),
            ],
            'members'  => [
                'title' => 'Dictation-Endpunkte',
                'empty' => 'Keine Dictation-Endpunkte konfiguriert.',
                'items' => array_map($memberRef, $speechKeys),
            ],
            'group'     => true,
            'synthetic' => true,
            'url'       => $linkBase . topology_admin_link('config-speech-card'),
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

    // ── Path of the running request ───────────────────────────────────────────
    // A tab that is waiting for an answer right now reports which endpoint it
    // asked (see recordClientRoute() in db.php). Its node carries that request
    // as route_kind/route_model/route_endpoint_id; here it is resolved into the
    // node keys of the path it walks, so a click on the client can highlight
    // exactly this path instead of every branch (see buildRoute() in
    // assets/js/flow-topology.js). Done last – it needs every node to exist.

    foreach ($clientItems as $item) {
        if (!is_array($item['route'] ?? null)) {
            continue;
        }
        $targets = topology_route_targets($item['route'], $nodes);
        if ($targets === []) {
            continue;
        }
        $nodes[$item['key']]['route'] = [
            'kind'        => (string) $item['route']['kind'],
            'model'       => (string) $item['route']['model'],
            'endpoint_id' => (int) $item['route']['endpoint_id'],
            'nodes'       => $targets,
        ];
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
