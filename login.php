<?php

/**
 * login.php
 *
 * User login page for the main chat interface.
 * On success the user is redirected back to index.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Already logged in?
if (isset($_SESSION['admin_user'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ldap_auth.php';

$error = '';

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['login_csrf'];

// ── SSO: auto-login via REMOTE_USER (Windows Authentication / Kerberos) ───────
if (ldapSsoEnabled()) {
    $ssoUser = ldapSsoUsername();
    if ($ssoUser !== '') {
        $userId = ldapProvisionUser(['username' => $ssoUser, 'dn' => '', 'email' => '', 'display_name' => '']);
        if ($userId !== null) {
            session_regenerate_id(true);
            $_SESSION['admin_user'] = $ssoUser;
            $_SESSION['admin_id']   = $userId;
            $_SESSION['requires_password_change'] = false;
            header('Location: index.php');
            exit;
        }
        // Conflict with a local account of the same name – fall through to manual login
        $error = 'SSO-Anmeldung fehlgeschlagen: Benutzername wird bereits als lokales Konto verwendet.';
    }
}

// ── Form POST ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $error = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Bitte Benutzername und Passwort eingeben.';
        } else {
            $authenticated = false;

            // ── LDAP auth (tried first when enabled) ──────────────────────────
            if (ldapEnabled()) {
                try {
                    $adInfo = ldapAuthenticate($username, $password);
                    if ($adInfo !== null) {
                        $userId = ldapProvisionUser($adInfo);
                        if ($userId !== null) {
                            session_regenerate_id(true);
                            $_SESSION['admin_user'] = $adInfo['username'];
                            $_SESSION['admin_id']   = $userId;
                            $_SESSION['requires_password_change'] = false;
                            header('Location: index.php');
                            exit;
                        }
                        // Conflict: a local account already has this username
                        $error         = 'AD-Benutzername ist bereits als lokales Konto vergeben. Bitte einen Administrator kontaktieren.';
                        $authenticated = true; // AD was fine, but provisioning blocked
                    }
                } catch (RuntimeException $e) {
                    // LDAP misconfigured or unreachable – log and fall through to local auth
                    error_log('[LDAP] ldapAuthenticate failed: ' . $e->getMessage());
                }
            }

            // ── Local auth (always tried when LDAP did not conclusively succeed) ──
            if (!$authenticated) {
                try {
                    $stmt = getDb()->prepare(
                        'SELECT id, username, password_hash, requires_password_change, auth_source
                           FROM users WHERE username = ? LIMIT 1'
                    );
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    if ($user && ($user['auth_source'] ?? 'local') === 'ldap') {
                        // AD user trying to log in with a password: reject with helpful message
                        $error = 'Dieses Konto wird über Active Directory verwaltet. Bitte das AD-Passwort verwenden.';
                    } elseif ($user && password_verify($password, $user['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['admin_user'] = $user['username'];
                        $_SESSION['admin_id']   = (int) $user['id'];
                        $_SESSION['requires_password_change'] = !empty($user['requires_password_change']);

                        getDb()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
                               ->execute([$user['id']]);

                        header('Location: index.php');
                        exit;
                    } else {
                        $error = 'Ungültige Anmeldedaten.';
                    }
                } catch (PDOException $e) {
                    $error = 'Datenbankfehler. Bitte zuerst setup.php ausführen.';
                }
            }
        }
    }
}

$ldapActive = ldapEnabled();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden – KHWF KI</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #212121;
            --surface:     #2f2f2f;
            --border:      rgba(255,255,255,.08);
            --accent:      #6c63ff;
            --accent-dark: #5249cc;
            --text:        #ececf1;
            --text-muted:  #8e8ea0;
            --error:       #ef4444;
            --info:        #3b82f6;
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
        }

        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 36px 40px;
            width: 100%;
            max-width: 380px;
        }

        .login-card h1 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .login-card .subtitle {
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

        input[type="text"],
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

        .error-msg {
            background: rgba(224,92,92,.12);
            border: 1px solid rgba(224,92,92,.4);
            border-radius: var(--radius);
            color: var(--error);
            font-size: .82rem;
            padding: 8px 12px;
            margin-bottom: 18px;
        }

        .info-msg {
            background: rgba(59,130,246,.1);
            border: 1px solid rgba(59,130,246,.35);
            border-radius: var(--radius);
            color: var(--info);
            font-size: .82rem;
            padding: 8px 12px;
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

        .btn-primary:hover { background: var(--accent-dark); }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: .8rem;
            color: var(--text-muted);
            text-decoration: none;
        }

        .back-link:hover { color: var(--text); }

        .register-link {
            display: block;
            text-align: center;
            margin-top: 8px;
            font-size: .8rem;
            color: var(--text-muted);
            text-decoration: none;
        }

        .register-link:hover { color: var(--text); }
    </style>
</head>
<body>
<div class="login-card">
    <h1>🔐 Anmelden</h1>
    <p class="subtitle">KHWF KI – Chat</p>

    <?php if ($error !== ''): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($ldapActive && $error === ''): ?>
        <div class="info-msg">🏢 AD-Anmeldung aktiv – Windows-Benutzername und AD-Passwort verwenden.</div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <div class="form-group">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" autofocus required>
        </div>
        <div class="form-group">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn-primary">Anmelden</button>
    </form>

    <a class="register-link" href="register.php">✍ Noch kein Konto? Jetzt registrieren</a>
    <a class="back-link" href="index.php">← Ohne Anmeldung fortfahren</a>
</div>
</body>
</html>

