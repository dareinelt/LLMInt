<?php

/**
 * admin/ldap.php
 *
 * Active Directory - LDAP-/Active-Directory-Anbindung fuer die Anmeldung.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'ldap';
$adminPageTitle = 'Active Directory';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('ldap-enabled', 'LDAP / Active-Directory-Anmeldung aktivieren',
    'Wenn aktiv, können sich Benutzer mit ihren AD-Zugangsdaten anmelden. Konten werden beim ersten '
    . 'Login automatisch angelegt (Just-in-Time Provisioning).');

admin_help_text('ldap-domain', 'Domain (für UPN-Bind)',
    'Wird als <code>benutzername@domain</code> für den Bind verwendet. Leer lassen, wenn kein UPN-Bind '
    . 'gewünscht.');

admin_help_text('ldap-base-dn', 'Base-DN (Nutzersuche)',
    'Basis-Pfad für die LDAP-Suche nach Benutzerattributen (z. B. E-Mail). Optional.');

admin_help_text('ldap-service-account', 'Optionales Dienstkonto',
    'Wird für die Nutzerattribut-Suche verwendet. Ohne Dienstkonto werden E-Mail und Anzeigename nicht '
    . 'ausgelesen (kein anonymer AD-Zugriff).');

admin_help_text('ldap-bind-password', 'Dienstkonto-Passwort',
    'Leer lassen, um das gespeicherte Passwort beizubehalten.');

admin_help_text('ldap-sspi', 'Windows-SSO via REMOTE_USER',
    'Erfordert Apache <code>mod_auth_kerb</code> / <code>mod_auth_ntlm_winbind</code> oder IIS Windows '
    . 'Authentication. Der Webserver muss <code>REMOTE_USER</code> / <code>AUTH_USER</code> setzen. '
    . 'Alternativ übernimmt der <code>auth</code>-Container von lanpa die Windows-Anmeldung '
    . '(Umgebungsvariablen <code>TRUSTED_PROXIES</code> und <code>PROXY_SSO_HEADER</code>, siehe README '
    . '„Betrieb hinter lanpa")'
    . (function_exists('reverseProxySsoEnabled') && reverseProxySsoEnabled() ? ' – <strong>aktiv</strong>' : '')
    . '.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Active Directory / LDAP
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-ldap-card">
        <details class="config-panel" id="config-ldap" open>
            <summary>🏢 Active Directory (LDAP)</summary>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action"     value="save_ldap_settings">

            <!-- Enable toggle -->
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="ldap-enabled" name="ldap_enabled" value="1"
                           <?= $ldapEnabled ? 'checked' : '' ?>>
                    LDAP / Active-Directory-Anmeldung aktivieren <?php admin_help_button('ldap-enabled'); ?>
                </label>
            </div>

            <div id="ldap-settings-fields" <?= $ldapEnabled ? '' : 'style="display:none"' ?>>

            <!-- Server -->
            <div class="form-row">
                <div class="form-group" style="flex:3">
                    <label for="ldap-host">LDAP-/AD-Server</label>
                    <input type="text" id="ldap-host" name="ldap_host"
                           placeholder="ad.example.com"
                           value="<?= htmlspecialchars($ldapHost) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:110px">
                    <label for="ldap-port">Port</label>
                    <input type="number" id="ldap-port" name="ldap_port"
                           min="1" max="65535"
                           value="<?= htmlspecialchars($ldapPort) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:120px;justify-content:flex-end">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:22px">
                        <input type="checkbox" id="ldap-use-ssl" name="ldap_use_ssl" value="1"
                               <?= $ldapUseSsl ? 'checked' : '' ?>>
                        LDAPS (SSL)
                    </label>
                </div>
            </div>

            <!-- Domain / Base-DN -->
            <div class="form-row">
                <div class="form-group">
                    <label for="ldap-domain">Domain (für UPN-Bind) <?php admin_help_button('ldap-domain'); ?></label>
                    <input type="text" id="ldap-domain" name="ldap_domain"
                           placeholder="example.com"
                           value="<?= htmlspecialchars($ldapDomain) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-base-dn">Base-DN (Nutzersuche) <?php admin_help_button('ldap-base-dn'); ?></label>
                    <input type="text" id="ldap-base-dn" name="ldap_base_dn"
                           placeholder="DC=example,DC=com"
                           value="<?= htmlspecialchars($ldapBaseDn) ?>">
                </div>
            </div>

            <!-- Service account -->
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:12px">
                <strong>Optionales Dienstkonto</strong> <?php admin_help_button('ldap-service-account'); ?>
            </p>
            <div class="form-row">
                <div class="form-group">
                    <label for="ldap-bind-dn">Dienstkonto-DN</label>
                    <input type="text" id="ldap-bind-dn" name="ldap_bind_dn"
                           autocomplete="off"
                           placeholder="CN=svc-ldap,OU=ServiceAccounts,DC=example,DC=com"
                           value="<?= htmlspecialchars($ldapBindDn) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-bind-password">Dienstkonto-Passwort <?php admin_help_button('ldap-bind-password'); ?></label>
                    <input type="password" id="ldap-bind-password" name="ldap_bind_password"
                           autocomplete="new-password"
                           placeholder="<?= $ldapBindPassword !== '' ? '(gespeichert – leer lassen zum Beibehalten)' : 'Passwort eingeben' ?>">
                </div>
            </div>

            <!-- Attribute mapping -->
            <div class="form-row">
                <div class="form-group">
                    <label for="ldap-user-attr">Benutzername-Attribut</label>
                    <input type="text" id="ldap-user-attr" name="ldap_user_attr"
                           placeholder="sAMAccountName"
                           value="<?= htmlspecialchars($ldapUserAttr) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-email-attr">E-Mail-Attribut</label>
                    <input type="text" id="ldap-email-attr" name="ldap_email_attr"
                           placeholder="mail"
                           value="<?= htmlspecialchars($ldapEmailAttr) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-display-name-attr">Anzeigename-Attribut</label>
                    <input type="text" id="ldap-display-name-attr" name="ldap_display_name_attr"
                           placeholder="displayName"
                           value="<?= htmlspecialchars($ldapDisplayNameAttr) ?>">
                </div>
            </div>

            <!-- SSO -->
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="ldap-sspi" name="ldap_sspi_enabled" value="1"
                           <?= $ldapSspiEnabled ? 'checked' : '' ?>>
                    Windows-SSO via <code>REMOTE_USER</code> aktivieren <?php admin_help_button('ldap-sspi'); ?>
                </label>
            </div>

            </div><!-- #ldap-settings-fields -->

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="ldap-test-btn" class="btn"
                        <?= $ldapEnabled ? '' : 'disabled title="Zuerst LDAP aktivieren und Einstellungen speichern"' ?>>
                    🔌 Verbindung testen
                </button>
                <span id="ldap-test-result" style="font-size:.85rem"></span>
            </div>
            </form>
        </details>
    </div>

<script>
(function () {
    'use strict';
    const enableChk = document.getElementById('ldap-enabled');
    const fields    = document.getElementById('ldap-settings-fields');
    const testBtn   = document.getElementById('ldap-test-btn');
    if (!enableChk || !fields) return;
    enableChk.addEventListener('change', function () {
        fields.style.display = enableChk.checked ? '' : 'none';
        if (testBtn) testBtn.disabled = !enableChk.checked;
    });
})();
</script>

<script>
(function () {
    'use strict';

    const testBtn    = document.getElementById('ldap-test-btn');
    const testResult = document.getElementById('ldap-test-result');

    if (!testBtn || !testResult) return;

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    testBtn.addEventListener('click', async function () {
        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Teste …';
        testResult.textContent = '';

        const host    = (document.getElementById('ldap-host')          || {}).value || '';
        const port    = parseInt((document.getElementById('ldap-port') || {}).value) || 389;
        const useSsl  = !!(document.getElementById('ldap-use-ssl')     || {}).checked;
        const bindDn  = (document.getElementById('ldap-bind-dn')       || {}).value || '';
        const bindPass= (document.getElementById('ldap-bind-password') || {}).value || '';

        try {
            const res  = await fetch('../api/test_ldap.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, ldap_host: host, ldap_port: port, ldap_use_ssl: useSsl, ldap_bind_dn: bindDn, ldap_bind_pass: bindPass }),
            });
            const data = await res.json();
            testResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
            testResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Verbindung testen';
        }
    });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
