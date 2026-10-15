<?php

/**
 * admin/system_messages.php
 *
 * Systemmeldungen - Texte der Systemmeldungen.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'system_messages';
$adminPageTitle = 'Systemmeldungen';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('intelligence-upgrade-message', 'Meldungstext für Intelligenz-Upgrade-Angebot',
    'Dieser Text wird dem Benutzer angezeigt, wenn nach einer Anfrage Ressourcen für ein intelligenteres '
    . 'Modell verfügbar sind und ein Upgrade angeboten wird. Leer lassen, um den Standardtext zu verwenden: '
    . '<em>„Es stehen Ressourcen bereit um die Aufgabe erneut mit größerer Intelligenz zu bearbeiten. '
    . 'Dies kann länger dauern als zuvor, kann jedoch genauere Antworten liefern. Fortfahren?"</em>');

admin_help_text('login-banner-enabled', 'Anmeldebanner aktivieren',
    'Wenn aktiviert, erscheint beim ersten Seitenaufruf einer Browser-Session ein Overlay-Banner, das der '
    . 'Benutzer mit „OK" bestätigen muss.');

admin_help_text('login-banner-text', 'Bannertext',
    'HTML ist erlaubt (z.&nbsp;B. <code>&lt;b&gt;</code>, <code>&lt;br&gt;</code>). Leer lassen, um keinen '
    . 'Text anzuzeigen.');

admin_help_text('registration-email-body', 'Inhalt der Bestätigungs-E-Mail',
    'Betreff und Inhalt der E-Mail, die neue Benutzer nach der Registrierung zur Bestätigung ihrer '
    . 'E-Mail-Adresse erhalten, können hier vollständig frei gestaltet werden. Folgende Platzhalter stehen '
    . 'zur Verfügung und werden beim Versand automatisch ersetzt:'
    . '<ul>'
    . '<li><code>{sitename}</code> – Name der Anwendung</li>'
    . '<li><code>{username}</code> – Benutzername des neuen Benutzers</li>'
    . '<li><code>{email}</code> – E-Mail-Adresse des neuen Benutzers</li>'
    . '<li><code>{verify_url}</code> – Bestätigungslink (wird in der HTML-Ansicht automatisch verlinkt)</li>'
    . '</ul>'
    . 'Beide Felder leer lassen, um den Standardtext zu verwenden.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Systemmeldungen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-system-messages-card">
        <details class="config-panel" id="config-system-messages" open>
            <summary>💬 Systemmeldungen</summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_system_messages">

                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">🧠 intelligence_upgrade</h3>
                <div class="form-group">
                    <label for="intelligence-upgrade-message">Meldungstext für Intelligenz-Upgrade-Angebot <?php admin_help_button('intelligence-upgrade-message'); ?></label>
                    <textarea id="intelligence-upgrade-message" name="intelligence_upgrade_message"
                              rows="4" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($intelligenceUpgradeMessage) ?></textarea>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                </div>

                <hr style="margin:24px 0;border-color:var(--border)">

                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">📢 Anmeldebanner</h3>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="login_banner_enabled"
                               <?= $loginBannerEnabled ? 'checked' : '' ?>>
                        Anmeldebanner aktivieren <?php admin_help_button('login-banner-enabled'); ?>
                    </label>
                </div>
                <div class="form-group">
                    <label for="login-banner-text">Bannertext <?php admin_help_button('login-banner-text'); ?></label>
                    <textarea id="login-banner-text" name="login_banner_text"
                              rows="5" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($loginBannerText) ?></textarea>
                </div>

                <hr style="margin:24px 0;border-color:var(--border)">

                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">✉️ Registrierungs-E-Mail</h3>
                <div class="form-group">
                    <label for="registration-email-subject">Betreff der Bestätigungs-E-Mail</label>
                    <input type="text" id="registration-email-subject" name="registration_email_subject"
                           style="width:100%;font-family:inherit"
                           value="<?= htmlspecialchars($registrationEmailSubject) ?>">
                </div>
                <div class="form-group">
                    <label for="registration-email-body">Inhalt der Bestätigungs-E-Mail <?php admin_help_button('registration-email-body'); ?></label>
                    <textarea id="registration-email-body" name="registration_email_body"
                              rows="10" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($registrationEmailBody) ?></textarea>
                </div>
            </form>
        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
