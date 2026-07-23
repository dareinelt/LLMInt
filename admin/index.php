<?php

/**
 * admin/index.php
 *
 * Protected admin dashboard.
 * Manages multiple LM Studio endpoints (CRUD), shows load-balancing
 * statistics, lists user accounts, and allows password changes.
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';

$db = getDb();
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));

// ── Generate CSRF token ───────────────────────────────────────────────────────

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Handle POST actions ───────────────────────────────────────────────────────

$flashOk    = '';
$flashError = '';
$editEp     = null; // endpoint being edited (populated after POST redirect)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $flashError = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Add endpoint ──────────────────────────────────────────────────────
        if ($action === 'add_endpoint') {
            $newUrl     = trim($_POST['ep_base_url'] ?? '');
            $newTimeout = (int) ($_POST['ep_timeout'] ?? 120);
            $newModel   = trim($_POST['ep_default_model'] ?? '');
            $isActive   = isset($_POST['ep_is_active']) ? 1 : 0;

            if ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO endpoints (base_url, timeout, default_model, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([rtrim($newUrl, '/'), $newTimeout, $newModel, $isActive, $maxOrder + 1]);
                $flashOk = 'Endpunkt hinzugefügt.';
            }

        // ── Update endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'update_endpoint') {
            $epId       = (int) ($_POST['ep_id'] ?? 0);
            $newUrl     = trim($_POST['ep_base_url'] ?? '');
            $newTimeout = (int) ($_POST['ep_timeout'] ?? 120);
            $newModel   = trim($_POST['ep_default_model'] ?? '');
            $isActive   = isset($_POST['ep_is_active']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $db->prepare(
                    'UPDATE endpoints
                        SET base_url = ?, timeout = ?, default_model = ?, is_active = ?
                      WHERE id = ?'
                )->execute([rtrim($newUrl, '/'), $newTimeout, $newModel, $isActive, $epId]);
                $flashOk = 'Endpunkt gespeichert.';
            }

        // ── Delete endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'delete_endpoint') {
            $epId = (int) ($_POST['ep_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM endpoints WHERE id = ?')->execute([$epId]);
                $flashOk = 'Endpunkt gelöscht.';
            }

        // ── Save search settings ──────────────────────────────────────────────
        } elseif ($action === 'save_search_settings') {
            $newSearxngUrl = trim($_POST['searxng_base_url'] ?? '');

            if ($newSearxngUrl !== '' && filter_var($newSearxngUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige SearXNG-URL eingeben oder das Feld leer lassen.';
            } else {
                $searxngBaseUrl = $newSearxngUrl === '' ? '' : rtrim($newSearxngUrl, '/');
                setSetting('searxng_base_url', $searxngBaseUrl);
                $flashOk = $searxngBaseUrl === ''
                    ? 'SearXNG-Suche deaktiviert.'
                    : 'SearXNG-URL gespeichert.';
            }

        // ── Change password ───────────────────────────────────────────────────
        } elseif ($action === 'change_password') {
            $oldPass  = $_POST['old_password']         ?? '';
            $newPass  = $_POST['new_password']          ?? '';
            $newPass2 = $_POST['new_password_confirm']  ?? '';

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

// ── Load data ─────────────────────────────────────────────────────────────────

$endpoints = $db->query(
    'SELECT * FROM endpoints ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$users = $db->query(
    'SELECT id, username, email, created_at, last_login FROM users ORDER BY id'
)->fetchAll();

// ── Load statistics ───────────────────────────────────────────────────────────

try {
    $epStats = $db->query('
        SELECT
            e.id,
            e.base_url,
            e.default_model,
            e.is_active,
            COALESCE(SUM(CASE WHEN t.status = \'running\' THEN 1 ELSE 0 END), 0) AS cnt_running,
            COALESCE(SUM(CASE WHEN t.status = \'done\'    THEN 1 ELSE 0 END), 0) AS cnt_done,
            COALESCE(SUM(CASE WHEN t.status = \'error\'   THEN 1 ELSE 0 END), 0) AS cnt_error,
            COALESCE(SUM(CASE WHEN t.status = \'done\'
                              AND t.started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS cnt_done_24h,
            COALESCE(SUM(t.total_tokens), 0) AS sum_tokens,
            COALESCE(ROUND(AVG(CASE WHEN t.total_tokens IS NOT NULL
                                    THEN t.total_tokens END)), 0) AS avg_tokens,
            COALESCE(SUM(CASE WHEN t.status = \'done\'
                              AND DATE(t.started_at) = CURDATE()
                         THEN 1 ELSE 0 END), 0) AS today_jobs,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.total_tokens, 0) ELSE 0 END), 0) AS today_tokens
        FROM endpoints e
        LEFT JOIN tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.base_url, e.default_model, e.is_active
        ORDER BY e.sort_order ASC, e.id ASC
    ')->fetchAll();

    $totals = $db->query('
        SELECT
            COALESCE(SUM(CASE WHEN status = \'running\' THEN 1 ELSE 0 END), 0) AS total_running,
            COALESCE(SUM(CASE WHEN status = \'done\'    THEN 1 ELSE 0 END), 0) AS total_done,
            COALESCE(SUM(CASE WHEN status = \'error\'   THEN 1 ELSE 0 END), 0) AS total_error,
            COALESCE(SUM(CASE WHEN status = \'done\'
                              AND started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS total_done_24h,
            COALESCE(SUM(total_tokens), 0) AS grand_tokens
        FROM tasks
    ')->fetch();
} catch (PDOException $e) {
    $epStats = [];
    $totals  = [
        'total_running' => 0, 'total_done' => 0, 'total_error' => 0,
        'total_done_24h' => 0, 'grand_tokens' => 0,
    ];
}

// ── SearXNG search statistics ─────────────────────────────────────────────────

$searxngStats = ['running' => 0, 'today_jobs' => 0, 'total_done' => 0];
if ($searxngBaseUrl !== '') {
    try {
        $sRow = $db->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND DATE(started_at) = CURDATE()
                             THEN 1 ELSE 0 END), 0) AS today_jobs,
                COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) AS total_done
            FROM search_logs
        ")->fetch();
        $searxngStats = [
            'running'    => (int) $sRow['running'],
            'today_jobs' => (int) $sRow['today_jobs'],
            'total_done' => (int) $sRow['total_done'],
        ];
    } catch (PDOException $e) {
        // search_logs table may not exist on older installations
    }
}

// Assign a stable colour to each distinct model name.
$palette       = ['#6c63ff', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#f97316', '#84cc16'];
$modelColorMap = [];
$colorIdx      = 0;
foreach ($endpoints as $ep) {
    $m = $ep['default_model'];
    if ($m !== '' && !isset($modelColorMap[$m])) {
        $modelColorMap[$m] = $palette[$colorIdx % count($palette)];
        $colorIdx++;
    }
}

// Populate $editEp if the URL requests editing a specific endpoint.
if (isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
    $epId = (int) $_GET['edit'];
    foreach ($endpoints as $ep) {
        if ($ep['id'] === $epId) {
            $editEp = $ep;
            break;
        }
    }
}

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

        .header-right a { color: var(--text-muted); text-decoration: none; }
        .header-right a:hover { color: var(--text); }

        /* ── Main layout ─────────────────────────────────────────── */
        main {
            flex: 1;
            padding: 28px 24px;
            max-width: 980px;
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

        .card h3 {
            font-size: .9rem;
            font-weight: 600;
            margin: 20px 0 12px;
        }

        /* ── Form elements ────────────────────────────────────────── */
        .form-group { margin-bottom: 14px; }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .form-row .form-group { flex: 1 1 180px; }

        label {
            display: block;
            font-size: .82rem;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        label.inline {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
            cursor: pointer;
        }

        input[type="text"],
        input[type="url"],
        input[type="number"],
        input[type="password"],
        select {
            width: 100%;
            padding: 8px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-size: .88rem;
            font-family: var(--font);
        }

        input:focus, select:focus { outline: none; border-color: var(--accent); }

        .hint {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* ── Buttons ─────────────────────────────────────────────── */
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: .88rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
            background: var(--surface-alt);
            color: var(--text);
        }

        .btn:hover { background: #464646; }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-dark); }

        .btn-danger { background: rgba(239,68,68,.15); color: var(--error); }
        .btn-danger:hover { background: rgba(239,68,68,.25); }

        .btn-sm {
            padding: 4px 12px;
            font-size: .8rem;
            border-radius: 8px;
        }

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

        /* ── Generic table ───────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }

        .data-table th {
            text-align: left;
            padding: 8px 12px;
            color: var(--text-muted);
            font-weight: 500;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--surface-alt);
            vertical-align: middle;
        }

        .data-table tr:last-child td { border-bottom: none; }

        /* ── Model badge ─────────────────────────────────────────── */
        .model-badge {
            display: inline-block;
            font-size: .72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 20px;
            color: #fff;
            white-space: nowrap;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge-empty {
            background: var(--surface-alt);
            color: var(--text-muted);
        }

        /* ── Status dots ─────────────────────────────────────────── */
        .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .dot-on  { background: var(--success); }
        .dot-off { background: var(--text-muted); }

        /* ── Stat summary boxes ──────────────────────────────────── */
        .stat-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-box {
            flex: 1 1 120px;
            background: var(--surface-alt);
            border-radius: var(--radius);
            padding: 14px 16px;
            text-align: center;
        }

        .stat-box .stat-val {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-box .stat-lbl {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .stat-running { color: var(--warning); }
        .stat-done    { color: var(--success); }
        .stat-error   { color: var(--error); }
        .stat-tokens  { color: var(--accent); }

        /* ── Add/edit form ───────────────────────────────────────── */
        .ep-form-section {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .ep-form-section h3 { margin-top: 0; }

        /* ── Action row for forms ────────────────────────────────── */
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ── User table ──────────────────────────────────────────── */
        .badge-you {
            font-size: .7rem;
            background: var(--accent);
            color: #fff;
            padding: 1px 7px;
            border-radius: 20px;
            margin-left: 6px;
            vertical-align: middle;
        }

        /* ── Separator ───────────────────────────────────────────── */
        .sep { color: var(--text-muted); }

        /* ── Load-distribution tree ──────────────────────────────── */
        #load-tree-container {
            overflow-x: auto;
            padding: 8px 0 4px;
        }

        #load-tree-svg {
            display: block;
            width: 100%;
        }

        .tree-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .tree-header-row h2 { margin: 0; padding: 0; border: none; }

        .tree-refresh-info {
            font-size: .75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tree-refresh-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--success);
        }

        @keyframes tree-pulse {
            0%,100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(1.8); opacity: .35; }
        }

        .pulse-dot {
            animation: tree-pulse 1.4s ease-in-out infinite;
            transform-origin: center;
        }

        @keyframes tree-fade-in {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .tree-refresh-spin {
            display: inline-block;
            animation: spin .8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
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

    <div class="card">
        <h2>🔎 Websuche</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_search_settings">

            <div class="form-group">
                <label for="searxng-base-url">SearXNG-URL</label>
                <input type="url" id="searxng-base-url" name="searxng_base_url"
                       placeholder="https://search.example.org"
                       value="<?= htmlspecialchars($searxngBaseUrl) ?>">
                <p class="hint">
                    Leer lassen, um die Websuche zu deaktivieren. Wenn gesetzt, können Modelle aktuelle Informationen über SearXNG abrufen.
                </p>
            </div>

            <button type="submit" class="btn btn-primary">💾 Speichern</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Endpoint Management
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>🔗 Endpunkte</h2>

        <?php if (empty($endpoints)): ?>
            <p style="color:var(--text-muted);margin-bottom:16px;">Noch keine Endpunkte konfiguriert. Füge unten einen hinzu.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>URL</th>
                    <th>Timeout</th>
                    <th>Standard-Modell</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($endpoints as $ep): ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= $ep['id'] ?></td>
                    <td style="font-family:monospace;font-size:.8rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($ep['base_url']) ?>">
                        <?= htmlspecialchars($ep['base_url']) ?>
                    </td>
                    <td><?= (int) $ep['timeout'] ?>s</td>
                    <td>
                        <?php if ($ep['default_model'] !== ''): ?>
                            <span class="model-badge"
                                  style="background:<?= htmlspecialchars($modelColorMap[$ep['default_model']] ?? '#555') ?>"
                                  title="<?= htmlspecialchars($ep['default_model']) ?>">
                                <?= htmlspecialchars($ep['default_model']) ?>
                            </span>
                        <?php else: ?>
                            <span class="model-badge badge-empty">–</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="dot <?= $ep['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= $ep['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm"
                                onclick="startEdit(<?= htmlspecialchars(json_encode($ep), ENT_QUOTES) ?>)">
                            ✏ Bearbeiten
                        </button>
                        <span class="sep"> </span>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Endpunkt #<?= $ep['id'] ?> wirklich löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"     value="delete_endpoint">
                            <input type="hidden" name="ep_id"      value="<?= (int) $ep['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <!-- ── Add / Edit form ─────────────────────────────────────────────── -->
        <div class="ep-form-section">
            <h3 id="ep-form-title">➕ Endpunkt hinzufügen</h3>

            <form method="POST" id="ep-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     id="ep-action" value="add_endpoint">
                <input type="hidden" name="ep_id"      id="ep-id"     value="">

                <div class="form-row">
                    <div class="form-group" style="flex:4">
                        <label for="ep-url">API-Endpunkt (Base URL) *</label>
                        <input type="url" id="ep-url" name="ep_base_url"
                               placeholder="http://192.168.1.10:1234/v1" required
                               value="<?= $editEp ? htmlspecialchars($editEp['base_url']) : '' ?>">
                        <p class="hint">Vollständige Base URL der LM Studio REST API.</p>
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px">
                        <label for="ep-timeout">Timeout (Sekunden) *</label>
                        <input type="number" id="ep-timeout" name="ep_timeout"
                               min="1" max="600" required
                               value="<?= $editEp ? (int) $editEp['timeout'] : 120 ?>">
                        <p class="hint">1 – 600 s</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="ep-model-input">Standard-Modell</label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="text" list="ep-model-list" id="ep-model-input"
                               name="ep_default_model"
                               placeholder="Modell-ID eingeben oder Modelle laden …"
                               style="flex:1 1 260px"
                               value="<?= $editEp ? htmlspecialchars($editEp['default_model']) : '' ?>">
                        <datalist id="ep-model-list"></datalist>
                        <button type="button" id="ep-load-btn" class="btn">
                            ⟳ Modelle laden
                        </button>
                    </div>
                    <p class="hint">
                        Endpunkte mit identischem Standard-Modell bilden automatisch eine Load-Balancing-Gruppe.
                    </p>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-active" name="ep_is_active"
                               <?= (!$editEp || $editEp['is_active']) ? 'checked' : '' ?>>
                        Endpunkt aktiv (nimmt Anfragen entgegen)
                    </label>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="resetForm()">✕ Abbrechen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Statistics
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>📊 Statistik &amp; Verteilung</h2>

        <!-- Summary boxes -->
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-val stat-running"><?= number_format((int) $totals['total_running']) ?></div>
                <div class="stat-lbl">Laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done"><?= number_format((int) $totals['total_done_24h']) ?></div>
                <div class="stat-lbl">Erledigt (24 h)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done"><?= number_format((int) $totals['total_done']) ?></div>
                <div class="stat-lbl">Erledigt (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-error"><?= number_format((int) $totals['total_error']) ?></div>
                <div class="stat-lbl">Fehler (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens"><?= number_format((int) $totals['grand_tokens']) ?></div>
                <div class="stat-lbl">Token (gesamt)</div>
            </div>
            <?php if ($searxngBaseUrl !== ''): ?>
            <div class="stat-box">
                <div class="stat-val stat-running"><?= number_format($searxngStats['running']) ?></div>
                <div class="stat-lbl">Suchen laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done"><?= number_format($searxngStats['today_jobs']) ?></div>
                <div class="stat-lbl">Suchen heute</div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($epStats)): ?>
            <p style="color:var(--text-muted)">Noch keine Aufgaben verarbeitet.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Endpunkt</th>
                    <th>Modell-Gruppe</th>
                    <th style="text-align:right">Laufend</th>
                    <th style="text-align:right">Erledigt (24 h)</th>
                    <th style="text-align:right">Erledigt (gesamt)</th>
                    <th style="text-align:right">Fehler</th>
                    <th style="text-align:right">⌀ Token</th>
                    <th style="text-align:right">Token gesamt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($epStats as $s): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($s['base_url']) ?>">
                        <span class="dot <?= $s['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($s['base_url']) ?>
                    </td>
                    <td>
                        <?php if ($s['default_model'] !== ''): ?>
                            <span class="model-badge"
                                  style="background:<?= htmlspecialchars($modelColorMap[$s['default_model']] ?? '#555') ?>">
                                <?= htmlspecialchars($s['default_model']) ?>
                            </span>
                        <?php else: ?>
                            <span class="model-badge badge-empty">–</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;color:var(--warning)"><?= number_format((int) $s['cnt_running']) ?></td>
                    <td style="text-align:right"><?= number_format((int) $s['cnt_done_24h']) ?></td>
                    <td style="text-align:right;color:var(--success)"><?= number_format((int) $s['cnt_done']) ?></td>
                    <td style="text-align:right;color:var(--error)"><?= number_format((int) $s['cnt_error']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['avg_tokens']) ?></td>
                    <td style="text-align:right;color:var(--accent)"><?= number_format((int) $s['sum_tokens']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Load distribution tree
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="load-tree-card">
        <div class="tree-header-row">
            <h2 style="margin-bottom:0;padding-bottom:0;border:none">🌐 Lastverteilung – Live</h2>
            <div class="tree-refresh-info">
                <span class="tree-refresh-dot" id="tree-live-dot"></span>
                <span id="tree-status">Initialisierung …</span>
            </div>
        </div>
        <div id="load-tree-container">
            <svg id="load-tree-svg"
                 xmlns="http://www.w3.org/2000/svg"
                 aria-label="Horizontale Lastverteilung der Endpunkte">
            </svg>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         User accounts
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>👤 Benutzerkonten</h2>

        <table class="data-table">
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

    <!-- ═══════════════════════════════════════════════════════════════════════
         Change password
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>🔑 Passwort ändern</h2>
        <form method="POST">
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
    'use strict';

    const formTitle    = document.getElementById('ep-form-title');
    const actionInput  = document.getElementById('ep-action');
    const idInput      = document.getElementById('ep-id');
    const urlInput     = document.getElementById('ep-url');
    const timeoutInput = document.getElementById('ep-timeout');
    const modelInput   = document.getElementById('ep-model-input');
    const modelList    = document.getElementById('ep-model-list');
    const activeCheck  = document.getElementById('ep-active');
    const loadBtn      = document.getElementById('ep-load-btn');

    // Pre-fill the form when the page loaded with ?edit=<id>
    <?php if ($editEp): ?>
    document.getElementById('ep-form-title').textContent = '✏ Endpunkt bearbeiten';
    document.getElementById('ep-action').value = 'update_endpoint';
    document.getElementById('ep-id').value     = <?= (int) $editEp['id'] ?>;
    document.querySelector('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    /**
     * Populate the add/edit form with an existing endpoint's values.
     * Called by the "Edit" button in each table row.
     */
    window.startEdit = function (ep) {
        formTitle.textContent  = '✏ Endpunkt bearbeiten';
        actionInput.value      = 'update_endpoint';
        idInput.value          = ep.id;
        urlInput.value         = ep.base_url;
        timeoutInput.value     = ep.timeout;
        modelInput.value       = ep.default_model || '';
        activeCheck.checked    = ep.is_active == 1;
        // Clear datalist options from a previous load-models call.
        modelList.innerHTML    = '';
        document.querySelector('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    };

    /** Reset form back to "add" mode. */
    window.resetForm = function () {
        formTitle.textContent = '➕ Endpunkt hinzufügen';
        actionInput.value     = 'add_endpoint';
        idInput.value         = '';
        urlInput.value        = '';
        timeoutInput.value    = '120';
        modelInput.value      = '';
        activeCheck.checked   = true;
        modelList.innerHTML   = '';
    };

    /** Load available models from the URL currently typed in the form. */
    loadBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            alert('Bitte zuerst eine URL eingeben.');
            return;
        }

        loadBtn.disabled    = true;
        loadBtn.textContent = '⟳ Laden …';

        try {
            const res  = await fetch('../api/models.php?endpoint_url=' + encodeURIComponent(url) + '&timeout=10');
            const data = await res.json();

            if (data.error) {
                alert('Fehler: ' + data.error);
                return;
            }

            const models = data.models || [];
            if (models.length === 0) {
                alert('Keine Modelle gefunden.');
                return;
            }

            // Populate the <datalist> so the text input offers autocomplete.
            modelList.innerHTML = models
                .map(m => `<option value="${m.id.replace(/"/g, '&quot;')}">`)
                .join('');

            // If the input is empty, pre-fill with the first model.
            if (!modelInput.value && models[0]) {
                modelInput.value = models[0].id;
            }
        } catch (e) {
            alert('Netzwerkfehler: ' + e.message);
        } finally {
            loadBtn.disabled    = false;
            loadBtn.textContent = '⟳ Modelle laden';
        }
    });
})();

// ── Load-distribution tree ────────────────────────────────────────────────────
(function () {
    'use strict';

    // Initial data injected from PHP (avoids an extra round-trip on first load).
    const INITIAL_DATA = <?= json_encode(
        array_map(function ($s) {
            return [
                'id'            => (int) $s['id'],
                'base_url'      => $s['base_url'],
                'default_model' => $s['default_model'],
                'is_active'     => (int) $s['is_active'],
                'running'       => (int) $s['cnt_running'],
                'today_jobs'    => (int) $s['today_jobs'],
                'today_tokens'  => (int) $s['today_tokens'],
            ];
        }, $epStats),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>;

    const INITIAL_SEARXNG = <?= json_encode([
        'enabled'    => $searxngBaseUrl !== '',
        'base_url'   => $searxngBaseUrl,
        'running'    => $searxngStats['running'],
        'today_jobs' => $searxngStats['today_jobs'],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const MODEL_COLORS = <?= json_encode($modelColorMap,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const PALETTE = ['#6c63ff','#22c55e','#f59e0b','#ef4444',
                     '#06b6d4','#a855f7','#f97316','#84cc16'];

    const svg      = document.getElementById('load-tree-svg');
    const statusEl = document.getElementById('tree-status');
    const liveDot  = document.getElementById('tree-live-dot');
    const NS       = 'http://www.w3.org/2000/svg';

    // ── Helpers ───────────────────────────────────────────────────────────────

    function mk(tag, attrs) {
        const e = document.createElementNS(NS, tag);
        if (attrs) {
            for (const [k, v] of Object.entries(attrs)) {
                e.setAttribute(k, v);
            }
        }
        return e;
    }

    function txt(parent, text, attrs) {
        const e = mk('text', attrs);
        e.textContent = text;
        parent.appendChild(e);
        return e;
    }

    function formatNum(n) {
        if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
        if (n >= 1_000)     return (n / 1_000).toFixed(1) + 'K';
        return String(n);
    }

    function shortUrl(url) {
        try {
            const u = new URL(url);
            return u.hostname + (u.port ? ':' + u.port : '');
        } catch (_) {
            return url.length > 28 ? url.slice(0, 26) + '…' : url;
        }
    }

    function truncate(str, max) {
        return str.length > max ? str.slice(0, max - 1) + '…' : str;
    }

    // ── Tree renderer ─────────────────────────────────────────────────────────

    function renderLoadTree(endpoints, searxng) {
        svg.innerHTML = '';

        const hasSearxng = searxng && searxng.enabled;

        if ((!endpoints || endpoints.length === 0) && !hasSearxng) {
            svg.setAttribute('viewBox', '0 0 400 60');
            svg.setAttribute('height', '60');
            txt(svg, 'Keine Endpunkte konfiguriert.', {
                x: 16, y: 38,
                fill: '#8e8ea0',
                'font-size': 13,
                'font-family': 'sans-serif',
            });
            return;
        }

        // Group endpoints by model
        const groups = new Map();
        const colorMap = {};
        let ci = 0;
        for (const ep of (endpoints || [])) {
            const key = ep.default_model || '–';
            if (!groups.has(key)) {
                groups.set(key, []);
                colorMap[key] = MODEL_COLORS[key] || PALETTE[ci % PALETTE.length];
                ci++;
            }
            groups.get(key).push(ep);
        }

        // ── Layout constants ──────────────────────────────────────────────────
        const PAD    = 22;
        const ROOT_W = 112, ROOT_H = 82;
        const MOD_W  = 178, MOD_H  = 64;
        const EP_W   = 218, EP_H   = 104;
        const H_GAP  = 60;
        const V_GAP  = 14;
        const SRXNG_W = EP_W, SRXNG_H = 90;

        const COL1_X = PAD;
        const COL2_X = COL1_X + ROOT_W + H_GAP;
        const COL3_X = COL2_X + MOD_W  + H_GAP;
        const TOTAL_W = COL3_X + EP_W + PAD;

        // Vertical layout: one slot per endpoint
        const totalEps = (endpoints || []).length;
        const LLM_H = totalEps > 0
            ? PAD * 2 + totalEps * EP_H + (totalEps - 1) * V_GAP
            : PAD * 2 + ROOT_H; // minimum height when no endpoints

        const SRXNG_V_GAP = 20;
        const TOTAL_H = hasSearxng
            ? LLM_H + SRXNG_V_GAP + SRXNG_H + PAD
            : LLM_H;

        let curY = PAD;
        const epCY  = {}; // ep.id → center Y
        const modCY = {}; // model  → center Y

        for (const [model, eps] of groups) {
            const startY = curY;
            for (const ep of eps) {
                epCY[ep.id] = curY + EP_H / 2;
                curY += EP_H + V_GAP;
            }
            curY -= V_GAP;
            modCY[model] = startY + (curY - startY) / 2;
            curY += V_GAP;
        }

        // Root is centered on the LLM content area only
        const rootCY = LLM_H / 2;

        // SearXNG tile center Y (below all LLM content)
        const searxngCY = LLM_H + SRXNG_V_GAP + SRXNG_H / 2;

        svg.setAttribute('viewBox', `0 0 ${TOTAL_W} ${TOTAL_H}`);
        svg.setAttribute('height', TOTAL_H);

        // ── Connector curves ──────────────────────────────────────────────────

        const CURVE_CTRL = H_GAP * 0.65;

        // Root → each model group
        for (const [model] of groups) {
            const mY = modCY[model];
            const rX = COL1_X + ROOT_W;
            svg.appendChild(mk('path', {
                d: `M ${rX},${rootCY} C ${rX + CURVE_CTRL},${rootCY} ${COL2_X - CURVE_CTRL},${mY} ${COL2_X},${mY}`,
                fill: 'none',
                stroke: 'rgba(255,255,255,0.11)',
                'stroke-width': 1.5,
            }));
        }

        // Each model group → its endpoints
        for (const [model, eps] of groups) {
            const mY = modCY[model];
            const mX = COL2_X + MOD_W;
            for (const ep of eps) {
                const eY = epCY[ep.id];
                svg.appendChild(mk('path', {
                    d: `M ${mX},${mY} C ${mX + CURVE_CTRL},${mY} ${COL3_X - CURVE_CTRL},${eY} ${COL3_X},${eY}`,
                    fill: 'none',
                    stroke: 'rgba(255,255,255,0.11)',
                    'stroke-width': 1.5,
                }));
            }
        }

        // Root → SearXNG tile (direct, bypassing model groups)
        if (hasSearxng) {
            const rX = COL1_X + ROOT_W;
            const ctrl = (COL3_X - rX) * 0.55;
            svg.appendChild(mk('path', {
                d: `M ${rX},${rootCY} C ${rX + ctrl},${rootCY} ${COL3_X - ctrl * 0.4},${searxngCY} ${COL3_X},${searxngCY}`,
                fill: 'none',
                stroke: 'rgba(6,182,212,0.3)',
                'stroke-width': 1.5,
                'stroke-dasharray': '4 3',
            }));
        }

        // ── Root node ─────────────────────────────────────────────────────────
        {
            const g = mk('g', { transform: `translate(${COL1_X},${rootCY - ROOT_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: ROOT_W, height: ROOT_H,
                rx: 11,
                fill: '#2a2a3a',
                stroke: 'rgba(108,99,255,0.55)',
                'stroke-width': 1.5,
            }));
            txt(g, '⚡', {
                x: ROOT_W / 2, y: 30,
                'text-anchor': 'middle',
                fill: '#ececf1',
                'font-size': 22,
                'font-family': 'sans-serif',
            });
            txt(g, 'LLMInt', {
                x: ROOT_W / 2, y: 50,
                'text-anchor': 'middle',
                fill: '#ececf1',
                'font-size': 12,
                'font-weight': 700,
                'font-family': 'sans-serif',
            });
            txt(g, 'System', {
                x: ROOT_W / 2, y: 66,
                'text-anchor': 'middle',
                fill: '#8e8ea0',
                'font-size': 10,
                'font-family': 'sans-serif',
            });

            svg.appendChild(g);
        }

        // ── Model group nodes ─────────────────────────────────────────────────
        for (const [model, eps] of groups) {
            const color = colorMap[model];
            const mY = modCY[model];
            const g  = mk('g', { transform: `translate(${COL2_X},${mY - MOD_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: MOD_W, height: MOD_H,
                rx: 10,
                fill: '#2f2f2f',
                stroke: color + '55',
                'stroke-width': 1.5,
            }));
            // Coloured left accent bar
            g.appendChild(mk('rect', {
                x: 0, y: 8, width: 4, height: MOD_H - 16,
                rx: 2,
                fill: color,
            }));

            const groupRunning = eps.reduce((s, e) => s + e.running, 0);
            const modelLabel   = truncate(model, 22);

            txt(g, modelLabel, {
                x: 16, y: 24,
                fill: '#ececf1',
                'font-size': 11,
                'font-weight': 700,
                'font-family': 'sans-serif',
            });
            txt(g, `${eps.length} Endpunkt${eps.length !== 1 ? 'e' : ''}`, {
                x: 16, y: 40,
                fill: '#8e8ea0',
                'font-size': 10,
                'font-family': 'sans-serif',
            });

            if (groupRunning > 0) {
                txt(g, `▶ ${groupRunning} laufend`, {
                    x: 16, y: 56,
                    fill: '#f59e0b',
                    'font-size': 10,
                    'font-family': 'sans-serif',
                });
                // Animated pulse ring at top-right corner
                const dot = mk('circle', {
                    class: 'pulse-dot',
                    cx: MOD_W - 14,
                    cy: 14,
                    r: 5,
                    fill: '#f59e0b',
                });
                g.appendChild(dot);
            }

            svg.appendChild(g);
        }

        // ── Endpoint nodes ────────────────────────────────────────────────────
        for (const ep of endpoints) {
            const model = ep.default_model || '–';
            const color = colorMap[model] || '#555';
            const eY    = epCY[ep.id];
            const g     = mk('g', { transform: `translate(${COL3_X},${eY - EP_H / 2})` });

            const isActive = ep.is_active === 1;

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: EP_W, height: EP_H,
                rx: 10,
                fill: '#2f2f2f',
                stroke: isActive ? 'rgba(255,255,255,0.10)' : 'rgba(239,68,68,0.35)',
                'stroke-width': 1.5,
            }));

            // Animated running-task pulse ring (drawn first, behind status dot)
            if (ep.running > 0 && isActive) {
                const pDot = mk('circle', {
                    class: 'pulse-dot',
                    cx: 14, cy: 18,
                    r: 4,
                    fill: '#f59e0b',
                });
                g.appendChild(pDot);
            }

            // Status dot (drawn on top of pulse ring)
            g.appendChild(mk('circle', {
                cx: 14, cy: 18,
                r: 4,
                fill: isActive ? '#22c55e' : '#8e8ea0',
            }));

            txt(g, truncate(shortUrl(ep.base_url), 26), {
                x: 26, y: 22,
                fill: '#ececf1',
                'font-size': 10.5,
                'font-weight': 700,
                'font-family': 'monospace, sans-serif',
            });

            // Divider
            g.appendChild(mk('line', {
                x1: 10, y1: 32, x2: EP_W - 10, y2: 32,
                stroke: 'rgba(255,255,255,0.07)',
                'stroke-width': 1,
            }));

            // Running count
            const runColor = ep.running > 0 ? '#f59e0b' : '#8e8ea0';
            txt(g, `▶  Laufend: ${ep.running}`, {
                x: 12, y: 50,
                fill: runColor,
                'font-size': 11,
                'font-family': 'sans-serif',
            });

            // Jobs today
            txt(g, `✓  Jobs heute: ${formatNum(ep.today_jobs)}`, {
                x: 12, y: 68,
                fill: '#22c55e',
                'font-size': 11,
                'font-family': 'sans-serif',
            });

            // Tokens today
            txt(g, `⬡  Token heute: ${formatNum(ep.today_tokens)}`, {
                x: 12, y: 86,
                fill: '#6c63ff',
                'font-size': 11,
                'font-family': 'sans-serif',
            });

            svg.appendChild(g);
        }

        // ── SearXNG tile ──────────────────────────────────────────────────────
        if (hasSearxng) {
            const SRXNG_COLOR = '#06b6d4';
            const g = mk('g', { transform: `translate(${COL3_X},${searxngCY - SRXNG_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: SRXNG_W, height: SRXNG_H,
                rx: 10,
                fill: '#1e2f35',
                stroke: SRXNG_COLOR + '66',
                'stroke-width': 1.5,
            }));

            // Animated pulse ring when searches are running
            if (searxng.running > 0) {
                g.appendChild(mk('circle', {
                    class: 'pulse-dot',
                    cx: 14, cy: 18,
                    r: 4,
                    fill: SRXNG_COLOR,
                }));
            }

            // Status dot
            g.appendChild(mk('circle', {
                cx: 14, cy: 18,
                r: 4,
                fill: SRXNG_COLOR,
            }));

            // SearXNG label / URL
            const srxngLabel = searxng.base_url
                ? truncate(shortUrl(searxng.base_url), 26)
                : 'SearXNG';
            txt(g, srxngLabel, {
                x: 26, y: 22,
                fill: '#ececf1',
                'font-size': 10.5,
                'font-weight': 700,
                'font-family': 'monospace, sans-serif',
            });

            // Divider
            g.appendChild(mk('line', {
                x1: 10, y1: 32, x2: SRXNG_W - 10, y2: 32,
                stroke: 'rgba(255,255,255,0.07)',
                'stroke-width': 1,
            }));

            // Running count
            const runColor = searxng.running > 0 ? '#f59e0b' : '#8e8ea0';
            txt(g, `▶  Laufend: ${searxng.running}`, {
                x: 12, y: 52,
                fill: runColor,
                'font-size': 11,
                'font-family': 'sans-serif',
            });

            // Jobs today
            txt(g, `✓  Jobs heute: ${formatNum(searxng.today_jobs)}`, {
                x: 12, y: 72,
                fill: '#22c55e',
                'font-size': 11,
                'font-family': 'sans-serif',
            });

            svg.appendChild(g);
        }
    }

    // ── Status bar ────────────────────────────────────────────────────────────

    function setStatus(label, loading) {
        if (statusEl) statusEl.textContent = label;
        if (liveDot) {
            liveDot.style.background = loading ? '#f59e0b' : '#22c55e';
        }
    }

    function tsLabel(ts) {
        const d = new Date(ts * 1000);
        return 'Aktualisiert ' + d.toLocaleTimeString('de-DE');
    }

    // ── Auto-refresh ──────────────────────────────────────────────────────────

    async function refreshTree() {
        setStatus('⟳ Aktualisiere …', true);
        try {
            const res  = await fetch('load_stats.php', { cache: 'no-store' });
            const data = await res.json();
            if (data.ok && Array.isArray(data.endpoints)) {
                renderLoadTree(data.endpoints, data.searxng || null);
                setStatus(tsLabel(data.ts), false);
            } else {
                setStatus('Fehler beim Laden', false);
            }
        } catch (err) {
            setStatus('Netzwerkfehler', false);
        }
    }

    // Initial render using PHP-injected data
    renderLoadTree(INITIAL_DATA, INITIAL_SEARXNG);
    setStatus(tsLabel(Math.floor(Date.now() / 1000)), false);

    // Refresh every 15 seconds
    setInterval(refreshTree, 15000);

})();
</script>

</body>
</html>
