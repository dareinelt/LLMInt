<?php

/**
 * admin/endpoint_tech.php
 *
 * "Endpunkte technische Verwaltung": pairs every LLM endpoint with a
 * quickinfo instance (https://github.com/dareinelt/quickinfo) and shows a
 * compact live overview per endpoint – model, average tokens/s, CPU/GPU
 * load and temperature (with 24h min/max), RAM/VRAM usage. Clicking an
 * endpoint row opens its quickinfo dashboard in a new tab.
 *
 * Live data is polled from admin/quickinfo_stats.php.
 */

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/quickinfo.php';

requireAdminOrRedirect('login.php');

$db = getDb();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$flashOk    = (string) ($_SESSION['qi_flash_ok'] ?? '');
$flashError = (string) ($_SESSION['qi_flash_error'] ?? '');
unset($_SESSION['qi_flash_ok'], $_SESSION['qi_flash_error']);

// ── POST actions (Post/Redirect/Get) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok  = '';
    $err = '';

    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $err = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $epId   = (int) ($_POST['endpoint_id'] ?? 0);

        $ep = null;
        if ($epId > 0) {
            $stmt = $db->prepare('SELECT id, alias, base_url, quickinfo_url, quickinfo_api_key, quickinfo_verify_tls FROM endpoints WHERE id = ?');
            $stmt->execute([$epId]);
            $ep = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $epLabel = $ep ? (trim((string) $ep['alias']) !== '' ? $ep['alias'] : $ep['base_url']) : '#' . $epId;

        if ($ep === null) {
            $err = 'Endpunkt nicht gefunden.';
        } elseif ($action === 'pair_quickinfo') {
            $url       = quickinfoNormalizeUrl((string) ($_POST['quickinfo_url'] ?? ''));
            $keyInput  = trim((string) ($_POST['quickinfo_api_key'] ?? ''));
            $verifyTls = isset($_POST['quickinfo_verify_tls']) ? 1 : 0;
            // Empty key input keeps the stored key (never re-displayed in the form).
            $apiKey = $keyInput !== '' ? $keyInput : (string) ($ep['quickinfo_api_key'] ?? '');

            if ($url === '') {
                $err = 'Bitte eine gültige quickinfo-URL angeben (z. B. https://192.168.1.10).';
            } elseif ($apiKey === '') {
                $err = 'Bitte den API-Schlüssel der quickinfo-Instanz angeben.';
            } else {
                $test = quickinfoTestPairing($url, $apiKey, (bool) $verifyTls);
                $db->prepare('UPDATE endpoints SET quickinfo_url = ?, quickinfo_api_key = ?, quickinfo_verify_tls = ? WHERE id = ?')
                   ->execute([$url, $apiKey, $verifyTls, $epId]);
                if ($test['ok']) {
                    $ok = sprintf('„%s“: %s', $epLabel, $test['message']);
                    writeLog('info', sprintf('quickinfo pairing saved for endpoint #%d (%s)', $epId, $url));
                } else {
                    $err = sprintf('„%s“: Pairing gespeichert – %s', $epLabel, $test['message']);
                    writeLog('warning', sprintf('quickinfo pairing test failed for endpoint #%d (%s): %s', $epId, $url, $test['message']));
                }
            }
        } elseif ($action === 'test_quickinfo') {
            $test = quickinfoTestPairing((string) $ep['quickinfo_url'], (string) ($ep['quickinfo_api_key'] ?? ''), (bool) $ep['quickinfo_verify_tls']);
            if ($test['ok']) {
                $ok = sprintf('„%s“: %s', $epLabel, $test['message']);
            } else {
                $err = sprintf('„%s“: %s', $epLabel, $test['message']);
            }
        } elseif ($action === 'unpair_quickinfo') {
            $db->prepare("UPDATE endpoints SET quickinfo_url = '', quickinfo_api_key = NULL, quickinfo_verify_tls = 0 WHERE id = ?")
               ->execute([$epId]);
            $ok = sprintf('„%s“: Pairing entfernt.', $epLabel);
            writeLog('info', sprintf('quickinfo pairing removed for endpoint #%d', $epId));
        } else {
            $err = 'Unbekannte Aktion.';
        }
    }

    if ($ok !== '')  { $_SESSION['qi_flash_ok'] = $ok; }
    if ($err !== '') { $_SESSION['qi_flash_error'] = $err; }
    header('Location: endpoint_tech.php#pairing-card');
    exit;
}

// ── Endpoint list for the pairing forms ──────────────────────────────────────
$endpoints = $db->query('
    SELECT id, alias, base_url, default_model, is_active, quickinfo_url, quickinfo_verify_tls,
           (quickinfo_api_key IS NOT NULL AND quickinfo_api_key <> \'\') AS has_key
    FROM endpoints
    ORDER BY sort_order ASC, id ASC
')->fetchAll(PDO::FETCH_ASSOC);

function qiShortUrl(string $url): string
{
    $h = parse_url($url, PHP_URL_HOST);
    $p = parse_url($url, PHP_URL_PORT);
    return $h ? $h . ($p ? ':' . $p : '') : $url;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Endpunkte technische Verwaltung – Admin</title>
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
            --mono:        ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100dvh; display: flex; flex-direction: column; }

        header {
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
            padding: 14px 24px; background: var(--surface); border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 5;
        }
        header h1 { font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .header-right { display: flex; align-items: center; gap: 14px; font-size: .82rem; color: var(--text-muted); }
        .header-right a { color: var(--text-muted); text-decoration: none; }
        .header-right a:hover { color: var(--text); }

        main { flex: 1; padding: 28px 32px; max-width: 1440px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 28px; min-width: 0; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px 24px; }
        .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .card h2 { font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .card p.hint { color: var(--text-muted); font-size: .82rem; line-height: 1.5; }
        .card p.hint code { font-family: var(--mono); font-size: .78rem; background: rgba(255,255,255,.06); padding: 1px 6px; border-radius: 5px; }

        .flash-ok, .flash-error { padding: 12px 16px; border-radius: 10px; font-size: .88rem; border: 1px solid; }
        .flash-ok    { background: rgba(34,197,94,.1);  border-color: rgba(34,197,94,.35);  color: #4ade80; }
        .flash-error { background: rgba(239,68,68,.1);  border-color: rgba(239,68,68,.35);  color: #f87171; }

        .btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: none;
            background: var(--accent); color: #fff; font-size: .84rem; font-weight: 500; cursor: pointer; text-decoration: none;
            transition: background .12s, transform .08s;
        }
        .btn:hover { background: var(--accent-dark); }
        .btn:active { transform: translateY(1px); }
        .btn.secondary { background: var(--surface-alt); color: var(--text); }
        .btn.secondary:hover { background: #454545; }
        .btn.danger { background: rgba(239,68,68,.14); color: #f87171; }
        .btn.danger:hover { background: rgba(239,68,68,.26); }
        .btn.small { padding: 6px 10px; font-size: .78rem; }

        /* ── Live overview ───────────────────────────────────────── */
        .live-meta { display: flex; align-items: center; gap: 10px; font-size: .78rem; color: var(--text-muted); }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--success); box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .live-dot.busy { background: var(--warning); box-shadow: 0 0 0 3px rgba(245,158,11,.18); animation: pulse 1s infinite alternate; }
        .live-dot.err  { background: var(--error);   box-shadow: 0 0 0 3px rgba(239,68,68,.18); }
        @keyframes pulse { from { opacity: .5; } to { opacity: 1; } }

        .ep-list { display: flex; flex-direction: column; gap: 10px; }

        .ep-row {
            display: grid;
            grid-template-columns: minmax(190px, 1.5fr) minmax(170px, 1.25fr) repeat(4, minmax(130px, 1fr)) 26px;
            gap: 14px; align-items: center;
            padding: 14px 18px;
            background: linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.015));
            border: 1px solid var(--border); border-radius: 12px;
            color: inherit; text-decoration: none;
            transition: border-color .15s, background .15s, transform .12s, box-shadow .15s;
        }
        a.ep-row:hover { border-color: rgba(108,99,255,.55); background: rgba(108,99,255,.07); transform: translateY(-1px); box-shadow: 0 6px 22px rgba(0,0,0,.28); }
        a.ep-row:hover .ep-open { color: var(--accent); transform: translate(2px, -2px); }
        .ep-row.unpaired { opacity: .72; }
        .ep-row.offline  { border-color: rgba(239,68,68,.3); }
        .ep-row.inactive .ep-name { text-decoration: line-through; text-decoration-color: var(--text-muted); }

        .ep-id { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .ep-name { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ep-status { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; background: #6b7280; }
        .ep-status.ok    { background: var(--success); box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .ep-status.stale { background: var(--warning); box-shadow: 0 0 0 3px rgba(245,158,11,.18); }
        .ep-status.err   { background: var(--error);   box-shadow: 0 0 0 3px rgba(239,68,68,.18); }
        .ep-sub { font-size: .74rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-family: var(--mono); }
        .ep-sub.warn { color: #fbbf24; font-family: var(--font); }
        .ep-sub.errtxt { color: #f87171; font-family: var(--font); }

        .ep-model { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .ep-model-name { font-size: .86rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ep-speed { display: inline-flex; align-items: center; gap: 5px; font-size: .78rem; color: #fbbf24; font-variant-numeric: tabular-nums; }
        .ep-speed .muted { color: var(--text-muted); }

        .metric { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .metric-head { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; }
        .metric-label { font-size: .66rem; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); font-weight: 600; }
        .metric-value { font-size: .92rem; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .metric-value.na { color: var(--text-muted); font-weight: 400; }
        .metric-bar { height: 5px; border-radius: 3px; background: rgba(255,255,255,.08); overflow: hidden; }
        .metric-bar > span { display: block; height: 100%; border-radius: 3px; background: var(--accent); transition: width .5s ease; }
        .metric-bar > span.ok   { background: linear-gradient(90deg, #22c55e, #4ade80); }
        .metric-bar > span.warn { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .metric-bar > span.hot  { background: linear-gradient(90deg, #ef4444, #f87171); }
        .metric-sub { display: flex; justify-content: space-between; gap: 6px; font-size: .7rem; color: var(--text-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .temp { font-weight: 600; }
        .temp.ok   { color: #4ade80; }
        .temp.warn { color: #fbbf24; }
        .temp.hot  { color: #f87171; }
        .ep-open { color: var(--text-muted); font-size: 1rem; text-align: right; transition: color .12s, transform .12s; }

        .ep-empty { padding: 30px; text-align: center; color: var(--text-muted); font-size: .88rem; border: 1px dashed var(--border); border-radius: 12px; }

        @media (max-width: 1100px) {
            .ep-row { grid-template-columns: repeat(4, minmax(120px, 1fr)); }
            .ep-id, .ep-model { grid-column: span 2; }
            .ep-open { display: none; }
        }
        @media (max-width: 760px) {
            main { padding: 18px 14px; }
            .ep-row { grid-template-columns: 1fr 1fr; }
            .ep-id, .ep-model { grid-column: 1 / -1; }
            .ep-open { display: none; }
        }

        /* ── Pairing table ───────────────────────────────────────── */
        .pair-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .pair-table th { text-align: left; font-size: .7rem; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); font-weight: 600; padding: 0 12px 2px; }
        .pair-table td { background: rgba(255,255,255,.025); padding: 12px; vertical-align: middle; font-size: .85rem; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .pair-table td:first-child { border-left: 1px solid var(--border); border-radius: 10px 0 0 10px; }
        .pair-table td:last-child  { border-right: 1px solid var(--border); border-radius: 0 10px 10px 0; }
        .pair-table input[type=text], .pair-table input[type=password] {
            width: 100%; background: var(--bg); border: 1px solid rgba(255,255,255,.12); border-radius: 8px; color: var(--text);
            padding: 8px 10px; font-size: .84rem; font-family: var(--mono);
        }
        .pair-table input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(108,99,255,.2); }
        .pair-name { font-weight: 600; white-space: nowrap; }
        .pair-name small { display: block; font-weight: 400; color: var(--text-muted); font-family: var(--mono); font-size: .72rem; margin-top: 2px; }
        .pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: .72rem; font-weight: 600; white-space: nowrap; }
        .pill.paired   { background: rgba(34,197,94,.14); color: #4ade80; }
        .pill.unpaired { background: rgba(255,255,255,.07); color: var(--text-muted); }
        .pair-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .tls-label { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; color: var(--text-muted); white-space: nowrap; cursor: pointer; }
        .tls-label input { accent-color: var(--accent); }
        @media (max-width: 900px) {
            .pair-table, .pair-table thead, .pair-table tbody, .pair-table tr, .pair-table td { display: block; }
            .pair-table thead { display: none; }
            .pair-table tr { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 10px; overflow: hidden; }
            .pair-table td { border: none !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body>

<header>
    <h1><span>🛠️</span> Endpunkte technische Verwaltung</h1>
    <div class="header-right">
        <span>Angemeldet als <strong><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></span>
        <a href="index.php">← Admin-Bereich</a>
        <a href="../index.php">Zum Chat</a>
        <a href="logout.php">Abmelden</a>
    </div>
</header>

<main>

    <?php if ($flashOk !== ''): ?>
        <div class="flash-ok">✓ <?= htmlspecialchars($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash-error">⚠ <?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- ── Live overview ─────────────────────────────────────────────────── -->
    <section class="card" id="overview-card">
        <div class="card-head">
            <h2><span>📡</span> Technische Übersicht</h2>
            <div class="live-meta">
                <span class="live-dot" id="live-dot"></span>
                <span id="live-text">Lade Daten …</span>
                <button type="button" class="btn secondary small" id="refresh-btn">↻ Aktualisieren</button>
            </div>
        </div>
        <p class="hint" style="margin-bottom:14px">
            Momentanwerte der gekoppelten quickinfo-Instanzen; Temperatur-Min./Max. beziehen sich auf die letzten 24&nbsp;Stunden.
            Modell und Ø&nbsp;Token/s stammen aus LLMInt (heute). Ein Klick auf eine Zeile öffnet quickinfo des Endpunkts in einem neuen Tab.
        </p>
        <div class="ep-list" id="ep-list">
            <div class="ep-empty">Lade Endpunkte …</div>
        </div>
    </section>

    <!-- ── Pairing ────────────────────────────────────────────────────────── -->
    <section class="card" id="pairing-card">
        <div class="card-head">
            <h2><span>🔗</span> Pairing mit quickinfo</h2>
            <a class="btn secondary small" href="https://github.com/dareinelt/quickinfo" target="_blank" rel="noopener noreferrer">quickinfo auf GitHub ↗</a>
        </div>
        <p class="hint" style="margin-bottom:16px">
            Jeder Endpunkt kann mit einer quickinfo-Instanz gekoppelt werden. Server-URL (z.&nbsp;B. <code>https://192.168.1.10</code>) und den
            API-Schlüssel eintragen – der Schlüssel wird in quickinfo unter <em>Einstellungen → API &amp; Management-Board</em> erzeugt
            oder per <code>php bin/apikey.php rotate</code>. quickinfo nutzt standardmäßig ein selbstsigniertes Zertifikat; die
            TLS-Prüfung nur aktivieren, wenn ein gültiges Zertifikat installiert ist. Ein leeres Schlüsselfeld behält den gespeicherten Schlüssel.
        </p>

        <?php if (empty($endpoints)): ?>
            <div class="ep-empty">Noch keine Endpunkte angelegt. Endpunkte werden im <a href="index.php#config-endpoints-card" style="color:var(--accent)">Admin-Bereich</a> verwaltet.</div>
        <?php else: ?>
        <table class="pair-table">
            <thead>
                <tr>
                    <th>Endpunkt</th>
                    <th>Status</th>
                    <th>quickinfo-URL</th>
                    <th>API-Schlüssel</th>
                    <th>TLS</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($endpoints as $ep):
                $paired = trim((string) $ep['quickinfo_url']) !== '' && (int) $ep['has_key'] === 1;
                $fid = 'pair-' . (int) $ep['id'];
            ?>
                <tr>
                    <td class="pair-name">
                        <?= htmlspecialchars(trim((string) $ep['alias']) !== '' ? $ep['alias'] : qiShortUrl((string) $ep['base_url'])) ?>
                        <small><?= htmlspecialchars($ep['default_model'] !== '' ? $ep['default_model'] : qiShortUrl((string) $ep['base_url'])) ?></small>
                    </td>
                    <td>
                        <?php if ($paired): ?>
                            <span class="pill paired">● gekoppelt</span>
                        <?php else: ?>
                            <span class="pill unpaired">○ nicht gekoppelt</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" id="<?= $fid ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="pair_quickinfo">
                            <input type="hidden" name="endpoint_id" value="<?= (int) $ep['id'] ?>">
                            <input type="text" name="quickinfo_url" placeholder="https://192.168.1.10"
                                   value="<?= htmlspecialchars((string) $ep['quickinfo_url']) ?>" autocomplete="off" spellcheck="false">
                        </form>
                    </td>
                    <td>
                        <input type="password" name="quickinfo_api_key" form="<?= $fid ?>" autocomplete="new-password"
                               placeholder="<?= $paired ? '•••••••• (gespeichert – leer lassen zum Behalten)' : 'API-Schlüssel aus quickinfo' ?>">
                    </td>
                    <td>
                        <label class="tls-label">
                            <input type="checkbox" name="quickinfo_verify_tls" form="<?= $fid ?>" value="1" <?= (int) $ep['quickinfo_verify_tls'] === 1 ? 'checked' : '' ?>>
                            prüfen
                        </label>
                    </td>
                    <td>
                        <div class="pair-actions">
                            <button type="submit" class="btn small" form="<?= $fid ?>"><?= $paired ? '💾 Speichern & Testen' : '🔗 Koppeln' ?></button>
                            <?php if ($paired): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="test_quickinfo">
                                    <input type="hidden" name="endpoint_id" value="<?= (int) $ep['id'] ?>">
                                    <button type="submit" class="btn secondary small">⚡ Testen</button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Pairing für diesen Endpunkt wirklich entfernen?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="unpair_quickinfo">
                                    <input type="hidden" name="endpoint_id" value="<?= (int) $ep['id'] ?>">
                                    <button type="submit" class="btn danger small">✕ Entkoppeln</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

</main>

<script>
(() => {
    const REFRESH_MS = 30000;
    const list    = document.getElementById('ep-list');
    const liveDot = document.getElementById('live-dot');
    const liveTxt = document.getElementById('live-text');
    const refreshBtn = document.getElementById('refresh-btn');
    let timer = null;
    let inflight = false;

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const shortUrl = url => { try { const u = new URL(url); return u.hostname + (u.port ? ':' + u.port : ''); } catch (_) { return url; } };
    const fmtPct  = v => (v === null || v === undefined || isNaN(v)) ? null : Math.round(v * 10) / 10;
    const fmtGiB  = bytes => (bytes / 1073741824).toFixed(bytes >= 107374182400 ? 0 : 1);
    const fmtMiB  = mb => mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Math.round(mb) + ' MB';
    const fmtTemp = t => (t === null || t === undefined) ? '–' : Math.round(t) + '°';
    const loadCls = pct => pct === null ? '' : (pct < 60 ? 'ok' : pct < 85 ? 'warn' : 'hot');
    const tempCls = t => (t === null || t === undefined) ? '' : (t < 70 ? 'ok' : t < 85 ? 'warn' : 'hot');

    function metric(label, pct, valueHtml, subHtml, opts = {}) {
        const p = fmtPct(pct);
        const bar = p === null
            ? `<div class="metric-bar"><span style="width:0"></span></div>`
            : `<div class="metric-bar"><span class="${loadCls(p)}" style="width:${Math.min(100, Math.max(0, p))}%"></span></div>`;
        return `<div class="metric" title="${esc(opts.title || '')}">
            <div class="metric-head"><span class="metric-label">${label}</span>${valueHtml}</div>
            ${bar}
            <div class="metric-sub">${subHtml}</div>
        </div>`;
    }

    function tempSpan(t) {
        return t === null || t === undefined
            ? `<span class="metric-value na">–</span>`
            : `<span class="metric-value temp ${tempCls(t)}">${fmtTemp(t)}</span>`;
    }

    function minMax(min, max) {
        if (min === null || max === null || min === undefined || max === undefined) return 'min – / max –';
        return `min ${fmtTemp(min)} / max ${fmtTemp(max)}`;
    }

    function renderEndpoint(ep) {
        const m = ep.metrics;
        const name = ep.alias || shortUrl(ep.base_url);
        const model = ep.default_model || '–';
        const speed = ep.today_avg_tokens_per_second > 0 ? ep.today_avg_tokens_per_second.toFixed(1) + ' Tok/s' : '<span class="muted">– Tok/s</span>';

        let statusCls = '', sub = '', rowCls = '';
        if (!ep.paired) {
            statusCls = '';
            sub = `<span class="ep-sub">nicht gekoppelt · <a href="#pairing-card" style="color:var(--accent);text-decoration:none">jetzt koppeln</a></span>`;
            rowCls = 'unpaired';
        } else if (!m || !m.ok) {
            statusCls = 'err';
            sub = `<span class="ep-sub errtxt" title="${esc(m ? m.error : '')}">quickinfo nicht erreichbar${m && m.error ? ' · ' + esc(m.error) : ''}</span>`;
            rowCls = 'offline';
        } else if (m.stale) {
            statusCls = 'stale';
            sub = `<span class="ep-sub warn">${esc(m.hostname || shortUrl(ep.quickinfo_url))} · Daten veraltet (${Math.round((m.snapshot_age || 0) / 60)} min)</span>`;
        } else {
            statusCls = 'ok';
            sub = `<span class="ep-sub">${esc(m.hostname || shortUrl(ep.quickinfo_url))}${m.gpu_model ? ' · ' + esc(m.gpu_model) : ''}</span>`;
        }
        if (!ep.is_active) rowCls += ' inactive';

        const ok = ep.paired && m && m.ok;
        const cpuPct  = ok ? m.cpu_util : null;
        const gpuPct  = ok ? m.gpu_util : null;
        const ramPct  = ok ? m.ram_pct : null;
        const vramPct = ok ? m.vram_pct : null;

        const cpuVal = ok && cpuPct !== null ? `<span class="metric-value">${fmtPct(cpuPct)}%</span>` : `<span class="metric-value na">–</span>`;
        const gpuVal = ok && gpuPct !== null ? `<span class="metric-value">${fmtPct(gpuPct)}%</span>` : `<span class="metric-value na">–</span>`;
        const ramVal = ok && ramPct !== null ? `<span class="metric-value">${fmtPct(ramPct)}%</span>` : `<span class="metric-value na">–</span>`;
        const vramVal = ok && vramPct !== null ? `<span class="metric-value">${fmtPct(vramPct)}%</span>` : `<span class="metric-value na">–</span>`;

        const cpuSub = ok
            ? `<span>Temp ${tempSpan(m.cpu_temp)}</span><span>${minMax(m.cpu_temp_min, m.cpu_temp_max)}</span>`
            : `<span>Temp –</span><span>min – / max –</span>`;
        const gpuSub = ok && m.gpu_count > 0
            ? `<span>Temp ${tempSpan(m.gpu_temp)}</span><span>${minMax(m.gpu_temp_min, m.gpu_temp_max)}</span>`
            : `<span>${ok ? 'keine GPU' : 'Temp –'}</span><span>min – / max –</span>`;
        const ramSub = ok && m.ram_total
            ? `<span>${fmtGiB(m.ram_used)} / ${fmtGiB(m.ram_total)} GB</span><span>${m.cpu_cores ? m.cpu_cores + ' Kerne' : ''}</span>`
            : `<span>–</span><span></span>`;
        const vramSub = ok && m.vram_total_mb
            ? `<span>${fmtMiB(m.vram_used_mb)} / ${fmtMiB(m.vram_total_mb)}</span><span>${m.gpu_power_w !== null ? Math.round(m.gpu_power_w) + ' W' : (m.gpu_count > 1 ? m.gpu_count + ' GPUs' : '')}</span>`
            : `<span>–</span><span></span>`;

        const inner = `
            <div class="ep-id">
                <div class="ep-name"><span class="ep-status ${statusCls}"></span><span title="${esc(ep.base_url)}">${esc(name)}</span></div>
                ${sub}
            </div>
            <div class="ep-model">
                <div class="ep-model-name" title="${esc(model)}">🧠 ${esc(model)}</div>
                <div class="ep-speed">⚡ Ø ${speed}</div>
            </div>
            ${metric('CPU-Last', cpuPct, cpuVal, cpuSub, { title: m && m.cpu_model ? m.cpu_model : '' })}
            ${metric('GPU-Last', gpuPct, gpuVal, gpuSub, { title: m && m.gpu_model ? m.gpu_model : '' })}
            ${metric('RAM', ramPct, ramVal, ramSub)}
            ${metric('VRAM', vramPct, vramVal, vramSub)}
            <div class="ep-open">${ep.paired ? '↗' : ''}</div>`;

        if (ep.paired && ep.quickinfo_url) {
            return `<a class="ep-row ${rowCls}" href="${esc(ep.quickinfo_url)}" target="_blank" rel="noopener noreferrer" title="quickinfo von ${esc(name)} öffnen">${inner}</a>`;
        }
        return `<div class="ep-row ${rowCls}">${inner}</div>`;
    }

    async function load() {
        if (inflight) return;
        inflight = true;
        liveDot.className = 'live-dot busy';
        liveTxt.textContent = 'Aktualisiere …';
        try {
            const res = await fetch('quickinfo_stats.php', { cache: 'no-store', credentials: 'same-origin' });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || data.message || 'Fehler');
            if (!data.endpoints.length) {
                list.innerHTML = '<div class="ep-empty">Noch keine Endpunkte angelegt.</div>';
            } else {
                list.innerHTML = data.endpoints.map(renderEndpoint).join('');
            }
            const paired = data.endpoints.filter(e => e.paired).length;
            const online = data.endpoints.filter(e => e.paired && e.metrics && e.metrics.ok).length;
            liveDot.className = 'live-dot' + (paired > 0 && online < paired ? ' err' : '');
            liveTxt.textContent = `${online}/${paired} gekoppelt erreichbar · ${new Date(data.ts * 1000).toLocaleTimeString('de-DE')}`;
        } catch (e) {
            liveDot.className = 'live-dot err';
            liveTxt.textContent = 'Fehler: ' + e.message;
        } finally {
            inflight = false;
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(async () => { await load(); schedule(); }, REFRESH_MS);
    }

    refreshBtn.addEventListener('click', async () => { await load(); schedule(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { load(); schedule(); } });

    load().then(schedule);
})();
</script>
</body>
</html>
