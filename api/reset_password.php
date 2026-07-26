<?php

/**
 * api/reset_password.php
 *
 * Token-based password reset form.
 * GET  ?token=…  → shows the form
 * POST            → validates and saves the new password,
 *                   clears requires_password_change flag
 *
 * Enforces the same password policy as register.php.
 */

session_start();

require_once __DIR__ . '/../db.php';

$token  = trim($_GET['token'] ?? trim($_POST['token'] ?? ''));
$error  = '';
$success = '';

// ── CSRF for the form ─────────────────────────────────────────────────────────
if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['reset_csrf'];

// ── Validate token ────────────────────────────────────────────────────────────
$user = null;
if ($token !== '') {
    try {
        $db   = getDb();
        $stmt = $db->prepare(
            'SELECT id, username, email, password_reset_expires
               FROM users
              WHERE password_reset_token = ?
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch() ?: null;

        if ($user && $user['password_reset_expires'] !== null
            && strtotime($user['password_reset_expires']) < time()) {
            $user  = null;
            $error = 'Dieser Link ist abgelaufen. Bitte fordere einen neuen an.';
        }
    } catch (PDOException $e) {
        $error = 'Datenbankfehler.';
    }
}

if ($token === '' || ($user === null && $error === '')) {
    $error = 'Ungültiger oder fehlender Reset-Token.';
}

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $error = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $newPass  = $_POST['password']  ?? '';
        $newPass2 = $_POST['password2'] ?? '';

        if ($newPass === '') {
            $error = 'Bitte ein neues Passwort eingeben.';
        } elseif ($newPass !== $newPass2) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } elseif (!validatePassword($newPass)) {
            $error = 'Das Passwort erfüllt nicht die Sicherheitsanforderungen.';
        } else {
            try {
                $db   = getDb();
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $db->prepare(
                    'UPDATE users
                        SET password_hash = ?,
                            password_reset_token = NULL,
                            password_reset_expires = NULL,
                            requires_password_change = 0
                      WHERE id = ?'
                )->execute([$hash, $user['id']]);

                $success = 'Dein Passwort wurde erfolgreich geändert. Du kannst dich jetzt anmelden.';
                // Regenerate CSRF
                $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['reset_csrf'];
            } catch (PDOException $e) {
                $error = 'Datenbankfehler. Bitte versuche es später erneut.';
            }
        }
    }
}

/**
 * Same policy as register.php.
 */
function validatePassword(string $pass): bool
{
    if (strlen($pass) < 8)                     { return false; }
    if (!preg_match('/[A-Z]/', $pass))          { return false; }
    if (!preg_match('/[a-z]/', $pass))          { return false; }
    if (!preg_match('/[0-9]/', $pass))          { return false; }
    if (!preg_match('/[#?!@$%^&*\-]/', $pass)) { return false; }
    return true;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort zurücksetzen – LLMInt</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #212121; --surface: #2f2f2f; --surface-alt: #3a3a3a;
            --border: rgba(255,255,255,.08); --accent: #6c63ff; --accent-dark: #5249cc;
            --text: #ececf1; --text-muted: #8e8ea0;
            --error: #ef4444; --success: #22c55e;
            --radius: 12px;
            --font: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        body { font-family: var(--font); background: var(--bg); color: var(--text);
               min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: var(--surface); border: 1px solid var(--border);
                border-radius: var(--radius); padding: 36px 40px; max-width: 420px; width: 100%; }
        .card h1 { font-size: 1.3rem; font-weight: 600; margin-bottom: 6px; }
        .subtitle { font-size: .8rem; color: var(--text-muted); margin-bottom: 28px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: .82rem; color: var(--text-muted); margin-bottom: 6px; }
        input[type="password"] { width: 100%; padding: 9px 12px; background: var(--bg);
            border: 1px solid var(--border); border-radius: var(--radius);
            color: var(--text); font-size: .9rem; font-family: var(--font); }
        input:focus { outline: none; border-color: var(--accent); }
        .strength-bar-wrap { height: 6px; background: var(--surface-alt); border-radius: 3px; margin-top: 8px; overflow: hidden; }
        .strength-bar { height: 100%; width: 0%; border-radius: 3px; transition: width .25s ease, background .25s ease; }
        .strength-label { font-size: .75rem; margin-top: 4px; min-height: 1.1em; transition: color .25s; }
        .hint { font-size: .75rem; color: var(--text-muted); margin-top: 6px; line-height: 1.5; }
        .hint ul { list-style: none; margin-top: 4px; }
        .hint ul li::before { content: '• '; }
        .msg-ok { background: rgba(76,175,125,.12); border: 1px solid rgba(76,175,125,.4);
                  border-radius: var(--radius); color: var(--success); font-size: .85rem; padding: 10px 14px; margin-bottom: 18px; }
        .msg-err { background: rgba(224,92,92,.12); border: 1px solid rgba(224,92,92,.4);
                   border-radius: var(--radius); color: var(--error); font-size: .85rem; padding: 10px 14px; margin-bottom: 18px; }
        .btn-primary { width: 100%; padding: 10px; background: var(--accent); color: #fff;
                       border: none; border-radius: var(--radius); font-size: .9rem;
                       font-weight: 500; cursor: pointer; transition: background .15s; }
        .btn-primary:hover { background: var(--accent-dark); }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
        .link-row { margin-top: 16px; font-size: .8rem; }
        .link-row a { color: var(--text-muted); text-decoration: none; }
        .link-row a:hover { color: var(--text); }
    </style>
</head>
<body>
<div class="card">
    <h1>🔑 Passwort zurücksetzen</h1>
    <?php if ($user !== null): ?>
        <p class="subtitle">Für das Konto <strong><?= htmlspecialchars($user['email']) ?></strong></p>
    <?php else: ?>
        <p class="subtitle">Passwort zurücksetzen</p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="msg-ok">✓ <?= htmlspecialchars($success) ?></div>
        <div class="link-row"><a href="../admin/login.php">→ Zur Anmeldung</a></div>
    <?php elseif ($error !== '' && $user === null): ?>
        <div class="msg-err">✗ <?= htmlspecialchars($error) ?></div>
        <div class="link-row"><a href="../admin/login.php">← Zur Anmeldung</a></div>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="msg-err">✗ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label for="password">Neues Passwort</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required autofocus>
                <div class="strength-bar-wrap">
                    <div class="strength-bar" id="strength-bar"></div>
                </div>
                <div class="strength-label" id="strength-label"></div>
                <div class="hint">
                    Das Passwort muss enthalten:
                    <ul>
                        <li id="rule-len">Mindestens 8 Zeichen</li>
                        <li id="rule-upper">Einen Großbuchstaben (A-Z)</li>
                        <li id="rule-lower">Einen Kleinbuchstaben (a-z)</li>
                        <li id="rule-digit">Eine Ziffer (0-9)</li>
                        <li id="rule-special">Ein Sonderzeichen (#?!@$%^&amp;*-)</li>
                    </ul>
                </div>
            </div>

            <div class="form-group">
                <label for="password2">Passwort wiederholen</label>
                <input type="password" id="password2" name="password2"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn-primary" id="submit-btn">Passwort speichern</button>
        </form>
        <div class="link-row"><a href="../admin/login.php">← Zur Anmeldung</a></div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    const passwordEl = document.getElementById('password');
    if (!passwordEl) return;
    const bar     = document.getElementById('strength-bar');
    const label   = document.getElementById('strength-label');
    const submitBtn = document.getElementById('submit-btn');

    const rules = {
        len:     { el: document.getElementById('rule-len'),     test: p => p.length >= 8 },
        upper:   { el: document.getElementById('rule-upper'),   test: p => /[A-Z]/.test(p) },
        lower:   { el: document.getElementById('rule-lower'),   test: p => /[a-z]/.test(p) },
        digit:   { el: document.getElementById('rule-digit'),   test: p => /[0-9]/.test(p) },
        special: { el: document.getElementById('rule-special'), test: p => /[#?!@$%^&*\-]/.test(p) },
    };
    const levels = [
        { label: '',             color: 'transparent', width:  '0%' },
        { label: 'Sehr schwach', color: '#ef4444',     width: '10%' },
        { label: 'Schwach',      color: '#f97316',     width: '25%' },
        { label: 'Mittel',       color: '#f59e0b',     width: '50%' },
        { label: 'Stark',        color: '#22c55e',     width: '75%' },
        { label: 'Sehr stark',   color: '#16a34a',     width: '100%' },
    ];
    function update() {
        const p = passwordEl.value;
        let score = 0;
        for (const [, rule] of Object.entries(rules)) {
            const ok = rule.test(p);
            if (ok) score++;
            if (rule.el) rule.el.style.color = p.length > 0 ? (ok ? '#22c55e' : '#ef4444') : '';
        }
        const lvl = p.length === 0 ? levels[0] : levels[score] || levels[levels.length - 1];
        bar.style.width = lvl.width;
        bar.style.background = lvl.color;
        label.textContent = lvl.label;
        label.style.color = lvl.color;
        if (submitBtn) submitBtn.disabled = p.length > 0 && score < 5;
    }
    passwordEl.addEventListener('input', update);
    update();
})();
</script>
</body>
</html>
