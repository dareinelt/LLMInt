<?php

/**
 * register.php
 *
 * User self-registration with e-mail verification.
 * Password policy:
 *   - at least 8 characters
 *   - at least one uppercase letter
 *   - at least one lowercase letter
 *   - at least one digit
 *   - at least one special character (#?!@$%^&*-)
 */

session_start();

// Already logged in?
if (isset($_SESSION['admin_user'])) {
    header('Location: admin/index.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/mailer.php';

$error   = '';
$success = '';

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['reg_csrf'])) {
    $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['reg_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $error = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $password2 = $_POST['password2']   ?? '';

        // ── Validation ────────────────────────────────────────────────────────
        if ($email === '' || $password === '') {
            $error = 'Bitte alle Felder ausfüllen.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        } elseif ($password !== $password2) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } elseif (!validatePassword($password)) {
            $error = 'Das Passwort erfüllt nicht die Sicherheitsanforderungen.';
        } else {
            try {
                $db = getDb();

                // Check duplicate
                $st = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                $st->execute([$email]);
                if ((int) $st->fetchColumn() > 0) {
                    $error = 'Diese E-Mail-Adresse ist bereits registriert.';
                } else {
                    // Generate username from e-mail local part (unique)
                    $baseUser = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]);
                    if ($baseUser === '') {
                        $baseUser = 'user';
                    }
                    $username = $baseUser;
                    $suffix   = 1;
                    while (true) {
                        $stU = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                        $stU->execute([$username]);
                        if ((int) $stU->fetchColumn() === 0) {
                            break;
                        }
                        $username = $baseUser . $suffix++;
                    }

                    $hash    = password_hash($password, PASSWORD_BCRYPT);
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

                    // Default model for new users (configured by admin)
                    $newUserModel = getSetting('new_user_default_model', '');

                    $db->prepare(
                        'INSERT INTO users
                            (username, password_hash, email, email_verified,
                             email_verification_token, verification_expires, default_model)
                         VALUES (?, ?, ?, 0, ?, ?, ?)'
                    )->execute([$username, $hash, $email, $token, $expires, $newUserModel]);

                    // Send verification e-mail
                    $verifyUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\')
                        . '/api/verify_email.php?token=' . urlencode($token);

                    $siteName = getSetting('smtp_from_name', 'LLMInt');

                    $registrationEmailText = getSetting('registration_email_text', '');
                    if ($registrationEmailText === '') {
                        $registrationEmailText = 'danke für Deine Registrierung bei {sitename}.';
                    }
                    $registrationEmailText = str_replace('{sitename}', $siteName, $registrationEmailText);

                    $textBody = "Hallo {$username},\r\n\r\n"
                        . "{$registrationEmailText}\r\n\r\n"
                        . "Bitte klicke auf den folgenden Link, um Deine E-Mail-Adresse zu bestätigen:\r\n"
                        . "{$verifyUrl}\r\n\r\n"
                        . "Der Link ist 24 Stunden gültig.\r\n\r\n"
                        . "Falls Du diese Registrierung nicht vorgenommen hast, ignoriere bitte diese E-Mail.\r\n\r\n"
                        . "Viele Grüße,\r\nDein {$siteName}-Team";

                    $htmlBody = '<p>Hallo <strong>' . htmlspecialchars($username) . '</strong>,</p>'
                        . '<p>' . nl2br(htmlspecialchars($registrationEmailText)) . '</p>'
                        . '<p>Bitte bestätige Deine E-Mail-Adresse durch Klick auf den Button:</p>'
                        . '<p><a href="' . htmlspecialchars($verifyUrl) . '" '
                        . 'style="display:inline-block;padding:10px 20px;background:#6c63ff;color:#fff;'
                        . 'text-decoration:none;border-radius:8px;font-weight:600;">E-Mail bestätigen</a></p>'
                        . '<p>Oder kopiere diesen Link in deinen Browser:<br>'
                        . '<a href="' . htmlspecialchars($verifyUrl) . '">' . htmlspecialchars($verifyUrl) . '</a></p>'
                        . '<p>Der Link ist 24 Stunden gültig.</p>'
                        . '<p>Viele Grüße,<br>Dein ' . htmlspecialchars($siteName) . '-Team</p>';

                    try {
                        sendMail($email, $username, "Bitte bestätige deine E-Mail-Adresse", $textBody, $htmlBody);
                        $success = 'Registrierung erfolgreich! Bitte prüfe dein Postfach und bestätige deine E-Mail-Adresse.';
                    } catch (Throwable $e) {
                        // Registration succeeded; e-mail failed – inform user
                        $success = 'Registrierung gespeichert, aber die Bestätigungs-E-Mail konnte nicht gesendet werden. '
                            . 'Bitte wende dich an den Administrator.';
                    }

                    // Regenerate CSRF to prevent double-submit
                    $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));
                    $csrfToken = $_SESSION['reg_csrf'];
                }
            } catch (PDOException $e) {
                $error = 'Datenbankfehler. Bitte versuche es später erneut.';
            }
        }
    }
}

/**
 * Validate password against the security policy:
 *  - min 8 chars
 *  - uppercase, lowercase, digit, special char
 */
function validatePassword(string $pass): bool
{
    if (strlen($pass) < 8)                        { return false; }
    if (!preg_match('/[A-Z]/', $pass))             { return false; }
    if (!preg_match('/[a-z]/', $pass))             { return false; }
    if (!preg_match('/[0-9]/', $pass))             { return false; }
    if (!preg_match('/[#?!@$%^&*\-]/', $pass))    { return false; }
    return true;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrieren – LLMInt</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #212121;
            --surface:     #2f2f2f;
            --surface-alt: #3a3a3a;
            --border:      rgba(255,255,255,.08);
            --accent:      #6c63ff;
            --accent-dark: #5249cc;
            --text:        #ececf1;
            --text-muted:  #8e8ea0;
            --error:       #ef4444;
            --success:     #22c55e;
            --warning:     #f59e0b;
            --radius:      12px;
            --font:        ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 36px 40px;
            width: 100%;
            max-width: 420px;
        }

        .card h1 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: .8rem;
            color: var(--text-muted);
            margin-bottom: 28px;
        }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            font-size: .82rem;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 9px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-size: .9rem;
            font-family: var(--font);
        }

        input:focus { outline: none; border-color: var(--accent); }

        /* ── Password strength meter ─────────────────────────────── */
        .strength-bar-wrap {
            height: 6px;
            background: var(--surface-alt);
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 3px;
            transition: width .25s ease, background .25s ease;
        }

        .strength-label {
            font-size: .75rem;
            margin-top: 4px;
            min-height: 1.1em;
            transition: color .25s;
        }

        .hint {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.5;
        }

        .hint ul {
            list-style: none;
            margin-top: 4px;
        }

        .hint ul li::before { content: '• '; }

        .msg-ok {
            background: rgba(76,175,125,.12);
            border: 1px solid rgba(76,175,125,.4);
            border-radius: var(--radius);
            color: var(--success);
            font-size: .85rem;
            padding: 10px 14px;
            margin-bottom: 18px;
        }

        .msg-err {
            background: rgba(224,92,92,.12);
            border: 1px solid rgba(224,92,92,.4);
            border-radius: var(--radius);
            color: var(--error);
            font-size: .85rem;
            padding: 10px 14px;
            margin-bottom: 18px;
        }

        .btn-primary {
            width: 100%;
            padding: 10px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: .9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-primary:hover  { background: var(--accent-dark); }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; }

        .link-row {
            display: flex;
            justify-content: space-between;
            margin-top: 16px;
            font-size: .8rem;
        }

        .link-row a { color: var(--text-muted); text-decoration: none; }
        .link-row a:hover { color: var(--text); }
    </style>
</head>
<body>
<div class="card">
    <h1>✍ Registrieren</h1>
    <p class="subtitle">Neues Benutzerkonto erstellen</p>

    <?php if ($success !== ''): ?>
        <div class="msg-ok">✓ <?= htmlspecialchars($success) ?></div>
    <?php elseif ($error !== ''): ?>
        <div class="msg-err">✗ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success === ''): ?>
    <form method="POST" id="reg-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
            <label for="email">E-Mail-Adresse</label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   autocomplete="email" required autofocus>
        </div>

        <div class="form-group">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password"
                   autocomplete="new-password" required>
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

        <button type="submit" class="btn-primary" id="submit-btn">Konto erstellen</button>
    </form>
    <?php endif; ?>

    <div class="link-row">
        <a href="index.php">← Zurück zum Chat</a>
        <a href="admin/login.php">Anmelden</a>
    </div>
</div>

<script>
(function () {
    'use strict';

    const passwordEl = document.getElementById('password');
    const bar        = document.getElementById('strength-bar');
    const label      = document.getElementById('strength-label');
    const submitBtn  = document.getElementById('submit-btn');

    const rules = {
        len:     { el: document.getElementById('rule-len'),     test: p => p.length >= 8 },
        upper:   { el: document.getElementById('rule-upper'),   test: p => /[A-Z]/.test(p) },
        lower:   { el: document.getElementById('rule-lower'),   test: p => /[a-z]/.test(p) },
        digit:   { el: document.getElementById('rule-digit'),   test: p => /[0-9]/.test(p) },
        special: { el: document.getElementById('rule-special'), test: p => /[#?!@$%^&*\-]/.test(p) },
    };

    const levels = [
        { max: 0,  label: '',             color: 'transparent', width:  '0%' },
        { max: 1,  label: 'Sehr schwach', color: '#ef4444',     width: '10%' },
        { max: 2,  label: 'Schwach',      color: '#f97316',     width: '25%' },
        { max: 3,  label: 'Mittel',       color: '#f59e0b',     width: '50%' },
        { max: 4,  label: 'Stark',        color: '#22c55e',     width: '75%' },
        { max: 5,  label: 'Sehr stark',   color: '#16a34a',     width: '100%' },
    ];

    function evaluate(p) {
        let score = 0;
        for (const [, rule] of Object.entries(rules)) {
            const ok = rule.test(p);
            if (ok) { score++; }
            if (rule.el) {
                rule.el.style.color = p.length > 0 ? (ok ? '#22c55e' : '#ef4444') : '';
            }
        }
        return score;
    }

    function update() {
        const p     = passwordEl.value;
        const score = evaluate(p);
        const lvl   = p.length === 0 ? levels[0] : levels[score] || levels[levels.length - 1];

        bar.style.width      = lvl.width;
        bar.style.background = lvl.color;
        label.textContent    = lvl.label;
        label.style.color    = lvl.color;

        // Only allow submit when all rules pass (score === 5)
        if (submitBtn) {
            submitBtn.disabled = p.length > 0 && score < 5;
        }
    }

    if (passwordEl) {
        passwordEl.addEventListener('input', update);
        update();
    }
})();
</script>
</body>
</html>
