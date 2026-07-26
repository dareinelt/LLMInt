<?php

/**
 * api/verify_email.php
 *
 * Handles the e-mail verification link sent during registration.
 * Validates the token, marks the user's e-mail as verified, and
 * redirects the user to the login page with a success message.
 */

require_once __DIR__ . '/../db.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    showPage('Ungültiger Link', 'Der Bestätigungslink ist ungültig.', false);
    exit;
}

try {
    $db   = getDb();
    $stmt = $db->prepare(
        'SELECT id, email_verified, verification_expires
           FROM users
          WHERE email_verification_token = ?
          LIMIT 1'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        showPage('Ungültiger Link', 'Dieser Bestätigungslink ist ungültig oder wurde bereits verwendet.', false);
        exit;
    }

    if ((int) $user['email_verified'] === 1) {
        showPage('Bereits bestätigt', 'Deine E-Mail-Adresse wurde bereits bestätigt. Du kannst dich jetzt anmelden.', true);
        exit;
    }

    if ($user['verification_expires'] !== null && strtotime($user['verification_expires']) < time()) {
        showPage('Link abgelaufen', 'Der Bestätigungslink ist abgelaufen. Bitte registriere dich erneut oder wende dich an den Administrator.', false);
        exit;
    }

    // Mark as verified and clear the token
    $db->prepare(
        'UPDATE users
            SET email_verified = 1,
                email_verification_token = NULL,
                verification_expires = NULL
          WHERE id = ?'
    )->execute([$user['id']]);

    showPage('E-Mail bestätigt', 'Deine E-Mail-Adresse wurde erfolgreich bestätigt. Du kannst dich jetzt anmelden.', true);

} catch (PDOException $e) {
    showPage('Fehler', 'Es ist ein Datenbankfehler aufgetreten. Bitte versuche es später erneut.', false);
}

// ── Helper ────────────────────────────────────────────────────────────────────

function showPage(string $title, string $message, bool $success): void
{
    $color    = $success ? '#22c55e' : '#ef4444';
    $bgColor  = $success ? 'rgba(34,197,94,.12)'  : 'rgba(239,68,68,.12)';
    $bdColor  = $success ? 'rgba(34,197,94,.4)'   : 'rgba(239,68,68,.4)';
    $icon     = $success ? '✓' : '✗';
    $loginUrl = '../admin/login.php';
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> – LLMInt</title>
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            :root {
                --bg: #212121; --surface: #2f2f2f; --border: rgba(255,255,255,.08);
                --accent: #6c63ff; --text: #ececf1; --text-muted: #8e8ea0;
                --radius: 12px;
                --font: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            }
            body { font-family: var(--font); background: var(--bg); color: var(--text);
                   min-height: 100dvh; display: flex; align-items: center; justify-content: center; }
            .card { background: var(--surface); border: 1px solid var(--border);
                    border-radius: var(--radius); padding: 36px 40px; max-width: 420px; width: 100%; }
            .msg { background: <?= $bgColor ?>; border: 1px solid <?= $bdColor ?>;
                   border-radius: var(--radius); color: <?= $color ?>; padding: 12px 16px;
                   font-size: .9rem; margin-bottom: 20px; }
            h1 { font-size: 1.2rem; margin-bottom: 16px; }
            a { color: var(--accent); text-decoration: none; font-size: .85rem; }
            a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
    <div class="card">
        <h1><?= htmlspecialchars($title) ?></h1>
        <div class="msg"><?= $icon ?> <?= htmlspecialchars($message) ?></div>
        <a href="<?= htmlspecialchars($loginUrl) ?>">→ Zur Anmeldung</a>
    </div>
    </body>
    </html>
    <?php
}
