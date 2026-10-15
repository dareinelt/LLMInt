<?php

/**
 * admin/speech.php
 *
 * Spracherkennung - SpeechInt-Endpunkte fuer Diktat und Sprachsteuerung.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'speech';
$adminPageTitle = 'Spracherkennung';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('speech-intro', 'Spracherkennung / Diktat',
    'Transkription (whisper.cpp) und Diktat-Nachbearbeitung laufen in einem separaten Dienst – dem '
    . '<strong>SpeechInt</strong>-Server. LLMInt schickt die Audioschnipsel und den erkannten Text per '
    . 'HTTP dorthin; das erkannte Diktat verlässt dabei den Server nicht und wird nicht gespeichert. '
    . 'Mindestanforderung je Speech-Endpunkt: <strong>6 CPU-Kerne mit AVX2</strong> und '
    . '<strong>16 GB RAM</strong>.');

admin_help_text('speech-enabled', 'Spracherkennung aktiviert',
    'Blendet den Mikrofon-Button 🎙 in der Chat-Eingabe ein. Das Diktat steht angemeldeten Benutzern '
    . 'zur Verfügung.');

admin_help_text('speech-endpoint-select', 'Aktiver Speech-Endpunkt',
    'Pro Diktat-Anfrage wird genau ein Endpunkt angesprochen – der hier ausgewählte. Ohne Auswahl wird '
    . 'der erste aktive Endpunkt der Liste genutzt.');

admin_help_text('speech-language', 'Sprache',
    'Sprachcode für Whisper (z.&nbsp;B. <code>de</code>, <code>en</code>). Leer oder <code>auto</code> '
    . 'lässt die Sprache automatisch erkennen.');

admin_help_text('speech-buffer-words', 'Anzahl gepufferter Wörter',
    'So viele Wörter werden zurückgehalten, bis der nachfolgende Kontext bekannt ist – erst dadurch '
    . 'sind Diktatbefehle wie <em>„neue Zeile"</em> zuverlässig erkennbar. Empfehlung: 3–4.');

admin_help_text('speech-stop-timeout', 'Wartezeit bis „Erkennung beenden" (Sekunden)',
    'Nach dieser Zeit ohne neu verarbeiteten Text erscheint die Schaltfläche „Erkennung beenden". Das '
    . 'Diktat läuft dabei weiter und kann fortgesetzt werden.');

admin_help_text('speech-max-segment', 'Maximale Segmentdauer (Sekunden)',
    'Die Mikrofonaufnahme wird in Segmente dieser Länge zerlegt und einzeln an SpeechInt gesendet. '
    . 'Kürzere Segmente liefern schnelleres Feedback, längere sind effizienter. Empfehlung: 10–20.');

admin_help_text('speech-max-audio', 'Maximale Audiodateigröße (MB)',
    'Größere Segmente werden verworfen und protokolliert (1–100 MB). Der SpeechInt-Server weist '
    . 'Aufnahmen über 25 MB zusätzlich selbst zurück.');

admin_help_text('speech-commands', 'Diktatbefehle und Kurzbefehle',
    'Prompt und Befehlstabelle bleiben in LLMInt konfiguriert und werden bei jeder Anfrage an SpeechInt '
    . 'mitgeschickt – der Speech-Endpunkt ist zustandslos.');

admin_help_text('speech-ep-alias', 'Name',
    'Nur zur Anzeige – hilft bei mehreren Speech-Endpunkten.');

admin_help_text('speech-ep-timeout', 'Timeout (Sekunden)',
    '10 – 600 s');

admin_help_text('speech-ep-url', 'Basis-URL des SpeechInt-Servers',
    'Ohne Pfad – die API-Pfade (<code>/v1/ready</code>, <code>/v1/audio/transcriptions</code>, '
    . '<code>/v1/dictate/process</code>) werden automatisch ergänzt. Der Server muss über mindestens '
    . '<strong>6 CPU-Kerne mit AVX2</strong> und <strong>16 GB RAM</strong> verfügen.');

admin_help_text('speech-ep-token', 'Auth-Token',
    'Gemeinsames Geheimnis des SpeechInt-Servers (<code>SPEECHINT_TOKEN</code> bzw. '
    . '<code>AUTH_TOKEN</code>). Das gespeicherte Token wird nur maskiert angezeigt – leer lassen '
    . 'behält es bei.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Speech recognition / dictation (SpeechInt)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-speech-card">
        <details class="config-panel" id="config-speech" open>
            <summary>🎙️ Spracherkennung / Diktat <?php admin_help_button('speech-intro'); ?> <?php admin_help_button('speech-commands'); ?></summary>

            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_speech_dictation_settings">

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="speech_dictation_enabled" value="1"
                           <?= $speechEnabled ? 'checked' : '' ?> style="width:auto">
                    Spracherkennung aktiviert
                    <?php admin_help_button('speech-enabled'); ?>
                </label>
            </div>

            <div class="form-group">
                <label for="speech-endpoint-select">Aktiver Speech-Endpunkt <?php admin_help_button('speech-endpoint-select'); ?></label>
                <select id="speech-endpoint-select" name="speech_dictation_endpoint_id">
                    <option value="0" <?= $speechEndpointId === 0 ? 'selected' : '' ?>>
                        – Automatisch (erster aktiver Endpunkt) –
                    </option>
                    <?php foreach ($speechEndpoints as $speechEp): ?>
                    <option value="<?= (int) $speechEp['id'] ?>"
                            <?= $speechEndpointId === (int) $speechEp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(speechDictationEndpointLabel($speechEp)) ?>
                        <?= (int) $speechEp['is_active'] === 1 ? '' : ' (inaktiv)' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    Aktuell wirksam: <strong><?= htmlspecialchars(speechDictationSourceLabel($speechUrlSrc)) ?></strong><?php
                    if ($speechUrlSrc === 'env'): ?> – die Umgebungsvariable
                    <code>SPEECHINT_URL</code> hat Vorrang vor dieser Auswahl.<?php endif; ?>
                    <?php if ($speechUrl !== ''): ?>
                    <br>Effektive Basis-URL: <code><?= htmlspecialchars($speechUrl) ?></code>
                    (Timeout <?= (int) $speechTimeout ?> s)
                    <?php endif; ?>
                </p>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label for="speech-language">Sprache <?php admin_help_button('speech-language'); ?></label>
                    <input type="text" id="speech-language" name="speech_dictation_language"
                           placeholder="de" value="<?= htmlspecialchars($speechLanguage) ?>">
                </div>
                <div class="form-group" style="flex:1">
                    <label for="speech-buffer-words">Anzahl gepufferter Wörter <?php admin_help_button('speech-buffer-words'); ?></label>
                    <input type="number" id="speech-buffer-words" name="speech_dictation_buffer_words"
                           min="1" max="50" value="<?= (int) $speechBufferWords ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label for="speech-stop-timeout">Wartezeit bis „Erkennung beenden" (Sekunden) <?php admin_help_button('speech-stop-timeout'); ?></label>
                    <input type="number" id="speech-stop-timeout" name="speech_dictation_stop_timeout_seconds"
                           min="1" max="60" value="<?= (int) $speechStopTimeout ?>">
                </div>
                <div class="form-group" style="flex:1">
                    <label for="speech-max-segment">Maximale Segmentdauer (Sekunden) <?php admin_help_button('speech-max-segment'); ?></label>
                    <input type="number" id="speech-max-segment" name="speech_dictation_max_segment_seconds"
                           min="3" max="120" value="<?= (int) $speechMaxSegment ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="speech-max-audio">Maximale Audiodateigröße (MB) <?php admin_help_button('speech-max-audio'); ?></label>
                <input type="number" id="speech-max-audio" name="speech_dictation_max_audio_mb"
                       min="1" max="100" value="<?= (int) $speechMaxAudioMb ?>">
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="speech-test-btn" class="btn">🔌 Verbindung testen</button>
                <span id="speech-test-result" style="font-size:.85rem"></span>
            </div>

            <div id="speech-test-detail" class="hint" hidden
                 style="margin-top:10px;padding:10px 12px;border:1px solid var(--border);
                        border-radius:8px;background:var(--surface-alt)"></div>

            <p class="hint" style="margin-top:12px">
                Diktatbefehle: <strong><?= (int) $speechCommandCount ?></strong> Phrasen aktiv
                (z.&nbsp;B. „Punkt", „Neue Zeile", „Lösche letztes Wort").
                Kurzbefehle nach dem Diktat: <strong><?= (int) $speechPillCount ?></strong> Pills.
            </p>
            </form>

            <!-- ── Speech endpoint management ──────────────────────────────── -->
            <div class="ep-form-section" style="margin-top:18px">
                <h3>🌐 Speech-Endpunkte</h3>

                <?php if (empty($speechEndpoints)): ?>
                    <p style="color:var(--text-muted);margin-bottom:16px;">
                        Noch keine Speech-Endpunkte konfiguriert. Füge unten einen hinzu.
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
                        <?php foreach ($speechEndpoints as $speechEp): ?>
                        <tr>
                            <td style="color:var(--text-muted)"><?= (int) $speechEp['id'] ?></td>
                            <td>
                                <?= htmlspecialchars($speechEp['alias'] !== '' ? $speechEp['alias'] : '–') ?>
                                <?php if ($speechEndpointId === (int) $speechEp['id']): ?>
                                    <br><span style="color:var(--accent);font-size:.75rem">aktiv gewählt</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family:monospace;font-size:.8rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="<?= htmlspecialchars($speechEp['base_url']) ?>">
                                <?= htmlspecialchars($speechEp['base_url']) ?>
                            </td>
                            <td style="font-family:monospace;font-size:.8rem">
                                <?= htmlspecialchars(speechDictationMaskToken((string) ($speechEp['token'] ?? ''))) ?>
                            </td>
                            <td><?= (int) $speechEp['timeout'] ?>s</td>
                            <td>
                                <span class="dot <?= $speechEp['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                                <?= $speechEp['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </td>
                            <td style="white-space:nowrap">
                                <button type="button" class="btn btn-sm"
                                        onclick="startSpeechEdit(<?= htmlspecialchars(json_encode($speechEp), ENT_QUOTES) ?>)">
                                    ✏ Bearbeiten
                                </button>
                                <span class="sep"> </span>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="move_speech_endpoint">
                                    <input type="hidden" name="speech_ep_id" value="<?= (int) $speechEp['id'] ?>">
                                    <input type="hidden" name="speech_ep_direction" value="up">
                                    <button type="submit" class="btn btn-sm" title="nach oben">▲</button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="move_speech_endpoint">
                                    <input type="hidden" name="speech_ep_id" value="<?= (int) $speechEp['id'] ?>">
                                    <input type="hidden" name="speech_ep_direction" value="down">
                                    <button type="submit" class="btn btn-sm" title="nach unten">▼</button>
                                </form>
                                <span class="sep"> </span>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Speech-Endpunkt #<?= (int) $speechEp['id'] ?> wirklich löschen?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action"     value="delete_speech_endpoint">
                                    <input type="hidden" name="speech_ep_id" value="<?= (int) $speechEp['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- ── Add / Edit form ─────────────────────────────────────── -->
                <h3 id="speech-ep-form-title" style="margin-top:20px">➕ Speech-Endpunkt hinzufügen</h3>

                <form method="POST" id="speech-ep-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action"    id="speech-ep-action" value="add_speech_endpoint">
                    <input type="hidden" name="speech_ep_id" id="speech-ep-id" value="">

                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="speech-ep-alias">Name <?php admin_help_button('speech-ep-alias'); ?></label>
                            <input type="text" id="speech-ep-alias" name="speech_ep_alias"
                                   placeholder="Speech-Host 1" maxlength="120"
                                   value="<?= $editSpeechEp ? htmlspecialchars($editSpeechEp['alias']) : '' ?>">
                        </div>
                        <div class="form-group" style="flex:1;min-width:140px">
                            <label for="speech-ep-timeout">Timeout (Sekunden) * <?php admin_help_button('speech-ep-timeout'); ?></label>
                            <input type="number" id="speech-ep-timeout" name="speech_ep_timeout"
                                   min="10" max="600" required
                                   value="<?= $editSpeechEp ? (int) $editSpeechEp['timeout'] : 120 ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="speech-ep-url">Basis-URL des SpeechInt-Servers * <?php admin_help_button('speech-ep-url'); ?></label>
                        <input type="url" id="speech-ep-url" name="speech_ep_base_url"
                               placeholder="http://192.168.1.20:8099" required
                               value="<?= $editSpeechEp ? htmlspecialchars($editSpeechEp['base_url']) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label for="speech-ep-token">Auth-Token (<code>X-Auth-Token</code>) <?php admin_help_button('speech-ep-token'); ?></label>
                        <input type="text" id="speech-ep-token" name="speech_ep_token"
                               autocomplete="off" spellcheck="false"
                               placeholder="<?= $editSpeechEp && (string) ($editSpeechEp['token'] ?? '') !== ''
                                                  ? htmlspecialchars(speechDictationMaskToken((string) $editSpeechEp['token']))
                                                  : 'ohne Token bleibt der Endpunkt offen' ?>">
                        <?php if ($editSpeechEp && (string) ($editSpeechEp['token'] ?? '') !== ''): ?>
                        <label class="inline" style="margin-top:6px">
                            <input type="checkbox" id="speech-ep-clear-token" name="speech_ep_clear_token">
                            Gespeichertes Token entfernen
                        </label>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="inline">
                            <input type="checkbox" id="speech-ep-active" name="speech_ep_is_active"
                                   <?= (!$editSpeechEp || $editSpeechEp['is_active']) ? 'checked' : '' ?>>
                            Endpunkt aktiv (nimmt Diktat-Anfragen entgegen)
                        </label>
                    </div>

                    <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary">💾 Speichern</button>
                        <button type="button" class="btn" onclick="resetSpeechForm()">✕ Abbrechen</button>
                        <button type="button" id="speech-ep-test-btn" class="btn">🔌 Verbindung testen</button>
                        <span id="speech-ep-test-result" style="font-size:.85rem"></span>
                    </div>
                </form>

                <div id="speech-ep-test-detail" class="hint" hidden
                     style="margin-top:10px;padding:10px 12px;border:1px solid var(--border);
                            border-radius:8px;background:var(--surface-alt)"></div>
            </div>
        </details>
    </div>

<script>
(function () {
    'use strict';

    const formTitle   = document.getElementById('speech-ep-form-title');
    const actionInput = document.getElementById('speech-ep-action');
    const idInput     = document.getElementById('speech-ep-id');
    const aliasInput  = document.getElementById('speech-ep-alias');
    const urlInput    = document.getElementById('speech-ep-url');
    const tokenInput  = document.getElementById('speech-ep-token');
    const timeoutInput = document.getElementById('speech-ep-timeout');
    const activeCheck = document.getElementById('speech-ep-active');
    const clearCheck  = document.getElementById('speech-ep-clear-token');
    const configPanel = document.getElementById('config-speech');

    if (!formTitle) { return; }

    // Pre-fill the form when the page loaded with ?edit_speech=<id>
    <?php if ($editSpeechEp): ?>
    formTitle.textContent = '✏ Speech-Endpunkt bearbeiten';
    actionInput.value = 'update_speech_endpoint';
    idInput.value     = <?= (int) $editSpeechEp['id'] ?>;
    if (configPanel) { configPanel.open = true; }
    document.getElementById('speech-ep-form').closest('.ep-form-section')
        .scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    window.startSpeechEdit = function (ep) {
        formTitle.textContent = '✏ Speech-Endpunkt bearbeiten';
        actionInput.value     = 'update_speech_endpoint';
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
        document.getElementById('speech-ep-form').closest('.ep-form-section')
            .scrollIntoView({ behavior: 'smooth' });
    };

    window.resetSpeechForm = function () {
        formTitle.textContent = '➕ Speech-Endpunkt hinzufügen';
        actionInput.value     = 'add_speech_endpoint';
        idInput.value         = '';
        aliasInput.value      = '';
        urlInput.value        = '';
        tokenInput.value      = '';
        timeoutInput.value    = '120';
        activeCheck.checked   = true;
        if (clearCheck) { clearCheck.checked = false; }
        document.getElementById('speech-ep-test-result').textContent = '';
        document.getElementById('speech-ep-test-detail').hidden = true;
    };
})();
</script>

<script>
(function () {
    'use strict';

    const cardBtn     = document.getElementById('speech-test-btn');
    const cardResult  = document.getElementById('speech-test-result');
    const cardDetail  = document.getElementById('speech-test-detail');
    const formBtn     = document.getElementById('speech-ep-test-btn');
    const formResult  = document.getElementById('speech-ep-test-result');
    const formDetail  = document.getElementById('speech-ep-test-detail');
    const formUrl     = document.getElementById('speech-ep-url');
    const formToken   = document.getElementById('speech-ep-token');

    if (!cardBtn && !formBtn) { return; }

    const MAX_ATTEMPTS = 12;

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = text === null || text === undefined ? '' : String(text);
        return div.innerHTML;
    }

    function componentRows(components) {
        const names = { whisper: 'Whisper (Transkription)', llm: 'Diktat-Modell' };
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
        const cores  = host.cores === null || host.cores === undefined ? '?' : host.cores;
        const memory = host.memory_gb === null || host.memory_gb === undefined ? '?' : host.memory_gb;
        const avx2   = host.avx2 === true ? 'ja' : (host.avx2 === false ? 'nein' : 'unbekannt');
        /* /v1/config reports the minimum as { cores, memory_gb, avx2 }. */
        const min    = host.minimum;
        let minText  = '';
        if (min && typeof min === 'object') {
            const parts = [];
            if (min.cores)     { parts.push(min.cores + ' Kerne'); }
            if (min.memory_gb) { parts.push(min.memory_gb + ' GB RAM'); }
            if (min.avx2)      { parts.push('AVX2'); }
            minText = parts.join(', ');
        } else if (min) {
            minText = String(min);
        }
        let html = '<div><strong>Dimensionierung:</strong> '
                 + cores + ' Kerne, ' + memory + ' GB RAM, AVX2: ' + avx2
                 + ' – Mindestanforderung '
                 + (host.meets_minimum ? 'erfüllt ✓' : 'nicht erfüllt ✗')
                 + (minText ? ' (' + esc(minText) + ')' : '')
                 + '</div>';
        (host.warnings || []).forEach(function (warning) {
            html += '<div style="color:var(--warning)">⚠ ' + esc(warning) + '</div>';
        });
        return html;
    }

    async function probe(button, resultEl, detailEl, url, token, attempt) {
        button.disabled    = true;
        button.textContent = '⟳ Teste …';
        resultEl.textContent = '';
        if (detailEl) { detailEl.hidden = true; }

        let query = '../api/speech_health.php';
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
                resultEl.textContent = '⏳ ' + (data.message || 'Die SpeechInt-Modelle werden noch geladen …')
                    + ' (Versuch ' + attempt + '/' + MAX_ATTEMPTS + ')';
                if (detailEl) {
                    detailEl.innerHTML = componentRows(data.components);
                    detailEl.hidden = false;
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
                resultEl.textContent = '✓ ' + (data.message || 'SpeechInt ist bereit.')
                    + ' (' + (data.latency_ms || 0) + ' ms)';
                if (detailEl) {
                    detailEl.innerHTML = componentRows(data.components) + hostRows(data.host);
                    detailEl.hidden = false;
                }
            } else {
                resultEl.style.color = 'var(--error)';
                resultEl.textContent = '✗ ' + ((data && data.message) || 'Unbekannter Fehler');
                if (detailEl) {
                    detailEl.innerHTML = componentRows(data && data.components) + hostRows(data && data.host);
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
