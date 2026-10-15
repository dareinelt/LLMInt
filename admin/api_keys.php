<?php

/**
 * admin/api_keys.php
 *
 * API-Keys - API-Keys anlegen, aktivieren, loeschen.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'api_keys';
$adminPageTitle = 'API-Keys';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('api-keys-intro', 'API-Keys',
    'Ein API-Key ist optional. Er dient der Zuordnung im Log und kann – wenn ein Modell gewählt wurde – '
    . 'das für diesen Key verwendete Modell festlegen. API-Zugriffe verhalten sich immer wie ein nicht '
    . 'angemeldeter Benutzer.');

admin_help_text('api-key-model', 'Modell (optional)',
    'Anfragen mit diesem API-Key verwenden das gewählte Modell. Ohne Auswahl gilt das Standardmodell für '
    . 'nicht angemeldete Zugriffe. Zur Auswahl stehen die Modelle der aktiven Endpunkte.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         API keys (OpenAI-compatible API)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="api-keys-card">
        <h2>🗝️ API-Keys <?php admin_help_button('api-keys-intro'); ?></h2>

        <?php if ($newApiKey !== ''): ?>
            <div class="flash-ok" style="margin-bottom:18px">
                <strong>Neuer API-Key</strong> – dieser Wert ist nur jetzt sichtbar:
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
                    <code id="new-api-key-value" style="flex:1;word-break:break-all;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px 12px"><?= htmlspecialchars($newApiKey) ?></code>
                    <button type="button" class="btn btn-sm" data-copy-target="new-api-key-value">📋 Kopieren</button>
                </div>
            </div>
        <?php endif; ?>

        <h3 style="margin-top:0">OpenAI API-Key erstellen</h3>
        <form method="POST" id="api-key-create-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="create_api_key">
            <div class="form-row">
                <div class="form-group">
                    <label for="api-key-name">Name</label>
                    <input type="text" id="api-key-name" name="name" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label for="api-key-expires">Ablaufdatum (optional)</label>
                    <input type="datetime-local" id="api-key-expires" name="expires_at">
                </div>
            </div>
            <div class="form-group" style="max-width:740px">
                <label for="api-key-description">Beschreibung (optional)</label>
                <textarea id="api-key-description" name="description" rows="2" maxlength="255"
                          style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:.88rem;font-family:var(--font)"></textarea>
            </div>
            <div class="form-group" style="max-width:740px">
                <label for="api-key-model">Modell (optional) <?php admin_help_button('api-key-model'); ?></label>
                <select id="api-key-model" name="model">
                    <option value="">Standardmodell verwenden</option>
                    <?php foreach ($availableModels as $availableModel): ?>
                        <option value="<?= htmlspecialchars($availableModel) ?>"><?= htmlspecialchars($availableModel) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableModels)): ?>
                    <p class="hint" style="color:var(--warning)">
                        Aktuell ist kein aktives Endpunkt-Modell verfügbar. Bitte zuerst unter
                        <a href="endpoints.php">Endpunkte</a> ein Standard-Modell konfigurieren.
                    </p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">API-Key erzeugen</button>
        </form>

        <h3>Vorhandene API-Keys</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Prefix</th>
                    <th>Modell</th>
                    <th>Erstellt</th>
                    <th>Letzter Zugriff</th>
                    <th>Ablauf</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apiKeys as $apiKey): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $apiKey['name']) ?></strong>
                        <?php if (trim((string) $apiKey['description']) !== ''): ?>
                            <br><span class="hint" style="margin-top:0"><?= htmlspecialchars((string) $apiKey['description']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars((string) $apiKey['key_prefix']) ?>…</code></td>
                    <td>
                        <?php if (trim((string) $apiKey['model']) !== ''): ?>
                            <code><?= htmlspecialchars((string) $apiKey['model']) ?></code>
                        <?php else: ?>
                            <span class="hint" style="margin-top:0">Standardmodell</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) $apiKey['created_at']) ?></td>
                    <td><?= $apiKey['last_used_at'] ? htmlspecialchars((string) $apiKey['last_used_at']) : '–' ?></td>
                    <td><?= $apiKey['expires_at'] ? htmlspecialchars((string) $apiKey['expires_at']) : '–' ?></td>
                    <td><?= (int) $apiKey['is_active'] === 1 ? 'aktiv' : 'inaktiv' ?></td>
                    <td style="white-space:nowrap">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="toggle_api_key">
                            <input type="hidden" name="key_id" value="<?= (int) $apiKey['id'] ?>">
                            <?php if ((int) $apiKey['is_active'] === 1): ?>
                                <input type="hidden" name="is_active" value="0">
                            <?php else: ?>
                                <input type="hidden" name="is_active" value="1">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm"><?= (int) $apiKey['is_active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('API-Key löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_api_key">
                            <input type="hidden" name="key_id" value="<?= (int) $apiKey['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($apiKeys)): ?>
                <tr><td colspan="8" class="hint" style="margin-top:0">Noch keine API-Keys.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <script>
        document.querySelectorAll('#api-keys-card [data-copy-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-copy-target'));
                var done = function () {
                    var old = btn.textContent;
                    btn.textContent = '✓ Kopiert';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                };
                var selectFallback = function () {
                    var range = document.createRange();
                    range.selectNodeContents(target);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                    document.execCommand('copy');
                    sel.removeAllRanges();
                    done();
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(target.textContent).then(done, selectFallback);
                } else {
                    selectFallback();
                }
            });
        });
        </script>
    </div>

<?php if ($apiKeysPostHandled): ?>
<script>
// Keep the API-keys card in view after its forms were submitted.
(function () {
    'use strict';
    var card = document.getElementById('api-keys-card');
    if (!card) return;
    if (window.location.hash !== '#api-keys-card') {
        history.replaceState(null, '', '#api-keys-card');
    }
    card.scrollIntoView({ block: 'start' });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_foot.php';
