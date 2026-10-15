<?php

/**
 * admin/endpoints.php
 *
 * Endpunkte - LM-Studio-Endpunkte (CRUD) samt zugehoerigem Bearbeiten-Overlay.
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
                                onclick="startEdit(<?= htmlspecialchars(json_encode($epEdit), ENT_QUOTES) ?>)">
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

        <!-- ── Add / Edit form ─────────────────────────────────────────────── -->
        <!-- Placeholder marker: keeps this form's original position in the DOM
             when it is temporarily moved into the endpoint edit overlay. -->
        <div id="ep-form-section-anchor"></div>
        <div class="ep-form-section" id="ep-form-section">
            <h3 id="ep-form-title">➕ Endpunkt hinzufügen</h3>

            <form method="POST" id="ep-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     id="ep-action" value="add_endpoint">
                <input type="hidden" name="ep_id"      id="ep-id"     value="">

                <div class="form-group">
                    <label for="ep-alias">Alias (optional)</label>
                    <input type="text" id="ep-alias" name="ep_alias" maxlength="120"
                           placeholder="z. B. GPU-Server 1"
                           value="<?= $editEp ? htmlspecialchars($editEp['alias']) : '' ?>">
                    <p class="hint">Kurzname für den Endpunkt, wird später in Antwortdetails angezeigt.</p>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:4">
                        <label for="ep-url">API-Endpunkt (Base URL) *</label>
                        <input type="url" id="ep-url" name="ep_base_url"
                               placeholder="http://192.168.1.10:1234/v1" required
                               value="<?= $editEp ? htmlspecialchars($editEp['base_url']) : '' ?>">
                        <p class="hint">Vollständige Base URL der LM Studio REST API.</p>
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px">
                        <label for="ep-timeout">Timeout (Sekunden) *</label>
                        <input type="number" id="ep-timeout" name="ep_timeout"
                               min="1" max="600" required
                               value="<?= $editEp ? (int) $editEp['timeout'] : 120 ?>">
                        <p class="hint">1 – 600 s</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ep-max-context">Maximaler Kontext (Token)</label>
                        <input type="number" id="ep-max-context" name="ep_max_context"
                               min="0" max="10000000" step="1"
                               value="<?= $editEp ? (int) ($editEp['max_context'] ?? 0) : 0 ?>">
                        <p class="hint">Kontextgröße (n_ctx) des Endpunkts in Token. 0 = kein Limit hinterlegt.</p>
                    </div>
                    <div class="form-group">
                        <label for="ep-context-limit-per-slot">Kontextlimit je Userslot (Token)</label>
                        <input type="number" id="ep-context-limit-per-slot" name="ep_context_limit_per_slot"
                               min="0" max="10000000" step="1"
                               value="<?= $editEp ? (int) ($editEp['context_limit_per_slot'] ?? 0) : 0 ?>">
                        <p class="hint">Maximaler Kontext, den eine einzelne Chat-Session belegen darf. 0 = kein separates Limit (nur der Endpunkt-Kontext gilt).</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="ep-model-input">Standard-Modell</label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="text" list="ep-model-list" id="ep-model-input"
                               name="ep_default_model"
                               placeholder="Modell-ID eingeben oder Modelle laden …"
                               style="flex:1 1 260px"
                               value="<?= $editEp ? htmlspecialchars($editEp['default_model']) : '' ?>">
                        <datalist id="ep-model-list"></datalist>
                        <button type="button" id="ep-load-btn" class="btn">
                            ⟳ Modelle laden
                        </button>
                    </div>
                    <p class="hint">
                        Endpunkte mit identischem Standard-Modell bilden automatisch eine Load-Balancing-Gruppe.
                    </p>
                </div>

                <div class="form-group">
                    <label for="ep-specialized-for">Spezialisierung (Routing-Kategorie)</label>
                    <select id="ep-specialized-for" name="ep_specialized_for_category">
                        <option value=""<?= (!$editEp || ($editEp['specialized_for_category'] ?? '') === '') ? ' selected' : '' ?>>
                            Allgemein (keine Einschränkung)
                        </option>
                        <?php foreach ($routingCategoriesData as $rc): ?>
                            <option value="<?= htmlspecialchars($rc['name']) ?>"
                                <?= ($editEp && ($editEp['specialized_for_category'] ?? '') === $rc['name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Ist hier eine Kategorie gewählt, wird dieses Modell beim <strong>Intelligence-Upgrade</strong>
                        nur dann als Vorschlag angezeigt, wenn die aktuelle Anfrage genau dieser Kategorie zugeordnet wurde.
                        So werden spezialisierte Modelle (z.&thinsp;B. Coding) nicht für allgemeine Anfragen empfohlen.
                    </p>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-active" name="ep_is_active"
                               <?= (!$editEp || $editEp['is_active']) ? 'checked' : '' ?>>
                        Endpunkt aktiv (nimmt Anfragen entgegen)
                    </label>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-supports-tool-calling" name="ep_supports_tool_calling"
                               <?= (!$editEp || (int) ($editEp['supports_tool_calling'] ?? 1)) ? 'checked' : '' ?>>
                        Tool-Calling unterstützt (search_web und web_fetch bei Bedarf verfügbar)
                    </label>
                    <p class="hint">
                        Aktivieren, wenn das Modell die OpenAI-kompatible Tool-Calling-API unterstützt.
                        Ist diese Option aktiviert und SearXNG konfiguriert, stehen <code>search_web</code>
                        und <code>web_fetch</code> als Tools zur Verfügung: <code>search_web</code> liefert
                        Treffer mit URLs, <code>web_fetch</code> lädt dazu den Textinhalt einzelner Seiten
                        nach. Das Modell entscheidet selbst, ob es aufgerufen wird –
                        nur wenn es keine ausreichend aktuellen Informationen zu einem Thema hat.
                    </p>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-is-llamacpp" name="ep_is_llamacpp"
                               <?= ($editEp && (int) ($editEp['is_llamacpp'] ?? 0)) ? 'checked' : '' ?>>
                        Direkte llama.cpp-Instanz (bare metal)
                    </label>
                    <p class="hint">
                        Aktivieren, wenn dieser Endpunkt ein direkt (bare metal) betriebener
                        llama.cpp-Server ist. Die JSON-Anfrage wird dann llama.cpp-kompatibel
                        aufgebaut (keine LM-Studio-Platzhalter wie
                        <code>"max_tokens":-1</code>) und es wird
                        kein Tool-Calling verwendet – ohne <code>--jinja</code> lehnt
                        llama.cpp Anfragen mit <code>tools</code> ab.
                        Beispiel-Startbefehl:
                        <code>./llama-server -m /pfad/zum/modell.gguf --host 0.0.0.0 --port 8080 --jinja</code>
                    </p>
                </div>

                <div class="form-group">
                    <label for="ep-reasoning-effort">Reasoning-Effort</label>
                    <select id="ep-reasoning-effort" name="ep_reasoning_effort">
                        <?php $currentReasoningEffort = $editEp ? ($editEp['reasoning_effort'] ?? 'high') : 'high'; ?>
                        <option value="none"<?= $currentReasoningEffort === 'none' ? ' selected' : '' ?>>Nicht senden</option>
                        <option value="low"<?= $currentReasoningEffort === 'low' ? ' selected' : '' ?>>Low</option>
                        <option value="medium"<?= $currentReasoningEffort === 'medium' ? ' selected' : '' ?>>Mid</option>
                        <option value="high"<?= $currentReasoningEffort === 'high' ? ' selected' : '' ?>>High</option>
                    </select>
                    <p class="hint">
                        Wert, der als <code>"reasoning_effort"</code> an diesen Endpunkt übergeben
                        wird – unabhängig davon, ob es sich um eine direkte llama.cpp-Instanz oder
                        einen anderen OpenAI-kompatiblen Endpunkt (LM Studio, vLLM, Ollama …)
                        handelt. Mit "Nicht senden" wird das Feld weggelassen, z. B. für Backends,
                        die unbekannte Felder ablehnen.
                    </p>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-supports-vision" name="ep_supports_vision"
                               <?= ($editEp && (int) ($editEp['supports_vision'] ?? 0)) ? 'checked' : '' ?>>
                        Vision-fähig (Bilder als Eingabe akzeptieren)
                    </label>
                    <p class="hint">
                        Aktivieren, wenn das von diesem Endpunkt bediente Modell Bild-Eingaben
                        (OpenAI-kompatible <code>image_url</code>-Inhalte) versteht. Nutzer können dann
                        im Chat Bilder anhängen, die als Base64 an dieses Modell übergeben werden.
                    </p>
                </div>

                <!-- ── SSH-Zugangsdaten (Systemmetriken) ──────────────────────── -->
                <details id="ep-ssh-details"<?= ($editEp && trim((string) ($editEp['ssh_host'] ?? '')) !== '') ? ' open' : '' ?>>
                    <summary style="cursor:pointer;font-weight:600;margin:12px 0 8px;color:var(--text-muted)">
                        🔑 SSH-Zugangsdaten (optional, für Systemmetriken)
                    </summary>
                    <p class="hint" style="margin-bottom:8px">
                        Wenn hinterlegt, werden RAM-Auslastung, CPU-Last und CPU-Temperatur
                        (lm-sensors) live im Dashboard angezeigt. Erfordert <code>php-ssh2</code>
                        auf dem Webserver sowie <code>lm-sensors</code> auf dem Zielrechner.
                    </p>
                    <div class="form-row">
                        <div class="form-group" style="flex:3">
                            <label for="ep-ssh-host">SSH-Host</label>
                            <input type="text" id="ep-ssh-host" name="ep_ssh_host"
                                   placeholder="192.168.1.10"
                                   value="<?= $editEp ? htmlspecialchars($editEp['ssh_host'] ?? '') : '' ?>">
                        </div>
                        <div class="form-group" style="flex:1;min-width:100px">
                            <label for="ep-ssh-port">SSH-Port</label>
                            <input type="number" id="ep-ssh-port" name="ep_ssh_port"
                                   min="1" max="65535"
                                   value="<?= $editEp ? (int) ($editEp['ssh_port'] ?? 22) : 22 ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="ep-ssh-user">SSH-Benutzer</label>
                            <input type="text" id="ep-ssh-user" name="ep_ssh_user"
                                   placeholder="ubuntu"
                                   autocomplete="off"
                                   value="<?= $editEp ? htmlspecialchars($editEp['ssh_user'] ?? '') : '' ?>">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="ep-ssh-password">SSH-Passwort<?= $editEp && trim((string) ($editEp['ssh_host'] ?? '')) !== '' ? ' <span style="font-weight:400;font-size:.8em">(leer lassen = unverändert)</span>' : '' ?></label>
                            <input type="password" id="ep-ssh-password" name="ep_ssh_password"
                                   autocomplete="new-password"
                                   placeholder="<?= $editEp && trim((string) ($editEp['ssh_host'] ?? '')) !== '' ? '••••••••' : 'Passwort' ?>">
                        </div>
                    </div>
                </details>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="resetForm()">✕ Abbrechen</button>
                </div>
            </form>
        </div>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Endpoint edit overlay (opened by clicking an endpoint node in the
         Dashboard mindmap). Hosts the very same Add/Edit form used under
         "Endpunkte" → "✏ Bearbeiten", so it offers exactly the same options.
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
            <div id="endpoint-edit-overlay-body"></div>
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
    const endpointConfigPanel = document.getElementById('config-endpoints');
    const specializedSelect = document.getElementById('ep-specialized-for');

    const sshDetails  = document.getElementById('ep-ssh-details');
    const sshHost     = document.getElementById('ep-ssh-host');
    const sshPort     = document.getElementById('ep-ssh-port');
    const sshUser     = document.getElementById('ep-ssh-user');
    const sshPassword = document.getElementById('ep-ssh-password');

    // Full edit data for every endpoint (id → fields), used to open the
    // endpoint edit overlay from a Dashboard mindmap click.
    window.EP_EDIT_DATA = <?= json_encode($epEditDataMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const epFormSection          = document.getElementById('ep-form-section');
    const epFormSectionAnchor    = document.getElementById('ep-form-section-anchor');
    const endpointEditOverlay     = document.getElementById('endpoint-edit-overlay');
    const endpointEditOverlayBody = document.getElementById('endpoint-edit-overlay-body');
    const endpointEditOverlayClose = document.getElementById('endpoint-edit-overlay-close');

    /** Reveal the (possibly relocated) add/edit form to the user. */
    function revealForm() {
        if (endpointEditOverlayBody && endpointEditOverlayBody.contains(epFormSection)) {
            return; // already visible inside the overlay
        }
        if (endpointConfigPanel) { endpointConfigPanel.open = true; }
        epFormSection.scrollIntoView({ behavior: 'smooth' });
    }

    /** Move the add/edit form into the overlay and show it, pre-filled for `ep`. */
    window.openEndpointEditOverlay = function (ep) {
        if (!ep || !endpointEditOverlay || !endpointEditOverlayBody) return;
        endpointEditOverlayBody.appendChild(epFormSection);
        window.startEdit(ep);
        endpointEditOverlay.style.display = 'flex';
    };

    /** Close the overlay and move the form back to its original place. */
    function closeEndpointEditOverlay() {
        if (!endpointEditOverlay) return;
        endpointEditOverlay.style.display = 'none';
        if (epFormSectionAnchor && epFormSectionAnchor.parentNode) {
            epFormSectionAnchor.parentNode.insertBefore(epFormSection, epFormSectionAnchor.nextSibling);
        }
    }
    if (endpointEditOverlayClose) endpointEditOverlayClose.addEventListener('click', closeEndpointEditOverlay);
    if (endpointEditOverlay) {
        endpointEditOverlay.addEventListener('click', function (e) {
            if (e.target === endpointEditOverlay) closeEndpointEditOverlay();
        });
    }

    // Pre-fill the form when the page loaded with ?edit=<id>
    <?php if ($editEp): ?>
    document.getElementById('ep-form-title').textContent = '✏ Endpunkt bearbeiten';
    document.getElementById('ep-action').value = 'update_endpoint';
    document.getElementById('ep-id').value     = <?= (int) $editEp['id'] ?>;
    if (endpointConfigPanel) { endpointConfigPanel.open = true; }
    epFormSection.scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    /**
     * Populate the add/edit form with an existing endpoint's values.
     * Called by the "Edit" button in each table row, and when the endpoint
     * edit overlay is opened from the Dashboard mindmap.
     */
    window.startEdit = function (ep) {
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
        revealForm();
    };

    /** Reset form back to "add" mode. */
    window.resetForm = function () {
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
        if (endpointEditOverlayBody && endpointEditOverlayBody.contains(epFormSection)) {
            closeEndpointEditOverlay();
        }
    };

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
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
