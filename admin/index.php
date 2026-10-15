<?php

/**
 * admin/index.php
 *
 * Dashboard - Live-Statistik, Lastverteilung und Nachrichtenfluss-Topologie.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'dashboard';
$adminPageTitle = 'Dashboard';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Dashboard – Statistik & Lastverteilung (live)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="dashboard-card">

        <!-- Header row -->
        <div class="tree-header-row">
            <h2 style="margin:0;padding:0;border:none">🚀 Dashboard</h2>
            <div class="tree-header-controls">
                <div class="tree-refresh-info">
                    <span class="tree-refresh-dot" id="tree-live-dot"></span>
                    <span id="tree-status">Initialisierung …</span>
                </div>
            </div>
        </div>

        <!-- Summary stat boxes -->
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-val stat-running" id="db-llm-running"><?= number_format((int) $totals['total_running']) ?></div>
                <div class="stat-lbl">LLM laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-llm-done24"><?= number_format((int) $totals['total_done_24h']) ?></div>
                <div class="stat-lbl">LLM erledigt (24 h)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-llm-done"><?= number_format((int) $totals['total_done']) ?></div>
                <div class="stat-lbl">LLM erledigt (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-error" id="db-llm-error"><?= number_format((int) $totals['total_error']) ?></div>
                <div class="stat-lbl">LLM Fehler (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-prompt-tok"><?= number_format((int) $totals['grand_prompt_tokens']) ?></div>
                <div class="stat-lbl">Prompt Token (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-comp-tok"><?= number_format((int) $totals['grand_completion_tokens']) ?></div>
                <div class="stat-lbl">Completion Token (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-total-tok"><?= number_format((int) $totals['grand_tokens']) ?></div>
                <div class="stat-lbl">Total Token (gesamt)</div>
            </div>
            <?php if ($searxngBaseUrl !== ''): ?>
            <div class="stat-box">
                <div class="stat-val stat-running" id="db-srxng-running"><?= number_format($searxngStats['running']) ?></div>
                <div class="stat-lbl">Suchen laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-srxng-today"><?= number_format($searxngStats['today_jobs']) ?></div>
                <div class="stat-lbl">Suchen heute</div>
            </div>
            <?php endif; ?>
            <?php if ($vectorStoreMode !== 'off'): ?>
            <div class="stat-box">
                <div class="stat-val <?= !empty($vectorStatus['online']) ? 'stat-done' : 'stat-error' ?>" id="db-vec-status"><?= !empty($vectorStatus['online']) ? 'online' : 'offline' ?></div>
                <div class="stat-lbl"><?= htmlspecialchars($vectorStatus['label']) ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-vec-today"><?= number_format((int) $vectorStatus['today_queries']) ?></div>
                <div class="stat-lbl">RAG-Abfragen heute</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-vec-vectors"><?= $vectorStatus['vectors'] !== null ? number_format((int) $vectorStatus['vectors']) : '–' ?></div>
                <div class="stat-lbl">Vektoren</div>
            </div>
            <?php endif; ?>
            <?php if (!empty($imageEndpoints)): ?>
            <div class="stat-box">
                <div class="stat-val <?= !empty($imageStatus['reachable']) ? 'stat-done' : 'stat-error' ?>" id="db-image-status"><?= !empty($imageStatus['reachable']) ? 'online' : 'offline' ?></div>
                <div class="stat-lbl">ImageInt</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-image-endpoints"><?= number_format(count($imageEndpoints)) ?></div>
                <div class="stat-lbl">Bild-Endpunkte</div>
            </div>
            <?php endif; ?>
            <?php if (!empty($speechEndpoints)): ?>
            <div class="stat-box">
                <div class="stat-val <?= !empty($speechStatus['reachable']) ? 'stat-done' : 'stat-error' ?>" id="db-speech-status"><?= !empty($speechStatus['reachable']) ? 'online' : 'offline' ?></div>
                <div class="stat-lbl">SpeechInt</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-speech-endpoints"><?= number_format(count($speechEndpoints)) ?></div>
                <div class="stat-lbl">Dictation-Endpunkte</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Live message-flow topology (canvas, drawn by assets/js/flow-topology.js) -->
        <?php
        $topologyInitial = topology_build([
            'ts'               => time(),
            'endpoints'        => array_map(static function ($s) {
                return [
                    'id'                          => (int) $s['id'],
                    'alias'                       => (string) ($s['alias'] ?? ''),
                    'base_url'                    => (string) $s['base_url'],
                    'default_model'               => (string) $s['default_model'],
                    'is_active'                   => (int) $s['is_active'],
                    'running'                     => (int) $s['cnt_running'],
                    'today_jobs'                  => (int) $s['today_jobs'],
                    'today_prompt_tokens'         => (int) $s['today_prompt_tokens'],
                    'today_completion_tokens'     => (int) $s['today_completion_tokens'],
                    'today_tokens'                => (int) $s['today_tokens'],
                    'today_avg_tokens_per_second' => (float) $s['today_avg_tokens_per_second'],
                    'ssh_configured'              => (bool) ($s['ssh_configured'] ?? false),
                    'sys_stats'                   => $s['sys_stats'] ?? null,
                ];
            }, $epStats),
            'searxng'          => [
                'enabled'              => $searxngBaseUrl !== '',
                'base_url'             => $searxngBaseUrl,
                'running'              => (int) $searxngStats['running'],
                'today_jobs'           => (int) $searxngStats['today_jobs'],
                'avg_duration_seconds' => $searxngStats['avg_duration_seconds'],
            ],
            'image_endpoints'  => array_map(static function ($s) {
                return [
                    'id'        => (int) $s['id'],
                    'alias'     => (string) ($s['alias'] ?? ''),
                    'base_url'  => (string) $s['base_url'],
                    'timeout'   => (int) $s['timeout'],
                    'is_active' => (int) $s['is_active'],
                ];
            }, $imageEndpoints),
            'image_status'     => $imageStatus,
            'clients'          => $clientStats,
            'api'              => $apiUsage,
            'totals'           => $totals,
            'vector_store'     => $vectorStatus,
            'speech_endpoints' => array_map(static function ($s) {
                return [
                    'id'        => (int) $s['id'],
                    'alias'     => (string) ($s['alias'] ?? ''),
                    'base_url'  => (string) $s['base_url'],
                    'timeout'   => (int) $s['timeout'],
                    'is_active' => (int) $s['is_active'],
                ];
            }, $speechEndpoints),
            'speech_status'    => $speechStatus,
        ], ['base_url' => '', 'slot_limit' => 4]);

        $hasSshEndpoints = false;
        foreach ($epStats as $epRow) {
            if (!empty($epRow['ssh_configured'])) {
                $hasSshEndpoints = true;
                break;
            }
        }
        ?>

        <div class="topo" data-flow-topology
             data-refresh-url="load_stats.php?topology=1"
             data-refresh-interval="15"
             data-overall-state="<?= htmlspecialchars($topologyInitial['overall']['state'] ?? 'ok') ?>">

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
                        <button type="button" class="topo-btn" data-topo-action="mode-3d" aria-pressed="true">3D</button>
                        <button type="button" class="topo-btn" data-topo-action="mode-2d" aria-pressed="false">2D</button>
                        <button type="button" class="topo-btn" data-topo-action="rotate" aria-pressed="true">⟳ Drehen</button>
                        <button type="button" class="topo-btn" data-topo-action="particles" aria-pressed="true">✦ Partikel</button>
                        <button type="button" class="topo-btn" data-topo-action="labels" aria-pressed="true">🏷 Labels</button>
                        <span class="topo-toolbar-sep" aria-hidden="true"></span>
                        <button type="button" class="topo-btn" data-topo-action="zoom-in" title="Vergrößern">＋</button>
                        <button type="button" class="topo-btn" data-topo-action="zoom-out" title="Verkleinern">－</button>
                        <button type="button" class="topo-btn" data-topo-action="fit">⊡ Einpassen</button>
                        <button type="button" class="topo-btn" data-topo-action="reset">↺ Ansicht zurücksetzen</button>
                        <span class="topo-toolbar-sep" aria-hidden="true"></span>
                        <button type="button" class="topo-btn" data-topo-action="focus-problems" aria-pressed="false">⚠ Nur Auffälligkeiten</button>
                        <a class="topo-btn topo-btn--primary" href="topology.php" target="_blank" rel="noopener"
                           title="Nachrichtenfluss-Topologie in eigenem Tab öffnen">⛶ Vollbild-Topologie</a>
                    </div>
                </div>
            </div>

            <!-- Graph -->
            <div class="topo-stage topo-stage--card">
                <canvas data-topo-canvas tabindex="0" role="application"
                        aria-label="Nachrichtenfluss der LLMInt-Bausteine: Clients, API-Aufrufe, Kern, Modellgruppen, LLM-Endpunkte und Zusatzdienste"></canvas>

                <div class="topo-hint" data-topo-hint>
                    Ziehen dreht die Ansicht · Mausrad zoomt · Klick öffnet die Detailkarte und hebt den Weg zwischen Endpunkt und Aufrufer hervor (eine Sitzung nur mit laufender Anfrage) ·
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

            <?= topology_initial_script($topologyInitial) ?>
        </div>

        <!-- Collapsible endpoint detail tables -->
        <div class="dash-detail-toggle" id="dash-detail-toggle">
            <span class="toggle-arrow">▶</span>
            <span>Endpunkt-Details</span>
        </div>
        <div class="dash-detail-body" id="dash-detail-body">

        <?php if (empty($epStats)): ?>
            <p style="color:var(--text-muted)">Noch keine LLM-Aufgaben verarbeitet.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Endpunkt</th>
                    <th>Modell-Gruppe</th>
                    <th>Status</th>
                    <th style="text-align:right">Laufend</th>
                    <th style="text-align:right">Erledigt (24 h)</th>
                    <th style="text-align:right">Erledigt (gesamt)</th>
                    <th style="text-align:right">Fehler</th>
                    <th style="text-align:right">⌀ Token</th>
                    <th style="text-align:right">Prompt Token</th>
                    <th style="text-align:right">Completion Token</th>
                    <th style="text-align:right">Total Token</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($epStats as $s): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($s['base_url']) ?>">
                        <span class="dot <?= $s['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($s['alias'] !== '' ? $s['alias'] : $s['base_url']) ?>
                    </td>
                    <td>
                        <?php if ($s['default_model'] !== ''): ?>
                            <span class="model-badge"
                                  style="background:<?= htmlspecialchars($modelColorMap[canonicalModelName((string) $s['default_model'])] ?? '#555') ?>">
                                <?= htmlspecialchars($s['default_model']) ?>
                            </span>
                        <?php else: ?>
                            <span class="model-badge badge-empty">–</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem">
                        <?php $circuitState = (string) ($s['circuit_state'] ?? 'closed'); ?>
                        <?php if ($circuitState === 'open'): ?>
                            <span style="color:var(--error)" title="Cooldown bis: <?= htmlspecialchars((string) ($s['cooldown_until'] ?? '')) ?>">🔴 Gesperrt (Circuit offen)</span>
                        <?php elseif ($circuitState === 'half_open'): ?>
                            <span style="color:var(--warning)">🟡 Testphase (Half-Open)</span>
                        <?php else: ?>
                            <span style="color:var(--success)">🟢 Gesund</span>
                        <?php endif; ?>
                        <?php if ((int) ($s['consecutive_failures'] ?? 0) > 0): ?>
                            <br><span style="color:var(--text-muted)"><?= (int) $s['consecutive_failures'] ?>&thinsp;Fehler in Folge</span>
                        <?php endif; ?>
                        <?php if ($s['avg_latency_ms'] !== null): ?>
                            <br><span style="color:var(--text-muted)">⌀&thinsp;<?= number_format((int) $s['avg_latency_ms']) ?>&thinsp;ms</span>
                        <?php endif; ?>
                        <?php if ($circuitState !== 'closed' || (int) ($s['consecutive_failures'] ?? 0) > 0): ?>
                            <br>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action"     value="reset_circuit">
                                <input type="hidden" name="ep_id"      value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="margin-top:4px"
                                        title="Circuit-Breaker schließen und Fehlerzähler zurücksetzen">♻ Circuit zurücksetzen</button>
                            </form>
                        <?php endif; ?>
                        <?php $epPaused = (int) ($s['is_active'] ?? 0) !== 1; ?>
                        <br>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"     value="toggle_endpoint_pause">
                            <input type="hidden" name="ep_id"      value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="pause"      value="<?= $epPaused ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm" style="margin-top:4px"
                                    title="<?= $epPaused
                                        ? 'Endpunkt wieder für neue Aufgaben freigeben'
                                        : 'Endpunkt für neue Aufgaben sperren (laufende Aufgaben bleiben unberührt)' ?>"><?= $epPaused ? '▶ Fortsetzen' : '⏸ Pausieren' ?></button>
                        </form>
                    </td>
                    <td style="text-align:right;color:var(--warning)"><?= number_format((int) $s['cnt_running']) ?></td>
                    <td style="text-align:right"><?= number_format((int) $s['cnt_done_24h']) ?></td>
                    <td style="text-align:right;color:var(--success)"><?= number_format((int) $s['cnt_done']) ?></td>
                    <td style="text-align:right;color:var(--error)"><?= number_format((int) $s['cnt_error']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['avg_tokens']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['sum_prompt_tokens']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['sum_completion_tokens']) ?></td>
                    <td style="text-align:right;color:var(--accent)"><?= number_format((int) $s['sum_tokens']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <?php if (!empty($imageEndpoints)): ?>
        <h3 style="margin-top:24px;margin-bottom:12px;font-size:.9rem;font-weight:600;">🖼️ Bildgenerierung (ImageInt)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bild-Endpunkt</th>
                    <th>Alias</th>
                    <th style="text-align:right">Timeout</th>
                    <th style="text-align:right">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($imageEndpoints as $imageEp): ?>
                <?php
                $imageEpEffective = !empty($imageStatus['configured'])
                    && rtrim((string) $imageStatus['url'], '/') === rtrim((string) $imageEp['base_url'], '/');
                if (!$imageEpEffective) {
                    $imageEpState = '–';
                } elseif (!empty($imageStatus['ready'])) {
                    $imageEpState = 'bereit';
                } elseif (!empty($imageStatus['reachable'])) {
                    $imageEpState = 'erreichbar';
                } else {
                    $imageEpState = 'nicht erreichbar';
                }
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($imageEp['base_url']) ?>">
                        <span class="dot <?= $imageEp['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($imageEp['base_url']) ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($imageEp['alias'] ?? '')) ?></td>
                    <td style="text-align:right"><?= (int) $imageEp['timeout'] ?> s</td>
                    <td style="text-align:right"><?= htmlspecialchars($imageEpState) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($speechEndpoints)): ?>
        <h3 style="margin-top:24px;margin-bottom:12px;font-size:.9rem;font-weight:600;">🎙️ Diktat (SpeechInt)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Dictation-Endpunkt</th>
                    <th>Alias</th>
                    <th style="text-align:right">Timeout</th>
                    <th style="text-align:right">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($speechEndpoints as $speechEp): ?>
                <?php
                $speechEpEffective = !empty($speechStatus['configured'])
                    && rtrim((string) $speechStatus['url'], '/') === rtrim((string) $speechEp['base_url'], '/');
                if (!$speechEpEffective) {
                    $speechEpState = '–';
                } elseif (!empty($speechStatus['ready'])) {
                    $speechEpState = 'bereit';
                } elseif (!empty($speechStatus['reachable'])) {
                    $speechEpState = 'erreichbar';
                } else {
                    $speechEpState = 'nicht erreichbar';
                }
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($speechEp['base_url']) ?>">
                        <span class="dot <?= $speechEp['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($speechEp['base_url']) ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($speechEp['alias'] ?? '')) ?></td>
                    <td style="text-align:right"><?= (int) $speechEp['timeout'] ?> s</td>
                    <td style="text-align:right"><?= htmlspecialchars($speechEpState) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        </div><!-- /.dash-detail-body -->
    </div>

<script src="../assets/js/flow-topology.js"></script>

<script>
(function () {
    'use strict';

    const root     = document.querySelector('[data-flow-topology]');
    const statusEl = document.getElementById('tree-status');
    const liveDot  = document.getElementById('tree-live-dot');

    // Rendered server-side: at least one endpoint has SSH credentials stored.
    const SSH_CONFIGURED = <?= $hasSshEndpoints ? 'true' : 'false' ?>;

    // ── Status bar ────────────────────────────────────────────────────────────

    function setStatus(label, loading) {
        if (statusEl) statusEl.textContent = label;
        if (liveDot)  liveDot.style.background = loading ? '#f59e0b' : '#22c55e';
    }

    function tsLabel(ts) {
        const d = new Date(ts * 1000);
        return 'Aktualisiert ' + d.toLocaleTimeString('de-DE');
    }

    // ── Live stat-box updates ─────────────────────────────────────────────────

    function fmtCount(n) {
        return Number(n).toLocaleString();
    }

    function setStatBox(id, val) {
        const el = document.getElementById(id);
        if (!el) return;
        const str = fmtCount(val);
        if (el.textContent !== str) {
            el.textContent = str;
            const box = el.closest('.stat-box');
            if (box) {
                box.classList.remove('stat-flash');
                // Force reflow so animation restarts
                void box.offsetWidth;
                box.classList.add('stat-flash');
            }
        }
    }

    /**
     * Renders the online/offline tile of a service group (ImageInt, SpeechInt).
     * `status` is the payload's *_status object; a missing entry means the group
     * has no endpoints configured and therefore no tile in the dashboard.
     */
    function updateServiceStatBox(id, status) {
        const el = document.getElementById(id);
        if (!el || !status) return;
        const online = !!status.reachable;
        const label = online ? 'online' : 'offline';
        if (el.textContent === label) return;
        el.textContent = label;
        el.classList.toggle('stat-done', online);
        el.classList.toggle('stat-error', !online);
        const box = el.closest('.stat-box');
        if (box) {
            box.classList.remove('stat-flash');
            // Force reflow so animation restarts
            void box.offsetWidth;
            box.classList.add('stat-flash');
        }
    }

    function updateStatBoxes(data) {
        const t      = data.totals       || {};
        const s      = data.searxng      || {};
        const image  = data.image_status  || null;
        const speech = data.speech_status || null;

        setStatBox('db-llm-running',   t.total_running           ?? 0);
        setStatBox('db-llm-done24',    t.total_done_24h          ?? 0);
        setStatBox('db-llm-done',      t.total_done              ?? 0);
        setStatBox('db-llm-error',     t.total_error             ?? 0);
        setStatBox('db-prompt-tok',    t.grand_prompt_tokens     ?? 0);
        setStatBox('db-comp-tok',      t.grand_completion_tokens ?? 0);
        setStatBox('db-total-tok',     t.grand_tokens            ?? 0);
        setStatBox('db-srxng-running', s.running                 ?? 0);
        setStatBox('db-srxng-today',   s.today_jobs              ?? 0);
        updateServiceStatBox('db-image-status',  image);
        updateServiceStatBox('db-speech-status', speech);

        const v = data.vector_store || null;
        if (v && v.enabled) {
            const vecStatusEl = document.getElementById('db-vec-status');
            if (vecStatusEl) {
                const label = v.online ? 'online' : 'offline';
                if (vecStatusEl.textContent !== label) {
                    vecStatusEl.textContent = label;
                    vecStatusEl.classList.toggle('stat-done', !!v.online);
                    vecStatusEl.classList.toggle('stat-error', !v.online);
                }
            }
            setStatBox('db-vec-today', v.today_queries ?? 0);
            const vecEl = document.getElementById('db-vec-vectors');
            if (vecEl) {
                if (v.vectors === null || v.vectors === undefined) { vecEl.textContent = '–'; }
                else { setStatBox('db-vec-vectors', v.vectors); }
            }
        }
    }

    // ── SSH system-stats refresh (every 60 seconds) ───────────────────────────
    //
    // Polls refresh_sys_stats.php which SSH-connects to each configured endpoint
    // and caches the results. The following load_stats.php poll then picks them
    // up; pulling once more right away shows them without waiting for the poll.

    let topology = null;

    async function refreshSysStats() {
        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 55000);
            const res  = await fetch('refresh_sys_stats.php', { cache: 'no-store', signal: controller.signal });
            clearTimeout(timer);
            const data = await res.json();
            if (data.ok && topology) {
                topology.load(false);
            }
        } catch (_e) {
            // Non-critical – silently ignore (SSH may simply not be configured)
        }
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    if (root && window.FlowTopology) {
        topology = window.FlowTopology.mount(root, {
            onData: function (payload) {
                updateStatBoxes(payload);
                setStatus(tsLabel(payload.ts), false);
            },
            onBusy: function () {
                setStatus('⟳ Aktualisiere …', true);
            },
            onError: function (message) {
                setStatus(message, false);
            },
        });
    }

    setStatus(tsLabel(Math.floor(Date.now() / 1000)), false);

    // Only poll if at least one endpoint has SSH configured
    if (SSH_CONFIGURED) {
        // First fetch shortly after page load so the tile shows data quickly
        setTimeout(refreshSysStats, 3000);
        setInterval(refreshSysStats, 60000);
    }

})();
</script>

<script>
// ── Detail tables toggle ──────────────────────────────────────────────────────
(function () {
    'use strict';
    const toggle = document.getElementById('dash-detail-toggle');
    const body   = document.getElementById('dash-detail-body');
    if (!toggle || !body) return;
    const STORE_KEY = 'dashDetailOpen';
    let stored = null;
    try { stored = sessionStorage.getItem(STORE_KEY); } catch (e) { /* ignore */ }
    if (stored === '1') {
        body.classList.add('open');
        toggle.classList.add('open');
    }
    toggle.addEventListener('click', function () {
        const open = body.classList.toggle('open');
        toggle.classList.toggle('open', open);
        try { sessionStorage.setItem(STORE_KEY, open ? '1' : '0'); } catch (e) { /* ignore */ }
    });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
