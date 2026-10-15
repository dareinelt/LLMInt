<?php

/**
 * admin/topology.php
 *
 * Full-screen message-flow topology of the whole LLMInt stack.
 *
 * Renders the same graph as the dashboard card in admin/index.php (clients →
 * LLMInt core → model groups → LLM endpoints, plus the auxiliary services) but
 * with room for the incident band and the event log. The page is deliberately
 * a thin shell: it only provides the markup contract, the data comes from
 * admin/load_stats.php?topology=1 and is translated by lib/topology.php.
 *
 * Layout: own minimal shell without the admin sidebar so the graph gets the
 * full viewport. The graph itself is drawn by assets/js/flow-topology.js.
 */

session_start();

require_once __DIR__ . '/../db.php';

requireAdminOrRedirect('login.php');

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin')), '/');
$appRoot   = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
$assetBase = $appRoot === '/' ? '' : $appRoot;

$version = static function (string $file): string {
    $path = __DIR__ . '/../' . $file;
    $mtime = @filemtime($path);
    return $mtime ? (string) $mtime : '1';
};
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Nachrichtenfluss-Topologie</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/flow-topology.css?v=<?= $version('assets/css/flow-topology.css') ?>">
    <style>
        .topo-page-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            background: rgba(22,25,38,.92);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 8px;
            color: #9aa8c4;
            font-size: .76rem;
            text-decoration: none;
        }
        .topo-page-back:hover { color: #e6ecff; background: rgba(255,255,255,.09); }
    </style>
</head>
<body class="topo-page">

<header class="topo-page-head">
    <h1>🛰 Nachrichtenfluss-Topologie</h1>
    <a class="topo-page-back" href="index.php#dashboard-card">← Zurück zum Dashboard</a>
</header>

<main class="topo-page-main">

    <div class="topo" data-flow-topology
         data-refresh-url="load_stats.php?topology=1"
         data-refresh-interval="15"
         data-overall-state="ok">

        <!-- Status line: overall state, counters, live indicator -->
        <div class="topo-head">
            <div class="topo-head-left">
                <div class="topo-metrics">
                    <div class="topo-metric topo-metric--state">
                        <span class="topo-metric-value" data-topo-field="overall-label">Lade …</span>
                        <span class="topo-metric-label">Gesamtzustand</span>
                    </div>
                    <div class="topo-metric topo-metric--errors">
                        <span class="topo-metric-value" data-topo-field="overall-errors">0</span>
                        <span class="topo-metric-label">Störungen</span>
                    </div>
                    <div class="topo-metric topo-metric--warnings">
                        <span class="topo-metric-value" data-topo-field="overall-warnings">0</span>
                        <span class="topo-metric-label">Einschränkungen</span>
                    </div>
                    <div class="topo-metric">
                        <span class="topo-metric-value" data-topo-field="node-count">0</span>
                        <span class="topo-metric-label">Bausteine</span>
                    </div>
                    <div class="topo-metric">
                        <span class="topo-metric-value" data-topo-field="generated">–</span>
                        <span class="topo-metric-label">Stand</span>
                    </div>
                </div>
                <div class="topo-subtitle" data-topo-field="overall-message"></div>
            </div>

            <div class="topo-head-right">
                <div class="topo-toolbar" role="toolbar" aria-label="Topologie-Werkzeuge">
                    <button type="button" class="topo-btn" data-topo-action="refresh">⟳ Aktualisieren</button>
                    <span class="topo-toolbar-sep" aria-hidden="true"></span>
                    <button type="button" class="topo-btn" data-topo-action="mode-3d" aria-pressed="true">3D</button>
                    <button type="button" class="topo-btn" data-topo-action="mode-2d" aria-pressed="false">2D</button>
                    <button type="button" class="topo-btn" data-topo-action="rotate" aria-pressed="true">⟳ Drehen</button>
                    <button type="button" class="topo-btn" data-topo-action="particles" aria-pressed="true">✦ Partikel</button>
                    <button type="button" class="topo-btn" data-topo-action="labels" aria-pressed="true">🏷 Labels</button>
                    <span class="topo-toolbar-sep" aria-hidden="true"></span>
                    <button type="button" class="topo-btn" data-topo-action="zoom-in" title="Vergrößern">＋</button>
                    <button type="button" class="topo-btn" data-topo-action="zoom-out" title="Verkleinern">－</button>
                    <button type="button" class="topo-btn" data-topo-action="fit">⊡ Einpassen</button>
                    <button type="button" class="topo-btn" data-topo-action="reset">↺ Ansicht</button>
                    <span class="topo-toolbar-sep" aria-hidden="true"></span>
                    <button type="button" class="topo-btn" data-topo-action="focus-problems" aria-pressed="false">⚠ Nur Auffälligkeiten</button>
                    <button type="button" class="topo-btn" data-topo-action="fullscreen">⛶ Browser-Vollbild</button>
                </div>
            </div>
        </div>

        <!-- Graph -->
        <div class="topo-stage topo-stage--page">
            <canvas data-topo-canvas tabindex="0" role="application"
                    aria-label="Nachrichtenfluss der LLMInt-Bausteine: Clients, API-Aufrufe, Kern, Modellgruppen, LLM-Endpunkte und Zusatzdienste"></canvas>

            <div class="topo-hint" data-topo-hint>
                Ziehen dreht die Ansicht · Mausrad zoomt · Klick öffnet die Detailkarte und hebt den Weg zwischen Endpunkt und Client bzw. API-Aufruf hervor (läuft eine Anfrage, nur deren Zweig) ·
                <kbd>Esc</kbd> schließt beides · <kbd>F</kbd> einpassen · <kbd>R</kbd> zurücksetzen · <kbd>Tab</kbd> nächster Baustein
            </div>

            <div class="topo-tooltip" data-topo-tooltip hidden>
                <strong class="topo-tooltip-title" data-topo-field="tooltip-title"></strong>
                <span class="badge topo-tooltip-state" data-topo-tooltip-state></span>
                <div class="topo-tooltip-message" data-topo-field="tooltip-message"></div>
            </div>

            <!-- Detail panel (filled by the renderer on selection) -->
            <aside class="topo-panel" data-topo-panel hidden aria-live="polite">
                <div class="topo-panel-head">
                    <div>
                        <div class="topo-panel-kind" data-topo-field="panel-kind"></div>
                        <h2 class="topo-panel-title" data-topo-field="panel-title"></h2>
                    </div>
                    <button type="button" class="topo-panel-close" data-topo-action="close-panel" title="Details schließen">✕</button>
                </div>
                <span class="badge" data-topo-field="panel-badge"></span>
                <div class="topo-panel-subtitle" data-topo-field="panel-subtitle"></div>
                <div class="topo-panel-message" data-topo-field="panel-message"></div>

                <div class="topo-facts" data-topo-field="panel-facts"></div>

                <div class="topo-panel-block" data-topo-field="panel-cloud" hidden>
                    <div class="topo-panel-block-title" data-topo-field="panel-cloud-title"></div>
                    <ul class="topo-members" data-topo-field="panel-cloud-list"></ul>
                    <div class="topo-panel-empty" data-topo-field="panel-cloud-empty"></div>
                </div>

                <div class="topo-panel-block" data-topo-field="panel-members" hidden>
                    <div class="topo-panel-block-title" data-topo-field="panel-members-title"></div>
                    <ul class="topo-members" data-topo-field="panel-members-list"></ul>
                    <div class="topo-panel-empty" data-topo-field="panel-members-empty"></div>
                </div>

                <div class="topo-panel-block" data-topo-field="panel-neighbours">
                    <div class="topo-panel-block-title">Verbundene Bausteine</div>
                    <div class="topo-chips" data-topo-field="panel-neighbours-list"></div>
                </div>

                <a class="topo-panel-link" data-topo-field="panel-link" hidden>In der Konfiguration öffnen →</a>
            </aside>
        </div>

        <!-- Legend + kind filters -->
        <div class="topo-legend" data-topo-kinds></div>

        <!-- Incident band -->
        <section class="topo-incidents" aria-label="Störungen und Einschränkungen">
            <div class="topo-incidents-head">
                <span>Störungsband</span>
                <span class="badge badge--muted" data-topo-field="incident-count">0</span>
            </div>
            <ul class="topo-incidents-list" data-topo-incidents-list></ul>
            <div class="topo-incidents-empty" data-topo-incidents-empty>Keine Auffälligkeiten – alle Bausteine melden einen unauffälligen Zustand.</div>
        </section>

        <!-- Event log -->
        <section class="topo-log" data-topo-log aria-label="Ereignisprotokoll">
            <div class="topo-log-head">
                <span>Ereignisprotokoll</span>
                <button type="button" class="topo-btn topo-btn--small" data-topo-action="toggle-log" aria-pressed="true">ein-/ausklappen</button>
            </div>
            <ul class="topo-log-list" data-topo-log-list></ul>
        </section>

    </div>
</main>

<noscript>
    <div class="topo-noscript">
        Die Nachrichtenfluss-Topologie benötigt JavaScript. Die Zahlenwerte des
        Dashboards stehen auch ohne Skript in <a href="index.php#dashboard-card">admin/index.php</a> bereit.
    </div>
</noscript>

<script src="<?= htmlspecialchars($assetBase) ?>/assets/js/flow-topology.js?v=<?= $version('assets/js/flow-topology.js') ?>"></script>
<script>
(function () {
    'use strict';
    const root = document.querySelector('[data-flow-topology]');
    if (!root || !window.FlowTopology) return;
    window.FlowTopology.mount(root, {
        onError: function (message) {
            const field = root.querySelector('[data-topo-field="overall-message"]');
            if (field) field.textContent = message;
        },
    });
})();
</script>

</body>
</html>
