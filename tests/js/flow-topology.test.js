/**
 * tests/js/flow-topology.test.js
 *
 * Behaviour tests for the admin message-flow renderer
 * (assets/js/flow-topology.js). Run with:
 *
 *   node --test tests/js/*.test.js
 *
 * The renderer runs in a VM sandbox with a DOM/canvas double
 * (tests/js/support/sandbox.js), so the tests need no browser and no
 * dependency. They cover the contract the server relies on: what `apply()`
 * ingests, what it drops, how selection switches between the in-canvas detail
 * card and the HTML panel, and that a full frame can be drawn without error.
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const { createEnvironment, FakeElement } = require('./support/sandbox');

// ── Fixtures ─────────────────────────────────────────────────────────────────

function node(key, kind, extra) {
    return Object.assign({ key: key, kind: kind, title: key, state: 'ok', neighbours: [] }, extra || {});
}

/**
 * A small but complete message-flow payload: LLMInt with the LLM branch (group →
 * endpoint), the client cloud with one session, and one incident.
 */
function basePayload() {
    const nodes = {};
    const add = (entry) => { nodes[entry.key] = entry; };

    add(node('hub', 'hub', {
        title: 'LLMInt',
        subtitle: 'Zentrale',
        facts: [{ label: 'Modelle', value: '3' }],
        neighbours: ['llm', 'clients'],
    }));
    add(node('llm', 'llm', {
        title: 'LLM',
        members: { title: 'Intelligenzgruppen', items: [{ title: 'gemma-3-27b', state: 'ok', badge: '3' }] },
        neighbours: ['hub', 'model:gemma-3-27b'],
    }));
    add(node('model:gemma-3-27b', 'model', {
        title: 'gemma-3-27b',
        members: { title: 'Endpunkte', items: [] },
        neighbours: ['llm', 'ep:1'],
    }));
    add(node('ep:1', 'endpoint', {
        title: 'ep:1',
        facts: [{ label: 'Status', value: 'bereit' }],
        slots: { used: 1, max: 4 },
        activity: 3,
        neighbours: ['model:gemma-3-27b'],
    }));
    add(node('clients', 'clients', {
        title: 'Clients',
        cloud: {
            title: 'Aktive Clients',
            items: [{ title: 'pc-1', subtitle: '10.0.0.1' }],
            empty: 'Keine aktiven Clients.',
        },
        neighbours: ['hub'],
    }));
    add(node('client:1', 'client', { title: 'pc-1', neighbours: ['clients'] }));

    return {
        generated: '12:00:00',
        overall: { state: 'warn', label: 'Eingeschränkt', message: '1 Einschränkung erkannt.', errors: 0, warnings: 1, nodes: 6 },
        nodes: nodes,
        edges: [
            { from: 'hub', to: 'llm', state: 'ok' },
            { from: 'llm', to: 'model:gemma-3-27b', state: 'ok' },
            { from: 'model:gemma-3-27b', to: 'ep:1', state: 'ok' },
            { from: 'hub', to: 'clients', state: 'ok' },
            { from: 'clients', to: 'client:1', state: 'ok' },
        ],
        incidents: [{ key: 'ep:1', title: 'ep:1', state: 'warn', message: 'Alle Slots belegt.', url: '' }],
    };
}

/** All texts the canvas painted in the last frame. */
function paintedTexts(env) {
    return env.ctx.calls
        .filter((call) => call.name === 'fillText')
        .map((call) => String(call.args[0]));
}

/**
 * Compares values the renderer returned with a literal. The renderer runs in
 * its own VM realm, so its arrays and objects carry a foreign prototype that
 * `deepStrictEqual` rejects; cloning first brings them into this realm.
 */
function same(actual, expected, message) {
    assert.deepStrictEqual(structuredClone(actual), expected, message);
}

function mountWith(options) {
    const env = createEnvironment(options);
    const api = env.api.mount(env.root, {});
    return { env: env, api: api };
}

// ── Exports ──────────────────────────────────────────────────────────────────

test('exportiert Version, Farben und Beschriftungen für alle Bausteine', () => {
    const env = createEnvironment();
    assert.equal(typeof env.api.mount, 'function');
    assert.equal(env.api.version, '1.0.0');
    assert.deepEqual(Object.keys(env.api.labels.states).sort(), ['error', 'off', 'ok', 'warn']);

    const kinds = Object.keys(env.api.labels.kinds);
    assert.ok(kinds.length >= 10);
    for (const kind of kinds) {
        assert.ok(env.api.colors.kinds[kind], kind + ' hat keine Farbe');
        assert.ok(env.api.labels.kinds[kind], kind + ' hat keine Beschriftung');
    }
    for (const state of Object.keys(env.api.labels.states)) {
        assert.ok(env.api.colors.states[state], state + ' hat keine Farbe');
    }
});

test('mount() lehnt fehlende oder unbrauchbare Wurzeln ab', () => {
    const env = createEnvironment();
    assert.equal(env.api.mount(null), null);
    assert.equal(env.api.mount(new FakeElement('div')), null);
});

test('mount() liefert die Steuer-API und baut die Legende', () => {
    const { env, api } = mountWith();
    assert.ok(api);
    for (const name of ['apply', 'load', 'resize', 'fit', 'resetView', 'select', 'route', 'card', 'state']) {
        assert.equal(typeof api[name], 'function', name + ' fehlt');
    }
    same(api.state(), {
        nodes: [],
        edges: [],
        overall: { state: 'ok', label: '', message: '', errors: 0, warnings: 0, nodes: 0 },
    });
    const legend = env.hook('[data-topo-kinds]');
    assert.equal(legend.children.length, Object.keys(env.api.labels.kinds).length + 1);
    assert.equal(legend.children[0].textContent, 'Bausteine');
});

// ── Ingest ───────────────────────────────────────────────────────────────────

test('apply() übernimmt Knoten, Kanten und Gesamtzustand', () => {
    const { api } = mountWith();
    const payload = basePayload();
    api.apply(payload);

    const state = api.state();
    assert.equal(state.nodes.length, Object.keys(payload.nodes).length);
    assert.equal(state.edges.length, payload.edges.length);
    assert.equal(state.overall.state, 'warn');
    assert.equal(state.overall.label, 'Eingeschränkt');
    assert.equal(state.overall.message, '1 Einschränkung erkannt.');
    assert.equal(state.overall.warnings, 1);
    assert.equal(state.overall.errors, 0);
    assert.equal(state.overall.nodes, 6);
});

test('jeder Knoten bekommt die Felder, die der Renderer erwartet', () => {
    const { api } = mountWith();
    const payload = basePayload();
    api.apply(payload);

    const byKey = new Map(api.state().nodes.map((entry) => [entry.key, entry]));
    const hub = byKey.get('hub');
    assert.equal(hub.kind, 'hub');
    assert.equal(hub.subtitle, 'Zentrale');
    assert.deepEqual(hub.facts, payload.nodes.hub.facts);
    assert.deepEqual(hub.neighbours, ['llm', 'clients']);

    const endpoint = byKey.get('ep:1');
    assert.deepEqual(endpoint.slots, { used: 1, max: 4 });
    assert.equal(endpoint.activity, 3);

    const llm = byKey.get('llm');
    assert.equal(llm.members.title, 'Intelligenzgruppen');
    assert.equal(llm.members.items.length, 1);

    const clients = byKey.get('clients');
    assert.equal(clients.cloud.empty, 'Keine aktiven Clients.');

    // List payloads stay separate from the key/value payload of the card.
    assert.equal(hub.members, null);
    assert.equal(hub.cloud, null);
    assert.equal(llm.cloud, null);
});

test('apply() füllt fehlende Angaben mit den Vorgabewerten', () => {
    const { api } = mountWith();
    api.apply({ nodes: { a: { key: 'a' } }, edges: [], overall: { state: 'quatsch' } });

    const state = api.state();
    assert.equal(state.overall.state, 'off');
    assert.equal(state.overall.label, 'Nicht aktiv');

    const only = state.nodes[0];
    assert.equal(only.key, 'a');
    assert.equal(only.kind, 'endpoint');
    assert.equal(only.title, 'a');
    assert.equal(only.state, 'off');
    assert.equal(only.activity, 0);
    assert.equal(only.synthetic, false);
    same(only.facts, []);
    same(only.neighbours, []);
    assert.equal(only.slots, null);
    assert.equal(only.cloud, null);
    assert.equal(only.members, null);
});

test('apply() ersetzt die Knotenliste vollständig', () => {
    const { api } = mountWith();
    api.apply(basePayload());
    api.apply({ nodes: { only: { key: 'only', kind: 'hub' } }, edges: [], overall: { state: 'ok' } });

    same(api.state().nodes.map((entry) => entry.key), ['only']);
    assert.equal(api.state().edges.length, 0);
});

test('apply() verträgt leere, fehlende und unbrauchbare Nutzlasten', () => {
    const { api } = mountWith();
    api.apply(null);
    api.apply(undefined);
    api.apply('kaputt');
    api.apply({});
    same(api.state().nodes, []);
    assert.equal(api.state().edges.length, 0);
});

test('Kanten mit unbekannten Enden und doppelte Kanten entfallen', () => {
    const { api } = mountWith();
    const payload = basePayload();
    payload.edges.push({ from: 'hub', to: 'gibt-es-nicht' });
    payload.edges.push({ from: 'nicht-da', to: 'hub' });
    payload.edges.push({ from: 'hub', to: 'llm' });
    payload.edges.push(null);
    payload.edges.push('kaputt');
    api.apply(payload);

    const state = api.state();
    assert.equal(state.edges.length, payload.edges.length - 5);
    same(state.edges.map((edge) => edge.id).sort(), [
        'clients>client:1',
        'hub>clients',
        'hub>llm',
        'llm>model:gemma-3-27b',
        'model:gemma-3-27b>ep:1',
    ]);
    for (const edge of state.edges) {
        assert.ok(edge.a && edge.b, edge.id + ' hat kein Ziel');
        assert.equal(edge.id, edge.from + '>' + edge.to);
    }
});

test('Kanten behalten ihre Krümmung über Aktualisierungen', () => {
    const { api } = mountWith();
    api.apply(basePayload());
    const before = api.state().edges.map((edge) => [edge.id, edge.bend, edge.lift]);

    api.apply(basePayload());
    const after = api.state().edges.map((edge) => [edge.id, edge.bend, edge.lift]);
    assert.deepEqual(after, before);
});

test('Störungen werden in die Liste übernommen', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());

    const list = env.hook('[data-topo-incidents-list]');
    assert.equal(list.children.length, 1);
    assert.equal(list.children[0].children[1].textContent, 'ep:1');
    assert.equal(env.hook('[data-topo-incidents-empty]').hidden, true);

    api.apply({ nodes: { a: { key: 'a', kind: 'hub' } }, edges: [], overall: {}, incidents: [] });
    assert.equal(list.children.length, 0);
    assert.equal(env.hook('[data-topo-incidents-empty]').hidden, false);
});

// ── Selection: detail card vs. HTML panel ────────────────────────────────────

test('Klick auf einen Endpunkt zeigt die Detailkarte statt des HTML-Panels', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    env.pump();

    api.select('ep:1');
    assert.equal(env.hook('[data-topo-panel]').hidden, true, 'Panel muss verborgen bleiben');

    env.ctx.calls.length = 0;
    env.pump();
    const texts = paintedTexts(env);
    assert.ok(texts.indexOf('Status: bereit') >= 0, 'Wert der Detailkarte fehlt');
    assert.ok(texts.indexOf('Endpunkte') < 0, 'die Karte darf nur Werte zeigen');
});

test('Klick auf eine Gruppe zeigt das HTML-Panel statt der Detailkarte', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    env.pump();

    api.select('llm');
    const panel = env.hook('[data-topo-panel]');
    assert.equal(panel.hidden, false, 'Panel muss sichtbar sein');
    assert.equal(env.field('panel-kind').textContent, 'LLM');
    assert.equal(env.field('panel-title').textContent, 'LLM');

    const members = env.field('panel-members-list');
    assert.equal(members.children.length, 1);
    assert.equal(members.children[0].children[0].children[0].textContent, 'gemma-3-27b');

    env.ctx.calls.length = 0;
    env.pump();
    assert.ok(paintedTexts(env).indexOf('Status: bereit') < 0, 'Karte darf nicht zusätzlich erscheinen');
});

test('Klick auf die Clients-Wolke zeigt ihre Liste im HTML-Panel', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    env.pump();

    api.select('clients');
    assert.equal(env.hook('[data-topo-panel]').hidden, false);
    assert.equal(env.field('panel-cloud-title').textContent, 'Aktive Clients');

    const cloud = env.field('panel-cloud-list');
    assert.equal(cloud.children.length, 1);
    assert.equal(cloud.children[0].children[0].children[0].textContent, 'pc-1');
    assert.equal(cloud.children[0].children[1].textContent, '10.0.0.1');

    // The cloud block disappears again for a node without a cloud.
    api.select('ep:1');
    assert.equal(env.hook('[data-topo-panel]').hidden, true);
});

test('select() ignoriert unbekannte Schlüssel und schließt das Panel', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    api.select('llm');
    assert.equal(env.hook('[data-topo-panel]').hidden, false);

    api.select('gibt-es-nicht');
    assert.equal(env.hook('[data-topo-panel]').hidden, true);

    api.select(null);
    assert.equal(env.hook('[data-topo-panel]').hidden, true);
});

test('eine Auswahl, die aus den Daten verschwindet, wird zurückgesetzt', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    api.select('llm');
    assert.equal(env.hook('[data-topo-panel]').hidden, false);

    api.apply({ nodes: { hub: { key: 'hub', kind: 'hub' } }, edges: [], overall: { state: 'ok' } });
    assert.equal(env.hook('[data-topo-panel]').hidden, true);
});

// ── Weg zwischen Endpunkt und Client ─────────────────────────────────────────

/**
 * basePayload() plus a second model group, a second endpoint, SearXNG and a
 * second chat session - enough branches to tell the way of one endpoint apart
 * from everything beside it.
 */
function branchyPayload() {
    const payload = basePayload();
    const add = (entry) => { payload.nodes[entry.key] = entry; };

    add(node('model:qwen', 'model', { title: 'qwen', neighbours: ['llm', 'ep:2'] }));
    add(node('ep:2', 'endpoint', { title: 'ep:2', neighbours: ['model:qwen'] }));
    add(node('search', 'search', { title: 'SearXNG', neighbours: ['hub'] }));
    add(node('client:2', 'client', { title: 'pc-2', neighbours: ['clients'] }));

    payload.nodes['llm'].neighbours = ['hub', 'model:gemma-3-27b', 'model:qwen'];
    payload.nodes['hub'].neighbours = ['llm', 'clients', 'search'];
    payload.nodes['clients'].neighbours = ['hub', 'client:1', 'client:2'];
    payload.edges = payload.edges.concat([
        { from: 'llm', to: 'model:qwen', state: 'ok' },
        { from: 'model:qwen', to: 'ep:2', state: 'ok' },
        { from: 'hub', to: 'search', state: 'ok' },
        { from: 'clients', to: 'client:2', state: 'ok' },
    ]);
    payload.overall.nodes = 10;
    return payload;
}

test('Klick auf einen Endpunkt hebt den Weg bis zum Client hervor', () => {
    const { api } = mountWith();
    api.apply(branchyPayload());
    api.select('ep:1');

    const route = api.route();
    assert.equal(route.active, true, 'der Weg muss aktiv sein');
    same(route.nodes.slice().sort(), [
        'client:1', 'client:2', 'clients', 'ep:1', 'hub', 'llm', 'model:gemma-3-27b',
    ]);
    same(route.edges.slice().sort(), [
        'clients>client:1',
        'clients>client:2',
        'hub>clients',
        'hub>llm',
        'llm>model:gemma-3-27b',
        'model:gemma-3-27b>ep:1',
    ]);
});

test('der Weg führt nicht in fremde Zweige', () => {
    const { api } = mountWith();
    api.apply(branchyPayload());
    api.select('ep:1');

    const route = api.route();
    for (const key of ['ep:2', 'model:qwen', 'search']) {
        assert.equal(route.nodes.indexOf(key), -1, key + ' liegt nicht auf dem Weg');
    }
    for (const id of ['llm>model:qwen', 'model:qwen>ep:2', 'hub>search']) {
        assert.equal(route.edges.indexOf(id), -1, id + ' liegt nicht auf dem Weg');
    }
});

test('Klick auf eine Sitzung ohne laufende Anfrage hebt alle Endpunkt-Zweige hervor', () => {
    const { api } = mountWith();
    api.apply(branchyPayload());
    api.select('client:1');

    const route = api.route();
    assert.equal(route.active, true, 'der Weg muss aktiv sein');
    assert.equal(route.busy, false, 'ohne laufende Anfrage ist der Weg nicht eingegrenzt');
    same(route.nodes.slice().sort(), [
        'client:1', 'clients', 'ep:1', 'ep:2', 'hub', 'llm',
        'model:gemma-3-27b', 'model:qwen', 'search',
    ]);
    same(route.edges.slice().sort(), [
        'clients>client:1',
        'hub>clients',
        'hub>llm',
        'hub>search',
        'llm>model:gemma-3-27b',
        'llm>model:qwen',
        'model:gemma-3-27b>ep:1',
        'model:qwen>ep:2',
    ]);
});

test('die übrigen Sitzungen liegen nicht auf dem Weg einer Sitzung', () => {
    const { api } = mountWith();
    api.apply(branchyPayload());
    api.select('client:1');

    const route = api.route();
    assert.equal(route.nodes.indexOf('client:2'), -1, 'die zweite Sitzung liegt nicht auf dem Weg');
    assert.equal(route.edges.indexOf('clients>client:2'), -1, 'ihre Linie ebenso wenig');
});

/**
 * branchyPayload() plus a running request on the first session. The server
 * resolves it into the keys of the path it walks (route.nodes), exactly like
 * topology_build() does.
 */
function runningPayload(route) {
    const payload = branchyPayload();
    payload.nodes['client:1'].route = Object.assign({
        kind: 'llm',
        model: 'gemma-3-27b',
        endpoint_id: 1,
        seconds: 4,
        nodes: ['model:gemma-3-27b', 'ep:1'],
    }, route || {});
    return payload;
}

test('Klick auf eine Sitzung mit laufender Anfrage hebt nur deren Zweig hervor', () => {
    const { api } = mountWith();
    api.apply(runningPayload());
    api.select('client:1');

    const route = api.route();
    assert.equal(route.active, true, 'der Weg muss aktiv sein');
    assert.equal(route.busy, true, 'die Sitzung wartet auf eine Antwort');
    same(route.nodes.slice().sort(), [
        'client:1', 'clients', 'ep:1', 'hub', 'llm', 'model:gemma-3-27b',
    ]);
    same(route.edges.slice().sort(), [
        'clients>client:1',
        'hub>clients',
        'hub>llm',
        'llm>model:gemma-3-27b',
        'model:gemma-3-27b>ep:1',
    ]);
});

test('die übrigen Zweige bleiben bei einer laufenden Anfrage dunkel', () => {
    const { api } = mountWith();
    api.apply(runningPayload());
    api.select('client:1');

    const route = api.route();
    for (const key of ['client:2', 'ep:2', 'model:qwen', 'search']) {
        assert.equal(route.nodes.indexOf(key), -1, key + ' gehört nicht zur laufenden Anfrage');
    }
    for (const id of ['clients>client:2', 'llm>model:qwen', 'model:qwen>ep:2', 'hub>search']) {
        assert.equal(route.edges.indexOf(id), -1, id + ' gehört nicht zur laufenden Anfrage');
    }
});

test('eine laufende Suche führt zur Suchmaschine', () => {
    const { api } = mountWith();
    api.apply(runningPayload({ kind: 'search', model: '', endpoint_id: 0, nodes: ['search'] }));
    api.select('client:1');

    const route = api.route();
    assert.equal(route.busy, true);
    same(route.nodes.slice().sort(), ['client:1', 'clients', 'hub', 'search']);
    same(route.edges.slice().sort(), ['clients>client:1', 'hub>clients', 'hub>search']);
});

test('ohne bekanntes Ziel bleibt die Sitzung bei ihrem bisherigen Weg', () => {
    const { api } = mountWith();
    api.apply(runningPayload({ nodes: ['ep:99'] }));
    api.select('client:1');

    const route = api.route();
    assert.equal(route.busy, false, 'ohne auffindbares Ziel gilt die Anfrage als unbekannt');
    assert.equal(route.nodes.indexOf('ep:2') >= 0, true,
        'dann öffnen sich wieder alle Endpunkt-Zweige');
});

test('nur der Weg einer laufenden Anfrage fließt', () => {
    const { env, api } = mountWith();
    api.apply(runningPayload());
    env.pump(40);

    // The travelling dashes are the only strokes with a 12/12 pattern.
    const flowing = () => env.ctx.calls.filter((call) => call.name === 'stroke'
        && call.dash.length === 2 && call.dash[0] === 12 && call.dash[1] === 12).length;

    env.ctx.calls.length = 0;
    env.pump();
    assert.equal(flowing(), 0, 'ohne Auswahl fließt nichts');

    api.select('client:1');
    env.ctx.calls.length = 0;
    env.pump();
    assert.equal(flowing(), api.route().edges.length, 'jede Kante der laufenden Anfrage fließt');
});

test('Klick auf die Clients-Wolke hebt alle Sitzungen hervor', () => {
    const { api } = mountWith();
    api.apply(branchyPayload());
    api.select('clients');

    const route = api.route();
    same(route.nodes.slice().sort(), ['client:1', 'client:2', 'clients', 'hub']);
    same(route.edges.slice().sort(), ['clients>client:1', 'clients>client:2', 'hub>clients']);
});

test('ohne Gegenstück endet der Weg einer Sitzung am Kern', () => {
    const { api } = mountWith();
    api.apply({
        nodes: {
            hub: node('hub', 'hub', { neighbours: ['clients'] }),
            clients: node('clients', 'clients', { neighbours: ['hub', 'client:1'] }),
            'client:1': node('client:1', 'client', { neighbours: ['clients'] }),
        },
        edges: [
            { from: 'hub', to: 'clients', state: 'ok' },
            { from: 'clients', to: 'client:1', state: 'ok' },
        ],
        overall: { state: 'ok' },
    });
    api.select('client:1');

    const route = api.route();
    assert.equal(route.active, true);
    same(route.nodes.slice().sort(), ['client:1', 'clients', 'hub']);
    same(route.edges.slice().sort(), ['clients>client:1', 'hub>clients']);
});

test('ohne Auswahl gibt es keinen Weg', () => {
    const { api } = mountWith();
    api.apply(basePayload());

    assert.equal(api.route().active, false);
    same(api.route().nodes, []);
    same(api.route().edges, []);

    api.select('ep:1');
    assert.equal(api.route().active, true);

    api.select(null);
    assert.equal(api.route().active, false);
    same(api.route().edges, []);
});

test('eine neue Nutzlast rechnet den Weg neu', () => {
    const { api } = mountWith();
    api.apply(basePayload());
    api.select('ep:1');
    assert.equal(api.route().nodes.length, 6);

    // A second session turns up: it joins the way without a new click.
    api.apply(branchyPayload());
    assert.equal(api.route().nodes.indexOf('ep:1') >= 0, true, 'die Auswahl bleibt erhalten');
    assert.equal(api.route().nodes.indexOf('client:2') >= 0, true, 'die neue Sitzung gehört zum Weg');
    assert.equal(api.route().nodes.length, 7);

    // The endpoint disappears: the selection and with it the way are dropped.
    api.apply({
        nodes: { hub: { key: 'hub', kind: 'hub' }, clients: { key: 'clients', kind: 'clients' } },
        edges: [{ from: 'hub', to: 'clients', state: 'ok' }],
        overall: { state: 'ok' },
    });
    assert.equal(api.route().active, false, 'ohne den gewählten Knoten gibt es keinen Weg');
    same(api.route().nodes, []);
});

test('der Weg wird kräftig gezeichnet, der Rest tritt zurück', () => {
    const { env, api } = mountWith();
    api.apply(branchyPayload());
    env.pump(40);

    // The halo is the widest stroke in the picture and only edges paint one,
    // so its alpha tells how far a line steps back - independent of how far a
    // node has faded in.
    const faintHalos = () => env.ctx.calls
        .filter((call) => call.name === 'stroke' && call.lineWidth >= 8 && call.alpha <= 0.05)
        .length;

    env.ctx.calls.length = 0;
    env.pump();
    assert.equal(faintHalos(), 0, 'ohne Auswahl darf keine Kante verblassen');

    api.select('ep:1');
    env.ctx.calls.length = 0;
    env.pump();

    // llm>model:qwen, model:qwen>ep:2 and hub>search lie beside the way.
    assert.equal(faintHalos(), 3, 'die Kanten neben dem Weg müssen zurücktreten');

    const way = env.ctx.calls.filter((call) => call.name === 'stroke'
        && call.alpha >= 0.99 && call.lineWidth >= 4);
    assert.equal(way.length, api.route().edges.length,
        'jede Kante des Weges wird kräftig und durchgezogen gezeichnet');
    for (const call of way) {
        same(call.dash, [], 'der Weg darf nicht gestrichelt sein');
    }
});

test('auch der Weg einer Sitzung wird kräftig gezeichnet', () => {
    const { env, api } = mountWith();
    api.apply(branchyPayload());
    env.pump(40);

    const faintHalos = () => env.ctx.calls
        .filter((call) => call.name === 'stroke' && call.lineWidth >= 8 && call.alpha <= 0.05)
        .length;

    api.select('client:1');
    env.ctx.calls.length = 0;
    env.pump();

    // Only the line to the second session lies beside the way.
    assert.equal(faintHalos(), 1, 'nur die Linie zur zweiten Sitzung tritt zurück');

    const way = env.ctx.calls.filter((call) => call.name === 'stroke'
        && call.alpha >= 0.99 && call.lineWidth >= 4);
    assert.equal(way.length, api.route().edges.length,
        'jede Kante des Weges wird kräftig und durchgezogen gezeichnet');
});

test('Klick auf eine Sitzung öffnet nicht die Detailkarte eines Endpunkts', () => {
    const { env, api } = mountWith();
    const payload = branchyPayload();
    payload.nodes['client:1'].facts = [{ label: 'Tabs', value: '2' }];
    api.apply(payload);
    env.pump();

    api.select('client:1');
    env.ctx.calls.length = 0;
    env.pump();

    const texts = paintedTexts(env);
    assert.ok(texts.indexOf('Status: bereit') < 0,
        'die Detailkarte des Endpunkts darf sich nicht öffnen');
    assert.ok(texts.indexOf('Tabs: 2') >= 0, 'die angeklickte Sitzung behält ihre eigene Karte');
    assert.equal(env.hook('[data-topo-panel]').hidden, true, 'das Panel bleibt zu');
});

// ── Platzierung der Detailkarte ──────────────────────────────────────────────

/** Distance from a point to a rectangle - 0 when the point lies inside. */
function distanceToRect(x, y, rect) {
    const dx = Math.max(rect.x - x, 0, x - (rect.x + rect.w));
    const dy = Math.max(rect.y - y, 0, y - (rect.y + rect.h));
    return Math.hypot(dx, dy);
}

/** Straight line between two nodes, sampled - the way the eye follows it. */
function crossesRect(from, to, rect, steps) {
    const count = steps || 40;
    for (let i = 0; i <= count; i++) {
        const t = i / count;
        const x = from.x + (to.x - from.x) * t;
        const y = from.y + (to.y - from.y) * t;
        if (x >= rect.x && x <= rect.x + rect.w && y >= rect.y && y <= rect.y + rect.h) return true;
    }
    return false;
}

test('die Detailkarte liegt neben dem Knoten, nicht darüber', () => {
    const { env, api } = mountWith();
    api.apply(branchyPayload());
    env.pump(40);

    api.select('ep:1');
    env.pump();

    const card = api.card();
    assert.ok(card, 'die Karte ist offen');

    const nodes = api.state().nodes;
    const selected = nodes.find((entry) => entry.key === 'ep:1');
    assert.ok(selected.screen.r > 0, 'der Knoten hat einen Radius');

    // The bubble the click landed on must stay visible - otherwise the click
    // would hide the very node it was meant to explain.
    const distance = distanceToRect(selected.screen.x, selected.screen.y, card);
    assert.ok(distance >= selected.screen.r,
        'der Knoten liegt neben der Karte, Abstand ' + distance.toFixed(1)
        + ' >= Radius ' + selected.screen.r.toFixed(1));

    assert.ok(card.x >= 0 && card.y >= 0
        && card.x + card.w <= 800 && card.y + card.h <= 480,
        'die Karte bleibt im sichtbaren Bereich');
});

test('die Detailkarte überdeckt keine Kante des Weges', () => {
    const { env, api } = mountWith();
    api.apply(branchyPayload());
    env.pump(40);

    api.select('ep:1');
    env.pump();

    const card = api.card();
    const nodes = api.state().nodes;
    const byKey = new Map(nodes.map((entry) => [entry.key, entry]));
    const route = api.route();
    assert.ok(route.edges.length > 0, 'der Weg ist aktiv');

    for (const id of route.edges) {
        const parts = id.split('>');
        const from = byKey.get(parts[0]);
        const to = byKey.get(parts[1]);
        assert.ok(from && to, 'beide Enden von ' + id + ' existieren');
        assert.equal(crossesRect(from.screen, to.screen, card), false,
            'die Karte verdeckt ' + id + ' nicht');
    }
});

test('die Detailkarte bleibt mit dem Knoten verbunden', () => {
    const { env, api } = mountWith();
    api.apply(branchyPayload());
    env.pump(40);

    api.select('ep:1');
    env.ctx.calls.length = 0;
    env.pump();

    // The card sits beside the bubble now, so a dashed line has to tie the two
    // together - otherwise the card would float next to an unknown node.
    const tie = env.ctx.calls.filter((call) => call.name === 'stroke'
        && call.lineWidth <= 2 && Array.isArray(call.dash) && call.dash.length > 0);
    assert.equal(tie.length, 1, 'genau eine Ankerlinie verbindet Karte und Knoten');
});

test('ohne geöffnete Karte gibt es keine Ankerlinie', () => {
    const { env, api } = mountWith();
    api.apply(branchyPayload());
    env.pump(40);

    const ties = () => env.ctx.calls.filter((call) => call.name === 'stroke'
        && call.lineWidth <= 2 && Array.isArray(call.dash) && call.dash.length > 0).length;

    env.ctx.calls.length = 0;
    env.pump();
    assert.equal(ties(), 0, 'ohne Auswahl gibt es keine Karte');

    // A group opens the HTML panel, not the detail card.
    api.select('llm');
    env.ctx.calls.length = 0;
    env.pump();
    assert.equal(api.card(), null, 'eine Gruppe öffnet das HTML-Panel');
    assert.equal(ties(), 0, 'ohne Karte gibt es keine Ankerlinie');
});

// ── Rendering ────────────────────────────────────────────────────────────────

test('ein vollständiger Frame läuft ohne Zeichenfehler durch', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    api.select('hub');
    env.pump(3);

    assert.equal(env.ctx.calls.length > 0, true, 'es wurde nichts gezeichnet');
    for (const call of env.ctx.calls) {
        assert.equal(typeof call.name, 'string');
    }
    // Canvas paths must be balanced and the state must be restored.
    const saves = env.ctx.calls.filter((call) => call.name === 'save').length;
    const restores = env.ctx.calls.filter((call) => call.name === 'restore').length;
    assert.equal(saves, restores, 'save() und restore() sind unausgeglichen');
    assert.deepEqual(env.consoleErrors, [], 'der Renderer hat Zeichenfehler gemeldet');
});

test('Klick auf einen Knoten wählt ihn aus', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    env.pump();

    const hub = api.state().nodes.find((entry) => entry.key === 'hub');
    assert.equal(hub.screen.visible, true);
    env.click({ x: hub.screen.x, y: hub.screen.y });

    // The hub carries facts, so the click opens the card and closes the panel.
    assert.equal(env.hook('[data-topo-panel]').hidden, true);
    env.ctx.calls.length = 0;
    env.pump();
    assert.ok(paintedTexts(env).indexOf('Modelle: 3') >= 0, 'Detailkarte des Hubs fehlt');

    // A click into the empty canvas clears the selection again.
    env.click({ x: 1, y: 1 });
    env.ctx.calls.length = 0;
    env.pump();
    assert.ok(paintedTexts(env).indexOf('Modelle: 3') < 0, 'Detailkarte blieb offen');
});

test('resize(), fit() und resetView() arbeiten ohne Fehler', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());
    env.pump();

    api.resize();
    api.fit();
    api.resetView();
    env.pump();
    assert.equal(env.canvas.width, 800);
    assert.equal(env.canvas.height, 480);

    env.emit('window', 'resize');
    env.emit('document', 'visibilitychange');
    env.pump();
    assert.ok(env.ctx.calls.length > 0);
});

test('reduzierte Bewegung deaktiviert Drehung und Partikel', () => {
    const { env, api } = mountWith({ reducedMotion: true });
    api.apply(basePayload());

    const logList = env.hook('[data-topo-log-list]');
    assert.equal(logList.children.length, 1);
    assert.match(logList.children[0].children[1].textContent, /Reduzierte Bewegung aktiv/);

    env.pump(2);
    assert.ok(env.ctx.calls.length > 0, 'auch ohne Bewegung muss gezeichnet werden');
});

test('ResizeObserver wird verwendet, wenn der Browser ihn anbietet', () => {
    const { env, api } = mountWith({ resizeObserver: true });
    api.apply(basePayload());
    env.pump();
    assert.ok(api.state().nodes.length > 0);
});

// ── Data flow ────────────────────────────────────────────────────────────────

test('die eingebettete JSON-Insel ersetzt den ersten Abruf', () => {
    const { env, api } = mountWith({ initialPayload: JSON.stringify(basePayload()) });

    assert.equal(env.fetches.length, 0, 'mit Insel darf nichts abgerufen werden');
    assert.equal(api.state().nodes.length, 6);
    assert.equal(env.field('overall-label').textContent, 'Eingeschränkt');
    assert.match(env.field('generated').textContent, /^\d{2}:\d{2}:\d{2}$/);
});

test('eine kaputte JSON-Insel wird ignoriert', () => {
    const { env, api } = mountWith({ initialPayload: '{ kaputt' });
    same(api.state().nodes, []);
});

test('load() holt die Topologie und reicht sie an die Rückrufe weiter', async () => {
    const seen = [];
    const env = createEnvironment({
        refreshUrl: '/admin/load_stats.php',
        refreshInterval: '3',
        fetchImpl: (url) => {
            env.fetches.push(String(url));
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ ok: true, topology: basePayload() }),
            });
        },
    });
    const api = env.api.mount(env.root, {
        onData: (payload) => seen.push(payload.topology.overall.state),
    });

    assert.equal(await api.load(true), true);
    assert.deepEqual(env.fetches, ['/admin/load_stats.php']);
    assert.deepEqual(seen, ['warn']);
    assert.equal(api.state().nodes.length, 6);

    // data-refresh-interval="3" is clamped to the module minimum of 5 s.
    const intervals = Array.from(env.timers.values()).filter((timer) => timer.repeat);
    assert.equal(intervals.length, 1);
    assert.equal(intervals[0].delay, 5000);
});

test('load() meldet Fehler über onError und liefert false', async () => {
    const errors = [];
    const env = createEnvironment({
        refreshUrl: '/admin/load_stats.php',
        fetchImpl: () => Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ ok: false }),
        }),
    });
    const api = env.api.mount(env.root, { onError: (message) => errors.push(message) });

    assert.equal(await api.load(true), false);
    assert.deepEqual(errors, ['Netzwerkfehler']);
    same(api.state().nodes, []);
});

test('load() ohne Abrufadresse tut nichts', async () => {
    const { api, env } = mountWith();
    assert.equal(await api.load(true), false);
    assert.equal(env.fetches.length, 0);
});

test('apply() schreibt die Kennzahlen in die Kopfzeile', () => {
    const { env, api } = mountWith();
    api.apply(basePayload());

    assert.equal(env.field('overall-label').textContent, 'Eingeschränkt');
    assert.equal(env.field('overall-message').textContent, '1 Einschränkung erkannt.');
    assert.equal(env.field('overall-errors').textContent, '0');
    assert.equal(env.field('overall-warnings').textContent, '1');
    assert.equal(env.field('node-count').textContent, '6');
    assert.equal(env.field('overall-badge').textContent, 'Eingeschränkt');
    assert.equal(env.root.getAttribute('data-overall-state'), 'warn');
});
