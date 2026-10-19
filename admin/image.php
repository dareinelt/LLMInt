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

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('image-intro', 'Bildgenerierung (ImageInt)',
    'Bilder erzeugt der separate Dienst <strong>ImageInt</strong> (Qwen-Image-2.1, Prompt-Enhancer '
    . 'und Renderer laufen dort in eigenen Modellservern). LLMInt schickt den Bildwunsch per HTTP '
    . 'dorthin und präsentiert dem Text-LLM das Tool <code>generate_image</code>.<br>'
    . 'ImageInt hat die früheren Integrationen AUTOMATIC1111 und ComfyUI abgelöst; deren '
    . 'Konfiguration bleibt in der Datenbank erhalten, wird aber nicht mehr angesprochen.<br>'
    . 'Eine Generierung dauert auf einer CPU <strong>mehrere Minuten</strong>. Auf Wunsch schickt '
    . 'LLMInt deshalb eine Benachrichtigungsmail, wenn das Bild fertig ist.');

admin_help_text('image-generation-enabled', 'Bildgenerierung aktiviert',
    'Stellt dem Text-LLM das Tool <code>generate_image</code> zur Verfügung. Ohne aktiven '
    . 'Endpunkt bleibt das Tool aus.');

admin_help_text('image-endpoint-select', 'Aktiver Image-Endpunkt',
    'Pro Bildauftrag wird genau ein Endpunkt angesprochen – der hier ausgewählte. Ohne Auswahl '
    . 'wird der erste aktive Endpunkt der Liste genutzt.');

admin_help_text('image-notify-enabled', 'E-Mail-Benachrichtigung anbieten',
    'Blendet im Chat nach einem laufenden Auftrag die Zustimmungsfrage ein. Erst nach einem „Ja" '
    . 'verschickt der Worker eine Mail an die hinterlegte Adresse des Nutzers.<br><br>'
    . 'Die Benachrichtigungsmail wird <strong>serverseitig</strong> zugestellt, damit sie auch bei '
    . 'geschlossenem Browser ankommt: <code>php api/image_notify_worker.php</code> in kurzem '
    . 'Intervall (Cron) ausführen. Der Worker fragt den Auftragsstatus ab und verschickt die Mail, '
    . 'sobald das Bild fertig ist. ImageInt bewahrt fertige Bilder nur für eine begrenzte Zeit auf '
    . '(Standard 24 h) – innerhalb dieses Fensters muss die Mail raus sein.');

admin_help_text('image-notify-consent', 'Zustimmungsfrage im Chat',
    'Text der Rückfrage, wenn ein Auftrag länger läuft. Die Antwort kommt als „Ja, per E-Mail '
    . 'benachrichtigen" / „Nein, ich warte hier" direkt darunter.');

admin_help_text('image-notify-base-url', 'Basis-URL für die Links in der Mail',
    'Präfix für <code>{chat_url}</code> und <code>{image_url}</code>. Leer lassen, um die Adresse '
    . 'der Anfrage zu verwenden – im Cron-Kontext ist das nicht möglich, deshalb ist ein fester '
    . 'Wert hier zuverlässiger. Alternativ die Umgebungsvariable <code>APP_BASE_URL</code> setzen.');

$imagePlaceholderHelp = 'Die Platzhalter gelten in beiden Texten. Unbekannte Platzhalter '
    . 'bleiben unverändert stehen.<br><br>';
foreach (imageGenerationPlaceholderHelp() as $ph => $phDesc) {
    $imagePlaceholderHelp .= '<code>' . htmlspecialchars($ph) . '</code> – '
        . htmlspecialchars($phDesc) . '<br>';
}

admin_help_text('image-placeholders', 'Verfügbare Platzhalter', $imagePlaceholderHelp);

admin_help_text('image-prompt-trigger', 'Auslöse-Formulierungen (Systemprompt)',
    'Wird dem Systemprompt angehängt und sagt dem Text-LLM, wann es <code>generate_image</code> '
    . 'aufrufen soll. Ohne Deployment erweiterbar.');

admin_help_text('image-prompt-anti-trigger', 'Gegenregel (Systemprompt)',
    'Ebenso wichtig: sagt dem Modell, wann es das Tool <em>nicht</em> aufrufen darf '
    . '(z.&nbsp;B. wenn nur über Bilder gesprochen wird).');

admin_help_text('image-ep-alias', 'Name',
    'Nur zur Anzeige – hilft bei mehreren Image-Endpunkten.');

admin_help_text('image-ep-timeout', 'Auftrags-Obergrenze (Sekunden)',
    '10 – 86400 s. Obergrenze des <em>ganzen</em> Vorgangs einschließlich Polling, nicht eines '
    . 'einzelnen HTTP-Aufrufs. Empfehlung: 1800.');

admin_help_text('image-ep-url', 'Basis-URL des ImageInt-Servers',
    'Ohne Pfad – die API-Pfade (<code>/v1/ready</code>, <code>/v1/images/generations</code>, '
    . '<code>/v1/jobs/{job_id}</code>) werden automatisch ergänzt. Das Token wird als '
    . '<code>X-Auth-Token</code> gesendet, dieselbe Kopfzeile wie bei SpeechInt.');

admin_help_text('image-ep-token', 'Auth-Token (X-Auth-Token)',
    'Gemeinsames Geheimnis des ImageInt-Servers (<code>IMAGEINT_TOKEN</code> bzw. '
    . '<code>AUTH_TOKEN</code>). Das gespeicherte Token wird nur maskiert angezeigt – leer lassen '
    . 'behält es bei.');

?>
<style>
    .image-load-panel {
        margin: 6px 0 18px;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--surface-alt);
    }
    .image-load-head {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }
    .image-load-head strong { font-size: .9rem; }
    .image-load-head .hint { flex: 1 1 220px; margin: 0; }
    .image-load-head .btn { margin-left: auto; }
    .image-progress-list { display: flex; flex-direction: column; gap: 12px; }
    .image-progress-row { display: flex; flex-direction: column; gap: 4px; }
    .image-progress-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 10px;
        font-size: .85rem;
    }
    .image-progress-head .state { color: var(--text-muted); font-weight: normal; }
    .image-progress-head .state.is-ready   { color: var(--success); }
    .image-progress-head .state.is-error   { color: var(--error); }
    .image-progress-head .state.is-loading { color: var(--warning); }
    .image-progress-pct { font-variant-numeric: tabular-nums; color: var(--text-muted); }
    .image-progress-track {
        position: relative;
        height: 10px;
        border-radius: 999px;
        background: var(--border);
        overflow: hidden;
    }
    .image-progress-fill {
        height: 100%;
        width: 0;
        border-radius: inherit;
        background: var(--accent);
        transition: width .35s ease;
    }
    .image-progress-fill.is-ready { background: var(--success); }
    .image-progress-fill.is-error { background: var(--error); }
    .image-progress-fill.is-indeterminate {
        width: 35%;
        background: linear-gradient(90deg, var(--accent), var(--warning));
        animation: image-progress-slide 1.4s ease-in-out infinite;
    }
    @keyframes image-progress-slide {
        0%   { transform: translateX(-110%); }
        100% { transform: translateX(320%); }
    }
    @media (prefers-reduced-motion: reduce) {
        .image-progress-fill.is-indeterminate { animation: none; width: 100%; opacity: .45; }
    }
    .image-progress-meta { font-size: .78rem; color: var(--text-muted); }
    .image-progress-meta.error   { color: var(--error); }
    .image-progress-meta.warning { color: var(--warning); }
</style>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Bildgenerierung (ImageInt)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-image-card">
        <details class="config-panel" id="config-image" open>
            <summary>🖼️ Bildgenerierung <?php admin_help_button('image-intro'); ?></summary>

            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_image_generation_settings">

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="image_generation_enabled" value="1"
                           <?= $imageEnabled ? 'checked' : '' ?> style="width:auto">
                    Bildgenerierung aktiviert
                    <?php admin_help_button('image-generation-enabled'); ?>
                </label>
            </div>

            <div class="form-group">
                <label for="image-endpoint-select">Aktiver Image-Endpunkt <?php admin_help_button('image-endpoint-select'); ?></label>
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

            <div class="image-load-panel" id="image-load-panel"<?= $imageUrl === '' ? ' hidden' : '' ?>>
                <div class="image-load-head">
                    <strong>⏬ Modell-Download</strong>
                    <span class="hint" id="image-load-summary">wird geprüft …</span>
                    <button type="button" class="btn btn-sm" id="image-load-refresh">↻ Aktualisieren</button>
                </div>
                <div class="image-progress-list" id="image-load-components"></div>
                <p class="hint" id="image-load-note" hidden></p>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="image_notify_enabled" value="1"
                           <?= $imageNotifyEnabled ? 'checked' : '' ?> style="width:auto">
                    E-Mail-Benachrichtigung anbieten
                    <?php admin_help_button('image-notify-enabled'); ?>
                </label>
            </div>

            <div class="form-group">
                <label for="image-notify-consent">Zustimmungsfrage im Chat <?php admin_help_button('image-notify-consent'); ?></label>
                <textarea id="image-notify-consent" name="image_notify_consent_text"
                          rows="3"><?= htmlspecialchars($imageConsentText) ?></textarea>
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
                <label for="image-notify-base-url">Basis-URL für die Links in der Mail <?php admin_help_button('image-notify-base-url'); ?></label>
                <input type="url" id="image-notify-base-url" name="image_notify_base_url"
                       placeholder="https://chat.example.org"
                       value="<?= htmlspecialchars($imageNotifyBaseUrl) ?>">
            </div>

            <div class="form-group">
                <label>Verfügbare Platzhalter <?php admin_help_button('image-placeholders'); ?></label>
            </div>

            <div class="form-group">
                <label for="image-prompt-trigger">Auslöse-Formulierungen (Systemprompt) <?php admin_help_button('image-prompt-trigger'); ?></label>
                <textarea id="image-prompt-trigger" name="image_prompt_trigger_text"
                          rows="5"><?= htmlspecialchars($imageTriggerText) ?></textarea>
            </div>

            <div class="form-group">
                <label for="image-prompt-anti-trigger">Gegenregel (Systemprompt) <?php admin_help_button('image-prompt-anti-trigger'); ?></label>
                <textarea id="image-prompt-anti-trigger" name="image_prompt_anti_trigger_text"
                          rows="4"><?= htmlspecialchars($imageAntiTriggerText) ?></textarea>
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="image-test-btn" class="btn">🔌 Verbindung testen</button>
                <span id="image-test-result" style="font-size:.85rem"></span>
            </div>

            <div id="image-test-detail" class="hint" hidden
                 style="margin-top:10px;padding:10px 12px;border:1px solid var(--border);
                        border-radius:8px;background:var(--surface-alt)"></div>

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
                            <label for="image-ep-alias">Name <?php admin_help_button('image-ep-alias'); ?></label>
                            <input type="text" id="image-ep-alias" name="image_ep_alias"
                                   placeholder="Image-Host 1" maxlength="120"
                                   value="<?= $editImageEp ? htmlspecialchars($editImageEp['alias']) : '' ?>">
                        </div>
                        <div class="form-group" style="flex:1;min-width:140px">
                            <label for="image-ep-timeout">Auftrags-Obergrenze (Sekunden) * <?php admin_help_button('image-ep-timeout'); ?></label>
                            <input type="number" id="image-ep-timeout" name="image_ep_timeout"
                                   min="10" max="86400" required
                                   value="<?= $editImageEp ? (int) $editImageEp['timeout'] : 1800 ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="image-ep-url">Basis-URL des ImageInt-Servers * <?php admin_help_button('image-ep-url'); ?></label>
                        <input type="url" id="image-ep-url" name="image_ep_base_url"
                               placeholder="http://192.168.1.30:8080" required
                               value="<?= $editImageEp ? htmlspecialchars($editImageEp['base_url']) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label for="image-ep-token">Auth-Token (<code>X-Auth-Token</code>) <?php admin_help_button('image-ep-token'); ?></label>
                        <input type="text" id="image-ep-token" name="image_ep_token"
                               autocomplete="off" spellcheck="false"
                               placeholder="<?= $editImageEp && (string) ($editImageEp['token'] ?? '') !== ''
                                                  ? htmlspecialchars(imageIntMaskToken((string) $editImageEp['token']))
                                                  : 'ohne Token bleibt der Endpunkt offen' ?>">
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

    const panel        = document.getElementById('image-load-panel');
    const panelSummary = document.getElementById('image-load-summary');
    const panelList    = document.getElementById('image-load-components');
    const panelNote    = document.getElementById('image-load-note');
    const panelRefresh = document.getElementById('image-load-refresh');

    if (!cardBtn && !formBtn && !panel) { return; }

    const MAX_ATTEMPTS = 12;

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = text === null || text === undefined ? '' : String(text);
        return div.innerHTML;
    }

    const COMPONENT_NAMES = { enhancer: 'Prompt-Enhancer', image: 'Bildmodell (Qwen-Image-2.1)' };

    function formatDuration(seconds) {
        const total = Math.max(0, Math.round(Number(seconds) || 0));
        const minutes = Math.floor(total / 60);
        const rest = total % 60;
        if (minutes <= 0) { return rest + ' s'; }
        return minutes + ':' + String(rest).padStart(2, '0') + ' min';
    }

    function formatBytes(bytes) {
        const value = Number(bytes);
        if (!isFinite(value) || value < 0) { return ''; }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let index = 0;
        let size = value;
        while (size >= 1024 && index < units.length - 1) {
            size /= 1024;
            index++;
        }
        const digits = size >= 10 || index === 0 ? 0 : 1;
        return size.toFixed(digits).replace('.', ',') + ' ' + units[index];
    }

    function stateText(component) {
        const state = String(component.state || '').toLowerCase();
        const labels = {
            ready: 'bereit', loading: 'lädt', starting: 'startet', idle: 'untätig',
            error: 'Fehler', unreachable: 'nicht erreichbar', unconfigured: 'nicht konfiguriert'
        };
        return labels[state] || component.state_label || component.state || 'unbekannt';
    }

    function stateClass(component, state) {
        if (component.ok || state === 'bereit') { return 'is-ready'; }
        if (state === 'Fehler' || state === 'nicht erreichbar' || state === 'nicht konfiguriert') {
            return 'is-error';
        }
        if (state === 'lädt' || state === 'startet') { return 'is-loading'; }
        return '';
    }

    function progressOf(component) {
        const progress = component.progress || {};
        let percent = progress.percent;
        if (percent === null || percent === undefined) {
            const done = Number(progress.bytes_downloaded);
            const total = Number(progress.bytes_total);
            if (isFinite(done) && isFinite(total) && total > 0) { percent = done / total * 100; }
        }
        if (percent === null || percent === undefined) {
            const done = Number(progress.files_downloaded);
            const total = Number(progress.files_total);
            if (isFinite(done) && isFinite(total) && total > 0) { percent = done / total * 100; }
        }
        if (percent === null || percent === undefined) {
            if (component.ok || String(component.state || '').toLowerCase() === 'ready') {
                percent = 100;
            }
        }
        if (percent !== null && percent !== undefined) {
            percent = Math.max(0, Math.min(100, Number(percent)));
        }
        return { percent: percent, progress: progress };
    }

    function progressRow(key, component) {
        const c = component || {};
        const label = c.label || COMPONENT_NAMES[key] || key;
        const state = stateText(c);
        const kind = stateClass(c, state);
        const info = progressOf(c);
        const percent = info.percent;
        const progress = info.progress || {};

        const fill = percent === null || percent === undefined
            ? '<div class="image-progress-fill is-indeterminate"></div>'
            : '<div class="image-progress-fill ' + kind + '" style="width:'
                + percent.toFixed(1) + '%"></div>';

        const headRight = percent === null || percent === undefined
            ? 'Bitte warten …'
            : percent.toFixed(percent >= 10 ? 0 : 1).replace('.', ',') + ' %';

        const meta = [];
        if (progress.bytes_downloaded !== null && progress.bytes_downloaded !== undefined) {
            const downloaded = formatBytes(progress.bytes_downloaded);
            const total = formatBytes(progress.bytes_total);
            meta.push(total ? downloaded + ' / ' + total : downloaded + ' geladen');
        }
        if (progress.files_total !== null && progress.files_total !== undefined) {
            meta.push((progress.files_downloaded || 0) + ' / ' + progress.files_total + ' Dateien');
        }
        if (progress.current_file) {
            meta.push('Datei: ' + progress.current_file);
        }
        if (c.transient && c.since_seconds !== null && c.since_seconds !== undefined) {
            meta.push('seit ' + formatDuration(c.since_seconds));
        }
        if (progress.eta_seconds !== null && progress.eta_seconds !== undefined) {
            meta.push('noch ca. ' + formatDuration(progress.eta_seconds));
        }
        if (c.load_seconds !== null && c.load_seconds !== undefined && Number(c.load_seconds) > 0) {
            meta.push('Ladezeit ' + formatDuration(c.load_seconds));
        }
        if (c.model) { meta.push('Modell: ' + c.model); }

        let metaHtml = meta.length
            ? '<div class="image-progress-meta">' + esc(meta.join(' · ')) + '</div>'
            : '';
        if (c.message) {
            const cls = kind === 'is-error' ? 'error' : (c.transient ? 'warning' : '');
            metaHtml += '<div class="image-progress-meta' + (cls ? ' ' + cls : '') + '">'
                + esc(c.message) + '</div>';
        }

        return '<div class="image-progress-row">'
            + '<div class="image-progress-head">'
            +   '<span><strong>' + esc(label) + '</strong> '
            +     '<span class="state ' + kind + '">' + esc(state) + '</span></span>'
            +   '<span class="image-progress-pct">' + esc(headRight) + '</span>'
            + '</div>'
            + '<div class="image-progress-track">' + fill + '</div>'
            + metaHtml
            + '</div>';
    }

    function componentRows(components) {
        const keys = Object.keys(components || {});
        if (!keys.length) { return ''; }
        return '<div class="image-progress-list">'
            + keys.map(function (key) { return progressRow(key, components[key]); }).join('')
            + '</div>';
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

    // ── Auto-refreshing download panel ──────────────────────────────────────
    // Polls the active endpoint while the models load. `quiet=1` keeps the
    // access log clean, `compact=1` skips the redundant /v1/models call.
    let panelTimer = null;
    let panelBusy  = false;

    function panelComponents(data) {
        const components = (data && data.components) || {};
        if (Object.keys(components).length) { return components; }
        if (!data || (!data.loading && !data.ok)) { return {}; }
        const state = data.loading ? 'loading' : 'ready';
        const fallback = {};
        ['enhancer', 'image'].forEach(function (key) {
            fallback[key] = {
                label: COMPONENT_NAMES[key],
                state: state,
                ok: !data.loading,
                transient: !!data.loading,
                message: data.message || ''
            };
        });
        return fallback;
    }

    function renderPanel(data) {
        const components = panelComponents(data);
        panelList.innerHTML = componentRows(components);

        const hasProgress = Object.keys(components).some(function (key) {
            const info = progressOf(components[key] || {});
            return info.percent !== null && info.percent !== undefined;
        });

        if (data && data.permanent) {
            panelSummary.textContent = '✗ ' + (data.message || 'Dieser Host wird von ImageInt abgelehnt.');
            panelSummary.style.color = 'var(--error)';
        } else if (data && data.ok) {
            panelSummary.textContent = '✓ ' + (data.message || 'Alle Komponenten sind bereit.');
            panelSummary.style.color = 'var(--success)';
        } else if (data && data.loading) {
            panelSummary.textContent = '⏳ ' + (data.message || 'Die Modelle werden noch geladen …');
            panelSummary.style.color = 'var(--warning)';
        } else {
            panelSummary.textContent = (data && data.message) || 'ImageInt ist nicht bereit.';
            panelSummary.style.color = 'var(--error)';
        }

        if (data && data.loading && Object.keys(components).length && !hasProgress) {
            panelNote.hidden = false;
            panelNote.textContent = 'ImageInt meldet noch keinen Byte-Fortschritt. Die Balken zeigen '
                + 'den Ladezustand, bis das jeweilige Modell bereit ist.';
        } else {
            panelNote.hidden = true;
            panelNote.textContent = '';
        }
    }

    function schedulePanel(milliseconds) {
        if (panelTimer !== null) { clearTimeout(panelTimer); }
        panelTimer = null;
        if (document.hidden) { return; }
        panelTimer = setTimeout(loadPanel, milliseconds);
    }

    async function loadPanel() {
        if (!panel || panel.hidden || panelBusy) { return; }
        if (panelTimer !== null) { clearTimeout(panelTimer); panelTimer = null; }
        panelBusy = true;
        if (panelRefresh) { panelRefresh.disabled = true; }
        panelSummary.textContent = 'wird geprüft …';
        panelSummary.style.color = 'var(--text-muted)';

        try {
            const res = await fetch('../api/image_health.php?quiet=1&compact=1', { cache: 'no-store' });
            const data = await res.json();
            renderPanel(data);
            const wait = data && data.loading
                ? Math.max(2, Math.min(60, parseInt(data.retry_after, 10) || 10))
                : 60;
            schedulePanel(wait * 1000);
        } catch (e) {
            panelSummary.textContent = '✗ ' + e.message;
            panelSummary.style.color = 'var(--error)';
            panelList.innerHTML = '';
            panelNote.hidden = true;
            schedulePanel(15000);
        } finally {
            panelBusy = false;
            if (panelRefresh) { panelRefresh.disabled = false; }
        }
    }

    if (panel && !panel.hidden) {
        loadPanel();
        if (panelRefresh) {
            panelRefresh.addEventListener('click', function () { loadPanel(); });
        }
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && panelTimer === null) { loadPanel(); }
        });
    }
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
