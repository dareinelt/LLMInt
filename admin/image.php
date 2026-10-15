<?php

/**
 * admin/image.php
 *
 * Bildgenerierung - ImageInt-Endpunkte und Benachrichtigungseinstellungen.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'image';
$adminPageTitle = 'Bildgenerierung';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Bildgenerierung (ImageInt)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-image-card">
        <details class="config-panel" id="config-image" open>
            <summary>🖼️ Bildgenerierung</summary>

            <p class="hint" style="margin-bottom:16px">
                Bilder erzeugt der separate Dienst <strong>ImageInt</strong>
                (Qwen-Image-2.1, Prompt-Enhancer und Renderer laufen dort in
                eigenen Modellservern). LLMInt schickt den Bildwunsch per HTTP
                dorthin und präsentiert dem Text-LLM das Tool
                <code>generate_image</code>.<br>
                ImageInt hat die früheren Integrationen AUTOMATIC1111 und
                ComfyUI abgelöst; deren Konfiguration bleibt in der Datenbank
                erhalten, wird aber nicht mehr angesprochen.<br>
                Eine Generierung dauert auf einer CPU <strong>mehrere
                Minuten</strong>. Auf Wunsch schickt LLMInt deshalb eine
                Benachrichtigungsmail, wenn das Bild fertig ist.
            </p>

            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_image_generation_settings">

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="image_generation_enabled" value="1"
                           <?= $imageEnabled ? 'checked' : '' ?> style="width:auto">
                    Bildgenerierung aktiviert
                </label>
                <p class="hint">
                    Stellt dem Text-LLM das Tool <code>generate_image</code> zur
                    Verfügung. Ohne aktiven Endpunkt bleibt das Tool aus.
                </p>
            </div>

            <div class="form-group">
                <label for="image-endpoint-select">Aktiver Image-Endpunkt</label>
                <select id="image-endpoint-select" name="image_endpoint_id">
                    <option value="0" <?= $imageEndpointId === 0 ? 'selected' : '' ?>>
                        – Automatisch (erster aktiver Endpunkt) –
                    </option>
                    <?php foreach ($imageEndpoints as $imageEp): ?>
                    <option value="<?= (int) $imageEp['id'] ?>"
                            <?= $imageEndpointId === (int) $imageEp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(imageIntEndpointLabel($imageEp)) ?>
                        <?= (int) $imageEp['is_active'] === 1 ? '' : ' (inaktiv)' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    Pro Bildauftrag wird genau ein Endpunkt angesprochen – der hier
                    ausgewählte. Ohne Auswahl wird der erste aktive Endpunkt der Liste genutzt.<br>
                    Aktuell wirksam: <strong><?= htmlspecialchars(imageIntSourceLabel($imageUrlSrc)) ?></strong><?php
                    if ($imageUrlSrc === 'env'): ?> – die Umgebungsvariable
                    <code>IMAGEINT_URL</code> hat Vorrang vor dieser Auswahl.<?php endif; ?>
                    <?php if ($imageUrl !== ''): ?>
                    <br>Effektive Basis-URL: <code><?= htmlspecialchars($imageUrl) ?></code>
                    (Auftrags-Obergrenze <?= (int) $imageTimeout ?> s, Wartezeit im
                    Chat <?= (int) $imageSyncTimeout ?> s)
                    <?php endif; ?>
                </p>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="image_notify_enabled" value="1"
                           <?= $imageNotifyEnabled ? 'checked' : '' ?> style="width:auto">
                    E-Mail-Benachrichtigung anbieten
                </label>
                <p class="hint">
                    Blendet im Chat nach einem laufenden Auftrag die Zustimmungsfrage
                    ein. Erst nach einem „Ja" verschickt der Worker eine Mail an die
                    hinterlegte Adresse des Nutzers.
                </p>
            </div>

            <div class="form-group">
                <label for="image-notify-consent">Zustimmungsfrage im Chat</label>
                <textarea id="image-notify-consent" name="image_notify_consent_text"
                          rows="3"><?= htmlspecialchars($imageConsentText) ?></textarea>
                <p class="hint">
                    Text der Rückfrage, wenn ein Auftrag länger läuft. Die Antwort
                    kommt als „Ja, per E-Mail benachrichtigen" / „Nein, ich warte hier"
                    direkt darunter.
                </p>
            </div>

            <div class="form-group">
                <label for="image-notify-subject">Betreff der Benachrichtigungsmail</label>
                <input type="text" id="image-notify-subject" name="image_notify_email_subject"
                       value="<?= htmlspecialchars($imageMailSubject) ?>">
            </div>

            <div class="form-group">
                <label for="image-notify-body">Text der Benachrichtigungsmail</label>
                <textarea id="image-notify-body" name="image_notify_email_body"
                          rows="12"><?= htmlspecialchars($imageMailBody) ?></textarea>
            </div>

            <div class="form-group">
                <label for="image-notify-base-url">Basis-URL für die Links in der Mail</label>
                <input type="url" id="image-notify-base-url" name="image_notify_base_url"
                       placeholder="https://chat.example.org"
                       value="<?= htmlspecialchars($imageNotifyBaseUrl) ?>">
                <p class="hint">
                    Präfix für <code>{chat_url}</code> und <code>{image_url}</code>.
                    Leer lassen, um die Adresse der Anfrage zu verwenden – im
                    Cron-Kontext ist das nicht möglich, deshalb ist ein fester Wert
                    hier zuverlässiger. Alternativ die Umgebungsvariable
                    <code>APP_BASE_URL</code> setzen.
                </p>
            </div>

            <div class="form-group">
                <label>Verfügbare Platzhalter</label>
                <p class="hint" style="font-family:monospace;line-height:1.7">
                    <?php foreach (imageGenerationPlaceholderHelp() as $ph => $phDesc): ?>
                        <code><?= htmlspecialchars($ph) ?></code> – <?= htmlspecialchars($phDesc) ?><br>
                    <?php endforeach; ?>
                </p>
                <p class="hint">
                    Die Platzhalter gelten in beiden Texten. Unbekannte Platzhalter
                    bleiben unverändert stehen.
                </p>
            </div>

            <div class="form-group">
                <label for="image-prompt-trigger">Auslöse-Formulierungen (Systemprompt)</label>
                <textarea id="image-prompt-trigger" name="image_prompt_trigger_text"
                          rows="5"><?= htmlspecialchars($imageTriggerText) ?></textarea>
                <p class="hint">
                    Wird dem Systemprompt angehängt und sagt dem Text-LLM, wann es
                    <code>generate_image</code> aufrufen soll. Ohne Deployment
                    erweiterbar.
                </p>
            </div>

            <div class="form-group">
                <label for="image-prompt-anti-trigger">Gegenregel (Systemprompt)</label>
                <textarea id="image-prompt-anti-trigger" name="image_prompt_anti_trigger_text"
                          rows="4"><?= htmlspecialchars($imageAntiTriggerText) ?></textarea>
                <p class="hint">
                    Ebenso wichtig: sagt dem Modell, wann es das Tool
                    <em>nicht</em> aufrufen darf (z.&nbsp;B. wenn nur über Bilder
                    gesprochen wird).
                </p>
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="image-test-btn" class="btn">🔌 Verbindung testen</button>
                <span id="image-test-result" style="font-size:.85rem"></span>
            </div>

            <div id="image-test-detail" class="hint" hidden
                 style="margin-top:10px;padding:10px 12px;border:1px solid var(--border);
                        border-radius:8px;background:var(--surface-alt)"></div>

            <p class="hint" style="margin-top:12px">
                Die Benachrichtigungsmail wird <strong>serverseitig</strong>
                zugestellt, damit sie auch bei geschlossenem Browser ankommt:
                <code>php api/image_notify_worker.php</code> in kurzem Intervall
                (Cron) ausführen. Der Worker fragt den Auftragsstatus ab und
                verschickt die Mail, sobald das Bild fertig ist. ImageInt bewahrt
                fertige Bilder nur für eine begrenzte Zeit auf (Standard 24 h) –
                innerhalb dieses Fensters muss die Mail raus sein.
            </p>
            </form>

            <!-- ── Image endpoint management ──────────────────────────────── -->
            <div class="ep-form-section" style="margin-top:18px">
                <h3>🌐 Image-Endpunkte</h3>

                <?php if (empty($imageEndpoints)): ?>
                    <p style="color:var(--text-muted);margin-bottom:16px;">
                        Noch keine Image-Endpunkte konfiguriert. Füge unten einen hinzu.
                    </p>
                <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Basis-URL</th>
                            <th>Token</th>
                            <th>Timeout</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imageEndpoints as $imageEp): ?>
                        <tr>
                            <td style="color:var(--text-muted)"><?= (int) $imageEp['id'] ?></td>
                            <td>
                                <?= htmlspecialchars($imageEp['alias'] !== '' ? $imageEp['alias'] : '–') ?>
                                <?php if ($imageEndpointId === (int) $imageEp['id']): ?>
                                    <br><span style="color:var(--accent);font-size:.75rem">aktiv gewählt</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family:monospace;font-size:.8rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="<?= htmlspecialchars($imageEp['base_url']) ?>">
                                <?= htmlspecialchars($imageEp['base_url']) ?>
                            </td>
                            <td style="font-family:monospace;font-size:.8rem">
                                <?= htmlspecialchars(imageIntMaskToken((string) ($imageEp['token'] ?? ''))) ?>
                            </td>
                            <td><?= (int) $imageEp['timeout'] ?>s</td>
                            <td>
                                <span class="dot <?= $imageEp['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                                <?= $imageEp['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </td>
                            <td style="white-space:nowrap">
                                <button type="button" class="btn btn-sm"
                                        onclick="startImageEdit(<?= htmlspecialchars(json_encode($imageEp), ENT_QUOTES) ?>)">
                                    ✏ Bearbeiten
                                </button>
                                <span class="sep"> </span>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="move_image_endpoint">
                                    <input type="hidden" name="image_ep_id" value="<?= (int) $imageEp['id'] ?>">
                                    <input type="hidden" name="image_ep_direction" value="up">
                                    <button type="submit" class="btn btn-sm" title="nach oben">▲</button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="move_image_endpoint">
                                    <input type="hidden" name="image_ep_id" value="<?= (int) $imageEp['id'] ?>">
                                    <input type="hidden" name="image_ep_direction" value="down">
                                    <button type="submit" class="btn btn-sm" title="nach unten">▼</button>
                                </form>
                                <span class="sep"> </span>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Image-Endpunkt #<?= (int) $imageEp['id'] ?> wirklich löschen?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action"     value="delete_image_endpoint">
                                    <input type="hidden" name="image_ep_id" value="<?= (int) $imageEp['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- ── Add / Edit form ─────────────────────────────────────── -->
                <h3 id="image-ep-form-title" style="margin-top:20px">➕ Image-Endpunkt hinzufügen</h3>

                <form method="POST" id="image-ep-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action"    id="image-ep-action" value="add_image_endpoint">
                    <input type="hidden" name="image_ep_id" id="image-ep-id" value="">

                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="image-ep-alias">Name</label>
                            <input type="text" id="image-ep-alias" name="image_ep_alias"
                                   placeholder="Image-Host 1" maxlength="120"
                                   value="<?= $editImageEp ? htmlspecialchars($editImageEp['alias']) : '' ?>">
                            <p class="hint">Nur zur Anzeige – hilft bei mehreren Image-Endpunkten.</p>
                        </div>
                        <div class="form-group" style="flex:1;min-width:140px">
                            <label for="image-ep-timeout">Auftrags-Obergrenze (Sekunden) *</label>
                            <input type="number" id="image-ep-timeout" name="image_ep_timeout"
                                   min="10" max="86400" required
                                   value="<?= $editImageEp ? (int) $editImageEp['timeout'] : 1800 ?>">
                            <p class="hint">
                                10 – 86400 s. Obergrenze des <em>ganzen</em> Vorgangs
                                einschließlich Polling, nicht eines einzelnen HTTP-Aufrufs.
                                Empfehlung: 1800.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="image-ep-url">Basis-URL des ImageInt-Servers *</label>
                        <input type="url" id="image-ep-url" name="image_ep_base_url"
                               placeholder="http://192.168.1.30:8080" required
                               value="<?= $editImageEp ? htmlspecialchars($editImageEp['base_url']) : '' ?>">
                        <p class="hint">
                            Ohne Pfad – die API-Pfade (<code>/v1/ready</code>,
                            <code>/v1/images/generations</code>, <code>/v1/jobs/{job_id}</code>)
                            werden automatisch ergänzt. Das Token wird als
                            <code>X-Auth-Token</code> gesendet, dieselbe Kopfzeile wie bei
                            SpeechInt.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="image-ep-token">Auth-Token (<code>X-Auth-Token</code>)</label>
                        <input type="text" id="image-ep-token" name="image_ep_token"
                               autocomplete="off" spellcheck="false"
                               placeholder="<?= $editImageEp && (string) ($editImageEp['token'] ?? '') !== ''
                                                  ? htmlspecialchars(imageIntMaskToken((string) $editImageEp['token']))
                                                  : 'ohne Token bleibt der Endpunkt offen' ?>">
                        <p class="hint">
                            Gemeinsames Geheimnis des ImageInt-Servers
                            (<code>IMAGEINT_TOKEN</code> bzw. <code>AUTH_TOKEN</code>).
                            Das gespeicherte Token wird nur maskiert angezeigt – leer
                            lassen behält es bei.
                        </p>
                        <?php if ($editImageEp && (string) ($editImageEp['token'] ?? '') !== ''): ?>
                        <label class="inline" style="margin-top:6px">
                            <input type="checkbox" id="image-ep-clear-token" name="image_ep_clear_token">
                            Gespeichertes Token entfernen
                        </label>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="inline">
                            <input type="checkbox" id="image-ep-active" name="image_ep_is_active"
                                   <?= (!$editImageEp || $editImageEp['is_active']) ? 'checked' : '' ?>>
                            Endpunkt aktiv (nimmt Bildaufträge entgegen)
                        </label>
                    </div>

                    <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary">💾 Speichern</button>
                        <button type="button" class="btn" onclick="resetImageForm()">✕ Abbrechen</button>
                        <button type="button" id="image-ep-test-btn" class="btn">🔌 Verbindung testen</button>
                        <span id="image-ep-test-result" style="font-size:.85rem"></span>
                    </div>
                </form>

                <div id="image-ep-test-detail" class="hint" hidden
                     style="margin-top:10px;padding:10px 12px;border:1px solid var(--border);
                            border-radius:8px;background:var(--surface-alt)"></div>
            </div>
        </details>
    </div>

<script>
(function () {
    'use strict';

    const formTitle   = document.getElementById('image-ep-form-title');
    const actionInput = document.getElementById('image-ep-action');
    const idInput     = document.getElementById('image-ep-id');
    const aliasInput  = document.getElementById('image-ep-alias');
    const urlInput    = document.getElementById('image-ep-url');
    const tokenInput  = document.getElementById('image-ep-token');
    const timeoutInput = document.getElementById('image-ep-timeout');
    const activeCheck = document.getElementById('image-ep-active');
    const clearCheck  = document.getElementById('image-ep-clear-token');
    const configPanel = document.getElementById('config-image');

    if (!formTitle) { return; }

    // Pre-fill the form when the page loaded with ?edit_image=<id>
    <?php if ($editImageEp): ?>
    formTitle.textContent = '✏ Image-Endpunkt bearbeiten';
    actionInput.value = 'update_image_endpoint';
    idInput.value     = <?= (int) $editImageEp['id'] ?>;
    if (configPanel) { configPanel.open = true; }
    document.getElementById('image-ep-form').closest('.ep-form-section')
        .scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    window.startImageEdit = function (ep) {
        formTitle.textContent = '✏ Image-Endpunkt bearbeiten';
        actionInput.value     = 'update_image_endpoint';
        idInput.value         = ep.id;
        aliasInput.value      = ep.alias || '';
        urlInput.value        = ep.base_url;
        // The stored secret is never sent to the browser – an empty field
        // therefore keeps whatever is stored on the server.
        tokenInput.value      = '';
        timeoutInput.value    = ep.timeout;
        activeCheck.checked   = ep.is_active == 1;
        if (clearCheck) { clearCheck.checked = false; }
        if (configPanel) { configPanel.open = true; }
        document.getElementById('image-ep-form').closest('.ep-form-section')
            .scrollIntoView({ behavior: 'smooth' });
    };

    window.resetImageForm = function () {
        formTitle.textContent = '➕ Image-Endpunkt hinzufügen';
        actionInput.value     = 'add_image_endpoint';
        idInput.value         = '';
        aliasInput.value      = '';
        urlInput.value        = '';
        tokenInput.value      = '';
        timeoutInput.value    = '1800';
        activeCheck.checked   = true;
        if (clearCheck) { clearCheck.checked = false; }
        document.getElementById('image-ep-test-result').textContent = '';
        document.getElementById('image-ep-test-detail').hidden = true;
    };
})();
</script>

<script>
(function () {
    'use strict';

    const cardBtn    = document.getElementById('image-test-btn');
    const cardResult = document.getElementById('image-test-result');
    const cardDetail = document.getElementById('image-test-detail');
    const formBtn    = document.getElementById('image-ep-test-btn');
    const formResult = document.getElementById('image-ep-test-result');
    const formDetail = document.getElementById('image-ep-test-detail');
    const formUrl    = document.getElementById('image-ep-url');
    const formToken  = document.getElementById('image-ep-token');

    if (!cardBtn && !formBtn) { return; }

    const MAX_ATTEMPTS = 12;

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = text === null || text === undefined ? '' : String(text);
        return div.innerHTML;
    }

    function componentRows(components) {
        const names = { enhancer: 'Prompt-Enhancer', image: 'Renderer (Qwen-Image-2.1)' };
        let html = '';
        Object.keys(components || {}).forEach(function (key) {
            const c = components[key] || {};
            const label = names[key] || key;
            const ok = c.ok ? '✓' : '✗';
            html += '<div>' + ok + ' <strong>' + esc(label) + '</strong>'
                 + ' – ' + esc(c.state_label || c.state || 'unbekannt')
                 + (c.model ? ' (Modell: ' + esc(c.model) + ')' : '')
                 + (c.message ? ': ' + esc(c.message) : '')
                 + '</div>';
        });
        return html;
    }

    function hostRows(host) {
        if (!host) { return ''; }
        const cores  = host.cpu_cores === null || host.cpu_cores === undefined ? '?' : host.cpu_cores;
        const memory = host.memory_gb === null || host.memory_gb === undefined ? '?' : host.memory_gb;
        const avx2   = host.avx2 === true ? 'ja' : (host.avx2 === false ? 'nein' : 'unbekannt');
        let html = '<div><strong>Dimensionierung:</strong> '
                 + cores + ' Kerne, ' + memory + ' GB RAM, AVX2: ' + avx2
                 + ' – ImageInt läuft hier '
                 + ((host.blocking || []).length === 0 ? '✓' : '✗')
                 + '</div>';
        if (host.avx512_note) {
            html += '<div>' + esc(host.avx512_note) + '</div>';
        }
        (host.blocking || []).forEach(function (reason) {
            html += '<div style="color:var(--error)">✗ ' + esc(reason) + '</div>';
        });
        (host.warnings || []).forEach(function (warning) {
            html += '<div style="color:var(--warning)">⚠ ' + esc(warning) + '</div>';
        });
        return html;
    }

    function limitRows(limits) {
        if (!limits || typeof limits !== 'object') { return ''; }
        const parts = [];
        if (limits.job_retention_seconds) {
            parts.push('Bilder werden ' + Math.round(limits.job_retention_seconds / 3600)
                + ' h aufbewahrt');
        }
        if (limits.poll_after_seconds) {
            parts.push('Polling alle ' + limits.poll_after_seconds + ' s');
        }
        if (limits.sync_timeout_seconds) {
            parts.push('Synchrone Obergrenze ' + limits.sync_timeout_seconds + ' s');
        }
        if (limits.max_concurrent_jobs !== undefined) {
            parts.push('gleichzeitig ' + limits.max_concurrent_jobs
                + ', Warteschlange ' + (limits.max_queued_jobs || 0));
        }
        if (!parts.length) { return ''; }
        return '<div><strong>Grenzen:</strong> ' + esc(parts.join(', ')) + '</div>';
    }

    async function probe(button, resultEl, detailEl, url, token, attempt) {
        button.disabled    = true;
        button.textContent = '⟳ Teste …';
        resultEl.textContent = '';
        if (detailEl) { detailEl.hidden = true; }

        let query = '../api/image_health.php';
        const params = [];
        if (url)   { params.push('url='   + encodeURIComponent(url)); }
        if (token) { params.push('token=' + encodeURIComponent(token)); }
        if (params.length) { query += '?' + params.join('&'); }

        try {
            const res  = await fetch(query, { cache: 'no-store' });
            const data = await res.json();

            if (data && data.loading) {
                // Still starting up: no error, keep waiting and try again.
                resultEl.style.color = 'var(--warning)';
                resultEl.textContent = '⏳ ' + (data.message || 'Die ImageInt-Modelle werden noch geladen …')
                    + ' (Versuch ' + attempt + '/' + MAX_ATTEMPTS + ')';
                if (detailEl) {
                    detailEl.innerHTML = componentRows(data.components) + limitRows(data.limits);
                    detailEl.hidden = detailEl.innerHTML === '';
                }
                if (attempt < MAX_ATTEMPTS) {
                    const wait = Math.max(1, Math.min(60, parseInt(data.retry_after, 10) || 15));
                    setTimeout(function () { probe(button, resultEl, detailEl, url, token, attempt + 1); },
                               wait * 1000);
                    return;
                }
                resultEl.textContent += ' – weiterhin nicht bereit.';
                return;
            }

            if (data && data.ok) {
                resultEl.style.color = 'var(--success)';
                resultEl.textContent = '✓ ' + (data.message || 'ImageInt ist bereit.')
                    + ' (' + (data.latency_ms || 0) + ' ms)';
                if (detailEl) {
                    detailEl.innerHTML = componentRows(data.components)
                        + hostRows(data.host) + limitRows(data.limits);
                    detailEl.hidden = false;
                }
            } else {
                // `host_unsupported` never resolves itself – say so plainly.
                const permanent = data && data.permanent;
                resultEl.style.color = 'var(--error)';
                resultEl.textContent = '✗ '
                    + (permanent ? 'Dieser Host wird von ImageInt abgelehnt (host_unsupported): ' : '')
                    + ((data && data.message) || 'Unbekannter Fehler');
                if (detailEl) {
                    detailEl.innerHTML = componentRows(data && data.components)
                        + hostRows(data && data.host) + limitRows(data && data.limits);
                    detailEl.hidden = detailEl.innerHTML === '';
                }
            }
        } catch (e) {
            resultEl.style.color = 'var(--error)';
            resultEl.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            button.disabled    = false;
            button.textContent = '🔌 Verbindung testen';
        }
    }

    if (cardBtn) {
        cardBtn.addEventListener('click', function () {
            probe(cardBtn, cardResult, cardDetail, '', '', 1);
        });
    }

    if (formBtn) {
        formBtn.addEventListener('click', function () {
            // The form's token field is empty while editing (the secret stays on
            // the server), so the stored token of the active endpoint is used.
            probe(formBtn, formResult, formDetail,
                  formUrl ? formUrl.value.trim() : '',
                  formToken ? formToken.value.trim() : '',
                  1);
        });
    }
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
