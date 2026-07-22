<?php

/**
 * admin/index.php
 *
 * Protected admin dashboard.
 * Allows managing the LM Studio endpoint / timeout settings
 * and changing the admin account password.
 * All data is persisted in the MySQL database.
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';

$db = getDb();

// ── Load current values ───────────────────────────────────────────────────────

$currentBaseUrl    = getSetting('lmstudio_base_url', 'http://localhost:1234/v1');
$currentTimeout    = getSetting('lmstudio_timeout', '120');
$currentDefaultModel = getSetting('default_model', '');

// ── Generate CSRF token ───────────────────────────────────────────────────────

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Handle POST actions ───────────────────────────────────────────────────────

$flashOk    = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $flashError = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_settings') {
            $newUrl          = trim($_POST['lmstudio_base_url'] ?? '');
            $newTimeout      = (int) ($_POST['lmstudio_timeout'] ?? 120);
            $newDefaultModel = trim($_POST['default_model'] ?? '');

            if ($newUrl === '') {
                $flashError = 'Die Endpunkt-URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } elseif ($newDefaultModel === '') {
                $flashError = 'Bitte ein Standardmodell auswählen.';
            } else {
                setSetting('lmstudio_base_url', rtrim($newUrl, '/'));
                setSetting('lmstudio_timeout',  (string) $newTimeout);
                setSetting('default_model',     $newDefaultModel);
                $currentBaseUrl       = rtrim($newUrl, '/');
                $currentTimeout       = (string) $newTimeout;
                $currentDefaultModel  = $newDefaultModel;
                $flashOk = 'Einstellungen gespeichert.';
            }

        } elseif ($action === 'change_password') {
            $oldPass  = $_POST['old_password']      ?? '';
            $newPass  = $_POST['new_password']      ?? '';
            $newPass2 = $_POST['new_password_confirm'] ?? '';

            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['admin_id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($oldPass, $row['password_hash'])) {
                $flashError = 'Aktuelles Passwort ist falsch.';
            } elseif ($newPass === '') {
                $flashError = 'Das neue Passwort darf nicht leer sein.';
            } elseif ($newPass !== $newPass2) {
                $flashError = 'Die neuen Passwörter stimmen nicht überein.';
            } elseif (strlen($newPass) < 8) {
                $flashError = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                   ->execute([$hash, $_SESSION['admin_id']]);
                $flashOk = 'Passwort erfolgreich geändert.';
            }
        }
    }
}

// ── Load user list for the "User info" section ────────────────────────────────

$users = $db->query(
    'SELECT id, username, email, created_at, last_login FROM users ORDER BY id'
)->fetchAll();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – LM Studio Chat</title>
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
            --radius:      12px;
            --font:        ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ──────────────────────────────────────────────── */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 24px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        header h1 { font-size: 1.1rem; font-weight: 600; }

        .header-right {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: .82rem;
            color: var(--text-muted);
        }

        .header-right a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .header-right a:hover { color: var(--text); }

        /* ── Main layout ─────────────────────────────────────────── */
        main {
            flex: 1;
            padding: 28px 24px;
            max-width: 860px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        /* ── Card ────────────────────────────────────────────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
        }

        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        /* ── Form elements ────────────────────────────────────────── */
        .form-group { margin-bottom: 16px; }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .form-row .form-group { flex: 1 1 200px; }

        label {
            display: block;
            font-size: .82rem;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="url"],
        input[type="number"],
        input[type="password"] {
            width: 100%;
            padding: 8px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-size: .88rem;
            font-family: var(--font);
        }

        input:focus { outline: none; border-color: var(--accent); }

        .hint {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: .88rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-dark); }

        /* ── Flash messages ──────────────────────────────────────── */
        .flash-ok {
            background: rgba(76,175,125,.12);
            border: 1px solid rgba(76,175,125,.4);
            border-radius: var(--radius);
            color: var(--success);
            font-size: .85rem;
            padding: 10px 14px;
            margin-bottom: 18px;
        }

        .flash-error {
            background: rgba(224,92,92,.12);
            border: 1px solid rgba(224,92,92,.4);
            border-radius: var(--radius);
            color: var(--error);
            font-size: .85rem;
            padding: 10px 14px;
            margin-bottom: 18px;
        }

        /* ── User table ──────────────────────────────────────────── */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }

        .user-table th {
            text-align: left;
            padding: 8px 12px;
            color: var(--text-muted);
            font-weight: 500;
            border-bottom: 1px solid var(--border);
        }

        .user-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--surface-alt);
        }

        .user-table tr:last-child td { border-bottom: none; }

        .badge-you {
            font-size: .7rem;
            background: var(--accent);
            color: #fff;
            padding: 1px 7px;
            border-radius: 20px;
            margin-left: 6px;
            vertical-align: middle;
        }
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<header>
    <h1>⚙️ Admin-Bereich</h1>
    <div class="header-right">
        <span>Angemeldet als <strong><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></span>
        <a href="../index.php">← Zum Chat</a>
        <a href="logout.php">Abmelden</a>
    </div>
</header>

<main>

    <?php if ($flashOk !== ''): ?>
        <div class="flash-ok">✓ <?= htmlspecialchars($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="flash-error">✗ <?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- ── Endpoint / model settings ─────────────────────────────────────── -->
    <div class="card">
        <h2>🔗 Endpunkt- und Verbindungseinstellungen</h2>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="form-group">
                <label for="lmstudio_base_url">LM Studio API-Endpunkt (Base URL)</label>
                <input type="url" id="lmstudio_base_url" name="lmstudio_base_url"
                       value="<?= htmlspecialchars($currentBaseUrl) ?>"
                       placeholder="http://localhost:1234/v1" required>
                <p class="hint">Vollständige Base URL der LM Studio REST API, z. B. http://192.168.1.10:1234/v1</p>
            </div>

            <div class="form-group" style="max-width:200px">
                <label for="lmstudio_timeout">Anfrage-Timeout (Sekunden)</label>
                <input type="number" id="lmstudio_timeout" name="lmstudio_timeout"
                       value="<?= htmlspecialchars($currentTimeout) ?>"
                       min="1" max="600" required>
                <p class="hint">Maximale Wartezeit pro Anfrage (1–600 s).</p>
            </div>

            <div class="form-group">
                <label for="default_model">Standardmodell</label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select id="default_model" name="default_model"
                            style="flex:1 1 260px;padding:8px 12px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:.88rem;">
                        <option value="">– Modelle laden –</option>
                        <?php if ($currentDefaultModel !== ''): ?>
                            <option value="<?= htmlspecialchars($currentDefaultModel) ?>" selected>
                                <?= htmlspecialchars($currentDefaultModel) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <button type="button" id="load-models-btn"
                            style="padding:8px 16px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:.85rem;cursor:pointer;">
                        ⟳ Modelle laden
                    </button>
                </div>
                <p class="hint">Das vom Nutzer verwendete Modell. Dieses wird automatisch beim Chat eingesetzt.</p>
            </div>

            <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
        </form>
    </div>

    <!-- ── User info / change password ───────────────────────────────────── -->
    <div class="card">
        <h2>👤 Benutzerkonten</h2>

        <table class="user-table">
            <thead>
                <tr>
                    <th>Benutzername</th>
                    <th>E-Mail</th>
                    <th>Erstellt am</th>
                    <th>Letzter Login</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($u['username']) ?>
                            <?php if ($u['username'] === $_SESSION['admin_user']): ?>
                                <span class="badge-you">Du</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['email'] !== null ? htmlspecialchars($u['email']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td><?= $u['last_login'] !== null ? htmlspecialchars($u['last_login']) : '<span style="color:var(--text-muted)">noch nie</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Change password ────────────────────────────────────────────────── -->
    <div class="card">
        <h2>🔑 Passwort ändern</h2>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-group" style="max-width:360px">
                <label for="old_password">Aktuelles Passwort</label>
                <input type="password" id="old_password" name="old_password"
                       autocomplete="current-password" required>
            </div>

            <div class="form-row" style="max-width:740px">
                <div class="form-group">
                    <label for="new_password">Neues Passwort</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" minlength="8" required>
                    <p class="hint">Mindestens 8 Zeichen.</p>
                </div>
                <div class="form-group">
                    <label for="new_password_confirm">Neues Passwort wiederholen</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm"
                           autocomplete="new-password" minlength="8" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Passwort ändern</button>
        </form>
    </div>

</main>

<script>
(function () {
    const loadBtn  = document.getElementById('load-models-btn');
    const modelSel = document.getElementById('default_model');
    const baseUrl  = <?= json_encode($currentBaseUrl) ?>;
    const current  = <?= json_encode($currentDefaultModel) ?>;

    loadBtn.addEventListener('click', async function () {
        loadBtn.disabled = true;
        loadBtn.textContent = '⟳ Laden …';
        try {
            const res  = await fetch('../api/models.php');
            const data = await res.json();
            if (data.error) { alert('Fehler: ' + data.error); return; }
            const models = data.models || [];
            if (models.length === 0) { alert('Keine Modelle gefunden.'); return; }
            modelSel.innerHTML = models
                .map(m => `<option value="${m.id}"${m.id === current ? ' selected' : ''}>${m.id}</option>`)
                .join('');
        } catch (e) {
            alert('Netzwerkfehler: ' + e.message);
        } finally {
            loadBtn.disabled = false;
            loadBtn.textContent = '⟳ Modelle laden';
        }
    });
})();
</script>
</body>
</html>
