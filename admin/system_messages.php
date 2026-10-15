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
                    <label for="intelligence-upgrade-message">Meldungstext für Intelligenz-Upgrade-Angebot</label>
                    <textarea id="intelligence-upgrade-message" name="intelligence_upgrade_message"
                              rows="4" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($intelligenceUpgradeMessage) ?></textarea>
                    <p class="hint">
                        Dieser Text wird dem Benutzer angezeigt, wenn nach einer Anfrage Ressourcen für ein
                        intelligenteres Modell verfügbar sind und ein Upgrade angeboten wird.
                        Leer lassen, um den Standardtext zu verwenden:
                        <em>„Es stehen Ressourcen bereit um die Aufgabe erneut mit größerer Intelligenz zu bearbeiten.
                        Dies kann länger dauern als zuvor, kann jedoch genauere Antworten liefern. Fortfahren?"</em>
                    </p>
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
                        Anmeldebanner aktivieren
                    </label>
                    <p class="hint">Wenn aktiviert, erscheint beim ersten Seitenaufruf einer Browser-Session ein Overlay-Banner, das der Benutzer mit „OK" bestätigen muss.</p>
                </div>
                <div class="form-group">
                    <label for="login-banner-text">Bannertext</label>
                    <textarea id="login-banner-text" name="login_banner_text"
                              rows="5" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($loginBannerText) ?></textarea>
                    <p class="hint">HTML ist erlaubt (z.&nbsp;B. <code>&lt;b&gt;</code>, <code>&lt;br&gt;</code>). Leer lassen, um keinen Text anzuzeigen.</p>
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
                    <label for="registration-email-body">Inhalt der Bestätigungs-E-Mail</label>
                    <textarea id="registration-email-body" name="registration_email_body"
                              rows="10" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($registrationEmailBody) ?></textarea>
                    <p class="hint">
                        Betreff und Inhalt der E-Mail, die neue Benutzer nach der Registrierung zur Bestätigung
                        ihrer E-Mail-Adresse erhalten, können hier vollständig frei gestaltet werden. Folgende
                        Platzhalter stehen zur Verfügung und werden beim Versand automatisch ersetzt:
                    </p>
                    <ul class="hint" style="margin:4px 0 8px 20px;padding:0">
                        <li><code>{sitename}</code> – Name der Anwendung</li>
                        <li><code>{username}</code> – Benutzername des neuen Benutzers</li>
                        <li><code>{email}</code> – E-Mail-Adresse des neuen Benutzers</li>
                        <li><code>{verify_url}</code> – Bestätigungslink (wird in der HTML-Ansicht automatisch verlinkt)</li>
                    </ul>
                    <p class="hint">Beide Felder leer lassen, um den Standardtext zu verwenden.</p>
                </div>
            </form>
        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
