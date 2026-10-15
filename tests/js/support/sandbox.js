/**
 * tests/js/support/sandbox.js
 *
 * Minimal DOM/canvas double so that assets/js/flow-topology.js can be loaded and
 * driven inside node:test without a browser, a bundler or any dependency.
 *
 * The renderer only needs a handful of browser APIs (see the top of
 * flow-topology.js): a canvas 2D context, `document.createElement`, class
 * lists, attributes, event registration, `matchMedia`, the rAF/timer family and
 * `fetch`. Everything else is optional and is simply left out so the module's
 * own feature detection takes its fallback path.
 *
 * Frames are never scheduled implicitly: `requestAnimationFrame` only records
 * the callback, and the test decides when a frame runs via `pump()`. That keeps
 * every assertion deterministic and free of timing flakiness.
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = path.join(__dirname, '..', '..', '..', 'assets', 'js', 'flow-topology.js');

// ── DOM doubles ──────────────────────────────────────────────────────────────

function createClassList() {
    const names = new Set();
    return {
        add(...values) { for (const value of values) if (value) names.add(value); },
        remove(...values) { for (const value of values) names.delete(value); },
        clear() { names.clear(); },
        contains(value) { return names.has(value); },
        toggle(value, on) { if (on) names.add(value); else names.delete(value); },
        get length() { return names.size; },
        toString() { return Array.from(names).join(' '); },
        [Symbol.iterator]() { return names.values(); },
    };
}

class FakeElement {
    constructor(tagName) {
        this.tagName = String(tagName || 'div').toUpperCase();
        this.nodeType = 1;
        this.attributes = new Map();
        this.children = [];
        this.parentNode = null;
        this.style = {};
        this.hidden = false;
        this.textContent = '';
        this.checked = false;
        this.value = '';
        this.offsetWidth = 0;
        this.offsetHeight = 0;
        this.clientWidth = 0;
        this.clientHeight = 0;
        this.listeners = new Map();
        this.classList = createClassList();
        // Selector registry: the renderer only ever looks up a fixed set of
        // hooks, so a plain lookup table replaces a CSS engine.
        this.selectors = new Map();
    }

    get className() { return this.classList.toString(); }
    set className(value) {
        this.classList.clear();
        for (const name of String(value || '').split(/\s+/)) if (name) this.classList.add(name);
    }

    get firstChild() { return this.children.length ? this.children[0] : null; }

    appendChild(child) {
        if (!child) return child;
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    removeChild(child) {
        const index = this.children.indexOf(child);
        if (index >= 0) this.children.splice(index, 1);
        child.parentNode = null;
        return child;
    }

    setAttribute(name, value) { this.attributes.set(String(name), String(value)); }
    getAttribute(name) { return this.attributes.has(String(name)) ? this.attributes.get(String(name)) : null; }
    removeAttribute(name) { this.attributes.delete(String(name)); }
    hasAttribute(name) { return this.attributes.has(String(name)); }

    addEventListener(type, handler) {
        if (!this.listeners.has(type)) this.listeners.set(type, []);
        this.listeners.get(type).push(handler);
    }

    removeEventListener(type, handler) {
        const list = this.listeners.get(type);
        if (!list) return;
        const index = list.indexOf(handler);
        if (index >= 0) list.splice(index, 1);
    }

    dispatch(type, event) {
        for (const handler of (this.listeners.get(type) || []).slice()) {
            handler(event || { type, preventDefault() {}, stopPropagation() {} });
        }
    }

    querySelector(selector) { return this.selectors.get(selector) || null; }
    querySelectorAll(selector) { const one = this.querySelector(selector); return one ? [one] : []; }

    getBoundingClientRect() {
        return {
            left: 0,
            top: 0,
            right: this.clientWidth,
            bottom: this.clientHeight,
            width: this.clientWidth,
            height: this.clientHeight,
        };
    }
}

class FakeCanvas extends FakeElement {
    constructor(context) {
        super('canvas');
        this.context = context;
        this.width = 0;
        this.height = 0;
        this.capturedPointers = [];
        this.classList.add('topo-canvas');
    }

    getContext(kind) { return kind === '2d' ? this.context : null; }
    setPointerCapture(id) { this.capturedPointers.push(id); }
    releasePointerCapture() {}
    focus() {}
}

/** Records every drawing call so a frame can run without a real canvas. */
function createContext(canvas) {
    const gradient = { addColorStop() {} };
    const calls = [];
    const stack = [];
    // Every call also snapshots the paint state it ran with: assertions about
    // how strongly something was drawn (highlighted way vs. dimmed rest) would
    // otherwise only see the call and not the style it used.
    const record = (name) => function (...args) {
        calls.push({
            name,
            args,
            alpha: ctx.globalAlpha,
            lineWidth: ctx.lineWidth,
            strokeStyle: ctx.strokeStyle,
            dash: ctx.dash,
        });
    };

    const ctx = {
        canvas,
        calls,
        fillStyle: '#000',
        strokeStyle: '#000',
        font: '10px sans-serif',
        globalAlpha: 1,
        lineCap: 'butt',
        lineJoin: 'miter',
        lineWidth: 1,
        lineDashOffset: 0,
        dash: [],
        shadowBlur: 0,
        shadowColor: 'transparent',
        shadowOffsetY: 0,
        textAlign: 'start',
        textBaseline: 'alphabetic',
        arc: record('arc'),
        beginPath: record('beginPath'),
        bezierCurveTo: record('bezierCurveTo'),
        closePath: record('closePath'),
        ellipse: record('ellipse'),
        fill: record('fill'),
        fillRect: record('fillRect'),
        fillText: record('fillText'),
        lineTo: record('lineTo'),
        moveTo: record('moveTo'),
        quadraticCurveTo: record('quadraticCurveTo'),
        restore() {
            record('restore')();
            const state = stack.pop();
            if (state) Object.assign(ctx, state);
        },
        save() {
            record('save')();
            stack.push({
                globalAlpha: ctx.globalAlpha,
                lineWidth: ctx.lineWidth,
                strokeStyle: ctx.strokeStyle,
                fillStyle: ctx.fillStyle,
                font: ctx.font,
                lineCap: ctx.lineCap,
                lineJoin: ctx.lineJoin,
                lineDashOffset: ctx.lineDashOffset,
                textAlign: ctx.textAlign,
                textBaseline: ctx.textBaseline,
                dash: ctx.dash,
            });
        },
        setLineDash(segments) {
            record('setLineDash')(segments);
            ctx.dash = Array.from(segments || []);
        },
        setTransform: record('setTransform'),
        stroke: record('stroke'),
        strokeRect: record('strokeRect'),
        createLinearGradient: () => gradient,
        createRadialGradient: () => gradient,
        measureText: (text) => ({ width: String(text == null ? '' : text).length * 6 }),
    };

    return ctx;
}

// ── Environment ──────────────────────────────────────────────────────────────

/**
 * Loads the renderer in a fresh VM context and returns the harness.
 *
 * @param {object} [options]
 * @param {boolean} [options.reducedMotion]  emulate `prefers-reduced-motion`
 * @param {string}  [options.refreshUrl]     value of `data-refresh-url`
 * @param {string}  [options.refreshInterval] value of `data-refresh-interval`
 * @param {string}  [options.initialPayload] JSON for the `data-flow-initial` island
 * @param {string[]} [options.hooks]         extra `[data-topo-*]` elements to create
 * @param {boolean} [options.resizeObserver] expose a `ResizeObserver`
 * @param {Function} [options.fetchImpl]     replace the default `fetch` stub
 */
function createEnvironment(options) {
    const opts = options || {};
    const sandbox = {};

    let now = 1000;
    const frames = new Map();
    const timers = new Map();
    let timerId = 0;
    const fetches = [];
    const windowListeners = new Map();
    const documentListeners = new Map();
    const consoleErrors = [];

    const ctx = createContext(null);
    const canvas = new FakeCanvas(ctx);
    ctx.canvas = canvas;
    canvas.clientWidth = 800;
    canvas.clientHeight = 480;

    const root = new FakeElement('div');
    root.clientWidth = 800;
    root.clientHeight = 480;
    root.selectors.set('[data-topo-canvas]', canvas);
    if (opts.refreshUrl !== undefined) root.setAttribute('data-refresh-url', opts.refreshUrl);
    if (opts.refreshInterval !== undefined) root.setAttribute('data-refresh-interval', opts.refreshInterval);

    // Hooks the renderer binds optionally. Only the ones a test asks for are
    // created, so "missing element" paths stay reachable as well.
    const hooks = ['[data-topo-tooltip]', '[data-topo-panel]', '[data-topo-hint]',
        '[data-topo-kinds]', '[data-topo-log-list]', '[data-topo-incidents-list]',
        '[data-topo-incidents-empty]'].concat(opts.hooks || []);
    for (const selector of hooks) {
        const element = new FakeElement(selector === '[data-topo-canvas]' ? 'canvas' : 'div');
        root.selectors.set(selector, element);
        root.appendChild(element);
    }
    root.selectors.set('[data-topo-field="overall-badge"]', new FakeElement('span'));
    root.selectors.set('[data-topo-tooltip-state]', new FakeElement('span'));

    // `[data-topo-field="…"]` / `[data-topo-action="…"]` hooks are created on
    // first use so a test can inspect every field the renderer writes.
    const autoFields = new Map();
    root.querySelector = (selector) => {
        if (root.selectors.has(selector)) return root.selectors.get(selector);
        if (/^\[data-topo-(field|action)="[^"]+"\]$/.test(selector)) {
            if (!autoFields.has(selector)) autoFields.set(selector, new FakeElement('div'));
            return autoFields.get(selector);
        }
        return null;
    };

    if (opts.initialPayload !== undefined) {
        const island = new FakeElement('script');
        island.textContent = opts.initialPayload;
        root.selectors.set('[data-flow-initial]', island);
    }

    const document = {
        hidden: false,
        fullscreenElement: null,
        createElement: (tag) => new FakeElement(tag),
        createTextNode: (text) => ({ nodeType: 3, textContent: String(text) }),
        addEventListener(type, handler) {
            if (!documentListeners.has(type)) documentListeners.set(type, []);
            documentListeners.get(type).push(handler);
        },
        exitFullscreen: () => Promise.resolve(),
        querySelector: () => null,
    };

    Object.assign(sandbox, {
        // The renderer logs drawing failures through `window.console.error`;
        // the harness records them so a test can assert that none happened.
        console: {
            error: (...args) => consoleErrors.push(args),
            warn: (...args) => consoleErrors.push(args),
            log: () => {},
            info: () => {},
            debug: () => {},
        },
        document,
        performance: { now: () => now },
        devicePixelRatio: 1,
        location: { href: '' },
        matchMedia: (query) => ({
            media: String(query),
            matches: query.indexOf('prefers-reduced-motion') >= 0 ? !!opts.reducedMotion : false,
            addEventListener() {},
            removeEventListener() {},
            addListener() {},
            removeListener() {},
        }),
        requestAnimationFrame(callback) { const id = ++timerId; frames.set(id, callback); return id; },
        cancelAnimationFrame(id) { frames.delete(id); },
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        setInterval(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay, repeat: true }); return id; },
        clearInterval(id) { timers.delete(id); },
        addEventListener(type, handler) {
            if (!windowListeners.has(type)) windowListeners.set(type, []);
            windowListeners.get(type).push(handler);
        },
        fetch: opts.fetchImpl || ((url) => {
            fetches.push(String(url));
            return new Promise(() => {});
        }),
        AbortController,
    });
    if (opts.resizeObserver) {
        sandbox.ResizeObserver = class {
            constructor(callback) { this.callback = callback; }
            observe() {}
            unobserve() {}
            disconnect() {}
        };
    }

    // The renderer reaches everything through `window`, so the sandbox is its
    // own window - the usual trick for browser modules under vm.
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;

    vm.runInNewContext(fs.readFileSync(SOURCE, 'utf8'), sandbox, { filename: SOURCE });

    return {
        sandbox,
        api: sandbox.FlowTopology,
        root,
        canvas,
        ctx,
        fetches,
        timers,
        frames,
        consoleErrors,
        windowListeners,
        documentListeners,
        hook: (selector) => root.querySelector(selector),
        /** Reads a `[data-topo-field="…"]` element the renderer writes into. */
        field: (name) => root.querySelector('[data-topo-field="' + name + '"]'),
        /** Runs the pending animation frame callbacks once. */
        pump(count) {
            const rounds = count === undefined ? 1 : count;
            for (let i = 0; i < rounds; i++) {
                const pending = Array.from(frames.values());
                frames.clear();
                now += 16;
                for (const callback of pending) callback(now);
            }
            return rounds;
        },
        /** Runs the registered window/document listeners of one type. */
        emit(target, type, event) {
            const map = target === 'document' ? documentListeners : windowListeners;
            for (const handler of (map.get(type) || []).slice()) {
                handler(event || { type, preventDefault() {}, stopPropagation() {} });
            }
        },
        advance(ms) { now += ms; },
        click(point) {
            const event = {
                type: 'pointerup',
                pointerId: 1,
                clientX: point.x,
                clientY: point.y,
                preventDefault() {},
                stopPropagation() {},
            };
            canvas.dispatch('pointerdown', event);
            canvas.dispatch('pointerup', event);
        },
    };
}

module.exports = { createEnvironment, FakeElement, FakeCanvas };
