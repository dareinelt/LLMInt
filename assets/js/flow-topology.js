/**
 * assets/js/flow-topology.js
 *
 * Message-flow topology renderer for the LLMInt admin area.
 *
 * The module draws the whole LLMInt stack as a 3D/2D particle flow on a canvas.
 * LLMInt itself always sits in the centre; its direct children are the three
 * service groups (LLM, SpeechInt, ImageInt) plus SearXNG, the knowledge base and
 * the connected clients. The intelligence groups hang off "LLM" and each of
 * their LLM endpoints off its group, while the SpeechInt/ImageInt endpoints and
 * the individual chat sessions form the outermost ring.
 *
 * The same module and the same markup contract drive two views:
 *   - embedded in the dashboard card of admin/index.php (compact, no log)
 *   - full screen on admin/topology.php (head line, log, incident band)
 *
 * The renderer is a pure consumer: it never builds its own data. Either the
 * server embeds an initial payload in a `<script type="application/json"
 * data-flow-initial>` island, or the module polls `data-refresh-url` and reads
 * `payload.topology` (see lib/topology.php for the payload contract).
 *
 * Conventions: no innerHTML, no external dependencies, no animation while the
 * tab is hidden or while the user prefers reduced motion. Inline styles are
 * limited to tooltip/panel positioning and to the data-driven kind colours.
 *
 * Usage:
 *   window.FlowTopology.mount(rootElement, { onData: fn, onBusy: fn, onError: fn });
 */
(function () {
    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────

    const STATE_COLORS = {
        ok:    '#2ad4a0',
        warn:  '#ffc14d',
        error: '#ff4d6d',
        off:   '#6b7a95',
    };

    const STATE_RGB = {
        ok:    [42, 212, 160],
        warn:  [255, 193, 77],
        error: [255, 77, 109],
        off:   [107, 122, 149],
    };

    const STATE_LABELS = {
        ok:    'In Ordnung',
        warn:  'Eingeschränkt',
        error: 'Störung',
        off:   'Nicht aktiv',
    };

    // Node kinds mirror the hierarchy built in lib/topology.php: LLMInt at the
    // centre, the three service groups (LLM, SpeechInt, ImageInt) plus SearXNG,
    // knowledge base, clients and API as its children, and the concrete
    // endpoints / chat sessions / API keys one level below those.
    const KIND_COLORS = {
        hub:      '#8b7dff',
        llm:      '#a78bfa',
        model:    '#7dd3fc',
        endpoint: '#60a5fa',
        speech:   '#38bdf8',
        image:    '#fb923c',
        search:   '#22d3ee',
        vector:   '#34d399',
        clients:  '#4ade80',
        client:   '#86efac',
        api:      '#f472b6',
        'api-key': '#f9a8d4',
    };

    const KIND_LABELS = {
        hub:      'LLMInt',
        llm:      'LLM',
        model:    'Intelligenzgruppen',
        endpoint: 'Endpunkte',
        speech:   'SpeechInt',
        image:    'ImageInt',
        search:   'SearXNG',
        vector:   'Wissensdatenbank',
        clients:  'Clients',
        client:   'Chat-Sitzungen',
        api:      'API',
        'api-key': 'API-Aufrufe',
    };

    const KIND_RADIUS = {
        hub:      28,
        llm:      20,
        model:    16,
        endpoint: 13,
        speech:   18,
        image:    18,
        search:   14,
        vector:   15,
        clients:  24,
        client:   10,
        api:      20,
        'api-key': 11,
    };

    const DEFAULT_KIND_RADIUS = 14;

    // Colours of the detail-card rows. The server names a tone per fact
    // (topology_fact() in lib/topology.php); unknown tones fall back to 'plain'.
    const FACT_TONES = {
        plain:      '#c7d2fe',
        muted:      '#8e8ea0',
        running:    '#f59e0b',
        jobs:       '#22c55e',
        ok:         '#22c55e',
        warn:       '#f59e0b',
        error:      '#ef4444',
        prompt:     '#6c63ff',
        completion: '#8b5cf6',
        total:      '#a78bfa',
        speed:      '#fbbf24',
        ram:        '#60a5fa',
        cpu:        '#34d399',
        temp:       '#fb923c',
    };

    // Geometry of the in-canvas detail card that replaces a clicked bubble.
    const CARD = {
        width:        264,
        pad:          12,
        headerHeight: 40,
        rowHeight:    17,
        radius:       12,
        barHeight:    5,
        barGap:       6,
        footerHeight: 24,
    };
    // Long focal length: the network is a wide, flat disc, so a strong
    // perspective would blow up the near side and squash the far one.
    const FOCAL = 3000;
    const CAM_YAW = -0.35;
    // Looking down at the disc by ~45°, so it reads as a top-down map while the
    // depth still shows.
    const CAM_PITCH = 0.78;
    const MAX_PARTICLES_PER_EDGE = 14;
    const STARS = 180;
    const IDLE_BEFORE_ROTATE = 4000;
    const MIN_REFRESH_INTERVAL = 5000;
    const LOG_LIMIT = 60;
    const REFRESH_TIMEOUT = 10000;

    const reducedMotion = typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── Small helpers ─────────────────────────────────────────────────────────

    function clearChildren(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function swapClass(node, prefix, value) {
        if (!node) return;
        for (const name of Array.from(node.classList)) {
            if (name.indexOf(prefix) === 0) node.classList.remove(name);
        }
        node.classList.add(prefix + value);
    }

    function stateOf(value) {
        return Object.prototype.hasOwnProperty.call(STATE_COLORS, value) ? value : 'off';
    }

    function timeNow() {
        const d = new Date();
        const p = (n) => (n < 10 ? '0' + n : String(n));
        return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    /** Deterministic string hash – keeps jitter stable across refreshes. */
    function hashString(value) {
        const text = String(value);
        let hash = 2166136261;
        for (let i = 0; i < text.length; i++) {
            hash ^= text.charCodeAt(i);
            hash = (hash * 16777619) >>> 0;
        }
        return hash;
    }

    /** Deterministic pseudo-random generator for one key. */
    function rng(key) {
        let seed = hashString(key) || 1;
        return function () {
            seed ^= seed << 13; seed >>>= 0;
            seed ^= seed >> 17;
            seed ^= seed << 5;  seed >>>= 0;
            return seed / 4294967296;
        };
    }

    function clamp(value, min, max) {
        return value < min ? min : (value > max ? max : value);
    }

    function lerp(a, b, t) {
        return a + (b - a) * t;
    }

    function formatInt(value) {
        return (Number(value) || 0).toLocaleString('de-DE');
    }

    function rgba(triple, alpha) {
        return 'rgba(' + triple[0] + ',' + triple[1] + ',' + triple[2] + ',' + alpha + ')';
    }

    // ── Mount ─────────────────────────────────────────────────────────────────

    function mount(root, options) {
        if (!root) return null;

        const canvas = root.querySelector('[data-topo-canvas]');
        if (!canvas || !canvas.getContext) return null;

        const ctx = canvas.getContext('2d');
        const opts = options || {};
        const refreshUrl = (root.getAttribute('data-refresh-url') || '').trim();
        const refreshInterval = Math.max(
            MIN_REFRESH_INTERVAL,
            (parseInt(root.getAttribute('data-refresh-interval'), 10) || 15) * 1000
        );

        const tooltipEl = root.querySelector('[data-topo-tooltip]');
        const panelEl = root.querySelector('[data-topo-panel]');
        const hintEl = root.querySelector('[data-topo-hint]');
        const legendKindsEl = root.querySelector('[data-topo-kinds]');
        const logListEl = root.querySelector('[data-topo-log-list]');
        const incidentListEl = root.querySelector('[data-topo-incidents-list]');
        const incidentEmptyEl = root.querySelector('[data-topo-incidents-empty]');

        // ── State ─────────────────────────────────────────────────────────────

        const nodes = new Map();
        let edges = [];
        let overall = { state: 'ok', label: '', message: '', errors: 0, warnings: 0, nodes: 0 };
        let previousStates = new Map();
        let selected = null;
        let hovered = null;
        let route = { nodes: new Set(), edges: new Set(), active: false };
        let cardHit = null;
        let cardSpot = null;
        let problemFilter = false;
        const kindFilter = new Set(Object.keys(KIND_LABELS));

        let viewW = 800;
        let viewH = 480;
        let dpr = 1;

        const cam = { yaw: CAM_YAW, pitch: CAM_PITCH, zoom: 1, panX: 0, panY: 0 };
        let mode3d = true;
        let rotate = !reducedMotion;
        let particles = !reducedMotion;
        let labels = true;

        let layoutDirty = true;
        let userAdjusted = false;
        let lastInteraction = 0;
        let rafId = 0;
        let running = false;
        let lastFrame = 0;
        let refreshTimer = 0;
        let inflight = null;
        let frameErrorLogged = false;

        const stars = [];
        const effects = [];
        const logs = [];

        // ── DOM field helpers ─────────────────────────────────────────────────

        function field(name) {
            return root.querySelector('[data-topo-field="' + name + '"]');
        }

        function setField(name, value, bump) {
            const node = field(name);
            if (!node) return;
            const text = value === null || value === undefined ? '' : String(value);
            if (node.textContent === text) return;
            node.textContent = text;
            if (bump) {
                node.classList.remove('topo-bump');
                void node.offsetWidth;
                node.classList.add('topo-bump');
            }
        }

        function setPressed(action, on) {
            const button = root.querySelector('[data-topo-action="' + action + '"]');
            if (button) button.setAttribute('aria-pressed', on ? 'true' : 'false');
        }

        // ── Legend ────────────────────────────────────────────────────────────

        function buildKindLegend() {
            if (!legendKindsEl) return;
            clearChildren(legendKindsEl);
            legendKindsEl.appendChild(el('span', 'topo-legend-title', 'Bausteine'));
            for (const kind of Object.keys(KIND_LABELS)) {
                const label = el('label', 'topo-legend-item topo-legend-item--filter');
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.checked = true;
                input.setAttribute('data-topo-kind-filter', kind);
                input.addEventListener('change', function () {
                    if (input.checked) kindFilter.add(kind); else kindFilter.delete(kind);
                    layoutDirty = true;
                    lastInteraction = performance.now();
                });
                const dot = el('i', 'topo-dot');
                dot.style.background = KIND_COLORS[kind] || STATE_COLORS.off;
                label.appendChild(input);
                label.appendChild(dot);
                label.appendChild(document.createTextNode(KIND_LABELS[kind]));
                legendKindsEl.appendChild(label);
            }
        }

        // ── Ingest ────────────────────────────────────────────────────────────

        function normaliseNode(raw) {
            const kind = String(raw.kind || 'endpoint');
            return {
                key:        String(raw.key || ''),
                kind:       kind,
                title:      String(raw.title || raw.key || ''),
                subtitle:   String(raw.subtitle || ''),
                state:      stateOf(raw.state),
                badge:      String(raw.badge || ''),
                message:    String(raw.message || ''),
                activity:   clamp(Number(raw.activity) || 0, 0, 12),
                counters:   raw.counters && typeof raw.counters === 'object' ? raw.counters : null,
                facts:      Array.isArray(raw.facts) ? raw.facts : [],
                slots:      raw.slots && typeof raw.slots === 'object' ? raw.slots : null,
                cloud:      raw.cloud && typeof raw.cloud === 'object' ? raw.cloud : null,
                members:    raw.members && typeof raw.members === 'object' ? raw.members : null,
                neighbours: Array.isArray(raw.neighbours) ? raw.neighbours.map(String) : [],
                url:        typeof raw.url === 'string' ? raw.url : '',
                synthetic:  !!raw.synthetic,
                // Set by topology_build() for a client that is waiting for an
                // answer right now: the path of that very request.
                route:      raw.route && typeof raw.route === 'object' ? raw.route : null,
                alpha:      1,
                appear:     0,
                effect:     0,
                p3:         { x: 0, y: 0, z: 0 },
                p2:         { x: 0, y: 0 },
                screen:     { x: 0, y: 0, r: 0, s: 1, visible: false },
            };
        }

        function makeEdge(raw) {
            const from = String(raw.from || '');
            const to = String(raw.to || '');
            const rand = rng(from + '>' + to);
            return {
                id:        from + '>' + to,
                from:      from,
                to:        to,
                state:     stateOf(raw.state),
                synthetic: !!raw.synthetic,
                label:     String(raw.label || ''),
                bend:      (rand() - 0.5) * 60,
                lift:      (rand() - 0.5) * 40,
                particles: [],
                visible:   false,
                a:         null,
                b:         null,
            };
        }

        function ingest(payload) {
            if (!payload || typeof payload !== 'object') return;

            const incoming = new Map();
            const rawNodes = payload.nodes && typeof payload.nodes === 'object' ? payload.nodes : {};
            for (const key of Object.keys(rawNodes)) {
                const raw = rawNodes[key];
                if (raw && typeof raw === 'object') incoming.set(key, raw);
            }

            let added = 0;
            for (const [key, raw] of incoming) {
                const next = normaliseNode(raw);
                const existing = nodes.get(key);
                if (existing) {
                    // Keep layout position, fade and effects across refreshes.
                    next.p3 = existing.p3;
                    next.p2 = existing.p2;
                    next.appear = existing.appear;
                    next.effect = existing.effect;
                } else {
                    next.appear = 0.001;
                    added++;
                }
                nodes.set(key, next);
            }
            for (const key of Array.from(nodes.keys())) {
                if (!incoming.has(key)) nodes.delete(key);
            }

            const rawEdges = Array.isArray(payload.edges) ? payload.edges : [];
            const seen = new Set();
            const nextEdges = [];
            for (const raw of rawEdges) {
                if (!raw || typeof raw !== 'object') continue;
                const edge = makeEdge(raw);
                if (!edge.from || !edge.to || !nodes.has(edge.from) || !nodes.has(edge.to)) continue;
                if (seen.has(edge.id)) continue;
                seen.add(edge.id);
                const before = edges.find((candidate) => candidate.id === edge.id);
                if (before) {
                    edge.bend = before.bend;
                    edge.lift = before.lift;
                }
                nextEdges.push(edge);
            }
            edges = nextEdges;
            for (const edge of edges) {
                edge.a = nodes.get(edge.from) || null;
                edge.b = nodes.get(edge.to) || null;
            }

            if (added > 0) layoutDirty = true;
        }

        function applyOverall(raw) {
            if (!raw || typeof raw !== 'object') return;
            overall = {
                state:    stateOf(raw.state),
                label:    String(raw.label || STATE_LABELS[stateOf(raw.state)]),
                message:  String(raw.message || ''),
                errors:   Number(raw.errors) || 0,
                warnings: Number(raw.warnings) || 0,
                nodes:    Number(raw.nodes) || nodes.size,
            };
            root.setAttribute('data-overall-state', overall.state);
            setField('overall-label', overall.label);
            setField('overall-message', overall.message);
            setField('overall-errors', formatInt(overall.errors), true);
            setField('overall-warnings', formatInt(overall.warnings), true);
            setField('node-count', formatInt(nodes.size));
            const badge = root.querySelector('[data-topo-field="overall-badge"]');
            if (badge) {
                swapClass(badge, 'badge--', overall.state);
                badge.textContent = overall.label;
            }
        }

        // ── Log + incidents ───────────────────────────────────────────────────

        function log(level, text) {
            logs.unshift({ level: stateOf(level), text: String(text), at: timeNow() });
            if (logs.length > LOG_LIMIT) logs.length = LOG_LIMIT;
            if (!logListEl) return;
            clearChildren(logListEl);
            for (const entry of logs) {
                const item = el('li', 'topo-log-item topo-log-item--' + entry.level);
                item.appendChild(el('span', 'topo-log-time', entry.at));
                item.appendChild(el('span', 'topo-log-text', entry.text));
                logListEl.appendChild(item);
            }
        }

        function setIncidents(list) {
            const incidents = Array.isArray(list) ? list.filter((item) => item && typeof item === 'object') : [];
            setField('incident-count', formatInt(incidents.length), true);
            if (incidentEmptyEl) incidentEmptyEl.hidden = incidents.length > 0;
            if (!incidentListEl) return;

            clearChildren(incidentListEl);
            for (const incident of incidents) {
                const state = stateOf(incident.state);
                const item = el('li', 'topo-incident topo-incident--' + state);
                item.appendChild(el('span', 'badge badge--' + state, STATE_LABELS[state]));
                item.appendChild(el('strong', 'topo-incident-title', String(incident.title || '')));
                item.appendChild(el('span', 'topo-incident-message', String(incident.message || '')));

                const key = String(incident.key || '');
                if (key && nodes.has(key)) {
                    const focus = el('button', 'topo-btn topo-btn--small', 'Fokussieren');
                    focus.type = 'button';
                    focus.addEventListener('click', function () {
                        focusNode(nodes.get(key));
                    });
                    item.appendChild(focus);
                }
                const url = String(incident.url || '');
                if (url.charAt(0) === '/') {
                    const link = el('a', 'topo-panel-link', 'Öffnen');
                    link.href = url;
                    item.appendChild(link);
                }
                incidentListEl.appendChild(item);
            }
        }

        // ── Layout ────────────────────────────────────────────────────────────

        function placeAt(node, x, y, z) {
            node.p3.x = x;
            node.p3.y = y;
            node.p3.z = z;
            // The flat view is the very same tree seen from straight above.
            node.p2.x = x;
            node.p2.y = -z;
        }

        /** The core node every layout is built around. */
        function hubNode() {
            for (const node of nodes.values()) {
                if (node.kind === 'hub') return node;
            }
            return null;
        }

        /**
         * Parent → children map derived from the edges. The payload always points
         * from parent to child, so the first edge claiming a node wins and the
         * core itself is never claimed.
         */
        function hierarchy() {
            const childrenOf = new Map();
            const claimed = new Set();
            const hub = hubNode();
            if (hub) claimed.add(hub.key);
            for (const edge of edges) {
                const parent = nodes.get(edge.from);
                const child = nodes.get(edge.to);
                if (!parent || !child || claimed.has(child.key)) continue;
                claimed.add(child.key);
                if (!childrenOf.has(parent.key)) childrenOf.set(parent.key, []);
                childrenOf.get(parent.key).push(child);
            }
            for (const list of childrenOf.values()) {
                list.sort((a, b) => (a.title < b.title ? -1 : (a.title > b.title ? 1 : 0)));
            }
            return childrenOf;
        }

        /** Ring radius per tree level; the outer rings grow slowly. */
        const DEPTH_RADIUS = [0, 330, 520, 680, 820];

        /**
         * Extent of the volumetric swarms around the caller groups. Both
         * "Clients" and "API" are not a single bubble but a swarm of concrete
         * callers, so they are laid out on a sphere around their group node.
         */
        const CLOUD_RADIUS = { clients: 150, api: 115 };

        /** Radius of a caller swarm, with a fallback for unknown group kinds. */
        const CLOUD_RADIUS_DEFAULT = 120;

        /** Largest radius factor `cloudAround()` may use, relative to the cloud radius. */
        const CLOUD_SPREAD = 1.28;

        /** Free space kept between a caller swarm and the next branch. */
        const CLOUD_MARGIN = 48;

        /**
         * Extra ring radius for the point-like service branches that share the
         * hub with the caller swarms. They lose nothing by sitting further out,
         * while the volumetric swarms gain the room their spheres need.
         */
        const SERVICE_RING_OFFSET = { search: 190, speech: 190, image: 190 };

        function serviceRingOffset(parent, node) {
            if (!parent || parent.kind !== 'hub') return 0;
            return SERVICE_RING_OFFSET[node.kind] || 0;
        }

        /** Is this node a group that carries a swarm of concrete callers? */
        function isCloudNode(node) {
            return !!node && (node.kind === 'clients' || node.kind === 'api');
        }

        function cloudRadius(node) {
            return CLOUD_RADIUS[node.kind] || CLOUD_RADIUS_DEFAULT;
        }

        /**
         * Extra angular slice a branch claims on top of its proportional share, so
         * that branches with wide content keep clear of their neighbours. Only the
         * caller swarms are wider than a point, and they are the reason the service
         * groups next to them are pushed further away.
         */
        function branchPadding(node, ringRadius) {
            if (!isCloudNode(node)) return 0;
            const reach = cloudRadius(node) * CLOUD_SPREAD + CLOUD_MARGIN;
            const ratio = clamp(reach / Math.max(ringRadius, reach + 1), 0, 1);
            return 2 * Math.asin(ratio);
        }

        /** Number of leaves below `node`, used to size its angular slice. */
        function subtreeWeight(node, childrenOf) {
            // A caller swarm is a fixed-size cloud, so it claims a normal slice
            // instead of one proportional to the number of open sessions / keys.
            if (isCloudNode(node)) return 1;
            const kids = childrenOf.get(node.key) || [];
            if (!kids.length) return 1;
            let total = 0;
            for (const kid of kids) total += subtreeWeight(kid, childrenOf);
            return total;
        }

        /**
         * Spread `list` on a sphere around `parent`. The Fibonacci distribution
         * avoids clumping at the poles, so the swarm looks even from every angle.
         */
        function cloudAround(list, parent, radius, seed) {
            const n = list.length;
            if (!n) return;
            if (n === 1) {
                placeAt(list[0], parent.p3.x, parent.p3.y, parent.p3.z + radius * 0.7);
                return;
            }
            const rand = rng(seed);
            const golden = Math.PI * (3 - Math.sqrt(5));
            list.forEach(function (node, index) {
                const phi = Math.acos(clamp(1 - 2 * ((index + 0.5) / n), -1, 1));
                const theta = golden * index + (rand() - 0.5) * 0.4;
                const r = radius * (CLOUD_SPREAD - 0.5 + rand() * 0.5);
                placeAt(
                    node,
                    parent.p3.x + Math.sin(phi) * Math.cos(theta) * r,
                    parent.p3.y + Math.cos(phi) * r,
                    parent.p3.z + Math.sin(phi) * Math.sin(theta) * r
                );
            });
        }

        /**
         * 3D layout: a radial tree lying flat in the horizontal plane. LLMInt owns
         * the full circle, every node hands its own angular slice down to its
         * children — sized by how much of the tree each child carries — and the
         * slices fan outwards from the core. Because a branch can only ever occupy
         * its parent's slice, sibling branches can never overlap.
         */
        function layout() {
            layoutDirty = false;
            const hub = hubNode();
            if (!hub) return;
            const childrenOf = hierarchy();
            const slices = new Map();
            const placed = new Set([hub.key]);
            placeAt(hub, 0, 0, 0);

            const walk = function (parent, depth, offset) {
                const kids = childrenOf.get(parent.key) || [];
                if (!kids.length) return;

                // The browser sessions are not part of the disc: they gather as a
                // volumetric cloud around the "Clients" node. The API keys do the
                // same around the "API" node.
                if (isCloudNode(parent)) {
                    cloudAround(kids, parent, cloudRadius(parent), parent.kind + '|' + parent.key);
                    for (const kid of kids) {
                        placed.add(kid.key);
                        walk(kid, depth + 1, offset);
                    }
                    return;
                }

                const slice = slices.get(parent.key) || [0, Math.PI * 2];
                const radius = DEPTH_RADIUS[Math.min(depth + 1, DEPTH_RADIUS.length - 1)];
                const weights = kids.map((kid) => subtreeWeight(kid, childrenOf));
                let total = 0;
                for (const weight of weights) total += weight;

                // Branches with wide content claim extra angular padding; the rest
                // of the slice is handed out proportionally to the subtree weight.
                const pads = kids.map((kid) => branchPadding(kid, radius));
                let padTotal = 0;
                for (const pad of pads) padTotal += pad;
                const usable = Math.max(0, slice[1] - slice[0] - padTotal);

                let cursor = slice[0];
                kids.forEach(function (kid, index) {
                    const pad = pads[index];
                    const span = usable * (weights[index] / total);
                    const own = [cursor + pad / 2, cursor + pad / 2 + span];
                    cursor = own[1] + pad / 2;
                    slices.set(kid.key, own);
                    const angle = (own[0] + own[1]) / 2;
                    // A shifted branch keeps its own internal spacing: the offset
                    // travels down with it, so parents and children stay apart.
                    const branchOffset = offset + serviceRingOffset(parent, kid);
                    const branchRadius = radius + branchOffset;
                    placeAt(kid, Math.cos(angle) * branchRadius, 0, Math.sin(angle) * branchRadius);
                    placed.add(kid.key);
                    walk(kid, depth + 1, branchOffset);
                });
            };
            walk(hub, 0, 0);

            // Nodes the core cannot reach still get a place on the outer ring.
            const orphans = Array.from(nodes.values()).filter((node) => !placed.has(node.key));
            const orphanRadius = DEPTH_RADIUS[1] * 1.55;
            orphans.forEach(function (node, index) {
                const angle = (index / orphans.length) * Math.PI * 2;
                placeAt(node, Math.cos(angle) * orphanRadius, 0, Math.sin(angle) * orphanRadius);
            });
        }

        /** 2D layout: the same tree seen from straight above, without perspective. */
        function layout2d() {
            for (const node of nodes.values()) {
                node.p2.x = node.p3.x;
                node.p2.y = -node.p3.z;
            }
        }

        // ── Projection ────────────────────────────────────────────────────────

        function project(node) {
            const mix = mode3d ? 1 : 0;
            const cy = Math.cos(cam.yaw);
            const sy = Math.sin(cam.yaw);
            const cp = Math.cos(cam.pitch);
            const sp = Math.sin(cam.pitch);

            const x1 = node.p3.x * cy + node.p3.z * sy;
            const z1 = -node.p3.x * sy + node.p3.z * cy;
            const y1 = node.p3.y * cp - z1 * sp;
            const z2 = node.p3.y * sp + z1 * cp;

            const behind = z2 >= FOCAL - 40;
            const scale3 = FOCAL / Math.max(40, FOCAL - z2);

            return {
                x: x1 * scale3 * mix + node.p2.x * (1 - mix),
                y: -y1 * scale3 * mix + -node.p2.y * (1 - mix),
                s: scale3 * mix + 1 * (1 - mix),
                behind: behind && mix > 0.5,
            };
        }

        function toScreen(px, py) {
            return {
                X: viewW / 2 + (px - cam.panX) * cam.zoom,
                Y: viewH / 2 + (py - cam.panY) * cam.zoom,
            };
        }

        function nodeScreen(node) {
            const p = project(node);
            const s = toScreen(p.x, p.y);
            return { X: s.X, Y: s.Y, s: p.s, behind: p.behind };
        }

        // ── Weg zwischen Endpunkt und Aufrufer ────────────────────────────────
        // The network is a tree with LLMInt at its root, so the way of a message
        // can be walked: from the clicked node up its branch to the core and from
        // there across into the opposite side. The callers (browser sessions and
        // API keys) are siblings of the endpoint branches, not their descendants,
        // so both halves belong to it: a click on an endpoint lights up every
        // caller.
        //
        // A session is different: while it waits for an answer it reports the
        // endpoint it asked (node.route, filled by topology_build()), so its way
        // is known exactly and only that one branch lights up. Without a running
        // request its way is unknown; guessing one would draw a route the message
        // never took, so the click selects the session alone.

        function parentEdges() {
            const parents = new Map();
            for (const edge of edges) {
                if (parents.has(edge.to)) continue;
                parents.set(edge.to, edge);
            }
            return parents;
        }

        /** Does this node belong to the caller side of the core? */
        function isCallerSide(node) {
            return !!node && (node.kind === 'client' || node.kind === 'clients'
                || node.kind === 'api' || node.kind === 'api-key');
        }

        /** Does `key` hang below `ancestor` in the tree? */
        function isBelow(ancestor, key, parents) {
            let cursor = key;
            while (cursor) {
                const up = parents.get(cursor);
                if (!up) return false;
                if (up.from === ancestor) return true;
                cursor = up.from;
            }
            return false;
        }

        function buildRoute(key) {
            const next = { nodes: new Set(), edges: new Set(), active: false, busy: false };
            if (!key || !nodes.has(key)) return next;

            const node = nodes.get(key);
            const running = node.kind === 'client' && node.route && Array.isArray(node.route.nodes)
                ? node.route.nodes.filter(function (target) { return nodes.has(String(target)); })
                : [];

            // Only a waiting session knows which way its message takes. An idle
            // one is selected without a way, so nothing is highlighted.
            if (node.kind === 'client' && !running.length) return next;

            const parents = parentEdges();
            const climbed = new Set();

            /** Walks up from a node to the core and collects the way. */
            function climb(start) {
                let cursor = start;
                while (cursor && !climbed.has(cursor)) {
                    climbed.add(cursor);
                    next.nodes.add(cursor);
                    const up = parents.get(cursor);
                    if (!up) break;
                    next.edges.add(up.id);
                    cursor = up.from;
                }
            }

            climb(key);

            if (running.length) {
                next.busy = true;
                for (const target of running) climb(String(target));
            } else {
                // A single caller lights up the way across to the endpoints it
                // talks to; a caller cloud only lights up its own branch.
                const fromCaller = node.kind === 'api-key';
                const ownBranch = node.kind === 'clients' || node.kind === 'api';
                for (const other of nodes.values()) {
                    if (next.nodes.has(other.key)) continue;
                    let wanted;
                    if (fromCaller) wanted = !isCallerSide(other);
                    else if (ownBranch) wanted = isBelow(node.key, other.key, parents);
                    else wanted = isCallerSide(other);
                    if (wanted) next.nodes.add(other.key);
                }
            }

            // The network is a tree, so a line belongs to the way when both of
            // its ends do.
            for (const edge of edges) {
                if (next.nodes.has(edge.from) && next.nodes.has(edge.to)) next.edges.add(edge.id);
            }

            next.active = next.nodes.size > 0;
            return next;
        }

        function refreshRoute() {
            route = buildRoute(selected);
        }

        function nodeAlpha(node) {
            const isSelected = selected === node.key;
            const isHovered = hovered === node.key;
            let alpha = clamp(node.appear, 0, 1);
            if (problemFilter && node.state !== 'error' && node.state !== 'warn' && !isSelected && !isHovered) {
                alpha *= 0.14;
            }
            if (!kindFilter.has(node.kind) && !isSelected) alpha *= 0.22;
            if (mode3d && !node.screen.visible) alpha *= 0.3;
            // Everything beside the way steps back, so the way itself reads.
            if (route.active && !route.nodes.has(node.key) && !isSelected && !isHovered) alpha *= 0.16;
            return alpha;
        }

        function isNeighbour(node, key) {
            const centre = key ? nodes.get(key) : null;
            if (!centre || !node) return false;
            if (node.key === centre.key) return true;
            return centre.neighbours.indexOf(node.key) !== -1;
        }

        function edgeAlpha(edge) {
            if (!edge.visible) return 0;
            if (route.active) {
                if (route.edges.has(edge.id)) return 1;
                // Hovering previews the way a click would trace.
                if (hovered && isNeighbour(edge.a, hovered) && isNeighbour(edge.b, hovered)) return 0.42;
                return 0.06;
            }
            if (hovered) {
                if (edge.from === hovered || edge.to === hovered) return 1;
                return 0.09;
            }
            if (problemFilter) return (edge.state === 'error' || edge.state === 'warn') ? 1 : 0.07;
            // The connecting lines *are* the topology: they stay clearly readable
            // even in a downscaled documentation screenshot.
            return 0.95;
        }

        // ── Curves ────────────────────────────────────────────────────────────

        function controlPoint(a, b, edge, side) {
            const mx = (a.X + b.X) / 2;
            const my = (a.Y + b.Y) / 2;
            const dx = b.X - a.X;
            const dy = b.Y - a.Y;
            const len = Math.max(1, Math.hypot(dx, dy));
            const nx = -dy / len;
            const ny = dx / len;
            const factor = side === 2 ? 1.3 : 1;
            const amount = (edge ? edge.bend : 0) * factor + (side === 2 && edge ? edge.lift : 0);
            return { x: mx + nx * amount, y: my + ny * amount };
        }

        function edgePath(edge) {
            const a = { X: edge.a.screen.x, Y: edge.a.screen.y };
            const b = { X: edge.b.screen.x, Y: edge.b.screen.y };
            return {
                a: a,
                b: b,
                c1: controlPoint(a, b, edge, 1),
                c2: controlPoint(a, b, edge, 2),
            };
        }

        function bezierPoint(path, t) {
            const mt = 1 - t;
            const a = mt * mt * mt;
            const b = 3 * mt * mt * t;
            const c = 3 * mt * t * t;
            const d = t * t * t;
            return {
                x: a * path.a.X + b * path.c1.x + c * path.c2.x + d * path.b.X,
                y: a * path.a.Y + b * path.c1.y + c * path.c2.y + d * path.b.Y,
            };
        }

        function syncParticles(edge) {
            const intensity = Math.max(edge.a.activity, edge.b.activity);
            let wanted = 0;
            if (particles && edge.visible) {
                if (edge.state === 'error') {
                    wanted = 4;
                } else if (edge.state === 'warn') {
                    wanted = Math.min(6, 2 + Math.round(intensity * 0.4));
                } else if (edge.state === 'off') {
                    wanted = 0;
                } else {
                    wanted = clamp(1 + Math.round(Math.log(intensity + 1) * 2.4), 1, MAX_PARTICLES_PER_EDGE);
                }
            }
            while (edge.particles.length < wanted) {
                edge.particles.push({ t: Math.random(), speed: 0.00016 + Math.random() * 0.00022 });
            }
            while (edge.particles.length > wanted) {
                edge.particles.pop();
            }
        }

        // ── Stars ─────────────────────────────────────────────────────────────

        function buildStars() {
            stars.length = 0;
            const rand = rng('flow-topology-stars');
            for (let i = 0; i < STARS; i++) {
                stars.push({
                    x: (rand() - 0.5) * 4200,
                    y: (rand() - 0.5) * 2600,
                    z: 0.2 + rand() * 0.8,
                    r: 0.4 + rand() * 1.2,
                });
            }
        }

        // ── Drawing ───────────────────────────────────────────────────────────

        function resize() {
            const rect = canvas.getBoundingClientRect();
            viewW = Math.max(240, Math.round(rect.width || root.clientWidth || 800));
            viewH = Math.max(180, Math.round(rect.height || root.clientHeight || 480));
            dpr = Math.min(2, window.devicePixelRatio || 1);
            canvas.width = Math.round(viewW * dpr);
            canvas.height = Math.round(viewH * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        function drawBackground() {
            const gradient = ctx.createLinearGradient(0, 0, 0, viewH);
            gradient.addColorStop(0, 'rgba(26,28,42,0.97)');
            gradient.addColorStop(1, 'rgba(13,15,23,0.99)');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, viewW, viewH);

            for (const star of stars) {
                const s = toScreen(star.x * 0.45, star.y * 0.45);
                if (s.X < -20 || s.Y < -20 || s.X > viewW + 20 || s.Y > viewH + 20) continue;
                ctx.globalAlpha = 0.04 + star.z * 0.14;
                ctx.fillStyle = '#cfe3ff';
                ctx.beginPath();
                ctx.arc(s.X, s.Y, star.r * (0.6 + star.z), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1;
        }

        /** Traces one edge bezier on the current path. */
        function traceEdge(path) {
            ctx.beginPath();
            ctx.moveTo(path.a.X, path.a.Y);
            ctx.bezierCurveTo(path.c1.x, path.c1.y, path.c2.x, path.c2.y, path.b.X, path.b.Y);
        }

        function drawEdges() {
            const dashOffset = -((performance.now() / 90) % 11);
            for (const edge of edges) {
                const alpha = edgeAlpha(edge);
                if (alpha <= 0.02) continue;
                const path = edgePath(edge);
                const triple = STATE_RGB[edge.state] || STATE_RGB.off;
                const onRoute = route.active && route.edges.has(edge.id);
                const focused = !!hovered && (edge.from === hovered || edge.to === hovered);
                const baseWidth = edge.state === 'error' ? 3.2 : 2.4;
                const width = baseWidth + (onRoute ? 1.8 : focused ? 1 : 0);
                const stroke = edge.state === 'off' ? 'rgba(150,162,188,0.75)' : rgba(triple, 0.9);

                // Soft halo first: it keeps the lines legible when the whole
                // picture is scaled down for the README.
                ctx.save();
                ctx.globalAlpha = alpha * (onRoute ? 0.5 : focused ? 0.34 : 0.24);
                ctx.lineWidth = width * 3.4;
                ctx.lineCap = 'round';
                ctx.strokeStyle = rgba(triple, 0.55);
                traceEdge(path);
                ctx.stroke();
                ctx.restore();

                ctx.save();
                ctx.globalAlpha = alpha;
                ctx.lineWidth = width;
                ctx.lineCap = 'round';
                if (edge.state === 'error') {
                    ctx.setLineDash([6, 5]);
                    ctx.lineDashOffset = dashOffset;
                } else if (edge.synthetic && !onRoute) {
                    ctx.setLineDash([7, 6]);
                }
                ctx.strokeStyle = onRoute ? rgba(triple, 1) : stroke;
                traceEdge(path);
                ctx.stroke();
                ctx.restore();

                // The way of a request that is running right now flows: bright
                // dashes travel along it from the session to the endpoint.
                if (onRoute && route.busy) {
                    ctx.save();
                    ctx.globalAlpha = alpha * 0.8;
                    ctx.lineWidth = width + 1.4;
                    ctx.lineCap = 'round';
                    ctx.setLineDash([12, 12]);
                    ctx.lineDashOffset = -((performance.now() / 24) % 24);
                    ctx.strokeStyle = 'rgba(255,255,255,0.9)';
                    traceEdge(path);
                    ctx.stroke();
                    ctx.restore();
                }

                if (edge.state === 'error') {
                    const mid = bezierPoint(path, 0.5);
                    ctx.save();
                    ctx.globalAlpha = alpha;
                    ctx.strokeStyle = STATE_COLORS.error;
                    ctx.lineWidth = 2.4;
                    ctx.beginPath();
                    ctx.moveTo(mid.x - 4, mid.y - 4);
                    ctx.lineTo(mid.x + 4, mid.y + 4);
                    ctx.moveTo(mid.x + 4, mid.y - 4);
                    ctx.lineTo(mid.x - 4, mid.y + 4);
                    ctx.stroke();
                    ctx.restore();
                }

                if (labels && edge.label && alpha > 0.4 && cam.zoom > 0.7) {
                    const mid = bezierPoint(path, 0.5);
                    ctx.save();
                    ctx.globalAlpha = alpha * 0.85;
                    ctx.fillStyle = '#c3d0e6';
                    ctx.font = '600 10px "Segoe UI", system-ui, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(edge.label, mid.x, mid.y - 8);
                    ctx.restore();
                }
            }
        }

        function drawParticles() {
            if (!particles) return;
            for (const edge of edges) {
                const alpha = edgeAlpha(edge);
                if (alpha <= 0.05 || edge.state === 'off') continue;
                // While a way is highlighted only that way keeps its dots.
                if (route.active && !route.edges.has(edge.id)) continue;
                const path = edgePath(edge);
                const triple = STATE_RGB[edge.state] || STATE_RGB.ok;
                for (const particle of edge.particles) {
                    if (edge.state === 'error' && particle.t > 0.82) continue;
                    const p = bezierPoint(path, particle.t);
                    const fade = edge.state === 'error' ? clamp((0.82 - particle.t) / 0.3, 0, 1) : 1;
                    // Halo + core, so the travelling dots stay visible at any zoom.
                    ctx.globalAlpha = alpha * fade * 0.3;
                    ctx.fillStyle = rgba(triple, 1);
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, 6.4, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.globalAlpha = alpha * fade;
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, 3.1, 0, Math.PI * 2);
                    ctx.fill();
                }
            }
            ctx.globalAlpha = 1;
        }

        function drawGlyph(node, x, y, size, color) {
            ctx.save();
            ctx.strokeStyle = color;
            ctx.fillStyle = color;
            ctx.lineWidth = Math.max(1.2, size * 0.11);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            const u = size;
            switch (node.kind) {
                case 'clients':
                    ctx.beginPath();
                    ctx.arc(x, y - u * 0.22, u * 0.2, 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.arc(x - u * 0.44, y - u * 0.1, u * 0.15, 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.arc(x + u * 0.44, y - u * 0.1, u * 0.15, 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.arc(x, y + u * 0.52, u * 0.46, Math.PI, 0);
                    ctx.stroke();
                    break;
                case 'hub':
                    ctx.beginPath();
                    ctx.moveTo(x - u * 0.12, y - u * 0.5);
                    ctx.lineTo(x + u * 0.28, y - u * 0.08);
                    ctx.lineTo(x + u * 0.02, y - u * 0.08);
                    ctx.lineTo(x + u * 0.16, y + u * 0.5);
                    ctx.lineTo(x - u * 0.3, y + u * 0.02);
                    ctx.lineTo(x - u * 0.02, y + u * 0.02);
                    ctx.closePath();
                    ctx.fill();
                    break;
                case 'llm':
                    // Stacked layers: the pool of intelligence groups.
                    ctx.beginPath();
                    for (let i = -1; i <= 1; i++) {
                        ctx.moveTo(x - u * 0.46, y + i * u * 0.3);
                        ctx.lineTo(x + u * 0.46, y + i * u * 0.3);
                    }
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.arc(x - u * 0.24, y - u * 0.3, u * 0.06, 0, Math.PI * 2);
                    ctx.fill();
                    break;
                case 'model':
                    ctx.beginPath();
                    ctx.moveTo(x, y - u * 0.44);
                    ctx.lineTo(x + u * 0.46, y - u * 0.16);
                    ctx.lineTo(x, y + u * 0.12);
                    ctx.lineTo(x - u * 0.46, y - u * 0.16);
                    ctx.closePath();
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(x - u * 0.46, y + u * 0.06);
                    ctx.lineTo(x, y + u * 0.34);
                    ctx.lineTo(x + u * 0.46, y + u * 0.06);
                    ctx.stroke();
                    break;
                case 'endpoint':
                    ctx.strokeRect(x - u * 0.4, y - u * 0.42, u * 0.8, u * 0.3);
                    ctx.strokeRect(x - u * 0.4, y + u * 0.04, u * 0.8, u * 0.3);
                    ctx.beginPath();
                    ctx.arc(x - u * 0.26, y - u * 0.27, u * 0.05, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.beginPath();
                    ctx.arc(x - u * 0.26, y + u * 0.19, u * 0.05, 0, Math.PI * 2);
                    ctx.fill();
                    break;
                case 'search':
                    ctx.beginPath();
                    ctx.arc(x - u * 0.08, y - u * 0.1, u * 0.32, 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(x + u * 0.16, y + u * 0.14);
                    ctx.lineTo(x + u * 0.42, y + u * 0.42);
                    ctx.stroke();
                    break;
                case 'vector':
                    ctx.beginPath();
                    ctx.ellipse(x, y - u * 0.28, u * 0.36, u * 0.16, 0, 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(x - u * 0.36, y - u * 0.28);
                    ctx.lineTo(x - u * 0.36, y + u * 0.3);
                    ctx.moveTo(x + u * 0.36, y - u * 0.28);
                    ctx.lineTo(x + u * 0.36, y + u * 0.3);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.ellipse(x, y + u * 0.3, u * 0.36, u * 0.16, 0, 0, Math.PI);
                    ctx.stroke();
                    break;
                case 'image':
                    ctx.strokeRect(x - u * 0.42, y - u * 0.34, u * 0.84, u * 0.68);
                    ctx.beginPath();
                    ctx.arc(x - u * 0.16, y - u * 0.12, u * 0.09, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.beginPath();
                    ctx.moveTo(x - u * 0.34, y + u * 0.28);
                    ctx.lineTo(x - u * 0.02, y - u * 0.04);
                    ctx.lineTo(x + u * 0.34, y + u * 0.28);
                    ctx.stroke();
                    break;
                case 'speech':
                    ctx.strokeRect(x - u * 0.16, y - u * 0.46, u * 0.32, u * 0.5);
                    ctx.beginPath();
                    ctx.arc(x, y - u * 0.2, u * 0.32, 0, Math.PI);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(x, y + u * 0.14);
                    ctx.lineTo(x, y + u * 0.44);
                    ctx.moveTo(x - u * 0.2, y + u * 0.44);
                    ctx.lineTo(x + u * 0.2, y + u * 0.44);
                    ctx.stroke();
                    break;
                case 'api':
                    // Braces: the OpenAI-compatible programming interface.
                    ctx.beginPath();
                    ctx.moveTo(x - u * 0.18, y - u * 0.46);
                    ctx.lineTo(x - u * 0.42, y - u * 0.46);
                    ctx.lineTo(x - u * 0.42, y + u * 0.46);
                    ctx.lineTo(x - u * 0.18, y + u * 0.46);
                    ctx.moveTo(x + u * 0.18, y - u * 0.46);
                    ctx.lineTo(x + u * 0.42, y - u * 0.46);
                    ctx.lineTo(x + u * 0.42, y + u * 0.46);
                    ctx.lineTo(x + u * 0.18, y + u * 0.46);
                    ctx.stroke();
                    break;
                case 'api-key':
                    // Key: the caller identifies itself with an API key.
                    ctx.beginPath();
                    ctx.arc(x - u * 0.22, y - u * 0.16, u * 0.2, 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.beginPath();
                    ctx.moveTo(x - u * 0.08, y - u * 0.02);
                    ctx.lineTo(x + u * 0.42, y + u * 0.4);
                    ctx.moveTo(x + u * 0.16, y + u * 0.14);
                    ctx.lineTo(x + u * 0.3, y + u * 0.02);
                    ctx.moveTo(x + u * 0.3, y + u * 0.28);
                    ctx.lineTo(x + u * 0.44, y + u * 0.16);
                    ctx.stroke();
                    break;
                default:
                    ctx.beginPath();
                    ctx.arc(x, y, u * 0.3, 0, Math.PI * 2);
                    ctx.stroke();
            }
            ctx.restore();
        }

        function drawNodes() {
            const order = Array.from(nodes.values()).sort((a, b) => a.screen.s - b.screen.s);
            for (const node of order) {
                const alpha = nodeAlpha(node);
                if (alpha <= 0.02) continue;

                const radius = (KIND_RADIUS[node.kind] || DEFAULT_KIND_RADIUS) * node.screen.s * cam.zoom;
                if (radius < 1.4) continue;

                const kindColor = KIND_COLORS[node.kind] || STATE_COLORS.off;
                const stateColor = STATE_COLORS[node.state] || STATE_COLORS.off;
                const stateTriple = STATE_RGB[node.state] || STATE_RGB.off;
                const isSelected = selected === node.key;
                const isHovered = hovered === node.key;
                const onRoute = route.active && route.nodes.has(node.key);

                ctx.save();
                ctx.globalAlpha = alpha;

                if (node.state === 'error' || node.state === 'warn' || isSelected) {
                    const outer = radius * (isSelected ? 3.4 : 2.6);
                    const glow = ctx.createRadialGradient(node.screen.x, node.screen.y, radius * 0.4,
                        node.screen.x, node.screen.y, outer);
                    glow.addColorStop(0, rgba(stateTriple, 0.34));
                    glow.addColorStop(1, rgba(stateTriple, 0));
                    ctx.fillStyle = glow;
                    ctx.beginPath();
                    ctx.arc(node.screen.x, node.screen.y, outer, 0, Math.PI * 2);
                    ctx.fill();
                }

                if (node.effect > 0) {
                    ctx.globalAlpha = alpha * node.effect * 0.6;
                    ctx.strokeStyle = stateColor;
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.arc(node.screen.x, node.screen.y, radius * (1.6 + (1 - node.effect) * 2.6), 0, Math.PI * 2);
                    ctx.stroke();
                    ctx.globalAlpha = alpha;
                }

                const body = ctx.createRadialGradient(
                    node.screen.x - radius * 0.3, node.screen.y - radius * 0.4, radius * 0.2,
                    node.screen.x, node.screen.y, radius
                );
                body.addColorStop(0, rgba(stateTriple, 0.32));
                body.addColorStop(1, 'rgba(16,18,28,0.92)');
                ctx.fillStyle = body;
                ctx.beginPath();
                ctx.arc(node.screen.x, node.screen.y, radius, 0, Math.PI * 2);
                ctx.fill();

                ctx.strokeStyle = node.state === 'off' ? 'rgba(140,150,175,0.65)' : stateColor;
                ctx.lineWidth = (isSelected || isHovered) ? 2.6 : 1.6;
                if (node.synthetic) ctx.setLineDash([4, 3]);
                ctx.stroke();
                ctx.setLineDash([]);

                // A station on the way gets its own ring, so the highlighted path
                // is readable even where several lines meet in the core.
                if (onRoute) {
                    ctx.strokeStyle = 'rgba(255,255,255,0.9)';
                    ctx.lineWidth = 1.5;
                    ctx.beginPath();
                    ctx.arc(node.screen.x, node.screen.y, radius * 1.6, 0, Math.PI * 2);
                    ctx.stroke();
                }

                if (radius > 9) {
                    drawGlyph(node, node.screen.x, node.screen.y, radius * 0.92,
                        node.state === 'off' ? 'rgba(200,210,230,0.7)' : rgba(stateTriple, 0.95));
                }

                if (node.state === 'error') {
                    ctx.fillStyle = STATE_COLORS.error;
                    ctx.font = 'bold ' + Math.max(10, radius * 0.9) + 'px "Segoe UI", system-ui, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('!', node.screen.x + radius * 0.85, node.screen.y - radius * 0.85);
                }

                ctx.restore();

                if (labels && alpha > 0.35 && radius > 5.5) {
                    const label = node.title.length > 26 ? node.title.slice(0, 25) + '…' : node.title;
                    ctx.save();
                    ctx.globalAlpha = Math.min(1, alpha);
                    ctx.font = (isSelected || isHovered ? '600 ' : '')
                        + Math.max(10, Math.min(13, radius * 0.78)) + 'px "Segoe UI", system-ui, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'top';
                    const y = node.screen.y + radius + 5;
                    const width = ctx.measureText(label).width;
                    ctx.fillStyle = 'rgba(10,12,20,0.72)';
                    ctx.fillRect(node.screen.x - width / 2 - 4, y - 2, width + 8, 16);
                    ctx.fillStyle = '#e6ecff';
                    ctx.fillText(label, node.screen.x, y);
                    ctx.restore();
                }

                if (node.counters && radius > 10 && (node.screen.s >= 0.5 || isHovered || isSelected)) {
                    const current = Number(node.counters.current);
                    if (Number.isFinite(current)) {
                        ctx.save();
                        ctx.globalAlpha = Math.min(1, alpha);
                        ctx.font = 'bold 10px "Segoe UI", system-ui, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        const text = String(current);
                        const width = Math.max(16, ctx.measureText(text).width + 10);
                        const bx = node.screen.x + radius * 0.74;
                        const by = node.screen.y - radius * 0.74;
                        ctx.fillStyle = kindColor;
                        ctx.beginPath();
                        ctx.arc(bx, by, width / 2, 0, Math.PI * 2);
                        ctx.fill();
                        ctx.fillStyle = '#0b0e16';
                        ctx.fillText(text, bx, by + 0.5);
                        ctx.restore();
                    }
                }
            }
        }

        function drawEffects() {
            for (let i = effects.length - 1; i >= 0; i--) {
                const effect = effects[i];
                effect.life -= 0.02;
                if (effect.life <= 0) {
                    effects.splice(i, 1);
                    continue;
                }
                ctx.save();
                ctx.globalAlpha = effect.life * 0.5;
                ctx.strokeStyle = STATE_COLORS[effect.state] || STATE_COLORS.ok;
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(effect.x, effect.y, lerp(6, effect.max, 1 - effect.life), 0, Math.PI * 2);
                ctx.stroke();
                ctx.restore();
            }
        }

        function drawVignette() {
            const gradient = ctx.createRadialGradient(
                viewW / 2, viewH / 2, Math.min(viewW, viewH) * 0.28,
                viewW / 2, viewH / 2, Math.max(viewW, viewH) * 0.72
            );
            gradient.addColorStop(0, 'rgba(0,0,0,0)');
            gradient.addColorStop(1, 'rgba(0,0,0,0.42)');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, viewW, viewH);
        }

        // ── Frame loop ────────────────────────────────────────────────────────

        function frame(now) {
            if (!running) return;
            rafId = window.requestAnimationFrame(frame);

            const delta = lastFrame ? Math.min(64, now - lastFrame) : 16;
            lastFrame = now;

            try {
                if (layoutDirty) {
                    layout();
                    layout2d();
                }

                // The core sits at the world origin, so a yaw/pitch rotation turns
                // the whole network around LLMInt.
                if (rotate && mode3d && !reducedMotion && now - lastInteraction > IDLE_BEFORE_ROTATE) {
                    cam.yaw += delta * 0.00012;
                }

                for (const node of nodes.values()) {
                    if (node.appear < 1) node.appear = Math.min(1, node.appear + delta * 0.0022);
                    if (node.effect > 0) node.effect = Math.max(0, node.effect - delta * 0.0016);
                    const s = nodeScreen(node);
                    node.screen.x = s.X;
                    node.screen.y = s.Y;
                    node.screen.s = s.s;
                    node.screen.r = (KIND_RADIUS[node.kind] || DEFAULT_KIND_RADIUS) * s.s * cam.zoom;
                    node.screen.visible = !s.behind;
                }

                for (const edge of edges) {
                    edge.visible = edge.a.screen.visible && edge.b.screen.visible
                        && kindFilter.has(edge.a.kind) && kindFilter.has(edge.b.kind);
                    syncParticles(edge);
                    if (particles && edge.visible) {
                        for (const particle of edge.particles) {
                            particle.t += particle.speed * delta * (edge.state === 'error' ? 1.6 : 1);
                            if (particle.t > 1) particle.t -= 1;
                        }
                    }
                }

                drawBackground();
                drawEdges();
                drawParticles();
                drawNodes();
                drawEffects();
                drawVignette();
                drawDetailCard();
            } catch (error) {
                if (!frameErrorLogged) {
                    frameErrorLogged = true;
                    window.console.error('[flow-topology] Zeichenfehler', error);
                }
                ctx.setLineDash([]);
                ctx.globalAlpha = 1;
            }
        }

        function start() {
            if (running) return;
            running = true;
            lastFrame = 0;
            rafId = window.requestAnimationFrame(frame);
        }

        function stop() {
            running = false;
            if (rafId) window.cancelAnimationFrame(rafId);
            rafId = 0;
        }

        // ── Interaction ───────────────────────────────────────────────────────

        let dragging = false;
        let dragMode = '';
        let dragStart = { x: 0, y: 0 };
        let dragOrigin = { yaw: 0, pitch: 0, panX: 0, panY: 0 };
        let suppressClick = false;
        const pointers = new Map();
        let pinchStart = 0;
        let pinchZoom = 1;

        function localPoint(event) {
            const rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }

        function hitTest(x, y) {
            let best = null;
            for (const node of nodes.values()) {
                if (!node.screen.visible || !kindFilter.has(node.kind)) continue;
                if (Math.hypot(node.screen.x - x, node.screen.y - y) > Math.max(12, node.screen.r)) continue;
                if (!best || node.screen.r > best.screen.r) best = node;
            }
            return best;
        }

        function showTooltip(node, x, y) {
            if (!tooltipEl) return;
            setField('tooltip-title', node.title);
            const stateEl = root.querySelector('[data-topo-tooltip-state]');
            if (stateEl) {
                swapClass(stateEl, 'badge--', node.state);
                stateEl.textContent = STATE_LABELS[node.state] || node.state;
            }
            setField('tooltip-message', node.message || node.subtitle || '');
            tooltipEl.hidden = false;
            const width = tooltipEl.offsetWidth || 220;
            const height = tooltipEl.offsetHeight || 80;
            tooltipEl.style.left = clamp(x + 16, 8, Math.max(8, viewW - width - 8)) + 'px';
            tooltipEl.style.top = clamp(y + 16, 8, Math.max(8, viewH - height - 8)) + 'px';
        }

        function hideTooltip() {
            if (tooltipEl) tooltipEl.hidden = true;
        }

        function renderList(blockName, data) {
            const block = root.querySelector('[data-topo-field="panel-' + blockName + '"]');
            if (!block) return;
            block.hidden = !data;
            if (!data) return;
            setField('panel-' + blockName + '-title', data.title || '');
            const listEl = root.querySelector('[data-topo-field="panel-' + blockName + '-list"]');
            const emptyEl = root.querySelector('[data-topo-field="panel-' + blockName + '-empty"]');
            const items = Array.isArray(data.items) ? data.items : [];
            if (listEl) {
                clearChildren(listEl);
                for (const item of items) {
                    if (!item || typeof item !== 'object') continue;
                    const row = el('li', 'topo-member');
                    const head = el('div', 'topo-member-head');
                    head.appendChild(el('span', 'topo-member-title', item.title));
                    if (item.badge) {
                        head.appendChild(el('span', 'badge badge--' + stateOf(item.state), item.badge));
                    }
                    row.appendChild(head);
                    if (item.subtitle) row.appendChild(el('div', 'topo-member-subtitle', item.subtitle));
                    listEl.appendChild(row);
                }
            }
            if (emptyEl) emptyEl.hidden = items.length > 0;
        }

        function renderPanel(node) {
            if (!panelEl) return;
            if (!node) {
                panelEl.hidden = true;
                return;
            }
            panelEl.hidden = false;
            setField('panel-kind', KIND_LABELS[node.kind] || node.kind);
            setField('panel-title', node.title);
            setField('panel-subtitle', node.subtitle);
            setField('panel-message', node.message);

            const badge = root.querySelector('[data-topo-field="panel-badge"]');
            if (badge) {
                swapClass(badge, 'badge--', node.state);
                badge.textContent = node.badge || (STATE_LABELS[node.state] || node.state);
            }

            const factsEl = root.querySelector('[data-topo-field="panel-facts"]');
            if (factsEl) {
                clearChildren(factsEl);
                for (const fact of node.facts) {
                    if (!fact || typeof fact !== 'object') continue;
                    const row = el('div', 'topo-fact');
                    row.appendChild(el('span', 'topo-fact-label',
                        (fact.icon ? fact.icon + ' ' : '') + (fact.label || '')));
                    row.appendChild(el('span', 'topo-fact-value', fact.value));
                    factsEl.appendChild(row);
                }
            }

            renderList('cloud', node.cloud);
            renderList('members', node.members);

            const neighboursEl = root.querySelector('[data-topo-field="panel-neighbours-list"]');
            if (neighboursEl) {
                clearChildren(neighboursEl);
                for (const key of node.neighbours) {
                    const other = nodes.get(key);
                    if (!other) continue;
                    const chip = el('button', 'topo-chip', other.title);
                    chip.type = 'button';
                    chip.addEventListener('click', function () { focusNode(other); });
                    neighboursEl.appendChild(chip);
                }
            }

            const linkEl = root.querySelector('[data-topo-field="panel-link"]');
            if (linkEl) {
                if (node.url) {
                    linkEl.href = node.url;
                    linkEl.hidden = false;
                } else {
                    linkEl.removeAttribute('href');
                    linkEl.hidden = true;
                }
            }
        }

        // ── Detail card ───────────────────────────────────────────────────────

        // A clicked bubble turns into an in-canvas detail card carrying the same
        // values the previous dashboard graphic showed. Nodes that ship a list
        // (cloud/members) keep the HTML side panel instead, because a list does
        // not fit a compact card - the two views never show up together.
        function usesDetailCard(node) {
            return !!node && Array.isArray(node.facts) && node.facts.length > 0;
        }

        function usesPanel(node) {
            return !!node && (!!node.cloud || !!node.members);
        }

        function factText(fact) {
            const icon = fact.icon ? String(fact.icon) + '  ' : '';
            const label = fact.label ? String(fact.label) + ': ' : '';
            const value = fact.value === null || fact.value === undefined ? '' : String(fact.value);
            return icon + label + value;
        }

        function factColor(fact) {
            return FACT_TONES[String(fact.tone || 'plain')] || FACT_TONES.plain;
        }

        function fitText(text, maxWidth) {
            if (ctx.measureText(text).width <= maxWidth) return text;
            let cut = text;
            while (cut.length > 1 && ctx.measureText(cut + '…').width > maxWidth) {
                cut = cut.slice(0, -1);
            }
            return cut + '…';
        }

        function roundRect(x, y, w, h, r) {
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.lineTo(x + w - r, y);
            ctx.quadraticCurveTo(x + w, y, x + w, y + r);
            ctx.lineTo(x + w, y + h - r);
            ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
            ctx.lineTo(x + r, y + h);
            ctx.quadraticCurveTo(x, y + h, x, y + h - r);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.closePath();
        }

        // ── Placing the detail card ──────────────────────────────────────────

        // The card is as big as a good part of the picture, so where it lands
        // decides what stays readable. It never sits on the clicked bubble and
        // never on the way that leads away from it - otherwise the click would
        // hide exactly what it was meant to show.
        const CARD_GAP = 14;

        /** How much the card would cover at this spot - 0 means free space. */
        function cardOverlap(node, x, y, w, h) {
            const pad = 8;
            const left = x - pad;
            const right = x + w + pad;
            const top = y - pad;
            const bottom = y + h + pad;
            const covers = (px, py) => px >= left && px <= right && py >= top && py <= bottom;

            let score = 0;
            for (const other of nodes.values()) {
                if (!other.screen.visible) continue;
                const dx = Math.max(left - other.screen.x, 0, other.screen.x - right);
                const dy = Math.max(top - other.screen.y, 0, other.screen.y - bottom);
                if (Math.hypot(dx, dy) >= other.screen.r + pad) continue;
                // The clicked bubble itself must stay free, the neighbours are
                // weighed more lightly: on a crowded branch something has to give.
                score += other.key === node.key ? 40 : 6;
            }
            for (const edge of edges) {
                if (!edge.visible || !route.edges.has(edge.id)) continue;
                const path = edgePath(edge);
                for (let t = 0; t <= 1.0001; t += 0.05) {
                    const point = bezierPoint(path, t);
                    if (covers(point.x, point.y)) {
                        score += 12;
                        break;
                    }
                }
            }
            return score;
        }

        /**
         * The free spot with the least overlap: first right beside the bubble,
         * then the border of the canvas. The network fills the middle of the
         * view, so its border is where the free room is left once the graph is
         * fitted in - and the card is big enough to need it. Among equally free
         * spots the one closest to the bubble wins, so the card only travels as
         * far as it has to.
         */
        function placeCard(node, width, height) {
            const margin = 10;
            const maxX = Math.max(margin, viewW - width - margin);
            const maxY = Math.max(margin, viewH - height - margin);
            const gap = node.screen.r + CARD_GAP;
            const cx = node.screen.x;
            const cy = node.screen.y;
            const left = cx - width / 2;
            const middle = cy - height / 2;

            const spots = [
                [cx + gap, middle],
                [cx - gap - width, middle],
                [left, cy + gap],
                [left, cy - gap - height],
                [cx + gap * 0.75, cy + gap * 0.75],
                [cx - gap * 0.75 - width, cy + gap * 0.75],
                [cx + gap * 0.75, cy - gap * 0.75 - height],
                [cx - gap * 0.75 - width, cy - gap * 0.75 - height],
                [margin, middle],
                [maxX, middle],
                [left, margin],
                [left, maxY],
                [margin, margin],
                [maxX, margin],
                [margin, maxY],
                [maxX, maxY],
            ];

            const options = spots.map((spot) => {
                const x = clamp(spot[0], margin, maxX);
                const y = clamp(spot[1], margin, maxY);
                return {
                    x: x,
                    y: y,
                    score: cardOverlap(node, x, y, width, height),
                    away: Math.hypot(x + width / 2 - cx, y + height / 2 - cy),
                };
            });

            let index = 0;
            for (let i = 1; i < options.length; i++) {
                const option = options[i];
                const best = options[index];
                if (option.score < best.score || (option.score === best.score && option.away < best.away)) {
                    index = i;
                }
            }

            // The network keeps turning while nobody touches it, so the spot is
            // kept as long as it stays as good as the best one - a card hopping
            // from one side of the bubble to the other would be unreadable.
            if (cardSpot && cardSpot.key === node.key && options[cardSpot.index]
                && options[cardSpot.index].score <= options[index].score) {
                index = cardSpot.index;
            }
            cardSpot = { key: node.key, index: index };
            return options[index];
        }

        function drawDetailCard() {
            cardHit = null;
            if (!selected) {
                cardSpot = null;
                return;
            }
            const node = nodes.get(selected);
            if (!node || !node.screen.visible || !kindFilter.has(node.kind)
                || !usesDetailCard(node) || usesPanel(node)) {
                cardSpot = null;
                return;
            }

            const slots    = node.slots && Number(node.slots.max) > 0 ? node.slots : null;
            const hasUrl   = !!node.url;
            const chrome   = CARD.headerHeight + CARD.pad
                + (slots ? CARD.barGap + CARD.barHeight : 0)
                + (hasUrl ? CARD.footerHeight : 0);

            ctx.save();
            ctx.font = CARD.rowHeight + 'px "Segoe UI", system-ui, sans-serif';

            // Trim the rows when the viewport is too short for the full card.
            const maxRows  = Math.max(1, Math.floor((viewH - 24 - chrome) / CARD.rowHeight));
            const overflow = node.facts.length > maxRows;
            const keep     = overflow ? Math.max(0, maxRows - 1) : node.facts.length;
            const rows     = node.facts.slice(0, keep);
            if (overflow) {
                rows.push({ icon: '…', label: '', value: (node.facts.length - keep) + ' weitere', tone: 'muted' });
            }

            const width  = CARD.width;
            const height = chrome + rows.length * CARD.rowHeight;
            const spot   = placeCard(node, width, height);
            const x = spot.x;
            const y = spot.y;

            const stateColor = STATE_COLORS[node.state] || STATE_COLORS.off;
            const stateTriple = STATE_RGB[node.state] || STATE_RGB.off;

            // The card sits beside the bubble, so a dashed line ties it back to
            // the node it belongs to.
            const anchorX = clamp(node.screen.x, x + 16, x + width - 16);
            const anchorY = clamp(node.screen.y, y + 16, y + height - 16);
            if (Math.hypot(anchorX - node.screen.x, anchorY - node.screen.y) > 2) {
                ctx.strokeStyle = rgba(stateTriple, 0.55);
                ctx.lineWidth = 1.5;
                ctx.setLineDash([4, 4]);
                ctx.beginPath();
                ctx.moveTo(node.screen.x, node.screen.y);
                ctx.lineTo(anchorX, anchorY);
                ctx.stroke();
                ctx.setLineDash([]);
            }

            ctx.shadowColor = 'rgba(0,0,0,0.55)';
            ctx.shadowBlur = 22;
            ctx.shadowOffsetY = 8;
            const body = ctx.createLinearGradient(x, y, x, y + height);
            body.addColorStop(0, 'rgba(24,27,44,0.97)');
            body.addColorStop(1, 'rgba(12,14,25,0.97)');
            ctx.fillStyle = body;
            roundRect(x, y, width, height, CARD.radius);
            ctx.fill();
            ctx.shadowColor = 'transparent';
            ctx.shadowBlur = 0;
            ctx.shadowOffsetY = 0;

            ctx.strokeStyle = rgba(stateTriple, 0.55);
            ctx.lineWidth = 1.2;
            roundRect(x, y, width, height, CARD.radius);
            ctx.stroke();

            // Accent bar on the left edge, exactly like the old endpoint tile.
            ctx.fillStyle = stateColor;
            roundRect(x + 1.5, y + 10, 3, height - 20, 1.5);
            ctx.fill();

            // Header: status dot, title, state badge.
            ctx.textBaseline = 'middle';
            ctx.fillStyle = stateColor;
            ctx.beginPath();
            ctx.arc(x + CARD.pad + 4, y + 20, 4, 0, Math.PI * 2);
            ctx.fill();

            const badge = node.badge || STATE_LABELS[node.state] || '';
            ctx.font = '600 10px "Segoe UI", system-ui, sans-serif';
            const badgeWidth = badge ? ctx.measureText(badge).width + 10 : 0;
            if (badge) {
                ctx.textAlign = 'right';
                ctx.fillStyle = stateColor;
                ctx.fillText(badge, x + width - CARD.pad, y + 20);
            }

            ctx.textAlign = 'left';
            ctx.font = '600 13px "Segoe UI", system-ui, sans-serif';
            ctx.fillStyle = '#eef2ff';
            ctx.fillText(
                fitText(node.title, width - CARD.pad * 2 - 16 - badgeWidth),
                x + CARD.pad + 16,
                y + 20
            );

            ctx.strokeStyle = 'rgba(255,255,255,0.08)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(x + 1, y + CARD.headerHeight - 6);
            ctx.lineTo(x + width - 1, y + CARD.headerHeight - 6);
            ctx.stroke();

            // Facts, one row per value in the colour the server named.
            ctx.font = '11.5px "Segoe UI", system-ui, sans-serif';
            let rowY = y + CARD.headerHeight + CARD.rowHeight / 2;
            for (const fact of rows) {
                ctx.fillStyle = factColor(fact);
                ctx.fillText(fitText(factText(fact), width - CARD.pad * 2), x + CARD.pad, rowY);
                rowY += CARD.rowHeight;
            }
            const rowsEnd = y + CARD.headerHeight + rows.length * CARD.rowHeight;

            // Parallel slot bar (max 4, like the previous dashboard graphic).
            if (slots) {
                const used = Math.max(0, Number(slots.used) || 0);
                const max = Math.max(1, Number(slots.max) || 1);
                const barX = x + CARD.pad;
                const barW = width - CARD.pad * 2;
                const barY = rowsEnd + CARD.barGap;
                ctx.fillStyle = 'rgba(255,255,255,0.07)';
                roundRect(barX, barY, barW, CARD.barHeight, CARD.barHeight / 2);
                ctx.fill();
                if (used > 0) {
                    ctx.fillStyle = '#f59e0b';
                    roundRect(barX, barY, Math.round(barW * Math.min(1, used / max)), CARD.barHeight, CARD.barHeight / 2);
                    ctx.fill();
                }
            }

            cardHit = { x: x, y: y, w: width, h: height, url: hasUrl ? node.url : '' };

            if (hasUrl) {
                const footerY = y + height - CARD.footerHeight;
                ctx.strokeStyle = 'rgba(255,255,255,0.08)';
                ctx.beginPath();
                ctx.moveTo(x + 1, footerY);
                ctx.lineTo(x + width - 1, footerY);
                ctx.stroke();
                ctx.fillStyle = '#8ea2ff';
                ctx.font = '600 11px "Segoe UI", system-ui, sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText(fitText('In der Konfiguration öffnen →', width - CARD.pad * 2),
                    x + CARD.pad, footerY + CARD.footerHeight / 2);
            }

            ctx.restore();
        }

        function pointInCard(x, y) {
            return !!cardHit
                && x >= cardHit.x && x <= cardHit.x + cardHit.w
                && y >= cardHit.y && y <= cardHit.y + cardHit.h;
        }

        function pointOnCardLink(x, y) {
            return !!cardHit && !!cardHit.url
                && pointInCard(x, y)
                && y >= cardHit.y + cardHit.h - CARD.footerHeight;
        }

        function select(key) {
            selected = key && nodes.has(key) ? key : null;
            refreshRoute();
            const node = selected ? nodes.get(selected) : null;
            // Lists stay in the HTML panel, key/value detail goes to the card.
            renderPanel(usesPanel(node) ? node : null);
        }

        function centerOn(node) {
            const p = project(node);
            cam.panX = p.x;
            cam.panY = p.y;
            lastInteraction = performance.now();
            userAdjusted = true;
        }

        function focusNode(node) {
            if (!node) return;
            select(node.key);
            centerOn(node);
        }

        function fit() {
            let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
            for (const node of nodes.values()) {
                if (!kindFilter.has(node.kind)) continue;
                const p = project(node);
                const radius = (KIND_RADIUS[node.kind] || DEFAULT_KIND_RADIUS) * p.s;
                minX = Math.min(minX, p.x - radius * 2.4);
                maxX = Math.max(maxX, p.x + radius * 2.4);
                minY = Math.min(minY, p.y - radius * 2.8);
                maxY = Math.max(maxY, p.y + radius * 2.8);
            }
            if (!Number.isFinite(minX)) return;
            // LLMInt stays in the middle: the camera follows the core and the
            // zoom is derived from the furthest distance on either side of it.
            const hub = hubNode();
            const centre = hub ? project(hub) : { x: (minX + maxX) / 2, y: (minY + maxY) / 2 };
            const width = Math.max(220, 2 * Math.max(centre.x - minX, maxX - centre.x));
            const height = Math.max(160, 2 * Math.max(centre.y - minY, maxY - centre.y));
            cam.zoom = clamp(Math.min((viewW - 70) / width, (viewH - 70) / height), 0.12, 2.4);
            cam.panX = centre.x;
            cam.panY = centre.y;
            lastInteraction = performance.now();
        }

        function resetView() {
            cam.yaw = CAM_YAW;
            cam.pitch = CAM_PITCH;
            cam.zoom = 1;
            cam.panX = 0;
            cam.panY = 0;
            userAdjusted = false;
            fit();
        }

        function zoomBy(factor, x, y) {
            const cx = x === undefined ? viewW / 2 : x;
            const cy = y === undefined ? viewH / 2 : y;
            const beforeX = cam.panX + (cx - viewW / 2) / cam.zoom;
            const beforeY = cam.panY + (cy - viewH / 2) / cam.zoom;
            cam.zoom = clamp(cam.zoom * factor, 0.1, 4);
            cam.panX = beforeX - (cx - viewW / 2) / cam.zoom;
            cam.panY = beforeY - (cy - viewH / 2) / cam.zoom;
            lastInteraction = performance.now();
            userAdjusted = true;
        }

        function setMode(threeD) {
            mode3d = !!threeD;
            setPressed('mode-3d', mode3d);
            setPressed('mode-2d', !mode3d);
            lastInteraction = performance.now();
        }

        function fadeHint() {
            if (hintEl && !hintEl.hidden) {
                hintEl.classList.add('topo-hint--hidden');
                window.setTimeout(function () { hintEl.hidden = true; }, 400);
            }
        }

        function endPointer(event) {
            pointers.delete(event.pointerId);
            if (pointers.size < 2) pinchStart = 0;
            if (pointers.size === 0) {
                dragging = false;
                dragMode = '';
                canvas.classList.remove('topo-canvas--dragging');
            }
        }

        canvas.addEventListener('pointerdown', function (event) {
            try {
                canvas.setPointerCapture(event.pointerId);
            } catch (error) {
                // Pointer capture is optional.
            }
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (pointers.size === 2) {
                const list = Array.from(pointers.values());
                pinchStart = Math.hypot(list[0].x - list[1].x, list[0].y - list[1].y);
                pinchZoom = cam.zoom;
                return;
            }
            dragStart = localPoint(event);
            dragOrigin = { yaw: cam.yaw, pitch: cam.pitch, panX: cam.panX, panY: cam.panY };
            dragMode = event.shiftKey || event.button === 2 ? 'pan' : 'rotate';
            dragging = true;
            suppressClick = false;
            canvas.classList.add('topo-canvas--dragging');
            lastInteraction = performance.now();
            fadeHint();
        });

        canvas.addEventListener('pointermove', function (event) {
            if (pointers.has(event.pointerId)) {
                pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            }

            if (pointers.size === 2 && pinchStart > 0) {
                const list = Array.from(pointers.values());
                const distance = Math.hypot(list[0].x - list[1].x, list[0].y - list[1].y);
                if (distance > 0) {
                    cam.zoom = clamp(pinchZoom * (distance / pinchStart), 0.1, 4);
                    lastInteraction = performance.now();
                }
                suppressClick = true;
                return;
            }

            const point = localPoint(event);

            if (dragging) {
                const dx = point.x - dragStart.x;
                const dy = point.y - dragStart.y;
                if (Math.abs(dx) + Math.abs(dy) > 4) suppressClick = true;
                if (dragMode === 'rotate' && mode3d) {
                    cam.yaw = dragOrigin.yaw + dx * 0.005;
                    cam.pitch = clamp(dragOrigin.pitch + dy * 0.004, -1.25, 1.25);
                } else {
                    cam.panX = dragOrigin.panX - dx / cam.zoom;
                    cam.panY = dragOrigin.panY - dy / cam.zoom;
                }
                userAdjusted = true;
                lastInteraction = performance.now();
                return;
            }

            const hit = hitTest(point.x, point.y);
            const key = hit ? hit.key : null;
            const overCard = pointInCard(point.x, point.y);
            if (key !== hovered) hovered = key;
            canvas.classList.toggle('topo-canvas--pointing',
                pointOnCardLink(point.x, point.y) || (!!hit && !overCard));
            if (hit && !overCard) showTooltip(hit, point.x, point.y);
            else hideTooltip();
        });

        canvas.addEventListener('pointerup', function (event) {
            const point = localPoint(event);
            endPointer(event);
            if (suppressClick) return;
            if (pointInCard(point.x, point.y)) {
                if (pointOnCardLink(point.x, point.y)) window.location.href = cardHit.url;
                return;
            }
            const hit = hitTest(point.x, point.y);
            select(hit ? hit.key : null);
        });

        canvas.addEventListener('pointercancel', endPointer);
        canvas.addEventListener('pointerleave', function () {
            hovered = null;
            canvas.classList.remove('topo-canvas--pointing');
            hideTooltip();
        });

        canvas.addEventListener('contextmenu', function (event) {
            event.preventDefault();
        });

        canvas.addEventListener('wheel', function (event) {
            event.preventDefault();
            const point = localPoint(event);
            zoomBy(event.deltaY < 0 ? 1.12 : 0.89, point.x, point.y);
            fadeHint();
        }, { passive: false });

        canvas.addEventListener('keydown', function (event) {
            const step = 40 / cam.zoom;
            if (event.key === 'ArrowLeft') cam.panX -= step;
            else if (event.key === 'ArrowRight') cam.panX += step;
            else if (event.key === 'ArrowUp') cam.panY -= step;
            else if (event.key === 'ArrowDown') cam.panY += step;
            else if (event.key === '+' || event.key === '=') zoomBy(1.15);
            else if (event.key === '-') zoomBy(0.87);
            else if (event.key === 'f' || event.key === 'F') fit();
            else if (event.key === 'r' || event.key === 'R') resetView();
            else if (event.key === 'Escape') {
                if (!selected) return;
                select(null);
                hideTooltip();
            }
            else if (event.key === 'Tab') {
                const order = Array.from(nodes.values()).filter((node) => kindFilter.has(node.kind));
                if (!order.length) return;
                const index = order.findIndex((node) => node.key === selected);
                const next = event.shiftKey
                    ? order[(index - 1 + order.length) % order.length]
                    : order[(index + 1) % order.length];
                focusNode(next);
            } else {
                return;
            }
            event.preventDefault();
            userAdjusted = true;
            lastInteraction = performance.now();
        });

        // ── Actions ───────────────────────────────────────────────────────────

        const actions = {
            refresh: function () { load(true); },
            fullscreen: function () {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else if (root.requestFullscreen) {
                    root.requestFullscreen();
                }
            },
            'mode-3d': function () { setMode(true); },
            'mode-2d': function () { setMode(false); },
            rotate: function () { rotate = !rotate; setPressed('rotate', rotate); },
            particles: function () { particles = !particles; setPressed('particles', particles); },
            labels: function () { labels = !labels; setPressed('labels', labels); },
            'focus-problems': function () {
                problemFilter = !problemFilter;
                setPressed('focus-problems', problemFilter);
            },
            'zoom-in': function () { zoomBy(1.2); },
            'zoom-out': function () { zoomBy(0.83); },
            fit: function () { fit(); },
            reset: function () { resetView(); },
            'close-panel': function () { select(null); },
            'toggle-log': function () {
                const logEl = root.querySelector('[data-topo-log]');
                if (!logEl) return;
                const collapsed = logEl.classList.toggle('topo-log--collapsed');
                setPressed('toggle-log', !collapsed);
            },
        };

        root.addEventListener('click', function (event) {
            const trigger = event.target && event.target.closest
                ? event.target.closest('[data-topo-action]')
                : null;
            if (!trigger) return;
            const name = trigger.getAttribute('data-topo-action');
            if (!name || !actions[name]) return;
            event.preventDefault();
            actions[name]();
            lastInteraction = performance.now();
        });

        // ── Data flow ─────────────────────────────────────────────────────────

        function parseInitial() {
            const island = root.querySelector('[data-flow-initial]');
            if (!island) return false;
            let payload = null;
            try {
                payload = JSON.parse(island.textContent || '');
            } catch (error) {
                return false;
            }
            if (!payload || !payload.nodes) return false;
            ingest(payload);
            applyOverall(payload.overall);
            setIncidents(payload.incidents);
            previousStates = new Map();
            for (const node of nodes.values()) previousStates.set(node.key, node.state);
            setField('generated', timeNow());
            return true;
        }

        function diff() {
            for (const node of nodes.values()) {
                const before = previousStates.get(node.key);
                if (before === undefined || before === node.state) continue;
                node.effect = 1;
                effects.push({
                    x: node.screen.x,
                    y: node.screen.y,
                    max: 70 * Math.max(0.6, node.screen.s),
                    life: 1,
                    state: node.state,
                });
                log(node.state, node.title + ': ' + (STATE_LABELS[before] || before)
                    + ' → ' + (STATE_LABELS[node.state] || node.state));
            }
            previousStates = new Map();
            for (const node of nodes.values()) previousStates.set(node.key, node.state);
        }

        function apply(payload) {
            // The public API is also called with partial or missing data (for
            // example when a poll failed), so a broken payload is a no-op.
            if (!payload || typeof payload !== 'object') return;
            ingest(payload);
            applyOverall(payload.overall);
            setIncidents(payload.incidents);
            diff();
            setField('generated', timeNow());
            if (selected && !nodes.has(selected)) selected = null;
            refreshRoute();
            const current = selected ? nodes.get(selected) : null;
            renderPanel(usesPanel(current) ? current : null);
            if (!userAdjusted) {
                layout();
                layout2d();
                fit();
            }
        }

        function load(manual) {
            if (!refreshUrl) return Promise.resolve(false);
            if (inflight) return inflight;
            if (manual && typeof opts.onBusy === 'function') opts.onBusy();

            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const timer = controller
                ? window.setTimeout(function () { controller.abort(); }, REFRESH_TIMEOUT)
                : 0;

            inflight = fetch(refreshUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined,
            }).then(function (response) {
                if (timer) window.clearTimeout(timer);
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (payload) {
                inflight = null;
                if (!payload || payload.ok !== true) throw new Error('Ungültige Antwort');
                if (payload.topology) apply(payload.topology);
                if (typeof opts.onData === 'function') opts.onData(payload);
                return true;
            }).catch(function (error) {
                if (timer) window.clearTimeout(timer);
                inflight = null;
                const aborted = error && error.name === 'AbortError';
                if (typeof opts.onError === 'function') {
                    opts.onError(aborted ? 'Zeitüberschreitung' : 'Netzwerkfehler');
                }
                return false;
            });

            return inflight;
        }

        function schedule() {
            if (refreshTimer) window.clearInterval(refreshTimer);
            refreshTimer = 0;
            if (!refreshUrl || document.hidden) return;
            refreshTimer = window.setInterval(function () { load(false); }, refreshInterval);
        }

        // ── Bootstrap ─────────────────────────────────────────────────────────

        buildStars();
        buildKindLegend();
        resize();

        setMode(true);
        setPressed('rotate', rotate);
        setPressed('particles', particles);
        setPressed('labels', labels);
        setPressed('focus-problems', false);
        setPressed('toggle-log', true);

        const hasInitial = parseInitial();
        layout();
        layout2d();
        fit();
        start();

        if (!hasInitial) {
            setField('overall-label', 'Lade …');
            setField('overall-message', 'Nachrichtenfluss wird geladen.');
            load(true);
        }
        schedule();

        if (reducedMotion) {
            log('warn', 'Reduzierte Bewegung aktiv – Drehung und Partikel sind deaktiviert.');
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else {
                start();
                load(false);
                schedule();
            }
        });

        window.addEventListener('resize', function () {
            resize();
            if (!userAdjusted) fit();
        });

        if (typeof ResizeObserver === 'function') {
            const observer = new ResizeObserver(function () {
                resize();
                if (!userAdjusted) fit();
            });
            observer.observe(canvas);
        }

        document.addEventListener('fullscreenchange', function () {
            window.setTimeout(function () {
                resize();
                if (!userAdjusted) fit();
            }, 60);
        });

        return {
            apply: apply,
            load: load,
            resize: resize,
            fit: fit,
            resetView: resetView,
            select: function (key) { select(key || null); },
            state: function () { return { nodes: Array.from(nodes.values()), edges: edges, overall: overall }; },
            /** Geometry of the open detail card, or null - the same rectangle
             *  the canvas uses for hit testing. */
            card: function () {
                return cardHit
                    ? { x: cardHit.x, y: cardHit.y, w: cardHit.w, h: cardHit.h, url: cardHit.url }
                    : null;
            },
            route: function () {
                return {
                    active: route.active,
                    busy: route.busy,
                    nodes: Array.from(route.nodes),
                    edges: Array.from(route.edges),
                };
            },
        };
    }

    window.FlowTopology = {
        mount: mount,
        version: '1.0.0',
        colors: { states: STATE_COLORS, kinds: KIND_COLORS },
        labels: { states: STATE_LABELS, kinds: KIND_LABELS },
    };
})();
