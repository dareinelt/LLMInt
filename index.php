<!DOCTYPE html>
<?php
require_once __DIR__ . '/db.php';
$defaultModel = getSetting('default_model', '');
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
            --bg:          #0f1117;
            --surface:     #1a1d27;
            --surface-alt: #22263a;
            --border:      #2e3250;
            --accent:      #6c63ff;
            --accent-dark: #5249cc;
            --text:        #e2e4f0;
            --text-muted:  #8b90b0;
            --user-bg:     #2a2d45;
            --bot-bg:      #1e2035;
            --error:       #e05c5c;
            --success:     #4caf7d;
            --radius:      10px;
            --font:        'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            height: 100dvh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ───────────────────────────────────────────────── */
        header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }

        header h1 {
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: .02em;
        }

        header .badge {
            font-size: .72rem;
            background: var(--accent);
            color: #fff;
            padding: 2px 8px;
            border-radius: 20px;
        }

        header .admin-link {
            margin-left: auto;
            font-size: .78rem;
            color: var(--text-muted);
            text-decoration: none;
        }

        header .admin-link:hover { color: var(--text); }

        /* ── Config bar ───────────────────────────────────────────── */
        #config-bar {
            display: none;
        }

        #config-bar label {
            font-size: .82rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        #model-select {
            flex: 1 1 200px;
            padding: 6px 10px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-size: .85rem;
        }

        button {
            padding: 6px 14px;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: .85rem;
            font-weight: 500;
            transition: background .15s, opacity .15s;
        }

        #refresh-btn {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
        }

        #refresh-btn:hover { background: var(--border); }

        #refresh-btn:disabled { opacity: .5; cursor: default; }

        /* ── Status bar ───────────────────────────────────────────── */
        #status-bar {
            font-size: .78rem;
            padding: 4px 20px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            min-height: 26px;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        #status-bar.ok    { color: var(--success); }
        #status-bar.error { color: var(--error); }
        #status-bar.info  { color: var(--text-muted); }

        /* ── Chat area ─────────────────────────────────────────────── */
        #chat-area {
            flex: 1 1 0;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            scroll-behavior: smooth;
        }

        .message {
            display: flex;
            gap: 12px;
            max-width: 860px;
            width: 100%;
        }

        .message.user  { align-self: flex-end; flex-direction: row-reverse; }
        .message.assistant { align-self: flex-start; }
        .message.system-msg { align-self: center; }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .message.user      .avatar { background: var(--accent); }
        .message.assistant .avatar { background: #3a3d5c; }

        .bubble {
            padding: 10px 14px;
            border-radius: var(--radius);
            line-height: 1.6;
            font-size: .9rem;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .message.user      .bubble { background: var(--user-bg); }
        .message.assistant .bubble { background: var(--bot-bg); border: 1px solid var(--border); white-space: normal; }

        /* ── Markdown rendering inside assistant bubbles ───────────── */
        .message.assistant .bubble h1,
        .message.assistant .bubble h2,
        .message.assistant .bubble h3,
        .message.assistant .bubble h4 {
            margin: .9em 0 .3em;
            font-weight: 600;
            line-height: 1.3;
        }
        .message.assistant .bubble h1 { font-size: 1.25rem; }
        .message.assistant .bubble h2 { font-size: 1.1rem; }
        .message.assistant .bubble h3 { font-size: 1rem; }
        .message.assistant .bubble h4 { font-size: .9rem; }
        .message.assistant .bubble h1:first-child,
        .message.assistant .bubble h2:first-child,
        .message.assistant .bubble h3:first-child,
        .message.assistant .bubble h4:first-child { margin-top: 0; }

        .message.assistant .bubble p { margin: .45em 0; }
        .message.assistant .bubble p:first-child { margin-top: 0; }
        .message.assistant .bubble p:last-child  { margin-bottom: 0; }

        .message.assistant .bubble ul,
        .message.assistant .bubble ol {
            margin: .4em 0 .4em 1.4em;
            padding: 0;
        }
        .message.assistant .bubble li { margin: .15em 0; }

        .message.assistant .bubble code {
            font-family: 'Consolas', 'Fira Code', monospace;
            font-size: .84em;
            background: rgba(255,255,255,.07);
            padding: .1em .35em;
            border-radius: 4px;
        }
        .message.assistant .bubble pre {
            background: #12141f;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 14px;
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
        .message.system-msg .bubble {
            background: transparent;
            color: var(--text-muted);
            font-size: .8rem;
            border: none;
            padding: 2px 0;
        }

        .message.assistant .bubble.streaming::after {
            content: '▋';
            animation: blink .8s step-end infinite;
        }

        @keyframes blink { 50% { opacity: 0; } }

        /* ── Input row ─────────────────────────────────────────────── */
        #input-row {
            display: flex;
            gap: 10px;
            padding: 14px 20px;
            background: var(--surface);
            border-top: 1px solid var(--border);
            flex-shrink: 0;
        }

        #user-input {
            flex: 1;
            padding: 10px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-family: var(--font);
            font-size: .9rem;
            resize: none;
            min-height: 44px;
            max-height: 180px;
            overflow-y: auto;
            line-height: 1.5;
        }

        #user-input:focus { outline: none; border-color: var(--accent); }

        #send-btn {
            background: var(--accent);
            color: #fff;
            padding: 0 20px;
            align-self: flex-end;
            height: 44px;
            border-radius: var(--radius);
        }

        #send-btn:hover:not(:disabled) { background: var(--accent-dark); }
        #send-btn:disabled { opacity: .5; cursor: default; }

        #clear-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
            align-self: flex-end;
            height: 44px;
        }

        #clear-btn:hover { border-color: var(--text-muted); color: var(--text); }

        /* ── Scrollbar ─────────────────────────────────────────────── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

        /* ── System-prompt section ─────────────────────────────────── */
        #system-toggle {
            font-size: .78rem;
            color: var(--text-muted);
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            text-decoration: underline dotted;
        }

        #system-prompt-wrap {
            display: none;
            padding: 8px 20px;
            background: var(--surface-alt);
            border-bottom: 1px solid var(--border);
        }

        #system-prompt-wrap textarea {
            width: 100%;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-family: var(--font);
            font-size: .82rem;
            padding: 8px 10px;
            resize: vertical;
            min-height: 60px;
        }

        #system-prompt-wrap textarea:focus { outline: none; border-color: var(--accent); }

        #system-prompt-label {
            font-size: .78rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header>
    <h1>🤖 KHWF KI</h1>
    <a class="admin-link" href="admin/login.php">⚙️ Admin</a>
</header>

<!-- ── Config bar ─────────────────────────────────────────── -->
<div id="config-bar">
    <label for="model-select">Modell:</label>
    <select id="model-select">
        <option value="">– Modelle laden –</option>
    </select>

    <button id="refresh-btn" title="Modelle neu laden">⟳ Modelle laden</button>

    <button id="system-toggle">System-Prompt</button>
</div>

<!-- ── System prompt (hidden by default) ──────────────────── -->
<div id="system-prompt-wrap">
    <div id="system-prompt-label">System-Prompt (optional):</div>
    <textarea id="system-prompt" rows="3"
              placeholder="Du bist ein hilfreicher Assistent …"></textarea>
</div>

<!-- ── Status bar ─────────────────────────────────────────── -->
<div id="status-bar" class="info">Bereit. Nachricht schreiben und los!</div>

<!-- ── Chat messages ──────────────────────────────────────── -->
<div id="chat-area"></div>

<!-- ── Input row ──────────────────────────────────────────── -->
<div id="input-row">
    <textarea id="user-input" rows="1"
              placeholder="Nachricht schreiben … (Enter = Senden, Shift+Enter = Zeilenumbruch)"></textarea>
    <button id="clear-btn" title="Verlauf löschen">🗑</button>
    <button id="send-btn">Senden ↑</button>
</div>

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

    /* Default model set by the admin */
    const defaultModel = <?= json_encode($defaultModel) ?>;

    /* Chat history (role / content pairs sent to the API) */
    let history = [];
    let isStreaming = false;

    /* ── Helpers ─────────────────────────────────────────── */

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
        const wrapper = document.createElement('div');
        wrapper.className = 'message ' + (role === 'user' ? 'user' : 'assistant');

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = role === 'user' ? '🧑' : '🤖';

        const bubble = document.createElement('div');
        bubble.className = 'bubble' + (streaming ? ' streaming' : '');
        if (role === 'assistant') {
            bubble.innerHTML = content ? renderMarkdown(content) : '';
        } else {
            bubble.textContent = content;
        }

        wrapper.appendChild(avatar);
        wrapper.appendChild(bubble);
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

    /* ── Send message ────────────────────────────────────── */

    async function sendMessage() {
        const text  = userInput.value.trim();
        const model = defaultModel;

        if (!text)  { userInput.focus(); return; }
        if (!model) { setStatus('Kein Standardmodell konfiguriert. Bitte im Admin-Bereich ein Modell festlegen.', 'error'); return; }
        if (isStreaming) return;

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
        let   accumulated = '';

        const payload = {
            model,
            messages,
            stream: true,
            temperature: 0.7,
        };

        try {
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
            let   buffer  = '';
            let   streamEnded = false;

            function processSseLine(line) {
                const match = line.match(/^data:\s?(.*)$/);
                if (!match) return;
                const raw = match[1].trim();
                if (!raw) return;
                if (raw === '[DONE]') {
                    streamEnded = true;
                    return;
                }

                let obj;
                try { obj = JSON.parse(raw); } catch { return; }

                if (obj.error) throw new Error(obj.error);

                const delta = obj.choices?.[0]?.delta?.content ?? '';
                accumulated += delta;
                bubble.innerHTML = renderMarkdown(accumulated);
                scrollToBottom();
            }

            while (!streamEnded) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // keep incomplete line

                for (const line of lines) {
                    processSseLine(line.trimEnd());
                    if (streamEnded) break;
                }
            }

            buffer += decoder.decode();

            // Flush remaining buffer.
            const remaining = buffer.trim();
            if (remaining !== '' && !streamEnded) {
                processSseLine(remaining);
            }

            bubble.innerHTML = accumulated ? renderMarkdown(accumulated) : '(Leere Antwort)';
            bubble.classList.remove('streaming');

            // Store assistant reply in history.
            history.push({ role: 'assistant', content: accumulated });
            setStatus('Bereit.', 'ok');
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

    clearBtn.addEventListener('click', () => {
        history    = [];
        chatArea.innerHTML = '';
        appendSystemMessage('Verlauf gelöscht.');
        setStatus('Verlauf gelöscht.', 'info');
    });

    systemToggle.addEventListener('click', () => {
        const hidden = systemPromptWrap.style.display !== 'block';
        systemPromptWrap.style.display = hidden ? 'block' : 'none';
    });

    /* ── Auto-focus input on start ───────────────────────── */
    userInput.focus();
})();
</script>
</body>
</html>
