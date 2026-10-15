<?php

/**
 * admin/endpoints.php
 *
 * Endpunkte - LM-Studio-Endpunkte (CRUD). Tabelle und Loeschen stehen direkt
 * auf der Seite; "Endpunkt hinzufuegen" und "Bearbeiten" oeffnen dasselbe
 * Overlay, leer beziehungsweise vorgefuellt.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'endpoints';
$adminPageTitle = 'Endpunkte';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Endpoint Management
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-endpoints-card">
        <details class="config-panel" id="config-endpoints" open>
        <summary>🔗 Endpunkte</summary>

        <?php if (empty($endpoints)): ?>
            <p style="color:var(--text-muted);margin-bottom:16px;">Noch keine Endpunkte konfiguriert. Füge unten einen hinzu.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Alias</th>
                    <th>URL</th>
                    <th>Timeout</th>
                    <th>Standard-Modell</th>
                    <th>Intelligenz</th>
                    <th>Spezialisierung</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($endpoints as $ep): ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= $ep['id'] ?></td>
                    <td><?= $ep['alias'] !== '' ? htmlspecialchars($ep['alias']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                    <td style="font-family:monospace;font-size:.8rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($ep['base_url']) ?>">
                        <?= htmlspecialchars($ep['base_url']) ?>
                    </td>
                    <td><?= (int) $ep['timeout'] ?>s</td>
                    <td>
                        <?php if ($ep['default_model'] !== ''): ?>
                            <span class="model-badge"
                                  style="background:<?= htmlspecialchars($modelColorMap[canonicalModelName((string) $ep['default_model'])] ?? '#555') ?>"
                                  title="<?= htmlspecialchars($ep['default_model']) ?>">
                                <?= htmlspecialchars($ep['default_model']) ?>
                            </span>
                        <?php else: ?>
                            <span class="model-badge badge-empty">–</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(modelIntelligenceLabel((string) $ep['default_model'])) ?></td>
                    <td>
                        <?php $sf = (string) ($ep['specialized_for_category'] ?? ''); ?>
                        <?php if ($sf !== ''): ?>
                            <span class="model-badge" style="background:#6d4c8a" title="Nur für Intelligence-Upgrade bei Kategorie &#39;<?= htmlspecialchars($sf) ?>&#39; vorgeschlagen">
                                🏷 <?= htmlspecialchars($sf) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:.85rem">Allgemein</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="dot <?= $ep['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= $ep['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                    </td>
                    <td>
                        <?php $epEdit = $epEditDataMap[(int) $ep['id']]; ?>
                        <button type="button" class="btn btn-sm"
                                onclick="openEndpointEditOverlay(<?= htmlspecialchars(json_encode($epEdit), ENT_QUOTES) ?>)">
                            ✏ Bearbeiten
                        </button>
                        <span class="sep"> </span>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Endpunkt #<?= $ep['id'] ?> wirklich löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"     value="delete_endpoint">
                            <input type="hidden" name="ep_id"      value="<?= (int) $ep['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <div style="margin-top:20px">
            <button type="button" class="btn btn-primary" id="ep-add-btn">➕ Endpunkt hinzufügen</button>
        </div>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Add / edit overlay

         Everything that used to sit under "➕ Endpunkt hinzufügen" on this page
         now lives here: opened empty by the "➕ Endpunkt hinzufügen" button,
         pre-filled by "✏ Bearbeiten" in a table row.
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="endpoint-edit-overlay" style="display:none;position:fixed;inset:0;z-index:1000;
         background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
         align-items:center;justify-content:center;overflow:auto;padding:24px">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
                    padding:28px 32px;max-width:640px;width:calc(100% - 32px);
                    max-height:calc(100vh - 48px);overflow:auto;
                    box-shadow:0 24px 60px rgba(0,0,0,.6);position:relative;margin:auto">
            <button id="endpoint-edit-overlay-close"
                    style="position:absolute;top:14px;right:16px;background:none;border:none;
                           color:var(--text-muted);font-size:1.2rem;cursor:pointer;line-height:1"
                    aria-label="Schließen">✕</button>
            <h3 id="ep-form-title" style="margin:0 0 16px">➕ Endpunkt hinzufügen</h3>

            <form method="POST" id="ep-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     id="ep-action" value="add_endpoint">
                <input type="hidden" name="ep_id"      id="ep-id"     value="">

                <div class="form-group">
                    <label for="ep-alias">Alias (optional) <button type="button" class="ep-help" data-help="ep-alias" title="Beschreibung anzeigen" aria-label="Beschreibung zu Alias anzeigen">?</button></label>
                    <input type="text" id="ep-alias" name="ep_alias" maxlength="120"
                           placeholder="z. B. GPU-Server 1"
                           value="">
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:4">
                        <label for="ep-url">API-Endpunkt (Base URL) * <button type="button" class="ep-help" data-help="ep-url" title="Beschreibung anzeigen" aria-label="Beschreibung zum API-Endpunkt anzeigen">?</button></label>
                        <input type="url" id="ep-url" name="ep_base_url"
                               placeholder="http://192.168.1.10:1234/v1" required
                               value="">
                    </div>
                    <div class="form-group" style="flex:1;min-width:160px">
                        <label for="ep-timeout">Timeout (Sekunden) * <button type="button" class="ep-help" data-help="ep-timeout" title="Beschreibung anzeigen" aria-label="Beschreibung zum Timeout anzeigen">?</button></label>
                        <input type="number" id="ep-timeout" name="ep_timeout"
                               min="1" max="600" required
                               value="120">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ep-max-context">Maximaler Kontext (Token) <button type="button" class="ep-help" data-help="ep-max-context" title="Beschreibung anzeigen" aria-label="Beschreibung zum maximalen Kontext anzeigen">?</button></label>
                        <input type="number" id="ep-max-context" name="ep_max_context"
                               min="0" max="10000000" step="1"
                               value="0">
                    </div>
                    <div class="form-group">
                        <label for="ep-context-limit-per-slot">Kontextlimit je Userslot (Token) <button type="button" class="ep-help" data-help="ep-context-limit-per-slot" title="Beschreibung anzeigen" aria-label="Beschreibung zum Kontextlimit je Userslot anzeigen">?</button></label>
                        <input type="number" id="ep-context-limit-per-slot" name="ep_context_limit_per_slot"
                               min="0" max="10000000" step="1"
                               value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ep-model-input">Standard-Modell <button type="button" class="ep-help" data-help="ep-model-input" title="Beschreibung anzeigen" aria-label="Beschreibung zum Standard-Modell anzeigen">?</button></label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="text" list="ep-model-list" id="ep-model-input"
                               name="ep_default_model"
                               placeholder="Modell-ID eingeben oder Modelle laden …"
                               style="flex:1 1 260px"
                               value="">
                        <datalist id="ep-model-list"></datalist>
                        <button type="button" id="ep-load-btn" class="btn">
                            ⟳ Modelle laden
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="ep-specialized-for">Spezialisierung (Routing-Kategorie) <button type="button" class="ep-help" data-help="ep-specialized-for" title="Beschreibung anzeigen" aria-label="Beschreibung zur Spezialisierung anzeigen">?</button></label>
                    <select id="ep-specialized-for" name="ep_specialized_for_category">
                        <option value="" selected>
                            Allgemein (keine Einschränkung)
                        </option>
                        <?php foreach ($routingCategoriesData as $rc): ?>
                            <option value="<?= htmlspecialchars($rc['name']) ?>">
                                <?= htmlspecialchars($rc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-active" name="ep_is_active" checked>
                        Endpunkt aktiv (nimmt Anfragen entgegen)
                    </label>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-supports-tool-calling" name="ep_supports_tool_calling" checked>
                        Tool-Calling unterstützt (search_web und web_fetch bei Bedarf verfügbar)
                        <button type="button" class="ep-help" data-help="ep-supports-tool-calling" title="Beschreibung anzeigen" aria-label="Beschreibung zu Tool-Calling anzeigen">?</button>
                    </label>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-is-llamacpp" name="ep_is_llamacpp">
                        Direkte llama.cpp-Instanz (bare metal)
                        <button type="button" class="ep-help" data-help="ep-is-llamacpp" title="Beschreibung anzeigen" aria-label="Beschreibung zur llama.cpp-Instanz anzeigen">?</button>
                    </label>
                </div>

                <div class="form-group">
                    <label for="ep-reasoning-effort">Reasoning-Effort <button type="button" class="ep-help" data-help="ep-reasoning-effort" title="Beschreibung anzeigen" aria-label="Beschreibung zum Reasoning-Effort anzeigen">?</button></label>
                    <select id="ep-reasoning-effort" name="ep_reasoning_effort">
                        <option value="none">Nicht senden</option>
                        <option value="low">Low</option>
                        <option value="medium">Mid</option>
                        <option value="high" selected>High</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-supports-vision" name="ep_supports_vision">
                        Vision-fähig (Bilder als Eingabe akzeptieren)
                        <button type="button" class="ep-help" data-help="ep-supports-vision" title="Beschreibung anzeigen" aria-label="Beschreibung zu Vision anzeigen">?</button>
                    </label>
                </div>

                <!-- ── SSH-Zugangsdaten (Systemmetriken) ──────────────────────── -->
                <details id="ep-ssh-details">
                    <summary style="cursor:pointer;font-weight:600;margin:12px 0 8px;color:var(--text-muted)">
                        🔑 SSH-Zugangsdaten (optional, für Systemmetriken)
                        <button type="button" class="ep-help" data-help="ep-ssh-host" title="Beschreibung anzeigen" aria-label="Beschreibung zu den SSH-Zugangsdaten anzeigen">?</button>
                    </summary>
                    <div class="form-row">
                        <div class="form-group" style="flex:3">
                            <label for="ep-ssh-host">SSH-Host</label>
                            <input type="text" id="ep-ssh-host" name="ep_ssh_host"
                                   placeholder="192.168.1.10"
                                   value="">
                        </div>
                        <div class="form-group" style="flex:1;min-width:100px">
                            <label for="ep-ssh-port">SSH-Port</label>
                            <input type="number" id="ep-ssh-port" name="ep_ssh_port"
                                   min="1" max="65535"
                                   value="22">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="ep-ssh-user">SSH-Benutzer</label>
                            <input type="text" id="ep-ssh-user" name="ep_ssh_user"
                                   placeholder="ubuntu"
                                   autocomplete="off"
                                   value="">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="ep-ssh-password">SSH-Passwort<span id="ep-ssh-password-hint"
                                   style="font-weight:400;font-size:.8em"></span></label>
                            <input type="password" id="ep-ssh-password" name="ep_ssh_password"
                                   autocomplete="new-password"
                                   placeholder="Passwort">
                        </div>
                    </div>
                </details>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="closeEndpointOverlay()">✕ Abbrechen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Field description popup ("?")

         Every field that used to carry a description below its input now has a
         "?" next to its label. The popup shows only that field's text and is
         rendered above the add/edit overlay.
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="endpoint-help-overlay" style="display:none;position:fixed;inset:0;z-index:1100;
         background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
         align-items:center;justify-content:center;overflow:auto;padding:24px">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
                    padding:22px 26px;max-width:440px;width:calc(100% - 32px);
                    max-height:calc(100vh - 48px);overflow:auto;
                    box-shadow:0 24px 60px rgba(0,0,0,.6);position:relative;margin:auto">
            <button id="endpoint-help-overlay-close"
                    style="position:absolute;top:12px;right:14px;background:none;border:none;
                           color:var(--text-muted);font-size:1.1rem;cursor:pointer;line-height:1"
                    aria-label="Schließen">✕</button>
            <h4 id="endpoint-help-title" style="font-size:.9rem;margin:0 0 8px;padding-right:26px"></h4>
            <div id="endpoint-help-text" style="font-size:.82rem;line-height:1.55;color:var(--text-muted)"></div>
        </div>
    </div>

<script>
(function () {
    'use strict';

    const formTitle    = document.getElementById('ep-form-title');
    const actionInput  = document.getElementById('ep-action');
    const idInput      = document.getElementById('ep-id');
    const aliasInput   = document.getElementById('ep-alias');
    const urlInput     = document.getElementById('ep-url');
    const timeoutInput = document.getElementById('ep-timeout');
    const maxContextInput = document.getElementById('ep-max-context');
    const contextLimitPerSlotInput = document.getElementById('ep-context-limit-per-slot');
    const modelInput   = document.getElementById('ep-model-input');
    const modelList    = document.getElementById('ep-model-list');
    const activeCheck  = document.getElementById('ep-active');
    const toolCallingCheck = document.getElementById('ep-supports-tool-calling');
    const llamacppCheck    = document.getElementById('ep-is-llamacpp');
    const reasoningEffortSelect = document.getElementById('ep-reasoning-effort');
    const visionCheck      = document.getElementById('ep-supports-vision');
    const loadBtn      = document.getElementById('ep-load-btn');
    const specializedSelect = document.getElementById('ep-specialized-for');

    const sshDetails  = document.getElementById('ep-ssh-details');
    const sshHost     = document.getElementById('ep-ssh-host');
    const sshPort     = document.getElementById('ep-ssh-port');
    const sshUser     = document.getElementById('ep-ssh-user');
    const sshPassword = document.getElementById('ep-ssh-password');

    const endpointEditOverlay      = document.getElementById('endpoint-edit-overlay');
    const endpointEditOverlayClose = document.getElementById('endpoint-edit-overlay-close');
    const endpointHelpOverlay      = document.getElementById('endpoint-help-overlay');
    const endpointHelpOverlayClose = document.getElementById('endpoint-help-overlay-close');
    const endpointHelpTitle        = document.getElementById('endpoint-help-title');
    const endpointHelpText         = document.getElementById('endpoint-help-text');
    const sshPasswordHint = document.getElementById('ep-ssh-password-hint');

    /**
     * Descriptions of the add/edit form fields, keyed by the id of the field
     * they belong to. Each "?" button in the form opens the popup with the
     * matching entry only.
     */
    const HELP_TEXTS = {
        'ep-alias': {
            title: 'Alias (optional)',
            body: 'Kurzname für den Endpunkt, wird später in Antwortdetails angezeigt.'
        },
        'ep-url': {
            title: 'API-Endpunkt (Base URL)',
            body: 'Vollständige Base URL der LM Studio REST API.'
        },
        'ep-timeout': {
            title: 'Timeout (Sekunden)',
            body: '1 – 600 s'
        },
        'ep-max-context': {
            title: 'Maximaler Kontext (Token)',
            body: 'Kontextgröße (n_ctx) des Endpunkts in Token. 0 = kein Limit hinterlegt.'
        },
        'ep-context-limit-per-slot': {
            title: 'Kontextlimit je Userslot (Token)',
            body: 'Maximaler Kontext, den eine einzelne Chat-Session belegen darf. '
                + '0 = kein separates Limit (nur der Endpunkt-Kontext gilt).'
        },
        'ep-model-input': {
            title: 'Standard-Modell',
            body: 'Endpunkte mit identischem Standard-Modell bilden automatisch eine Load-Balancing-Gruppe.'
        },
        'ep-specialized-for': {
            title: 'Spezialisierung (Routing-Kategorie)',
            body: 'Ist hier eine Kategorie gewählt, wird dieses Modell beim <strong>Intelligence-Upgrade</strong> '
                + 'nur dann als Vorschlag angezeigt, wenn die aktuelle Anfrage genau dieser Kategorie zugeordnet wurde. '
                + 'So werden spezialisierte Modelle (z.&thinsp;B. Coding) nicht für allgemeine Anfragen empfohlen.'
        },
        'ep-supports-tool-calling': {
            title: 'Tool-Calling unterstützt',
            body: 'Aktivieren, wenn das Modell die OpenAI-kompatible Tool-Calling-API unterstützt. '
                + 'Ist diese Option aktiviert und SearXNG konfiguriert, stehen <code>search_web</code> '
                + 'und <code>web_fetch</code> als Tools zur Verfügung: <code>search_web</code> liefert '
                + 'Treffer mit URLs, <code>web_fetch</code> lädt dazu den Textinhalt einzelner Seiten '
                + 'nach. Das Modell entscheidet selbst, ob es aufgerufen wird – '
                + 'nur wenn es keine ausreichend aktuellen Informationen zu einem Thema hat.'
        },
        'ep-is-llamacpp': {
            title: 'Direkte llama.cpp-Instanz (bare metal)',
            body: 'Aktivieren, wenn dieser Endpunkt ein direkt (bare metal) betriebener '
                + 'llama.cpp-Server ist. Die JSON-Anfrage wird dann llama.cpp-kompatibel '
                + 'aufgebaut (keine LM-Studio-Platzhalter wie <code>"max_tokens":-1</code>) und es wird '
                + 'kein Tool-Calling verwendet – ohne <code>--jinja</code> lehnt llama.cpp Anfragen '
                + 'mit <code>tools</code> ab. Beispiel-Startbefehl: '
                + '<code>./llama-server -m /pfad/zum/modell.gguf --host 0.0.0.0 --port 8080 --jinja</code>'
        },
        'ep-reasoning-effort': {
            title: 'Reasoning-Effort',
            body: 'Wert, der als <code>"reasoning_effort"</code> an diesen Endpunkt übergeben '
                + 'wird – unabhängig davon, ob es sich um eine direkte llama.cpp-Instanz oder '
                + 'einen anderen OpenAI-kompatiblen Endpunkt (LM Studio, vLLM, Ollama …) '
                + 'handelt. Mit "Nicht senden" wird das Feld weggelassen, z. B. für Backends, '
                + 'die unbekannte Felder ablehnen.'
        },
        'ep-supports-vision': {
            title: 'Vision-fähig',
            body: 'Aktivieren, wenn das von diesem Endpunkt bediente Modell Bild-Eingaben '
                + '(OpenAI-kompatible <code>image_url</code>-Inhalte) versteht. Nutzer können dann '
                + 'im Chat Bilder anhängen, die als Base64 an dieses Modell übergeben werden.'
        },
        'ep-ssh-host': {
            title: 'SSH-Zugangsdaten (optional, für Systemmetriken)',
            body: 'Wenn hinterlegt, werden RAM-Auslastung, CPU-Last und CPU-Temperatur '
                + '(lm-sensors) live im Dashboard angezeigt. Erfordert <code>php-ssh2</code> '
                + 'auf dem Webserver sowie <code>lm-sensors</code> auf dem Zielrechner.'
        }
    };

    function openOverlay() {
        if (!endpointEditOverlay) return;
        endpointEditOverlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    /** Close the overlay and return the form to its empty "add" state. */
    function closeEndpointOverlay() {
        if (!endpointEditOverlay) return;
        closeHelpOverlay();
        endpointEditOverlay.style.display = 'none';
        document.body.style.overflow = '';
        resetForm();
    }

    /** "?" next to a field: show only that field's description on top of the overlay. */
    function openHelpOverlay(fieldId) {
        const entry = HELP_TEXTS[fieldId];
        if (!endpointHelpOverlay || !entry) return;
        endpointHelpTitle.textContent = entry.title;
        endpointHelpText.innerHTML    = entry.body;
        endpointHelpOverlay.style.display = 'flex';
    }

    function closeHelpOverlay() {
        if (!endpointHelpOverlay) return;
        endpointHelpOverlay.style.display = 'none';
    }

    /** Open the overlay pre-filled with an existing endpoint ("✏ Bearbeiten"). */
    function openEndpointEditOverlay(ep) {
        if (!ep) return;
        resetForm();
        startEdit(ep);
        openOverlay();
    }

    // Handles used by the inline markup of this page.
    window.closeEndpointOverlay    = closeEndpointOverlay;
    window.openEndpointEditOverlay = openEndpointEditOverlay;

    // "➕ Endpunkt hinzufügen": the markup already contains an empty form, so
    // this is just a reset plus opening the overlay.
    document.getElementById('ep-add-btn').addEventListener('click', function () {
        resetForm();
        openOverlay();
        aliasInput.focus();
    });

    if (endpointEditOverlayClose) endpointEditOverlayClose.addEventListener('click', closeEndpointOverlay);
    if (endpointEditOverlay) {
        endpointEditOverlay.addEventListener('click', function (e) {
            if (e.target === endpointEditOverlay) closeEndpointOverlay();
        });
    }

    // One "?" per described field; each shows its own text. preventDefault keeps
    // the click from also activating the surrounding <label>/<summary>.
    document.querySelectorAll('.ep-help').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openHelpOverlay(btn.getAttribute('data-help'));
        });
    });

    if (endpointHelpOverlayClose) endpointHelpOverlayClose.addEventListener('click', closeHelpOverlay);
    if (endpointHelpOverlay) {
        endpointHelpOverlay.addEventListener('click', function (e) {
            if (e.target === endpointHelpOverlay) closeHelpOverlay();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (endpointHelpOverlay && endpointHelpOverlay.style.display === 'flex') {
            closeHelpOverlay();
        } else if (endpointEditOverlay && endpointEditOverlay.style.display === 'flex') {
            closeEndpointOverlay();
        }
    });

    /**
     * Populate the add/edit form with an existing endpoint's values.
     * Called by "✏ Bearbeiten" in each table row.
     */
    function startEdit(ep) {
        formTitle.textContent  = '✏ Endpunkt bearbeiten';
        actionInput.value      = 'update_endpoint';
        idInput.value          = ep.id;
        aliasInput.value       = ep.alias || '';
        urlInput.value         = ep.base_url;
        timeoutInput.value     = ep.timeout;
        if (maxContextInput) maxContextInput.value = ep.max_context || 0;
        if (contextLimitPerSlotInput) contextLimitPerSlotInput.value = ep.context_limit_per_slot || 0;
        modelInput.value       = ep.default_model || '';
        activeCheck.checked    = ep.is_active == 1;
        if (toolCallingCheck) toolCallingCheck.checked = ep.supports_tool_calling != 0;
        if (llamacppCheck) llamacppCheck.checked = ep.is_llamacpp == 1;
        if (reasoningEffortSelect) reasoningEffortSelect.value = ep.reasoning_effort || 'high';
        if (visionCheck) visionCheck.checked = ep.supports_vision == 1;
        // Clear datalist options from a previous load-models call.
        modelList.innerHTML    = '';
        // Specialization
        if (specializedSelect) specializedSelect.value = ep.specialized_for_category || '';
        // SSH fields
        if (sshHost)     sshHost.value     = ep.ssh_host || '';
        if (sshPort)     sshPort.value     = ep.ssh_port || 22;
        if (sshUser)     sshUser.value     = ep.ssh_user || '';
        if (sshPassword) sshPassword.value = '';   // never pre-fill password
        if (sshDetails && (ep.ssh_host || '').trim() !== '') sshDetails.open = true;
        applySshPasswordHint();
    }

    /** "Leave empty = unchanged" hint on the SSH password field. */
    function applySshPasswordHint() {
        if (!sshPasswordHint || !sshPassword) return;
        const editing = !!(sshHost && sshHost.value.trim() !== '');
        sshPasswordHint.textContent = editing ? ' (leer lassen = unverändert)' : '';
        sshPassword.placeholder = editing ? '••••••••' : 'Passwort';
    }

    /** Reset form back to "add" mode. */
    function resetForm() {
        formTitle.textContent = '➕ Endpunkt hinzufügen';
        actionInput.value     = 'add_endpoint';
        idInput.value         = '';
        aliasInput.value      = '';
        urlInput.value        = '';
        timeoutInput.value    = '120';
        if (maxContextInput) maxContextInput.value = '0';
        if (contextLimitPerSlotInput) contextLimitPerSlotInput.value = '0';
        modelInput.value      = '';
        activeCheck.checked   = true;
        if (toolCallingCheck) toolCallingCheck.checked = true;
        if (llamacppCheck) llamacppCheck.checked = false;
        if (reasoningEffortSelect) reasoningEffortSelect.value = 'high';
        if (visionCheck) visionCheck.checked = false;
        modelList.innerHTML   = '';
        if (specializedSelect) specializedSelect.value = '';
        if (sshHost)     sshHost.value     = '';
        if (sshPort)     sshPort.value     = '22';
        if (sshUser)     sshUser.value     = '';
        if (sshPassword) sshPassword.value = '';
        if (sshDetails)  sshDetails.open   = false;
        applySshPasswordHint();
    }

    /** Load available models from the URL currently typed in the form. */
    loadBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            alert('Bitte zuerst eine URL eingeben.');
            return;
        }

        loadBtn.disabled    = true;
        loadBtn.textContent = '⟳ Laden …';

        try {
            const res  = await fetch('../api/models.php?endpoint_url=' + encodeURIComponent(url) + '&timeout=10');
            const data = await res.json();

            if (data.error) {
                alert('Fehler: ' + data.error);
                return;
            }

            const models = data.models || [];
            if (models.length === 0) {
                alert('Keine Modelle gefunden.');
                return;
            }

            // Populate the <datalist> so the text input offers autocomplete.
            modelList.innerHTML = models
                .map(m => `<option value="${m.id.replace(/"/g, '&quot;')}">`)
                .join('');

            // If the input is empty, pre-fill with the first model.
            if (!modelInput.value && models[0]) {
                modelInput.value = models[0].id;
            }
        } catch (e) {
            alert('Netzwerkfehler: ' + e.message);
        } finally {
            loadBtn.disabled    = false;
            loadBtn.textContent = '⟳ Modelle laden';
        }
    });

    // Open the overlay pre-filled when the page loaded with ?edit=<id>
    <?php if ($editEp): ?>
    openEndpointEditOverlay(<?= json_encode($epEditDataMap[(int) $editEp['id']] ?? null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
    <?php endif; ?>
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
