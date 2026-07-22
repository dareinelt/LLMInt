<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LM Studio Chat</title>
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
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            background: var(--surface-alt);
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
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
        .message.assistant .bubble { background: var(--bot-bg); border: 1px solid var(--border); }
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
    <h1>🤖 LM Studio Chat</h1>
    <span class="badge">PHP · REST API</span>
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
<div id="status-bar" class="info">Bereit. Bitte zuerst Modelle laden.</div>

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

    /* Chat history (role / content pairs sent to the API) */
    let history = [];
    let isStreaming = false;

    /* ── Helpers ─────────────────────────────────────────── */

    function setStatus(msg, type = 'info') {
        statusBar.textContent = msg;
        statusBar.className   = type;
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function scrollToBottom() {
        chatArea.scrollTop = chatArea.scrollHeight;
    }

    function autoResizeTextarea(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 180) + 'px';
    }

    /* ── Model loading ───────────────────────────────────── */

    async function loadModels() {
        refreshBtn.disabled = true;
        setStatus('Modelle werden geladen …', 'info');
        modelSelect.innerHTML = '<option value="">– Laden … –</option>';

        try {
            const res  = await fetch('api/models.php');
            const data = await res.json();

            if (data.error) {
                setStatus('Fehler: ' + data.error, 'error');
                modelSelect.innerHTML = '<option value="">– Fehler –</option>';
                return;
            }

            const models = data.models || [];

            if (models.length === 0) {
                setStatus('Keine Modelle gefunden. Ist LM Studio gestartet?', 'error');
                modelSelect.innerHTML = '<option value="">– Keine Modelle –</option>';
                return;
            }

            modelSelect.innerHTML = models
                .map(m => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.id)}</option>`)
                .join('');

            setStatus(`${models.length} Modell(e) geladen. Modell auswählen und chatten!`, 'ok');
        } catch (err) {
            setStatus('Netzwerkfehler: ' + err.message, 'error');
            modelSelect.innerHTML = '<option value="">– Fehler –</option>';
        } finally {
            refreshBtn.disabled = false;
        }
    }

    refreshBtn.addEventListener('click', loadModels);

    /* ── Render a message bubble ─────────────────────────── */

    function appendMessage(role, content, streaming = false) {
        const wrapper = document.createElement('div');
        wrapper.className = 'message ' + (role === 'user' ? 'user' : 'assistant');

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = role === 'user' ? '🧑' : '🤖';

        const bubble = document.createElement('div');
        bubble.className = 'bubble' + (streaming ? ' streaming' : '');
        bubble.textContent = content;

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
        const model = modelSelect.value;

        if (!text)  { userInput.focus(); return; }
        if (!model) { setStatus('Bitte zuerst ein Modell auswählen.', 'error'); return; }
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

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop(); // keep incomplete line

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const raw = line.slice(6).trim();
                    if (raw === '[DONE]') continue;

                    let obj;
                    try { obj = JSON.parse(raw); } catch { continue; }

                    if (obj.error) throw new Error(obj.error);

                    const delta = obj.choices?.[0]?.delta?.content ?? '';
                    accumulated += delta;
                    bubble.textContent = accumulated;
                    scrollToBottom();
                }
            }

            // Flush remaining buffer.
            if (buffer.startsWith('data: ')) {
                const raw = buffer.slice(6).trim();
                if (raw && raw !== '[DONE]') {
                    try {
                        const obj   = JSON.parse(raw);
                        const delta = obj.choices?.[0]?.delta?.content ?? '';
                        accumulated += delta;
                    } catch { /* ignore */ }
                }
            }

            bubble.textContent = accumulated || '(Leere Antwort)';
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

    /* ── Auto-load models on start ───────────────────────── */
    loadModels();
})();
</script>
</body>
</html>
