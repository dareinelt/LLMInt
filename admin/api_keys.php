<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_user'], $_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/openai_api.php';

$db = getDb();
$userId = (int) $_SESSION['admin_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$flashOk = '';
$flashError = '';
$newApiKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $flashError = 'Ungültiger CSRF-Token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_api_key') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));

            if ($name === '') {
                $flashError = 'Name darf nicht leer sein.';
            } elseif (mb_strlen($name) > 150) {
                $flashError = 'Name darf maximal 150 Zeichen enthalten.';
            } else {
                $expiresAtSql = null;
                if ($expiresAt !== '') {
                    $dt = date_create($expiresAt);
                    if (!$dt) {
                        $flashError = 'Ungültiges Ablaufdatum.';
                    } else {
                        $expiresAtSql = $dt->format('Y-m-d H:i:s');
                    }
                }

                if ($flashError === '') {
                    $material = openaiGenerateApiKeyMaterial();
                    $db->prepare(
                        'INSERT INTO api_keys (user_id, name, description, key_prefix, api_key_hash, created_at, expires_at, is_active)
                         VALUES (?, ?, ?, ?, ?, NOW(), ?, 1)'
                    )->execute([
                        $userId,
                        $name,
                        mb_substr($description, 0, 255),
                        $material['prefix'],
                        $material['hash'],
                        $expiresAtSql,
                    ]);

                    $newApiKey = $material['plain'];
                    $flashOk = 'API-Key erstellt. Bitte jetzt kopieren.';
                }
            }
        } elseif ($action === 'toggle_api_key') {
            $keyId = (int) ($_POST['key_id'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($keyId > 0) {
                $db->prepare('UPDATE api_keys SET is_active = ? WHERE id = ? AND user_id = ?')->execute([$active, $keyId, $userId]);
                $flashOk = $active ? 'API-Key aktiviert.' : 'API-Key deaktiviert.';
            }
        } elseif ($action === 'delete_api_key') {
            $keyId = (int) ($_POST['key_id'] ?? 0);
            if ($keyId > 0) {
                $db->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')->execute([$keyId, $userId]);
                $flashOk = 'API-Key gelöscht.';
            }
        }
    }
}

$keys = $db->prepare(
    'SELECT id, name, description, key_prefix, created_at, last_used_at, expires_at, is_active
       FROM api_keys
      WHERE user_id = ?
      ORDER BY id DESC'
);
$keys->execute([$userId]);
$apiKeys = $keys->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API-Keys – LLMInt</title>
    <style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;background:#212121;color:#ececf1;margin:0;padding:24px}
        .wrap{max-width:1080px;margin:0 auto}
        .card{background:#2f2f2f;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px;margin-bottom:16px}
        input,textarea{width:100%;background:#212121;border:1px solid rgba(255,255,255,.1);border-radius:10px;color:#ececf1;padding:8px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;font-size:.9rem}
        .btn{background:#6c63ff;color:#fff;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}
        .btn.secondary{background:#3d3d3d}
        .ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);padding:10px;border-radius:10px}
        .err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);padding:10px;border-radius:10px}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    </style>
</head>
<body>
<div class="wrap">
    <p><a href="index.php" style="color:#8e8ea0">← Zurück zum Admin-Bereich</a></p>

    <?php if ($flashOk !== ''): ?><div class="ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="err"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

    <?php if ($newApiKey !== ''): ?>
        <div class="card">
            <h3>Neuer API-Key</h3>
            <p>Diesen Wert kannst du nur jetzt sehen:</p>
            <code style="display:block;word-break:break-all;background:#1a1a1a;padding:10px;border-radius:8px"><?= htmlspecialchars($newApiKey) ?></code>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>OpenAI API-Key erstellen</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="create_api_key">
            <div class="row">
                <div>
                    <label>Name</label>
                    <input name="name" maxlength="150" required>
                </div>
                <div>
                    <label>Ablaufdatum (optional)</label>
                    <input type="datetime-local" name="expires_at">
                </div>
            </div>
            <div style="margin-top:10px">
                <label>Beschreibung (optional)</label>
                <textarea name="description" rows="2" maxlength="255"></textarea>
            </div>
            <p style="margin-top:10px"><button class="btn" type="submit">API-Key erzeugen</button></p>
        </form>
    </div>

    <div class="card">
        <h2>Vorhandene API-Keys</h2>
        <table>
            <thead><tr><th>Name</th><th>Prefix</th><th>Erstellt</th><th>Letzter Zugriff</th><th>Ablauf</th><th>Status</th><th>Aktionen</th></tr></thead>
            <tbody>
            <?php foreach ($apiKeys as $key): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $key['name']) ?></strong><br>
                        <span style="color:#8e8ea0;font-size:.8rem"><?= htmlspecialchars((string) $key['description']) ?></span>
                    </td>
                    <td><code><?= htmlspecialchars((string) $key['key_prefix']) ?>…</code></td>
                    <td><?= htmlspecialchars((string) $key['created_at']) ?></td>
                    <td><?= $key['last_used_at'] ? htmlspecialchars((string) $key['last_used_at']) : '–' ?></td>
                    <td><?= $key['expires_at'] ? htmlspecialchars((string) $key['expires_at']) : '–' ?></td>
                    <td><?= (int) $key['is_active'] === 1 ? 'aktiv' : 'inaktiv' ?></td>
                    <td style="white-space:nowrap;display:flex;gap:6px">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="toggle_api_key">
                            <input type="hidden" name="key_id" value="<?= (int) $key['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= (int) $key['is_active'] === 1 ? '0' : '1' ?>">
                            <button class="btn secondary" type="submit"><?= (int) $key['is_active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('API-Key löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_api_key">
                            <input type="hidden" name="key_id" value="<?= (int) $key['id'] ?>">
                            <button class="btn secondary" type="submit">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($apiKeys)): ?><tr><td colspan="7" style="color:#8e8ea0">Noch keine API-Keys.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
