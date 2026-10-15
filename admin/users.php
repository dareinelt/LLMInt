<?php

/**
 * admin/users.php
 *
 * Benutzerkonten - Benutzerverwaltung samt Benutzer-Overlays.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'users';
$adminPageTitle = 'Benutzerkonten';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('users-table', 'Benutzerkonten',
    'Klicke auf einen Benutzer, um Einstellungen zu ändern oder ein Passwort-Reset zu senden.');

admin_help_text('overlay-role', 'Rolle',
    'Nur Benutzer mit der Rolle "Admin" können den Verwaltungsbereich (/admin) aufrufen.');

admin_help_text('overlay-model', 'Standard-Modell für diesen Benutzer',
    'Ist das gewählte Modell nicht verfügbar, wird automatisch das Modell mit der nächst-geringeren '
    . 'Intelligenz verwendet.');

admin_help_text('overlay-doc-upload', 'Dokument-Upload erlauben',
    'Erlaubt dem Benutzer, Dokumente (Bilder) in der Chat-Oberfläche hochzuladen und per Vision-Modell '
    . 'zu analysieren.');

admin_help_text('overlay-reset-pw', 'Passwort-Reset senden',
    'Sendet eine E-Mail mit einem Link zum Zurücksetzen des Passworts. Das Konto wird beim nächsten '
    . 'Login zur Passwortänderung aufgefordert.');

admin_help_text('cu-username', 'Benutzername',
    'Erlaubt: Buchstaben, Ziffern und <code>_</code> (max. 100 Zeichen). Keine E-Mail-Adresse nötig.');

admin_help_text('cu-password', 'Passwort',
    'Min. 8 Zeichen, Groß-/Kleinbuchstaben, Ziffer und Sonderzeichen (<code>#?!@$%^&amp;*-</code>).');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         User accounts
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="users-card">
        <h2 style="display:flex;align-items:center;gap:10px">
            👤 Benutzerkonten <?php admin_help_button('users-table'); ?>
            <button id="create-user-btn"
                    class="btn btn-primary"
                    style="font-size:.8rem;padding:4px 10px;line-height:1.4"
                    title="Neuen Benutzer anlegen">＋</button>
        </h2>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Benutzername</th>
                    <th>E-Mail</th>
                    <th>Bestätigt</th>
                    <th>Rolle</th>
                    <th>Standard-Modell</th>
                    <th>Erstellt am</th>
                    <th>Letzter Login</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="user-row" data-user='<?= htmlspecialchars(json_encode([
                        'id'                   => (int) $u['id'],
                        'username'             => $u['username'],
                        'email'                => $u['email'] ?? '',
                        'email_verified'       => (int) ($u['email_verified'] ?? 0),
                        'default_model'        => $u['default_model'] ?? '',
                        'can_upload_documents' => (int) ($u['can_upload_documents'] ?? 0),
                        'role'                 => $u['role'] ?? 'user',
                        'auth_source'          => $u['auth_source'] ?? 'local',
                    ]), ENT_QUOTES) ?>'
                    style="cursor:pointer" title="Klicken für Benutzerdetails">
                        <td>
                            <?= htmlspecialchars($u['username']) ?>
                            <?php if ($u['username'] === $_SESSION['admin_user']): ?>
                                <span class="badge-you">Du</span>
                            <?php endif; ?>
                            <?php if (($u['auth_source'] ?? 'local') === 'ldap'): ?>
                                <span class="badge-you" style="background:rgba(59,130,246,.18);color:#3b82f6;border-color:rgba(59,130,246,.35)">AD</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['email'] !== null ? htmlspecialchars($u['email']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                        <td>
                            <?php if ((int)($u['email_verified'] ?? 0) === 1): ?>
                                <span style="color:var(--success)">✓</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($u['role'] ?? 'user') === 'admin'): ?>
                                <span class="badge-you" style="background:rgba(108,99,255,.18);color:#6c63ff;border-color:rgba(108,99,255,.35)">Admin</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">Benutzer</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($u['default_model'] ?? '') !== ''): ?>
                                <span style="font-size:.8rem;font-family:monospace" title="<?= htmlspecialchars($u['default_model']) ?>">
                                    <?= htmlspecialchars(strlen($u['default_model']) > 28 ? substr($u['default_model'], 0, 26) . '…' : $u['default_model']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:.8rem">Standard</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td><?= $u['last_login'] !== null ? htmlspecialchars($u['last_login']) : '<span style="color:var(--text-muted)">noch nie</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:12px;font-size:.82rem">
            <a href="../register.php" style="color:var(--accent);text-decoration:none">+ Registrierungsseite öffnen</a>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         User overlay
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="user-overlay" style="display:none;position:fixed;inset:0;z-index:1000;
         background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
         align-items:center;justify-content:center">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
                    padding:28px 32px;max-width:480px;width:calc(100% - 32px);
                    box-shadow:0 24px 60px rgba(0,0,0,.6);position:relative">
            <button id="user-overlay-close"
                    style="position:absolute;top:14px;right:16px;background:none;border:none;
                           color:var(--text-muted);font-size:1.2rem;cursor:pointer;line-height:1"
                    aria-label="Schließen">✕</button>

            <h2 style="font-size:1rem;margin-bottom:4px" id="overlay-username"></h2>
            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:20px" id="overlay-email"></p>

            <!-- Role selector -->
            <div class="form-group">
                <label for="overlay-role">Rolle <?php admin_help_button('overlay-role'); ?></label>
                <select id="overlay-role" style="width:100%;padding:8px 12px;background:var(--bg);
                        border:1px solid var(--border);border-radius:var(--radius);
                        color:var(--text);font-size:.88rem;font-family:var(--font)">
                    <option value="user">Benutzer</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
                <button id="overlay-save-role" class="btn btn-sm">💾 Rolle speichern</button>
                <span id="overlay-role-result" style="font-size:.82rem"></span>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

            <!-- Model selector -->
            <div class="form-group">
                <label for="overlay-model">Standard-Modell für diesen Benutzer <?php admin_help_button('overlay-model'); ?></label>
                <select id="overlay-model" style="width:100%;padding:8px 12px;background:var(--bg);
                        border:1px solid var(--border);border-radius:var(--radius);
                        color:var(--text);font-size:.88rem;font-family:var(--font)">
                    <option value="">Systemweites Standard-Modell verwenden</option>
                    <?php foreach ($availableGuestModels as $model): ?>
                        <?php $intelligence = modelIntelligenceLabel($model); ?>
                        <option value="<?= htmlspecialchars($model) ?>">
                            <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
                <button id="overlay-save-model" class="btn btn-primary">💾 Modell speichern</button>
                <span id="overlay-model-result" style="font-size:.82rem"></span>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

            <!-- Document upload permission -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <span style="font-size:.88rem">Dokument-Upload erlauben <?php admin_help_button('overlay-doc-upload'); ?></span>
                <label style="position:relative;display:inline-block;width:40px;height:22px;cursor:pointer">
                    <input type="checkbox" id="overlay-doc-upload"
                           style="opacity:0;width:0;height:0;position:absolute">
                    <span id="overlay-doc-upload-track"
                          style="position:absolute;inset:0;border-radius:22px;background:var(--surface-alt);
                                 transition:background .2s;display:block"></span>
                    <span id="overlay-doc-upload-thumb"
                          style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;
                                 background:#fff;transition:transform .2s;display:block"></span>
                </label>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:4px">
                <button id="overlay-save-doc-perm" class="btn btn-sm">💾 Berechtigung speichern</button>
                <span id="overlay-doc-perm-result" style="font-size:.82rem"></span>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

            <!-- Password reset (hidden for LDAP users) -->
            <div id="overlay-pw-reset-section">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button id="overlay-reset-pw" class="btn">📧 Passwort-Reset senden</button>
                    <?php admin_help_button('overlay-reset-pw'); ?>
                    <span id="overlay-reset-result" style="font-size:.82rem"></span>
                </div>
            </div>
            <div id="overlay-ldap-notice" style="display:none">
                <p style="font-size:.85rem;color:#3b82f6">
                    🏢 Dieses Konto wird über Active Directory verwaltet. Passwortänderungen erfolgen direkt im AD.
                </p>
            </div>

            <input type="hidden" id="overlay-user-id" value="">
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Create user overlay
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="create-user-overlay" style="display:none;position:fixed;inset:0;z-index:1000;
         background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
         align-items:center;justify-content:center">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
                    padding:28px 32px;max-width:480px;width:calc(100% - 32px);
                    box-shadow:0 24px 60px rgba(0,0,0,.6);position:relative">
            <button id="create-user-overlay-close"
                    style="position:absolute;top:14px;right:16px;background:none;border:none;
                           color:var(--text-muted);font-size:1.2rem;cursor:pointer;line-height:1"
                    aria-label="Schließen">✕</button>

            <h2 style="font-size:1rem;margin-bottom:20px">➕ Neuen Benutzer anlegen</h2>

            <div id="create-user-error" style="display:none;background:rgba(224,92,92,.12);
                 border:1px solid rgba(224,92,92,.4);border-radius:var(--radius);color:var(--error);
                 font-size:.85rem;padding:10px 14px;margin-bottom:16px"></div>

            <div class="form-group">
                <label for="cu-username">Benutzername <span style="color:var(--error)">*</span> <?php admin_help_button('cu-username'); ?></label>
                <input type="text" id="cu-username" name="cu_username"
                       autocomplete="off" placeholder="z. B. max_mustermann"
                       style="width:100%;padding:9px 12px;background:var(--bg);
                              border:1px solid var(--border);border-radius:var(--radius);
                              color:var(--text);font-size:.9rem;font-family:var(--font)">
            </div>

            <div class="form-group">
                <label for="cu-password">Passwort <span style="color:var(--error)">*</span> <?php admin_help_button('cu-password'); ?></label>
                <input type="password" id="cu-password" autocomplete="new-password"
                       style="width:100%;padding:9px 12px;background:var(--bg);
                              border:1px solid var(--border);border-radius:var(--radius);
                              color:var(--text);font-size:.9rem;font-family:var(--font)">
                <div style="height:6px;background:var(--surface-alt);border-radius:3px;margin-top:8px;overflow:hidden">
                    <div id="cu-strength-bar" style="height:100%;width:0%;border-radius:3px;transition:width .25s,background .25s"></div>
                </div>
                <div id="cu-strength-label" style="font-size:.75rem;margin-top:4px;min-height:1.1em;transition:color .25s"></div>
            </div>

            <div class="form-group">
                <label for="cu-password2">Passwort wiederholen <span style="color:var(--error)">*</span></label>
                <input type="password" id="cu-password2" autocomplete="new-password"
                       style="width:100%;padding:9px 12px;background:var(--bg);
                              border:1px solid var(--border);border-radius:var(--radius);
                              color:var(--text);font-size:.9rem;font-family:var(--font)">
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
                <button id="cu-submit" class="btn btn-primary">✔ Benutzer anlegen</button>
                <span id="cu-result" style="font-size:.82rem"></span>
            </div>
        </div>
    </div>

<script>
(function () {
    'use strict';

    const overlay     = document.getElementById('user-overlay');
    const closeBtn    = document.getElementById('user-overlay-close');
    const usernameEl  = document.getElementById('overlay-username');
    const emailEl     = document.getElementById('overlay-email');
    const modelSelect = document.getElementById('overlay-model');
    const userIdInput = document.getElementById('overlay-user-id');
    const saveModelBtn = document.getElementById('overlay-save-model');
    const modelResult  = document.getElementById('overlay-model-result');
    const resetPwBtn   = document.getElementById('overlay-reset-pw');
    const resetResult  = document.getElementById('overlay-reset-result');
    const docUploadChk = document.getElementById('overlay-doc-upload');
    const docUploadTrack = document.getElementById('overlay-doc-upload-track');
    const docUploadThumb = document.getElementById('overlay-doc-upload-thumb');
    const saveDocPermBtn = document.getElementById('overlay-save-doc-perm');
    const docPermResult  = document.getElementById('overlay-doc-perm-result');
    const pwResetSection = document.getElementById('overlay-pw-reset-section');
    const ldapNotice     = document.getElementById('overlay-ldap-notice');
    const roleSelect     = document.getElementById('overlay-role');
    const saveRoleBtn    = document.getElementById('overlay-save-role');
    const roleResult     = document.getElementById('overlay-role-result');

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function updateToggleUI(checked) {
        if (!docUploadTrack || !docUploadThumb) return;
        docUploadTrack.style.background = checked ? 'var(--accent)' : 'var(--surface-alt)';
        docUploadThumb.style.transform  = checked ? 'translateX(18px)' : 'translateX(0)';
    }

    if (docUploadChk) {
        docUploadChk.addEventListener('change', function () {
            updateToggleUI(this.checked);
        });
    }

    function openOverlay(user) {
        if (!overlay) return;
        userIdInput.value      = user.id;
        usernameEl.textContent = user.username;
        emailEl.textContent    = user.email || '(keine E-Mail hinterlegt)';

        // Pre-select current model
        if (modelSelect) {
            modelSelect.value = user.default_model || '';
            // Fallback: if option doesn't exist, add it temporarily
            if (modelSelect.value !== (user.default_model || '')) {
                const opt = document.createElement('option');
                opt.value       = user.default_model;
                opt.textContent = user.default_model + ' (derzeit nicht verfügbar)';
                modelSelect.appendChild(opt);
                modelSelect.value = user.default_model;
            }
        }

        // Pre-select current role
        if (roleSelect) {
            roleSelect.value = user.role || 'user';
        }

        // Set document upload toggle
        if (docUploadChk) {
            docUploadChk.checked = !!user.can_upload_documents;
            updateToggleUI(docUploadChk.checked);
        }

        // Show/hide LDAP notice and password-reset section
        const isLdap = (user.auth_source === 'ldap');
        if (pwResetSection) pwResetSection.style.display = isLdap ? 'none' : '';
        if (ldapNotice)     ldapNotice.style.display     = isLdap ? ''     : 'none';

        if (modelResult)   modelResult.textContent  = '';
        if (resetResult)   resetResult.textContent   = '';
        if (docPermResult) docPermResult.textContent = '';
        if (roleResult)    roleResult.textContent    = '';

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Open overlay on row click
    document.querySelectorAll('.user-row').forEach(row => {
        row.addEventListener('click', function () {
            try {
                const user = JSON.parse(this.dataset.user);
                openOverlay(user);
            } catch (_) {}
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeOverlay();
    });

    // ── Save role ─────────────────────────────────────────────────────────────
    if (saveRoleBtn) {
        saveRoleBtn.addEventListener('click', async function () {
            const userId = userIdInput.value;
            const role   = roleSelect ? roleSelect.value : 'user';

            saveRoleBtn.disabled    = true;
            saveRoleBtn.textContent = '⟳ Speichern …';
            if (roleResult) roleResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_user_role', user_id: parseInt(userId), role, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (roleResult) {
                    roleResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    roleResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
                if (data.ok) {
                    document.querySelectorAll('.user-row').forEach(row => {
                        try {
                            const u = JSON.parse(row.dataset.user);
                            if (u.id === parseInt(userId)) {
                                u.role = role;
                                row.dataset.user = JSON.stringify(u);
                            }
                        } catch (_) {}
                    });
                    // Reflect change immediately; reload to refresh the table's role column/count.
                    setTimeout(() => window.location.reload(), 600);
                }
            } catch (e) {
                if (roleResult) {
                    roleResult.style.color = 'var(--error)';
                    roleResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                saveRoleBtn.disabled    = false;
                saveRoleBtn.textContent = '💾 Rolle speichern';
            }
        });
    }

    // ── Save model ────────────────────────────────────────────────────────────
    if (saveModelBtn) {
        saveModelBtn.addEventListener('click', async function () {
            const userId = userIdInput.value;
            const model  = modelSelect ? modelSelect.value : '';

            saveModelBtn.disabled    = true;
            saveModelBtn.textContent = '⟳ Speichern …';
            if (modelResult) modelResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_user_model', user_id: parseInt(userId), model, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (modelResult) {
                    modelResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    modelResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
            } catch (e) {
                if (modelResult) {
                    modelResult.style.color = 'var(--error)';
                    modelResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                saveModelBtn.disabled    = false;
                saveModelBtn.textContent = '💾 Modell speichern';
            }
        });
    }

    // ── Send password reset ───────────────────────────────────────────────────
    if (resetPwBtn) {
        resetPwBtn.addEventListener('click', async function () {
            const userId = userIdInput.value;

            resetPwBtn.disabled    = true;
            resetPwBtn.textContent = '⟳ Sende …';
            if (resetResult) resetResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'send_password_reset', user_id: parseInt(userId), csrf_token: CSRF }),
                });
                const data = await res.json();
                if (resetResult) {
                    resetResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    resetResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
            } catch (e) {
                if (resetResult) {
                    resetResult.style.color = 'var(--error)';
                    resetResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                resetPwBtn.disabled    = false;
                resetPwBtn.textContent = '📧 Passwort-Reset senden';
            }
        });
    }

    // ── Save document upload permission ───────────────────────────────────────
    if (saveDocPermBtn) {
        saveDocPermBtn.addEventListener('click', async function () {
            const userId  = userIdInput.value;
            const allowed = docUploadChk ? docUploadChk.checked : false;

            saveDocPermBtn.disabled    = true;
            saveDocPermBtn.textContent = '⟳ Speichern …';
            if (docPermResult) docPermResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_user_doc_permission', user_id: parseInt(userId), allowed, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (docPermResult) {
                    docPermResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    docPermResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
                // Update the row data in the table.
                if (data.ok) {
                    document.querySelectorAll('.user-row').forEach(row => {
                        try {
                            const u = JSON.parse(row.dataset.user);
                            if (u.id === parseInt(userId)) {
                                u.can_upload_documents = allowed ? 1 : 0;
                                row.dataset.user = JSON.stringify(u);
                            }
                        } catch (_) {}
                    });
                }
            } catch (e) {
                if (docPermResult) {
                    docPermResult.style.color = 'var(--error)';
                    docPermResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                saveDocPermBtn.disabled    = false;
                saveDocPermBtn.textContent = '💾 Berechtigung speichern';
            }
        });
    }
})();
</script>

<script>
// ── Create user overlay ────────────────────────────────────────────────────────
(function () {
    'use strict';

    const overlay      = document.getElementById('create-user-overlay');
    const openBtn      = document.getElementById('create-user-btn');
    const closeBtn     = document.getElementById('create-user-overlay-close');
    const usernameEl   = document.getElementById('cu-username');
    const passwordEl   = document.getElementById('cu-password');
    const password2El  = document.getElementById('cu-password2');
    const strengthBar  = document.getElementById('cu-strength-bar');
    const strengthLbl  = document.getElementById('cu-strength-label');
    const submitBtn    = document.getElementById('cu-submit');
    const resultEl     = document.getElementById('cu-result');
    const errorBox     = document.getElementById('create-user-error');

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const pwRules = {
        len:     p => p.length >= 8,
        upper:   p => /[A-Z]/.test(p),
        lower:   p => /[a-z]/.test(p),
        digit:   p => /[0-9]/.test(p),
        special: p => /[#?!@$%^&*\-]/.test(p),
    };
    const pwLevels = [
        { label: '',             color: 'transparent', width:  '0%' },
        { label: 'Sehr schwach', color: '#ef4444',     width: '10%' },
        { label: 'Schwach',      color: '#f97316',     width: '25%' },
        { label: 'Mittel',       color: '#f59e0b',     width: '50%' },
        { label: 'Stark',        color: '#22c55e',     width: '75%' },
        { label: 'Sehr stark',   color: '#16a34a',     width: '100%' },
    ];

    function evalPassword(p) {
        return Object.values(pwRules).filter(fn => fn(p)).length;
    }

    function updateStrength() {
        const p     = passwordEl ? passwordEl.value : '';
        const score = evalPassword(p);
        const lvl   = p.length === 0 ? pwLevels[0] : (pwLevels[score] || pwLevels[pwLevels.length - 1]);
        if (strengthBar) { strengthBar.style.width = lvl.width; strengthBar.style.background = lvl.color; }
        if (strengthLbl) { strengthLbl.textContent = lvl.label; strengthLbl.style.color = lvl.color; }
    }

    function resetForm() {
        if (usernameEl)  usernameEl.value  = '';
        if (passwordEl)  passwordEl.value  = '';
        if (password2El) password2El.value = '';
        if (resultEl)    resultEl.textContent = '';
        if (errorBox)  { errorBox.style.display = 'none'; errorBox.textContent = ''; }
        updateStrength();
    }

    function openOverlay() {
        if (!overlay) return;
        resetForm();
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (usernameEl) usernameEl.focus();
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    if (openBtn)  openBtn.addEventListener('click', openOverlay);
    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.style.display !== 'none') closeOverlay();
    });

    if (passwordEl) passwordEl.addEventListener('input', updateStrength);

    if (submitBtn) {
        submitBtn.addEventListener('click', async function () {
            const username  = usernameEl  ? usernameEl.value.trim()  : '';
            const password  = passwordEl  ? passwordEl.value          : '';
            const password2 = password2El ? password2El.value         : '';

            if (errorBox) { errorBox.style.display = 'none'; errorBox.textContent = ''; }
            if (resultEl) resultEl.textContent = '';

            if (!username || !password) {
                if (errorBox) { errorBox.textContent = 'Bitte alle Pflichtfelder ausfüllen.'; errorBox.style.display = 'block'; }
                return;
            }
            if (evalPassword(password) < 5) {
                if (errorBox) { errorBox.textContent = 'Das Passwort erfüllt nicht die Sicherheitsanforderungen.'; errorBox.style.display = 'block'; }
                return;
            }
            if (password !== password2) {
                if (errorBox) { errorBox.textContent = 'Die Passwörter stimmen nicht überein.'; errorBox.style.display = 'block'; }
                return;
            }

            submitBtn.disabled    = true;
            submitBtn.textContent = '⟳ Anlegen …';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create_user', username, password, password2, csrf_token: CSRF }),
                });
                const data = await res.json();

                if (data.ok) {
                    // Add new row to the users table
                    const tbody = document.querySelector('#users-card table tbody');
                    if (tbody && data.user) {
                        const u = data.user;
                        const tr = document.createElement('tr');
                        tr.className = 'user-row';
                        tr.style.cursor = 'pointer';
                        tr.title = 'Klicken für Benutzerdetails';
                        tr.dataset.user = JSON.stringify({
                            id: u.id, username: u.username, email: u.email || '',
                            email_verified: u.email_verified, default_model: u.default_model || '',
                        });
                        tr.innerHTML =
                            '<td>' + u.username.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</td>' +
                            '<td><span style="color:var(--text-muted)">–</span></td>' +
                            '<td><span style="color:var(--success)">✓</span></td>' +
                            '<td><span style="color:var(--text-muted);font-size:.8rem">Standard</span></td>' +
                            '<td>' + (u.created_at || '').replace(/&/g,'&amp;') + '</td>' +
                            '<td><span style="color:var(--text-muted)">noch nie</span></td>';
                        tbody.appendChild(tr);

                        // Make the new row clickable (same as existing rows)
                        tr.addEventListener('click', function () {
                            try {
                                const user = JSON.parse(this.dataset.user);
                                const editOverlay = document.getElementById('user-overlay');
                                if (!editOverlay) return;
                                document.getElementById('overlay-user-id').value      = user.id;
                                document.getElementById('overlay-username').textContent = user.username;
                                document.getElementById('overlay-email').textContent    = user.email || '(keine E-Mail hinterlegt)';
                                const ms = document.getElementById('overlay-model');
                                if (ms) ms.value = user.default_model || '';
                                const mr = document.getElementById('overlay-model-result');
                                const rr = document.getElementById('overlay-reset-result');
                                if (mr) mr.textContent = '';
                                if (rr) rr.textContent = '';
                                editOverlay.style.display = 'flex';
                                document.body.style.overflow = 'hidden';
                            } catch (_) {}
                        });
                    }
                    closeOverlay();
                } else {
                    if (errorBox) { errorBox.textContent = data.message || 'Unbekannter Fehler.'; errorBox.style.display = 'block'; }
                }
            } catch (err) {
                if (errorBox) { errorBox.textContent = '✗ Netzwerkfehler: ' + err.message; errorBox.style.display = 'block'; }
            } finally {
                submitBtn.disabled    = false;
                submitBtn.textContent = '✔ Benutzer anlegen';
            }
        });
    }
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
