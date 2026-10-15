<?php

/**
 * admin/partials/layout_head.php
 *
 * Shared shell of every admin page: <head> with the stylesheet, the header
 * bar, the left navigation and the opening <main> including the flash
 * messages.
 *
 * Expects the shared bootstrap to be loaded and the including page to have
 * set $adminPage (navigation slug) and $adminPageTitle.
 */

require_once __DIR__ . '/nav.php';

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= htmlspecialchars($adminPageTitle) ?></title>
    <link rel="stylesheet" href="../assets/css/flow-topology.css">
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

        /* ── Page body (sidebar + main) ─────────────────────────── */
        .page-body {
            display: flex;
            flex: 1;
            align-items: flex-start;
            min-height: 0;
        }

        /* ── Left navigation sidebar ─────────────────────────────── */
        .sidebar {
            position: sticky;
            top: 0;
            height: 100dvh;
            width: 200px;
            min-width: 200px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 20px 0 20px;
            gap: 2px;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .sidebar-label {
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 14px 16px 6px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 16px;
            font-size: .82rem;
            color: var(--text-muted);
            text-decoration: none;
            border-left: 2px solid transparent;
            transition: color .12s, background .12s, border-color .12s;
            white-space: nowrap;
        }

        .sidebar a:hover {
            color: var(--text);
            background: rgba(255,255,255,.04);
        }

        .sidebar a.active {
            color: var(--accent);
            border-left-color: var(--accent);
            background: rgba(108,99,255,.08);
        }

        /* ── Main layout ─────────────────────────────────────────── */
        main {
            flex: 1;
            padding: 28px 32px;
            max-width: 1440px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 28px;
            min-width: 0;
        }

        /* ── Card ────────────────────────────────────────────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            order: 10;
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

        #dashboard-card { order: 1; }
        #usage-stats-card { order: 2; }
        #config-smtp-card { order: 3; }
        #config-speech-card { order: 4; }
        #config-searxng-card { order: 5; }
        #config-endpoints-card { order: 6; }
        #config-request-handling-card { order: 7; }
        #config-global-system-prompt-card { order: 8; }
        #config-balancer-card { order: 9; }
        #config-image-card { order: 10; }
        #config-routing-card { order: 12; }
        #config-vector-store-card { order: 13; }
        #config-embedding-card { order: 14; }
        #config-hybrid-search-card { order: 15; }
        #config-reranker-card { order: 16; }
        #embedding-stats-card { order: 17; }
        #config-system-messages-card { order: 18; }
        #log-config-card { order: 19; }
        #log-viewer-card { order: 20; }

        /* ── Vektordatenbank ─────────────────────────────────────── */
        .vector-mode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .vector-mode-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 400;
        }
        .vector-mode-option.active { border-color: var(--accent); background: rgba(16,185,129,.08); }
        .vector-mode-option input { margin-top: 3px; accent-color: var(--accent); }
        .vector-mode-option span { display: flex; flex-direction: column; gap: 2px; }
        .vector-mode-option small { color: var(--text-muted); font-size: .76rem; }
        .vector-mode-fields {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px 4px;
            margin: 8px 0 16px;
        }
        .vector-mode-fields legend { padding: 0 6px; font-weight: 600; font-size: .9rem; }
        .vector-mode-fields[hidden] { display: none; }

        /* ── Nutzungsstatistik ───────────────────────────────────── */
        .usage-range {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .usage-range button {
            background: var(--surface-alt);
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: .8rem;
            font-family: inherit;
            cursor: pointer;
            transition: background .15s, color .15s;
        }

        .usage-range button:hover { color: var(--text); }

        .usage-range button.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .usage-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin: 14px 0 4px;
        }

        .usage-legend .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .8rem;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        .usage-legend .legend-item.on { color: var(--text); }

        .usage-legend .legend-swatch {
            width: 14px;
            height: 3px;
            border-radius: 2px;
            opacity: .35;
        }

        .usage-legend .legend-item.on .legend-swatch { opacity: 1; }

        .usage-chart-wrap { position: relative; }

        #usage-chart {
            width: 100%;
            height: 320px;
            display: block;
        }

        .usage-tooltip {
            position: absolute;
            pointer-events: none;
            display: none;
            background: rgba(20,20,20,.95);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: .75rem;
            line-height: 1.5;
            white-space: nowrap;
            z-index: 5;
        }

        /* ── User row hover ──────────────────────────────────────── */
        .user-row:hover td { background: rgba(108,99,255,.06); }

        .config-panel > summary {
            list-style: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .config-panel > summary::-webkit-details-marker { display: none; }
        .config-panel > summary::before {
            content: '▸';
            font-size: .9rem;
            color: var(--text-muted);
            transform: translateY(1px);
        }
        .config-panel[open] > summary::before { content: '▾'; }

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
        input[type="email"],
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

        /* ── Dashboard header (title, topology toolbar, live indicator) ── */
        .tree-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .tree-header-row h2 { margin: 0; padding: 0; border: none; }

        .tree-header-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

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

        /* ── Stat-box flash on live update ───────────────────────── */
        @keyframes stat-flash {
            0%   { background: rgba(108,99,255,.32); }
            100% { background: var(--surface-alt); }
        }
        .stat-box.stat-flash { animation: stat-flash .55s ease-out; }

        /* ── Dashboard detail tables (collapsed by default) ──────── */
        .dash-detail-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-muted);
            user-select: none;
        }
        .dash-detail-toggle:hover { color: var(--text); }
        .dash-detail-toggle .toggle-arrow { transition: transform .2s; }
        .dash-detail-toggle.open .toggle-arrow { transform: rotate(90deg); }
        .dash-detail-body { display: none; margin-top: 14px; }
        .dash-detail-body.open { display: block; }
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

<div class="page-body">

<?php admin_nav($adminPage); ?>

<main>

    <?php if (!empty($_SESSION['requires_password_change'])): ?>
        <div class="flash-error" style="border-color:var(--warning);color:var(--warning);background:rgba(245,158,11,.1)">
            ⚠ Du hast dich mit einem temporären Passwort angemeldet.
            Bitte ändere dein Passwort sofort unter <strong>Passwort ändern</strong>.
        </div>
    <?php endif; ?>

    <?php if ($flashOk !== ''): ?>
        <div class="flash-ok">✓ <?= htmlspecialchars($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="flash-error">✗ <?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

