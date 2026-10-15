<?php

/**
 * admin/smtp.php
 *
 * E-Mail (SMTP) - SMTP-Server fuer Registrierungs- und Benachrichtigungsmails.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'smtp';
$adminPageTitle = 'E-Mail (SMTP)';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('smtp-pass', 'SMTP-Passwort',
    'Leer lassen, um das gespeicherte Passwort beizubehalten.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         SMTP / Outgoing Mail Server
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-smtp-card">
        <details class="config-panel" id="config-smtp" open>
            <summary>📧 E-Mail (SMTP)</summary>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action"     value="save_smtp_settings">

            <div class="form-row">
                <div class="form-group" style="flex:3">
                    <label for="smtp-host">SMTP-Host</label>
                    <input type="text" id="smtp-host" name="smtp_host"
                           placeholder="smtp.example.com"
                           value="<?= htmlspecialchars($smtpHost) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:120px">
                    <label for="smtp-port">Port</label>
                    <input type="number" id="smtp-port" name="smtp_port"
                           min="1" max="65535"
                           value="<?= htmlspecialchars($smtpPort) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:140px">
                    <label for="smtp-encryption">Verschlüsselung</label>
                    <select id="smtp-encryption" name="smtp_encryption">
                        <option value="tls"  <?= $smtpEncryption === 'tls'  ? 'selected' : '' ?>>STARTTLS (587)</option>
                        <option value="ssl"  <?= $smtpEncryption === 'ssl'  ? 'selected' : '' ?>>SSL/TLS (465)</option>
                        <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>Keine</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="smtp-auth" name="smtp_auth" value="1"
                               <?= $smtpAuth ? 'checked' : '' ?>>
                        Authentifizierung erforderlich
                    </label>
                </div>
            </div>

            <div id="smtp-auth-fields" <?= $smtpAuth ? '' : 'style="display:none"' ?>>
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-user">SMTP-Benutzername</label>
                    <input type="text" id="smtp-user" name="smtp_user"
                           autocomplete="off"
                           placeholder="nutzer@example.com"
                           value="<?= htmlspecialchars($smtpUser) ?>">
                </div>
                <div class="form-group">
                    <label for="smtp-pass">SMTP-Passwort <?php admin_help_button('smtp-pass'); ?></label>
                    <input type="password" id="smtp-pass" name="smtp_pass"
                           autocomplete="new-password"
                           placeholder="<?= $smtpPass !== '' ? '(gespeichert – leer lassen zum Beibehalten)' : 'Passwort eingeben' ?>">
                </div>
            </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-from-email">Absender-E-Mail</label>
                    <input type="email" id="smtp-from-email" name="smtp_from_email"
                           placeholder="noreply@example.com"
                           value="<?= htmlspecialchars($smtpFromEmail) ?>">
                </div>
                <div class="form-group">
                    <label for="smtp-from-name">Absender-Name</label>
                    <input type="text" id="smtp-from-name" name="smtp_from_name"
                           placeholder="LLMInt"
                           value="<?= htmlspecialchars($smtpFromName) ?>">
                </div>
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="smtp-test-btn" class="btn"
                        <?= $smtpHost === '' ? 'disabled title="Zuerst SMTP-Einstellungen speichern"' : '' ?>>
                    🔌 Test-E-Mail senden
                </button>
                <input type="email" id="smtp-test-to" name="smtp_test_to"
                       placeholder="empfänger@example.com"
                       style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);
                              border-radius:var(--radius);color:var(--text);font-size:.88rem;
                              font-family:var(--font);flex:1;min-width:200px;max-width:320px">
                <span id="smtp-test-result" style="font-size:.85rem"></span>
            </div>
            </form>
        </details>
    </div>

<script>
(function () {
    'use strict';

    const testBtn    = document.getElementById('smtp-test-btn');
    const testResult = document.getElementById('smtp-test-result');
    const toInput    = document.getElementById('smtp-test-to');

    if (!testBtn) return;

    testBtn.addEventListener('click', async function () {
        const to = (toInput?.value || '').trim();
        if (!to) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Bitte eine Empfänger-E-Mail eingeben.';
            return;
        }

        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Sende …';
        testResult.textContent = '';

        try {
            const res  = await fetch('../api/test_smtp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ to }),
            });
            const data = await res.json();
            testResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
            testResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Test-E-Mail senden';
        }
    });
})();
</script>

<script>
(function () {
    'use strict';
    const authChk    = document.getElementById('smtp-auth');
    const authFields = document.getElementById('smtp-auth-fields');
    if (!authChk || !authFields) return;
    authChk.addEventListener('change', function () {
        authFields.style.display = authChk.checked ? '' : 'none';
    });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
