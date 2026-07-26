<!DOCTYPE html>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
$defaultModel = getSetting('default_model', '');
if ($defaultModel === '') {
    // Fall back to the first active endpoint's configured model.
    try {
        $ep = getDb()->query(
            "SELECT default_model FROM endpoints WHERE is_active = 1 AND default_model != '' ORDER BY sort_order ASC, id ASC LIMIT 1"
        )->fetch();
        if ($ep) {
            $defaultModel = $ep['default_model'];
        }
    } catch (PDOException $e) {
        // Ignore – endpoints table may not exist yet (before setup.php is run).
    }
}

$loggedIn   = isset($_SESSION['admin_user']);
$loggedUser = $loggedIn ? htmlspecialchars((string) $_SESSION['admin_user']) : '';
?>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KHWF KI</title>
    <style>
        /* ── Reset & base ─────────────────────────────────────────── */
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
            --user-bg:     #2f2f2f;
            --error:       #ef4444;
            --success:     #22c55e;
            --radius:      12px;
            --radius-lg:   20px;
            --font:        ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            --max-w:       760px;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ── Header ───────────────────────────────────────────────── */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            height: 52px;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }

        header h1 {
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: .01em;
        }

        header .admin-link {
            font-size: .8rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: 4px 12px;
            border-radius: 8px;
            transition: background .15s, color .15s;
        }

        header .admin-link:hover { background: var(--surface); color: var(--text); }

        /* ── Config bar (hidden – keeps DOM refs for JS) ──────────── */
        #config-bar { display: none; }

        /* ── Chat area ─────────────────────────────────────────────── */
        #chat-area {
            flex: 1 1 0;
            overflow-y: auto;
            scroll-behavior: smooth;
            padding: 8px 0;
        }

        /* ── Welcome screen ────────────────────────────────────────── */
        #welcome {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            gap: 10px;
            text-align: center;
            padding: 32px 16px;
        }

        .welcome-logo { font-size: 2.8rem; margin-bottom: 8px; }

        #welcome h2 { font-size: 1.6rem; font-weight: 600; }

        #welcome p { font-size: .9rem; color: var(--text-muted); max-width: 380px; }

        /* ── Message rows ──────────────────────────────────────────── */
        .message {
            display: flex;
            gap: 12px;
            max-width: var(--max-w);
            margin: 0 auto;
            padding: 10px 24px;
            width: 100%;
            align-items: flex-start;
        }

        .message.user { flex-direction: row-reverse; }

        .message.system-msg { justify-content: center; }

        /* ── Avatar (assistant only) ───────────────────────────────── */
        .avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .62rem;
            font-weight: 700;
            flex-shrink: 0;
            margin-top: 3px;
            letter-spacing: .02em;
        }

        .message.assistant .avatar { background: var(--accent); color: #fff; }

        /* ── Bubbles ───────────────────────────────────────────────── */
        .bubble {
            line-height: 1.65;
            font-size: .94rem;
            word-break: break-word;
        }

        /* User: rounded pill, no avatar shown */
        .message.user .bubble {
            background: var(--user-bg);
            border-radius: var(--radius-lg) var(--radius-lg) 4px var(--radius-lg);
            padding: 10px 16px;
            max-width: 80%;
            white-space: pre-wrap;
        }

        /* Assistant: no background, full width */
        .message.assistant .bubble {
            flex: 1;
            min-width: 0;
            white-space: normal;
        }

        .assistant-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .response-details {
            margin-top: 8px;
            width: 100%;
            color: var(--text-muted);
            font-size: .8rem;
        }

        .response-details > summary {
            cursor: pointer;
            user-select: none;
        }

        .response-details-body {
            margin-top: 6px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
        }

        /* System message */
        .message.system-msg .bubble {
            color: var(--text-muted);
            font-size: .8rem;
        }

        .message.intelligence-upgrade {
            justify-content: flex-start;
            padding-top: 0;
        }

        .intelligence-upgrade-card {
            margin-left: 40px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 12px;
            max-width: min(760px, 100%);
            font-size: .84rem;
            line-height: 1.45;
            color: var(--text-muted);
        }

        .intelligence-upgrade-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .intelligence-upgrade-actions button {
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: .8rem;
            cursor: pointer;
            background: var(--surface-alt);
            color: var(--text);
        }

        .intelligence-upgrade-actions button:hover { opacity: .92; }
        .intelligence-upgrade-actions .yes { background: var(--accent); color: #fff; }

        /* Streaming cursor */
        .message.assistant .bubble.streaming::after {
            content: '▋';
            animation: blink .8s step-end infinite;
        }

        @keyframes blink { 50% { opacity: 0; } }

        /* ── Markdown inside assistant bubbles ─────────────────────── */
        .message.assistant .bubble h1,
        .message.assistant .bubble h2,
        .message.assistant .bubble h3,
        .message.assistant .bubble h4 {
            margin: .8em 0 .3em;
            font-weight: 600;
            line-height: 1.3;
        }
        .message.assistant .bubble h1 { font-size: 1.2rem; }
        .message.assistant .bubble h2 { font-size: 1.05rem; }
        .message.assistant .bubble h3 { font-size: .97rem; }
        .message.assistant .bubble h1:first-child,
        .message.assistant .bubble h2:first-child,
        .message.assistant .bubble h3:first-child { margin-top: 0; }

        .message.assistant .bubble p { margin: .4em 0; }
        .message.assistant .bubble p:first-child { margin-top: 0; }
        .message.assistant .bubble p:last-child  { margin-bottom: 0; }

        .message.assistant .bubble ul,
        .message.assistant .bubble ol { margin: .4em 0 .4em 1.4em; }
        .message.assistant .bubble li { margin: .15em 0; }

        .message.assistant .bubble code {
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: .83em;
            background: rgba(255,255,255,.07);
            padding: .1em .35em;
            border-radius: 4px;
        }
        .message.assistant .bubble pre {
            background: #1a1a1a;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin: .6em 0;
            overflow-x: auto;
        }
        .message.assistant .bubble pre code {
            background: none;
            padding: 0;
            font-size: .83rem;
            white-space: pre;
        }
        .message.assistant .bubble hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: .7em 0;
        }
        .message.assistant .bubble blockquote {
            border-left: 3px solid var(--accent);
            margin: .5em 0;
            padding: .2em .8em;
            color: var(--text-muted);
        }

        /* ── Thinking bubble (reasoning tokens, semi-transparent during streaming) ── */
        .bubble .thinking-bubble {
            opacity: 0.45;
            font-size: .83rem;
            font-style: italic;
            color: var(--text-muted);
            padding: 6px 10px;
            border-left: 2px solid var(--accent);
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* ── Input outer (fade + full-width bg) ───────────────────── */
        #input-outer {
            flex-shrink: 0;
            background: linear-gradient(to bottom, transparent, var(--bg) 30%);
            padding-top: 24px;
        }

        /* ── Input section (max-width centered) ────────────────────── */
        #input-section {
            max-width: var(--max-w);
            margin: 0 auto;
            padding: 0 16px 16px;
        }

        /* ── System prompt ─────────────────────────────────────────── */
        #system-prompt-wrap {
            display: none;
            margin-bottom: 8px;
        }

        #system-prompt-label {
            font-size: .75rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        #system-prompt-wrap textarea {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-family: var(--font);
            font-size: .85rem;
            padding: 10px 14px;
            resize: vertical;
            min-height: 72px;
            outline: none;
        }

        #system-prompt-wrap textarea:focus { border-color: var(--accent); }

        /* ── Input box (textarea + action buttons) ─────────────────── */
        #input-box {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 10px 10px 10px 16px;
            transition: border-color .15s;
        }

        #input-box:focus-within { border-color: rgba(108,99,255,.45); }

        #user-input {
            flex: 1;
            background: none;
            border: none;
            color: var(--text);
            font-family: var(--font);
            font-size: .94rem;
            resize: none;
            min-height: 24px;
            max-height: 200px;
            line-height: 1.55;
            outline: none;
            padding: 0;
            overflow-y: auto;
        }

        #user-input::placeholder { color: var(--text-muted); }

        #clear-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: .85rem;
            transition: background .15s, color .15s;
        }

        #clear-btn:hover { background: var(--surface-alt); color: var(--text); }

        #send-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.1rem;
            font-weight: 600;
            transition: background .15s, opacity .15s;
        }

        #send-btn:hover:not(:disabled) { background: var(--accent-dark); }
        #send-btn:disabled { opacity: .35; cursor: default; }

        /* ── Input meta row (system-prompt toggle + status) ────────── */
        #input-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 2px 0;
            min-height: 22px;
        }

        #system-toggle {
            font-size: .75rem;
            color: var(--text-muted);
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            text-decoration: underline dotted;
        }

        #system-toggle:hover { color: var(--text); }

        #status-bar { font-size: .75rem; }
        #status-bar.ok    { color: var(--success); }
        #status-bar.error { color: var(--error); }
        #status-bar.info  { color: var(--text-muted); }

        /* ── Scrollbar ─────────────────────────────────────────────── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 3px; }

        /* ── Layout wrapper (header + below) ───────────────────────── */
        #content-wrapper {
            flex: 1 1 0;
            display: flex;
            flex-direction: row;
            overflow: hidden;
        }

        /* ── Sidebar (session list, logged-in users only) ──────────── */
        #sidebar {
            width: 240px;
            flex-shrink: 0;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #sidebar-header {
            padding: 10px 10px 6px;
            flex-shrink: 0;
        }

        #new-chat-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            padding: 9px 12px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: .85rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
        }

        #new-chat-btn:hover { background: var(--accent-dark); }

        #session-list {
            flex: 1 1 0;
            overflow-y: auto;
            padding: 4px 6px 10px;
        }

        .session-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            padding: 8px 10px;
            border-radius: 8px;
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-muted);
            transition: background .12s, color .12s;
            overflow: hidden;
        }

        .session-item:hover { background: var(--surface-alt); color: var(--text); }

        .session-item.active { background: var(--surface-alt); color: var(--text); }

        .session-title {
            flex: 1;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .session-delete {
            flex-shrink: 0;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: .75rem;
            padding: 2px 4px;
            border-radius: 4px;
            opacity: 0;
            transition: opacity .12s, color .12s;
        }

        .session-item:hover .session-delete { opacity: 1; }
        .session-delete:hover { color: var(--error); }

        /* ── Main area (chat + input) ───────────────────────────────── */
        #main-area {
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header>
    <h1>🤖 KHWF KI</h1>
    <div style="display:flex;align-items:center;gap:10px">
<?php if ($loggedIn): ?>
        <span style="font-size:.8rem;color:var(--text-muted)">👤 <?= $loggedUser ?></span>
        <a href="logout.php" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:background .12s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">Abmelden</a>
<?php else: ?>
        <a href="login.php" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:background .12s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">🔐 Anmelden</a>
        <a href="register.php" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:background .12s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">✍ Registrieren</a>
<?php endif; ?>
        <a class="admin-link" href="admin/login.php">⚙ Admin</a>
    </div>
</header>

<!-- ── Config bar (hidden – DOM refs kept for JS) ─────────── -->
<div id="config-bar">
    <label for="model-select">Modell:</label>
    <select id="model-select"><option value="">– Modelle laden –</option></select>
    <button id="refresh-btn">⟳ Modelle laden</button>
</div>

<!-- ── Content wrapper (sidebar + main) ───────────────────── -->
<div id="content-wrapper">

<?php if ($loggedIn): ?>
<!-- ── Session sidebar (logged-in users) ──────────────────── -->
<nav id="sidebar">
    <div id="sidebar-header">
        <button id="new-chat-btn">✏ Neuer Chat</button>
    </div>
    <div id="session-list"></div>
</nav>
<?php endif; ?>

<!-- ── Main area (chat + input) ───────────────────────────── -->
<div id="main-area">

<!-- ── Chat messages ──────────────────────────────────────── -->
<div id="chat-area"></div>

<!-- ── Input (bottom, centered) ───────────────────────────── -->
<div id="input-outer">
    <div id="input-section">

        <!-- System prompt (shown on demand above the input box) -->
        <div id="system-prompt-wrap">
            <div id="system-prompt-label">System-Prompt (optional):</div>
            <textarea id="system-prompt" rows="3"
                      placeholder="Du bist ein hilfreicher Assistent …"></textarea>
        </div>

        <!-- Main input container -->
        <div id="input-box">
            <textarea id="user-input" rows="1"
                      placeholder="Nachricht schreiben … (Enter = Senden, Shift+Enter = Zeilenumbruch)"></textarea>
            <button id="clear-btn" title="Verlauf löschen">🗑</button>
            <button id="send-btn" title="Senden">↑</button>
        </div>

        <!-- Meta row: system-prompt toggle + status -->
        <div id="input-meta">
            <button id="system-toggle">System-Prompt ▾</button>
            <span id="status-bar" class="info">Bereit</span>
        </div>

    </div>
</div>

</div><!-- /#main-area -->
</div><!-- /#content-wrapper -->

<script>
/* ─────────────────────────────────────────────────────────────────
   LM Studio Chat – Frontend
   ─────────────────────────────────────────────────────────────── */

(function () {
    'use strict';

    /* DOM refs */
    const modelSelect     = document.getElementById('model-select');
    const refreshBtn      = document.getElementById('refresh-btn');
    const statusBar       = document.getElementById('status-bar');
    const chatArea        = document.getElementById('chat-area');
    const userInput       = document.getElementById('user-input');
    const sendBtn         = document.getElementById('send-btn');
    const clearBtn        = document.getElementById('clear-btn');
    const systemToggle    = document.getElementById('system-toggle');
    const systemPromptWrap = document.getElementById('system-prompt-wrap');
    const systemPromptTA  = document.getElementById('system-prompt');

    /* ── Welcome screen ──────────────────────────────────── */

    function showWelcome() {
        chatArea.innerHTML =
            '<div id="welcome">' +
            '<div class="welcome-logo">🤖</div>' +
            '<h2>Wie kann ich helfen?</h2>' +
            '<p>Stelle eine Frage – ich helfe dir gerne weiter.</p>' +
            '</div>';
    }

    function hideWelcome() {
        const w = document.getElementById('welcome');
        if (w) w.remove();
    }

    /* Default model set by the admin */
    const defaultModel = <?= json_encode($defaultModel) ?>;

    /* Chat history (role / content pairs sent to the API) */
    let history = [];
    let isStreaming = false;
    let activeUpgradePrompt = null;

    /* ── Session ID (persists conversation server-side) ─── */
    let sessionId = sessionStorage.getItem('chat_session_id') || '';
    if (!sessionId) {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        sessionId = Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
        sessionStorage.setItem('chat_session_id', sessionId);
    }

    /* Whether the current user is logged in (set by PHP) */
    const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;

    /* ── Sidebar session management ──────────────────────── */

    const sessionListEl = document.getElementById('session-list');
    const newChatBtn    = document.getElementById('new-chat-btn');

    function generateSessionId() {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    function truncate(str, max) {
        if (!str) return '(Neuer Chat)';
        return str.length > max ? str.slice(0, max) + '…' : str;
    }

    /** Render the list of sessions in the sidebar. */
    function renderSessionList(sessions) {
        if (!sessionListEl) return;
        sessionListEl.innerHTML = '';
        if (!sessions || sessions.length === 0) {
            const empty = document.createElement('div');
            empty.style.cssText = 'padding:12px 10px;font-size:.78rem;color:var(--text-muted)';
            empty.textContent = 'Noch keine Chats gespeichert.';
            sessionListEl.appendChild(empty);
            return;
        }
        sessions.forEach(s => {
            const item = document.createElement('div');
            item.className = 'session-item' + (s.session_id === sessionId ? ' active' : '');
            item.dataset.sessionId = s.session_id;

            const titleEl = document.createElement('span');
            titleEl.className = 'session-title';
            titleEl.textContent = truncate(s.title || '', 60);
            titleEl.title = s.title || '';

            const delBtn = document.createElement('button');
            delBtn.className = 'session-delete';
            delBtn.title = 'Chat löschen';
            delBtn.textContent = '✕';
            delBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                if (!confirm('Diesen Chat-Verlauf löschen?')) return;
                await deleteSession(s.session_id);
            });

            item.appendChild(titleEl);
            item.appendChild(delBtn);

            item.addEventListener('click', () => loadSession(s.session_id));
            sessionListEl.appendChild(item);
        });
    }

    /** Load and display the list of user sessions. */
    async function refreshSessionList() {
        if (!loggedIn || !sessionListEl) return;
        try {
            const res = await fetch('api/chat_sessions.php?action=list');
            if (!res.ok) return;
            const sessions = await res.json();
            renderSessionList(sessions);
        } catch (_) { /* ignore */ }
    }

    /** Switch to a different conversation from the sidebar. */
    async function loadSession(sid) {
        if (isStreaming) return;
        if (sid === sessionId) return;
        try {
            const res = await fetch('api/chat_sessions.php?action=load&session_id=' + encodeURIComponent(sid));
            if (!res.ok) { setStatus('Fehler beim Laden des Chats.', 'error'); return; }
            const data = await res.json();
            if (data.error) { setStatus(data.error, 'error'); return; }

            sessionId = sid;
            sessionStorage.setItem('chat_session_id', sessionId);

            // Rebuild the visible chat from stored messages.
            clearUpgradePrompt();
            chatArea.innerHTML = '';
            history = [];

            const msgs = data.messages || [];
            for (const msg of msgs) {
                if (msg.role === 'user' || msg.role === 'assistant') {
                    const content = typeof msg.content === 'string' ? msg.content : '';
                    appendMessage(msg.role, content);
                    history.push({ role: msg.role, content });
                }
            }

            if (history.length === 0) {
                showWelcome();
            } else {
                scrollToBottom();
            }

            // Highlight active item in sidebar.
            document.querySelectorAll('.session-item').forEach(el => {
                el.classList.toggle('active', el.dataset.sessionId === sessionId);
            });

            setStatus('Chat geladen.', 'ok');
        } catch (_) {
            setStatus('Fehler beim Laden des Chats.', 'error');
        }
    }

    /** Delete a session from the server and refresh the list. */
    async function deleteSession(sid) {
        try {
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('session_id', sid);
            await fetch('api/chat_sessions.php', { method: 'POST', body: fd });

            // If active session was deleted, start fresh.
            if (sid === sessionId) {
                startNewChat();
            } else {
                refreshSessionList();
            }
        } catch (_) { /* ignore */ }
    }

    /** Start a brand-new empty chat. */
    function startNewChat() {
        history = [];
        chatArea.innerHTML = '';
        clearUpgradePrompt();
        sessionId = generateSessionId();
        sessionStorage.setItem('chat_session_id', sessionId);
        showWelcome();
        setStatus('Neuer Chat gestartet.', 'info');
        // Deselect sidebar items.
        document.querySelectorAll('.session-item').forEach(el => el.classList.remove('active'));
        userInput.focus();
    }

    if (newChatBtn) {
        newChatBtn.addEventListener('click', startNewChat);
    }

    /** After a successful exchange, refresh the session list so the new
     *  title (derived from the first message) appears in the sidebar. */
    function afterExchangeRefresh() {
        if (loggedIn) {
            refreshSessionList();
        }
    }

    function setStatus(msg, type = 'info') {
        statusBar.textContent = msg;
        statusBar.className   = type;
    }

    function scrollToBottom() {
        chatArea.scrollTop = chatArea.scrollHeight;
    }

    function autoResizeTextarea(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 180) + 'px';
    }

    /* ── Markdown renderer (for assistant messages) ─────── */

    function escapeHtmlContent(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function inlineMarkdown(str) {
        // Bold+italic ***text***
        str = str.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        // Bold **text**
        str = str.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Bold __text__
        str = str.replace(/__(.+?)__/g, '<strong>$1</strong>');
        // Italic *text*
        str = str.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');
        // Italic _text_
        str = str.replace(/_([^_\n]+?)_/g, '<em>$1</em>');
        // Inline code `code`
        str = str.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Image ![alt](url) – must come before link processing
        str = str.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function (_, alt, url) {
            const safeAlt = alt.replace(/"/g, '&quot;');
            const safeUrl = url.replace(/"/g, '&quot;');
            return '<img src="' + safeUrl + '" alt="' + safeAlt + '" style="max-width:100%;border-radius:8px;margin:.4em 0;">';
        });
        // Link [text](url)
        str = str.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (_, text, url) {
            const safeUrl = url.replace(/"/g, '&quot;');
            return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        });
        return str;
    }

    function renderMarkdown(text) {
        const lines = text.split('\n');
        let html = '';
        let inCodeBlock = false;
        let codeContent = '';
        let inList = false;
        let listOrdered = false;

        function closeList() {
            if (inList) {
                html += listOrdered ? '</ol>' : '</ul>';
                inList = false;
            }
        }

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];

            // Fenced code block (``` or ~~~)
            if (/^(`{3,}|~{3,})/.test(line)) {
                if (!inCodeBlock) {
                    closeList();
                    inCodeBlock = true;
                    codeContent = '';
                } else {
                    inCodeBlock = false;
                    html += '<pre><code>' + escapeHtmlContent(codeContent) + '</code></pre>';
                }
                continue;
            }
            if (inCodeBlock) {
                codeContent += (codeContent ? '\n' : '') + line;
                continue;
            }

            const escaped = escapeHtmlContent(line);

            // Horizontal rule
            if (/^[-*_]{3,}\s*$/.test(line)) {
                closeList();
                html += '<hr>';
                continue;
            }

            // Headings
            const hMatch = line.match(/^(#{1,4}) (.+)$/);
            if (hMatch) {
                closeList();
                const level = hMatch[1].length;
                html += `<h${level}>${inlineMarkdown(escapeHtmlContent(hMatch[2]))}</h${level}>`;
                continue;
            }

            // Blockquote
            const bqMatch = line.match(/^> (.*)$/);
            if (bqMatch) {
                closeList();
                html += `<blockquote>${inlineMarkdown(escapeHtmlContent(bqMatch[1]))}</blockquote>`;
                continue;
            }

            // Ordered list item (1. text)
            const olMatch = line.match(/^\d+\. (.+)$/);
            if (olMatch) {
                if (!inList || !listOrdered) { closeList(); html += '<ol>'; inList = true; listOrdered = true; }
                html += `<li>${inlineMarkdown(escapeHtmlContent(olMatch[1]))}</li>`;
                continue;
            }

            // Unordered list item (*, -, +)
            const ulMatch = line.match(/^[*\-+] (.+)$/);
            if (ulMatch) {
                if (!inList || listOrdered) { closeList(); html += '<ul>'; inList = true; listOrdered = false; }
                html += `<li>${inlineMarkdown(escapeHtmlContent(ulMatch[1]))}</li>`;
                continue;
            }

            // Empty line → close list or add spacing
            if (line.trim() === '') {
                if (inList) {
                    closeList();
                } else {
                    // Only add break if the previous output doesn't already end with a block element
                    if (html && !/(<\/(?:h[1-4]|p|ul|ol|pre|blockquote|hr)>|<hr>)$/.test(html)) {
                        html += '<br>';
                    }
                }
                continue;
            }

            // Regular line
            closeList();
            html += `<p>${inlineMarkdown(escaped)}</p>`;
        }

        closeList();
        if (inCodeBlock) {
            html += '<pre><code>' + escapeHtmlContent(codeContent) + '</code></pre>';
        }

        return html;
    }

    /* ── Render a message bubble ─────────────────────────── */

    function appendMessage(role, content, streaming = false) {
        hideWelcome();

        const wrapper = document.createElement('div');
        wrapper.className = 'message ' + (role === 'user' ? 'user' : 'assistant');

        // Avatar only for assistant
        if (role === 'assistant') {
            const avatar = document.createElement('div');
            avatar.className = 'avatar';
            avatar.textContent = 'KI';
            wrapper.appendChild(avatar);
        }

        const messageContent = role === 'assistant'
            ? document.createElement('div')
            : wrapper;
        if (role === 'assistant') {
            messageContent.className = 'assistant-content';
        }

        const bubble = document.createElement('div');
        bubble.className = 'bubble' + (streaming ? ' streaming' : '');
        if (role === 'assistant') {
            bubble.innerHTML = content ? renderMarkdown(content) : '';
        } else {
            bubble.textContent = content;
        }

        messageContent.appendChild(bubble);

        if (role === 'assistant') {
            const detailsWrap = document.createElement('details');
            detailsWrap.className = 'response-details';

            const summary = document.createElement('summary');
            summary.textContent = 'Details zur Antwort';

            const body = document.createElement('div');
            body.className = 'response-details-body';
            body.innerHTML = 'Antwort bearbeitet durch: <strong>Unbekannt</strong>';

            bubble._responseDetailsBodyEl = body;

            detailsWrap.appendChild(summary);
            detailsWrap.appendChild(body);
            messageContent.appendChild(detailsWrap);
            wrapper.appendChild(messageContent);
        }

        chatArea.appendChild(wrapper);
        scrollToBottom();

        return bubble;
    }

    function appendSystemMessage(text) {
        const wrapper = document.createElement('div');
        wrapper.className = 'message system-msg';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = text;
        wrapper.appendChild(bubble);
        chatArea.appendChild(wrapper);
        scrollToBottom();
    }

    function clearUpgradePrompt() {
        if (activeUpgradePrompt) {
            activeUpgradePrompt.remove();
            activeUpgradePrompt = null;
        }
    }

    async function executeStreamingRequest(payload, bubble) {
        const res = await fetch('api/chat.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({ error: 'Unbekannter Fehler' }));
            throw new Error(err.error || 'HTTP ' + res.status);
        }

        const reader  = res.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer  = '';
        let queueNoticeShown = false;
        let accumulated = '';
        let accumulatedThinking = '';
        let upgradeSuggestion = null;
        let responseDetails = null;

        function processSseLine(line) {
            const match = line.match(/^data:\s?(.*)$/);
            if (!match) return false;
            const raw = match[1].trim();
            if (!raw) return false;
            if (raw === '[DONE]') {
                return true;
            }

            let obj;
            try { obj = JSON.parse(raw); } catch { return false; }

            if (obj.error) throw new Error(obj.error);
            if (obj.status === 'queued' && obj.message) {
                if (!queueNoticeShown) {
                    appendSystemMessage(obj.message);
                    queueNoticeShown = true;
                }
                setStatus(obj.message, 'info');
                return false;
            }
            if (obj.type === 'intelligence_upgrade' && obj.upgrade) {
                upgradeSuggestion = obj.upgrade;
                return false;
            }
            if (obj.type === 'response_details' && obj.details) {
                responseDetails = obj.details;
                return false;
            }

            const thinkingDelta = obj.choices?.[0]?.delta?.reasoning_content ?? '';
            const delta = obj.choices?.[0]?.delta?.content ?? '';
            if (thinkingDelta) {
                accumulatedThinking += thinkingDelta;
            }
            if (delta) {
                if (queueNoticeShown) {
                    setStatus('Antwort wird generiert …', 'info');
                }
                accumulated += delta;
            }
            if (delta || thinkingDelta) {
                if (accumulated) {
                    bubble.innerHTML = renderMarkdown(accumulated);
                } else if (accumulatedThinking) {
                    bubble.innerHTML = '<div class="thinking-bubble">' + escapeHtmlContent(accumulatedThinking) + '</div>';
                }
                scrollToBottom();
            }
            return false;
        }

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();

            for (const line of lines) {
                processSseLine(line.trimEnd());
            }
        }

        buffer += decoder.decode();
        const remaining = buffer.trim();
        if (remaining !== '') {
            processSseLine(remaining);
        }

        return { accumulated, upgradeSuggestion, responseDetails };
    }

    function setResponseDetailsForBubble(bubble, responseDetails) {
        if (!bubble || !bubble._responseDetailsBodyEl) {
            return;
        }
        const alias = (responseDetails && typeof responseDetails.processed_by === 'string' && responseDetails.processed_by.trim())
            ? responseDetails.processed_by.trim()
            : 'Unbekannt';
        const elapsed = (responseDetails && typeof responseDetails.elapsed_seconds === 'number')
            ? responseDetails.elapsed_seconds
            : null;
        const searchQuery = (responseDetails && typeof responseDetails.search_query === 'string')
            ? responseDetails.search_query.trim()
            : '';

        let html;
        if (elapsed !== null && searchQuery) {
            html = `Bearbeitet durch <strong>${escapeHtmlContent(alias)}</strong> in ${elapsed} Sekunde${elapsed === 1 ? '' : 'n'} unter zur Hilfenahme aktueller Suchergebnisse zum Thema <strong>${escapeHtmlContent(searchQuery)}</strong>`;
        } else if (elapsed !== null) {
            html = `Bearbeitet durch <strong>${escapeHtmlContent(alias)}</strong> in ${elapsed} Sekunde${elapsed === 1 ? '' : 'n'}`;
        } else {
            html = `Antwort bearbeitet durch: <strong>${escapeHtmlContent(alias)}</strong>`;
        }
        bubble._responseDetailsBodyEl.innerHTML = html;
    }

    function showUpgradePrompt(upgrade, requestMessages, historyAssistantIndex) {
        if (!upgrade || !upgrade.available || !upgrade.suggested_model) {
            return;
        }
        clearUpgradePrompt();

        const wrapper = document.createElement('div');
        wrapper.className = 'message intelligence-upgrade';

        const card = document.createElement('div');
        card.className = 'intelligence-upgrade-card';
        card.textContent = upgrade.message || 'Es stehen freie Ressourcen für ein intelligenteres Modell bereit. Fortfahren?';

        const actions = document.createElement('div');
        actions.className = 'intelligence-upgrade-actions';

        const yesBtn = document.createElement('button');
        yesBtn.className = 'yes';
        yesBtn.type = 'button';
        yesBtn.textContent = 'Ja';

        const noBtn = document.createElement('button');
        noBtn.type = 'button';
        noBtn.textContent = 'Nein';

        actions.appendChild(yesBtn);
        actions.appendChild(noBtn);
        card.appendChild(actions);
        wrapper.appendChild(card);
        chatArea.appendChild(wrapper);
        activeUpgradePrompt = wrapper;
        scrollToBottom();

        noBtn.addEventListener('click', () => {
            clearUpgradePrompt();
            setStatus('Bereit.', 'ok');
        });

        yesBtn.addEventListener('click', async () => {
            if (isStreaming) return;
            clearUpgradePrompt();

            isStreaming = true;
            sendBtn.disabled = true;
            setStatus(`Antwort wird mit ${upgrade.suggested_intelligence || 'größerer'} Intelligenz neu generiert …`, 'info');

            const bubble = appendMessage('assistant', '', true);
            try {
                const retryPayload = {
                    model: upgrade.suggested_model,
                    messages: JSON.parse(JSON.stringify(requestMessages)),
                    session_id: sessionId,
                    stream: true,
                    temperature: 0.7,
                };
                const result = await executeStreamingRequest(retryPayload, bubble);
                let finalText = result.accumulated;
                let retryResponseDetails = result.responseDetails;

                if (!finalText) {
                    setStatus('Leere Antwort – Wiederholungsversuch …', 'info');
                    bubble.innerHTML = '';
                    const retryResult = await executeStreamingRequest(retryPayload, bubble);
                    finalText = retryResult.accumulated;
                    retryResponseDetails = retryResult.responseDetails || retryResponseDetails;
                }

                finalText = finalText || '(Leere Antwort)';
                bubble.innerHTML = renderMarkdown(finalText);
                setResponseDetailsForBubble(bubble, retryResponseDetails);
                bubble.classList.remove('streaming');

                if (historyAssistantIndex >= 0 && historyAssistantIndex < history.length && history[historyAssistantIndex]?.role === 'assistant') {
                    history[historyAssistantIndex] = { role: 'assistant', content: finalText };
                } else {
                    history.push({ role: 'assistant', content: finalText });
                }

                setStatus('Bereit.', 'ok');
            } catch (err) {
                bubble.textContent = '⚠ Fehler: ' + err.message;
                bubble.classList.remove('streaming');
                bubble.style.color = 'var(--error)';
                setStatus('Fehler: ' + err.message, 'error');
            } finally {
                isStreaming = false;
                sendBtn.disabled = false;
                userInput.focus();
            }
        });
    }

    /* ── Send message ────────────────────────────────────── */

    async function sendMessage() {
        const text  = userInput.value.trim();
        const model = defaultModel;

        if (!text)  { userInput.focus(); return; }
        if (!model) { setStatus('Kein Standardmodell konfiguriert. Bitte im Admin-Bereich ein Modell festlegen.', 'error'); return; }
        if (isStreaming) return;

        clearUpgradePrompt();

        // Add user message to UI + history.
        appendMessage('user', text);
        history.push({ role: 'user', content: text });
        userInput.value = '';
        autoResizeTextarea(userInput);

        // Build message array (optionally prepend system prompt).
        const messages = [];
        const sysPrompt = systemPromptTA.value.trim();
        if (sysPrompt) messages.push({ role: 'system', content: sysPrompt });
        messages.push(...history);

        isStreaming = true;
        sendBtn.disabled = true;
        setStatus('Antwort wird generiert …', 'info');

        const bubble = appendMessage('assistant', '', true);

        const payload = {
            model,
            messages,
            session_id: sessionId,
            stream: true,
            temperature: 0.7,
        };

        try {
            let result = await executeStreamingRequest(payload, bubble);
            let accumulated = result.accumulated;
            let responseDetails = result.responseDetails;
            let upgradeSuggestion = result.upgradeSuggestion;

            if (!accumulated) {
                // First retry: same model/endpoint
                setStatus('Leere Antwort – Wiederholungsversuch …', 'info');
                bubble.innerHTML = '';
                const retryResult = await executeStreamingRequest(payload, bubble);
                accumulated = retryResult.accumulated;
                responseDetails = retryResult.responseDetails || responseDetails;
                upgradeSuggestion = retryResult.upgradeSuggestion || upgradeSuggestion;

                if (!accumulated && upgradeSuggestion?.available && upgradeSuggestion?.suggested_model) {
                    // Second retry: next smarter model (automatic)
                    setStatus(`Leere Antwort – Versuche mit ${upgradeSuggestion.suggested_intelligence || 'größerem'} Modell …`, 'info');
                    bubble.innerHTML = '';
                    const upgradePayload = { ...payload, model: upgradeSuggestion.suggested_model };
                    const upgradeResult = await executeStreamingRequest(upgradePayload, bubble);
                    accumulated = upgradeResult.accumulated;
                    responseDetails = upgradeResult.responseDetails || responseDetails;
                    upgradeSuggestion = null; // upgrade was already used automatically
                }
            }

            bubble.innerHTML = accumulated ? renderMarkdown(accumulated) : '(Leere Antwort)';
            setResponseDetailsForBubble(bubble, responseDetails);
            bubble.classList.remove('streaming');

            // Store assistant reply in history.
            history.push({ role: 'assistant', content: accumulated });
            const assistantHistoryIndex = history.length - 1;
            showUpgradePrompt(upgradeSuggestion, messages, assistantHistoryIndex);
            setStatus('Bereit.', 'ok');
            afterExchangeRefresh();
        } catch (err) {
            bubble.textContent = '⚠ Fehler: ' + err.message;
            bubble.classList.remove('streaming');
            bubble.style.color = 'var(--error)';
            // Remove the user message that was added before the failed request so the user can retry.
            history.pop();
            setStatus('Fehler: ' + err.message, 'error');
        } finally {
            isStreaming       = false;
            sendBtn.disabled  = false;
            userInput.focus();
        }
    }

    /* ── Event bindings ──────────────────────────────────── */

    sendBtn.addEventListener('click', sendMessage);

    userInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    userInput.addEventListener('input', () => autoResizeTextarea(userInput));

    clearBtn.addEventListener('click', startNewChat);

    systemToggle.addEventListener('click', () => {
        const hidden = systemPromptWrap.style.display !== 'block';
        systemPromptWrap.style.display = hidden ? 'block' : 'none';
    });

    /* ── Auto-focus input on start ───────────────────────── */
    showWelcome();
    userInput.focus();

    /* ── Load session list on startup (logged-in users) ─── */
    if (loggedIn) {
        refreshSessionList();
    }
})();
</script>

<script>
// ── Client-presence heartbeat ─────────────────────────────────────────────────
(function () {
    'use strict';

    // Stable per-tab token (survives page reload within the same tab).
    let token = sessionStorage.getItem('hb_token') || '';
    if (!token) {
        const bytes = crypto.getRandomValues(new Uint8Array(32));
        token = Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
        sessionStorage.setItem('hb_token', token);
    }

    function sendHeartbeat() {
        fetch('api/heartbeat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token }),
            keepalive: true,
        }).catch(() => {}); // fire-and-forget
    }

    sendHeartbeat();
    setInterval(sendHeartbeat, 30000);
})();
</script>
</body>
</html>
