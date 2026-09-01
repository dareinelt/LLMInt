<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ldap_auth.php';
require_once __DIR__ . '/lib/healthcheck.php';

// ── SSO redirect: if REMOTE_USER is set and SSO is enabled, let login.php handle it ──
if (!isset($_SESSION['admin_user']) && ldapSsoEnabled() && ldapSsoUsername() !== '') {
    header('Location: login.php');
    exit;
}

// ── Maintenance mode: show a fallback page instead of the chat UI when no
//    active LLM endpoint currently responds to a health probe ─────────────────
if (!isAnyLlmEndpointHealthy()) {
    http_response_code(503);
    header('Retry-After: 30');
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KHWF KI – Wartungsmodus</title>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #14161a;
            color: #e8e8ec;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .maintenance-box {
            max-width: 480px;
            margin: 24px;
            padding: 32px 36px;
            border-radius: 12px;
            background: #1d2026;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
            text-align: center;
        }
        .maintenance-box h1 {
            margin: 0 0 12px;
            font-size: 1.4rem;
        }
        .maintenance-box p {
            margin: 0 0 8px;
            color: #b7bac2;
            line-height: 1.5;
        }
        .maintenance-icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
    <div class="maintenance-box">
        <div class="maintenance-icon">🛠️</div>
        <h1>Wartungsmodus</h1>
        <p>Der KI-Dienst ist derzeit nicht erreichbar. Bitte versuchen Sie es in Kürze erneut.</p>
        <p id="maintenance-status">Diese Seite lädt automatisch neu, sobald der Dienst wieder verfügbar ist.</p>
    </div>
    <script>
        (function () {
            function poll() {
                fetch('api/healthcheck.php', { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.available) {
                            window.location.reload();
                        }
                    })
                    .catch(function () { /* ignore, retry on next interval */ });
            }
            setInterval(poll, 15000);
        })();
    </script>
</body>
</html>
    <?php
    exit;
}

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

// Check if the current user has document upload permission.
$canUploadDocuments = false;
if ($loggedIn && isset($_SESSION['admin_id'])) {
    try {
        $stmt = getDb()->prepare('SELECT can_upload_documents FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $uRow = $stmt->fetch();
        $canUploadDocuments = $uRow && (int) ($uRow['can_upload_documents'] ?? 0) === 1;
    } catch (Throwable $_e) {
        $canUploadDocuments = false;
    }
}

// Intelligence groups (e.g. "35b") that can be addressed with the "@@" prefix.
// The feature can be switched off in the administration.
$intelligenceGroupEnabled = isIntelligenceGroupFeatureEnabled();
$intelligenceGroups = [];
if ($loggedIn && $intelligenceGroupEnabled) {
    try {
        $intelligenceGroups = array_keys(listIntelligenceGroups());
    } catch (Throwable $_e) {
        $intelligenceGroups = [];
    }
}

// Check if vision model is configured (upload only meaningful when it is).
$visionModelConfigured = trim(getSetting('vision_model', '')) !== '';

// Login banner settings.
$loginBannerEnabled = getSetting('login_banner_enabled', '0') === '1';
$loginBannerText    = getSetting('login_banner_text', '');

// Whether the assistant's reply is rendered token-by-token as it streams in,
// or only shown in full once the response is complete (blinking cursor only).
$streamingEnabled = getSetting('streaming_enabled', '1') === '1';

// CSRF token for upload requests.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
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
            display: flex;
            align-items: center;
            gap: 8px;
        }

        header h1 .brand-logo {
            width: 24px;
            height: 24px;
            object-fit: contain;
            flex-shrink: 0;
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
        .welcome-logo img { width: 96px; height: 96px; object-fit: contain; }

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
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .context-usage-summary {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Small, deliberately unobtrusive context-usage indicators. Each is a
           ring that visually "closes" (the colored arc grows clockwise) as
           the endpoint's / session's context fills up. */
        .context-usage-circle {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex: 0 0 auto;
            opacity: .55;
            vertical-align: middle;
            box-shadow: 0 0 0 1px var(--border) inset;
            transition: opacity .2s ease;
        }

        .response-details > summary:hover .context-usage-circle {
            opacity: .9;
        }

        .context-usage-circle.context-usage-critical {
            opacity: .85;
        }

        .response-details-body {
            margin-top: 6px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
        }

        .response-details-body .context-usage-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            font-size: .78rem;
            color: var(--text-muted);
        }

        .context-limit-notice {
            margin-top: 6px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid var(--error);
            color: var(--error);
            font-size: .82rem;
            background: color-mix(in srgb, var(--error) 10%, transparent);
        }

        /* Quellen-Pills unterhalb einer Antwort, die per Websuche recherchiert
           wurde: kleines Favicon plus verlinkter Seitentitel. */
        .source-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .source-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 220px;
            padding: 3px 10px 3px 6px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text-muted);
            font-size: .76rem;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color .15s ease, border-color .15s ease;
        }

        .source-pill:hover {
            color: var(--text);
            border-color: var(--accent);
        }

        .source-pill img {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            flex: 0 0 auto;
        }

        .source-pill span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
        .message.assistant .bubble blockquote blockquote {
            margin: .3em 0;
        }
        .message.assistant .bubble table {
            border-collapse: collapse;
            width: 100%;
            margin: .6em 0;
            font-size: .9em;
        }
        .message.assistant .bubble th,
        .message.assistant .bubble td {
            border: 1px solid var(--border);
            padding: .4em .7em;
            text-align: left;
        }
        .message.assistant .bubble th {
            background: rgba(255,255,255,.06);
            font-weight: 600;
        }
        .message.assistant .bubble del { opacity: .7; }

        .message.assistant .bubble li.clickable-question {
            cursor: pointer;
            border-radius: 6px;
            padding: .1em .4em;
            margin: .15em -.4em;
            transition: background .15s ease, color .15s ease;
        }
        .message.assistant .bubble li.clickable-question:hover,
        .message.assistant .bubble li.clickable-question:focus-visible {
            background: rgba(108, 99, 255, .16);
            color: var(--accent);
            outline: none;
        }

        /* ── LaTeX-ish math ($...$ / $$...$$) ───────────────────────── */
        .message.assistant .bubble .math-inline {
            font-family: 'Cambria Math', 'STIX Two Math', 'Latin Modern Math', Georgia, serif;
            font-style: italic;
            padding: 0 .1em;
        }
        .message.assistant .bubble .math-block {
            display: block;
            font-family: 'Cambria Math', 'STIX Two Math', 'Latin Modern Math', Georgia, serif;
            font-style: italic;
            text-align: center;
            margin: .7em 0;
            overflow-x: auto;
            font-size: 1.05em;
        }
        .message.assistant .bubble .math-inline sup,
        .message.assistant .bubble .math-block sup {
            font-size: .75em;
        }
        .message.assistant .bubble .math-inline sub,
        .message.assistant .bubble .math-block sub {
            font-size: .75em;
        }
        .message.assistant .bubble .math-frac {
            display: inline-flex;
            flex-direction: column;
            vertical-align: middle;
            text-align: center;
            margin: 0 .15em;
            line-height: 1.1;
        }
        .message.assistant .bubble .math-frac .math-frac-num {
            border-bottom: 1px solid currentColor;
            padding: 0 .2em;
        }
        .message.assistant .bubble .math-frac .math-frac-den {
            padding: 0 .2em;
        }

        /* ── Thinking display (robot with thought bubble + animated reasoning lines) ── */
        .bubble .thinking-bubble {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 14px;
            margin-bottom: 12px;
        }

        .bubble .thinking-robot {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .bubble .thinking-thought {
            position: relative;
            display: flex;
            align-items: center;
            gap: 3px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 6px 9px;
            margin-bottom: 9px;
        }

        /* Thought bubble tail (two small trailing circles towards the robot's head) */
        .bubble .thinking-thought::before {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 8px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
        }

        .bubble .thinking-thought::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 5px;
            width: 3px;
            height: 3px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
        }

        .bubble .thinking-thought span {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--text-muted);
            animation: thinkingDot 1.2s ease-in-out infinite;
        }

        .bubble .thinking-thought span:nth-child(2) { animation-delay: .2s; }
        .bubble .thinking-thought span:nth-child(3) { animation-delay: .4s; }

        .bubble .thinking-face {
            position: relative;
            width: 34px;
            height: 34px;
        }

        .bubble .thinking-face img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            opacity: 0;
            transition: opacity .7s ease;
        }

        /* Live/streaming: robots A/B/C cycle in sequence, cross-faded via a JS-driven data-step attribute */
        .bubble .thinking-face.live .tf-a { opacity: 1; }
        .bubble .thinking-face.live[data-step="1"] .tf-a { opacity: 0; }
        .bubble .thinking-face.live[data-step="1"] .tf-b { opacity: 1; }
        .bubble .thinking-face.live[data-step="2"] .tf-b { opacity: 0; }

        /* Robot C additionally spins once clockwise while it fades in and back out.
           C stays visible 3x as long as A/B, so its animation runs 3x as long too. */
        .bubble .thinking-face.live[data-step="2"] .tf-c {
            animation: thinkingSpinFade 3.9s ease-in-out;
        }

        @keyframes thinkingSpinFade {
            0%   { opacity: 0; transform: rotate(0deg); }
            20%  { opacity: 1; transform: rotate(72deg); }
            55%  { opacity: 1; transform: rotate(360deg); }
            100% { opacity: 0; transform: rotate(360deg); }
        }

        /* Done: replace the alternating pair with the static welcome/header mascot */
        .bubble .thinking-bubble.done .thinking-face .tf-a,
        .bubble .thinking-bubble.done .thinking-face .tf-b,
        .bubble .thinking-bubble.done .thinking-face .tf-c { opacity: 0; }
        .bubble .thinking-bubble.done .thinking-face .tf-done { opacity: 1; }

        .bubble .thinking-content {
            flex: 1;
            min-width: 0;
            font-size: .83rem;
            font-style: italic;
            color: var(--text-muted);
            max-height: 200px;
            overflow-y: auto;
            overflow-x: hidden;
            word-break: break-word;
            align-self: center;
        }

        .bubble .thinking-content .thinking-line {
            margin: 0 0 .5em;
            animation: thinkingFlyIn .45s ease both;
        }

        .bubble .thinking-content .thinking-line:last-child { margin-bottom: 0; }

        .bubble .thinking-bubble.done .thinking-line { animation: none; }
        .bubble .thinking-bubble.done .thinking-thought span { animation: none; opacity: .55; }

        @keyframes thinkingFlyIn {
            from { opacity: 0; transform: translateX(32px); }
            to   { opacity: 1; transform: none; }
        }

        @keyframes thinkingDot {
            0%, 60%, 100% { opacity: .35; transform: translateY(0); }
            30%           { opacity: 1;   transform: translateY(-2px); }
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

        /* ── Intelligence group pill (set via "@@35b" prefix) and
              reasoning pill (set via the "!!" prefix) ──────────────── */
        #group-pill,
        #reasoning-pill {
            display: none;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            align-self: center;
            background: rgba(108,99,255,.15);
            color: var(--accent);
            border: 1px solid rgba(108,99,255,.45);
            border-radius: 999px;
            padding: 2px 6px 2px 10px;
            font-size: .78rem;
            font-weight: 600;
            white-space: nowrap;
        }

        #group-pill.visible,
        #reasoning-pill.visible { display: inline-flex; }

        #group-pill button,
        #reasoning-pill button {
            border: none;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font-size: .8rem;
            line-height: 1;
            padding: 2px 4px;
            border-radius: 999px;
        }

        #group-pill button:hover,
        #reasoning-pill button:hover { background: rgba(108,99,255,.25); }

        #reasoning-pill {
            background: rgba(255,196,0,.15);
            color: #f5b301;
            border-color: rgba(255,196,0,.45);
        }

        #reasoning-pill button:hover { background: rgba(255,196,0,.25); }

        /* ── Prompt-function pills (set via "/command" prefixes, e.g.
              "/tldr", "/table", "/eli5") ────────────────────────────── */
        #cmd-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            flex-shrink: 0;
            align-self: center;
        }

        .cmd-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(46,196,140,.15);
            color: #2ec48c;
            border: 1px solid rgba(46,196,140,.45);
            border-radius: 999px;
            padding: 2px 6px 2px 10px;
            font-size: .78rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .cmd-pill button {
            border: none;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font-size: .8rem;
            line-height: 1;
            padding: 2px 4px;
            border-radius: 999px;
        }

        .cmd-pill button:hover { background: rgba(46,196,140,.25); }

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

        #attach-image-btn {
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
            font-size: .95rem;
            transition: background .15s, color .15s;
        }

        #attach-image-btn:hover { background: var(--surface-alt); color: var(--text); }
        #attach-image-btn.has-images { color: var(--accent); }

        #attach-detail-select {
            display: none;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface-alt);
            color: var(--text);
            font-size: .75rem;
            padding: 0 6px;
            flex-shrink: 0;
            cursor: pointer;
        }

        #attach-detail-select.visible { display: inline-block; }

        /* ── Attached-image preview strip (shown above the input box) ─ */
        #attach-preview {
            display: none;
            flex-wrap: wrap;
            gap: 8px;
            padding: 0 2px 8px;
        }

        #attach-preview.visible { display: flex; }

        .attach-thumb {
            position: relative;
            width: 56px;
            height: 56px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .attach-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .attach-thumb-remove {
            position: absolute;
            top: 1px;
            right: 1px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: none;
            background: rgba(0,0,0,.65);
            color: #fff;
            font-size: .65rem;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Images attached to a sent user message ────────────────── */
        .msg-images {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 6px;
        }

        .msg-images img {
            max-width: 160px;
            max-height: 160px;
            border-radius: 8px;
            display: block;
        }

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

        /* ── Footer info (below input) ─────────────────────────────── */
        #footer-info {
            text-align: center;
            font-size: .7rem;
            color: var(--text-muted);
            opacity: .55;
            padding: 0 16px 10px;
            user-select: none;
        }

        #footer-info-link {
            color: inherit;
            background: none;
            border: none;
            font: inherit;
            padding: 0;
            cursor: pointer;
            text-decoration: underline dotted;
        }

        #footer-info-link:hover { color: var(--text); }

        /* ── Info overlay (footer credit) ──────────────────────────── */
        #info-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 700;
            background: rgba(0,0,0,.6);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        #info-overlay.open { display: flex; }

        #info-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px 36px 28px;
            max-width: 520px;
            width: calc(100% - 32px);
            box-shadow: 0 24px 60px rgba(0,0,0,.6);
            text-align: left;
            line-height: 1.6;
        }

        #info-box .info-content {
            margin-bottom: 20px;
            font-size: .93rem;
            color: var(--text);
        }

        #info-close {
            display: block;
            width: 100%;
            padding: 10px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        #info-close:hover { background: var(--accent-dark); }

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

        /* ── Notification / upload header buttons ─────────────────── */
        .header-icon-btn {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: background .15s, color .15s;
            flex-shrink: 0;
        }

        .header-icon-btn:hover { background: var(--surface); color: var(--text); }

        .header-icon-btn .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 16px;
            height: 16px;
            background: var(--accent);
            color: #fff;
            font-size: .62rem;
            font-weight: 700;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 3px;
            line-height: 1;
        }

        .header-icon-btn .badge.badge-error { background: var(--error); }
        .header-icon-btn .badge.badge-warn  { background: var(--warning); }

        /* ── Notification panel ───────────────────────────────────── */
        #notif-panel {
            display: none;
            position: fixed;
            top: 58px;
            right: 14px;
            width: 340px;
            max-height: 480px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 16px 40px rgba(0,0,0,.5);
            z-index: 500;
            overflow: hidden;
            flex-direction: column;
        }

        #notif-panel.open { display: flex; }

        #notif-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px 10px;
            border-bottom: 1px solid var(--border);
            font-size: .88rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        #notif-panel-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }

        #notif-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }

        .notif-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 10px 16px;
            font-size: .82rem;
            border-bottom: 1px solid var(--border);
        }

        .notif-item:last-child { border-bottom: none; }

        .notif-status-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

        .notif-info { flex: 1; min-width: 0; }

        .notif-name {
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .notif-meta { font-size: .74rem; color: var(--text-muted); margin-top: 2px; }

        .notif-error { font-size: .74rem; color: var(--error); margin-top: 3px; word-break: break-word; }

        /* ── Upload modal ─────────────────────────────────────────── */
        #upload-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 600;
            background: rgba(0,0,0,.55);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        #upload-modal.open { display: flex; }

        #upload-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 32px;
            max-width: 420px;
            width: calc(100% - 32px);
            box-shadow: 0 24px 60px rgba(0,0,0,.6);
            position: relative;
        }

        #upload-modal h3 { font-size: 1rem; margin-bottom: 16px; }

        #upload-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.2rem;
            cursor: pointer;
            line-height: 1;
        }

        #upload-drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 32px 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            color: var(--text-muted);
            font-size: .88rem;
        }

        #upload-drop-zone:hover,
        #upload-drop-zone.dragover {
            border-color: var(--accent);
            background: rgba(108,99,255,.06);
            color: var(--text);
        }

        #upload-drop-zone .drop-icon { font-size: 2rem; margin-bottom: 8px; }

        #upload-file-input { display: none; }

        #upload-preview {
            margin-top: 12px;
            font-size: .82rem;
            color: var(--text-muted);
        }

        #upload-global-wrap {
            margin-top: 12px;
            font-size: .82rem;
            color: var(--text-muted);
        }

        #upload-global-wrap label {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            cursor: pointer;
            line-height: 1.35;
        }

        #upload-global-rag {
            margin-top: 2px;
            accent-color: var(--accent);
        }

        #upload-progress {
            margin-top: 12px;
            display: none;
        }

        #upload-progress-bar {
            height: 4px;
            background: var(--surface-alt);
            border-radius: 2px;
            overflow: hidden;
        }

        #upload-progress-fill {
            height: 100%;
            width: 0%;
            background: var(--accent);
            transition: width .3s;
        }

        #upload-msg {
            font-size: .82rem;
            margin-top: 8px;
        }

        #upload-msg.ok    { color: var(--success); }
        #upload-msg.error { color: var(--error); }

        #upload-submit-btn {
            margin-top: 14px;
            width: 100%;
            padding: 9px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: .9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
        }

        #upload-submit-btn:hover:not(:disabled) { background: var(--accent-dark); }
        #upload-submit-btn:disabled { opacity: .4; cursor: default; }

        /* ── Login banner overlay ──────────────────────────── */
        #login-banner-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 700;
            background: rgba(0,0,0,.6);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        #login-banner-overlay.open { display: flex; }

        #login-banner-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px 36px 28px;
            max-width: 520px;
            width: calc(100% - 32px);
            box-shadow: 0 24px 60px rgba(0,0,0,.6);
            text-align: left;
            line-height: 1.6;
        }

        #login-banner-box .banner-content {
            margin-bottom: 20px;
            font-size: .93rem;
            color: var(--text);
        }

        #login-banner-ok {
            display: block;
            width: 100%;
            padding: 10px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        #login-banner-ok:hover { background: var(--accent-dark); }
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────── -->
<header>
    <h1><img class="brand-logo" src="assets/img/ai-mascot.png" alt="KHWF KI"> KHWF KI</h1>
    <div style="display:flex;align-items:center;gap:8px">
<?php if ($loggedIn): ?>
        <span style="font-size:.8rem;color:var(--text-muted)">👤 <?= $loggedUser ?></span>
<?php if ($canUploadDocuments && $visionModelConfigured): ?>
        <!-- Document upload button -->
        <button class="header-icon-btn" id="upload-btn" title="Dokument hochladen">
            📎
        </button>
        <!-- Notification bell -->
        <button class="header-icon-btn" id="notif-btn" title="Dokument-Analysestatus">
            🔔
            <span id="notif-badge" class="badge" style="display:none">0</span>
        </button>
<?php endif; ?>
        <a href="logout.php" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:background .12s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">Abmelden</a>
<?php else: ?>
        <a href="login.php" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:background .12s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">🔐 Anmelden</a>
        <a href="register.php" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;padding:6px 12px;border-radius:8px;border:1px solid var(--border);transition:background .12s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">✍ Registrieren</a>
<?php endif; ?>
        <a class="admin-link" href="admin/login.php">⚙ Admin</a>
    </div>
</header>

<?php if ($canUploadDocuments && $visionModelConfigured): ?>
<!-- ── Notification panel (document status) ────────────────── -->
<div id="notif-panel">
    <div id="notif-panel-header">
        <span>📄 Dokumente</span>
        <button id="notif-panel-close" title="Schließen">✕</button>
    </div>
    <div id="notif-list">
        <div style="padding:16px;text-align:center;color:var(--text-muted);font-size:.82rem">
            Lade …
        </div>
    </div>
</div>

<!-- ── Upload modal ────────────────────────────────────────── -->
<div id="upload-modal">
    <div id="upload-box">
        <button id="upload-close" title="Schließen" aria-label="Schließen">✕</button>
        <h3>📎 Dokument hochladen</h3>

        <div id="upload-drop-zone">
            <div class="drop-icon">📄</div>
            <div>Datei hier ablegen oder <strong>klicken zum Auswählen</strong></div>
            <div style="font-size:.76rem;margin-top:6px">PNG, JPG, WEBP, GIF, PDF · max. 20 MB</div>
        </div>
        <input type="file" id="upload-file-input"
               accept="image/png,image/jpeg,image/webp,image/gif,application/pdf">

        <div id="upload-preview"></div>
        <div id="upload-global-wrap">
            <label for="upload-global-rag">
                <input type="checkbox" id="upload-global-rag" checked>
                Upload global für RAG freigeben (alle Nutzer können die Inhalte verwenden)
            </label>
        </div>

        <div id="upload-progress">
            <div id="upload-progress-bar">
                <div id="upload-progress-fill"></div>
            </div>
            <div id="upload-msg"></div>
        </div>

        <button id="upload-submit-btn" disabled>📤 Hochladen & Analysieren</button>
    </div>
</div>
<?php endif; ?>

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

        <!-- Attached-image preview strip -->
        <div id="attach-preview"></div>

        <!-- Main input container -->
        <div id="input-box">
            <span id="group-pill" title="Angesprochene Intelligenzgruppe">
                <span id="group-pill-label"></span>
                <button type="button" id="group-pill-remove" title="Intelligenzgruppe entfernen" aria-label="Intelligenzgruppe entfernen">×</button>
            </span>
            <span id="reasoning-pill" title="Reasoning für diesen Prompt aktiv">
                <span id="reasoning-pill-label"></span>
                <button type="button" id="reasoning-pill-remove" title="Reasoning deaktivieren" aria-label="Reasoning deaktivieren">×</button>
            </span>
            <span id="cmd-pills"></span>
            <textarea id="user-input" rows="1"
                      placeholder="Nachricht schreiben … (Enter = Senden, Shift+Enter = Zeilenumbruch)"></textarea>
            <input type="file" id="attach-image-input" accept="image/png,image/jpeg,image/webp,image/gif" multiple style="display:none">
            <select id="attach-detail-select" title="Bild-Detailgrad (Vision-API)" aria-label="Bild-Detailgrad">
                <option value="auto">Detail: Auto</option>
                <option value="low">Detail: Niedrig</option>
                <option value="high">Detail: Hoch</option>
            </select>
            <button id="attach-image-btn" title="Bild anhängen">🖼</button>
            <button id="clear-btn" title="Verlauf löschen">🗑</button>
            <button id="send-btn" title="Senden">↑</button>
        </div>

        <!-- Meta row: system-prompt toggle + status -->
        <div id="input-meta">
            <button id="system-toggle">System-Prompt ▾</button>
            <span id="status-bar" class="info">Bereit</span>
        </div>

    </div>
    <div id="footer-info"><button type="button" id="footer-info-link">LLMInt by Daniel-André Reinelt</button></div>
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
    const groupPill       = document.getElementById('group-pill');
    const groupPillLabel  = document.getElementById('group-pill-label');
    const groupPillRemove = document.getElementById('group-pill-remove');
    const reasoningPill       = document.getElementById('reasoning-pill');
    const reasoningPillLabel  = document.getElementById('reasoning-pill-label');
    const reasoningPillRemove = document.getElementById('reasoning-pill-remove');
    const cmdPillsEl          = document.getElementById('cmd-pills');
    const attachImageBtn   = document.getElementById('attach-image-btn');
    const attachImageInput = document.getElementById('attach-image-input');
    const attachPreview    = document.getElementById('attach-preview');
    const attachDetailSelect = document.getElementById('attach-detail-select');

    /* Images attached to the next outgoing message: { dataUrl, mimeType, name } */
    let pendingImages = [];

    const MAX_ATTACH_IMAGES     = 4;
    const MAX_ATTACH_FILE_MB    = 10;
    const MAX_ATTACH_DIMENSION  = 1536; // px, longest side after downscale
    const ATTACH_JPEG_QUALITY   = 0.82;

    /* ── Welcome screen ──────────────────────────────────── */

    function showWelcome() {
        chatArea.innerHTML =
            '<div id="welcome">' +
            '<div class="welcome-logo"><img src="assets/img/ai-mascot.png" alt="KHWF KI"></div>' +
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

    /* Whether replies are rendered live as they stream in (admin-configurable) */
    const streamingEnabled = <?= json_encode($streamingEnabled) ?>;

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

    /* ── Intelligence groups (addressed with the "@@" prefix) ─── */
    /* Whether the feature is enabled in the administration. */
    const intelligenceGroupEnabled = <?= $intelligenceGroupEnabled ? 'true' : 'false' ?>;
    /* Available groups derived from the models of all active endpoints. */
    const intelligenceGroups = <?= json_encode($intelligenceGroups) ?>;

    /* Group that is active for this chat (e.g. "35b"), '' when none. */
    let activeGroup = '';
    /* Whether the group changed and has to be transmitted with the next request. */
    let groupChanged = false;

    /** Normalize a typed token ("35", "35B") to an available group label ("35b").
     *  Returns '' when no such group exists. */
    function normalizeGroupToken(raw) {
        const m = /^(\d+(?:[.,]\d+)?)\s*b?$/i.exec(String(raw).trim());
        if (!m) return '';
        const value = parseFloat(m[1].replace(',', '.'));
        if (!(value > 0)) return '';
        const label = (Math.round(value * 100) / 100) + 'b';
        return intelligenceGroups.includes(label) ? label : '';
    }

    function renderGroupPill() {
        if (!groupPill) return;
        if (activeGroup) {
            groupPillLabel.textContent = '🧠 ' + activeGroup;
            groupPill.classList.add('visible');
        } else {
            groupPill.classList.remove('visible');
        }
    }

    /** Activate a group and show it as a pill. */
    function setActiveGroup(label, markChanged) {
        if (activeGroup === label && !markChanged) return;
        activeGroup = label;
        if (markChanged) groupChanged = true;
        renderGroupPill();
    }

    /** Remove the active group (× on the pill or a new chat). */
    function removeActiveGroup(markChanged) {
        if (!activeGroup && !markChanged) { renderGroupPill(); return; }
        activeGroup = '';
        if (markChanged) groupChanged = true;
        renderGroupPill();
    }

    /** Turn a leading "@@<group>" in the input into a pill as soon as it is typed. */
    function applyGroupPrefixFromInput() {
        if (!intelligenceGroupEnabled) return;
        const m = /^\s*@@(\d+(?:[.,]\d+)?\s*[bB]?)(\s+|$)/.exec(userInput.value);
        if (!m) return;
        const label = normalizeGroupToken(m[1]);
        if (!label) return;
        userInput.value = userInput.value.slice(m[0].length);
        setActiveGroup(label, true);
        setStatus('Intelligenzgruppe ' + label + ' aktiv.', 'info');
        autoResizeTextarea(userInput);
    }

    /* ── Reasoning (addressed with the "!!" prefix) ────────────── */
    /* Thinking/reasoning is off by default and is switched on for a single
       prompt by starting it with "!!". */
    let reasoningActive = false;

    function renderReasoningPill() {
        if (!reasoningPill) return;
        if (reasoningActive) {
            reasoningPillLabel.textContent = '💡 Reasoning';
            reasoningPill.classList.add('visible');
        } else {
            reasoningPill.classList.remove('visible');
        }
    }

    /** Turn a leading "!!" in the input into a pill as soon as it is typed. */
    function applyReasoningPrefixFromInput() {
        const m = /^\s*!!\s*/.exec(userInput.value);
        if (!m) return;
        userInput.value = userInput.value.slice(m[0].length);
        reasoningActive = true;
        renderReasoningPill();
        setStatus('Reasoning für diesen Prompt aktiv.', 'info');
        autoResizeTextarea(userInput);
    }

    if (reasoningPillRemove) {
        reasoningPillRemove.addEventListener('click', () => {
            reasoningActive = false;
            renderReasoningPill();
            setStatus('Reasoning deaktiviert.', 'info');
            userInput.focus();
        });
    }

    if (groupPillRemove) {
        groupPillRemove.addEventListener('click', () => {
            removeActiveGroup(true);
            setStatus('Intelligenzgruppe entfernt.', 'info');
            userInput.focus();
        });
    }

    /* ── Prompt functions (addressed with a "/command" prefix) ─── */
    /* Each function appends a fixed instruction to the system prompt for the
       next request only, e.g. "/tldr" or "/table". Several functions can be
       combined by chaining multiple "/command" prefixes at the start of the
       message; each becomes its own removable pill. Aliases point to the
       same underlying instruction so only one pill is ever shown for them. */
    const PROMPT_COMMANDS = {
        table:      { emoji: '📊', label: 'Tabelle',
            addition: 'Gib deine gesamte Antwort ausschließlich als gut strukturierte Markdown-Tabelle aus (mit Kopfzeile und Trennzeile). Verzichte auf einleitenden oder abschließenden Fließtext außerhalb der Tabelle.' },
        outline:    { emoji: '🗂️', label: 'Gliederung',
            addition: 'Strukturiere deine Antwort als übersichtliche, hierarchische Gliederung bzw. Inhaltsverzeichnis mit nummerierten oder eingerückten Ebenen (Haupt- und Unterpunkte). Vermeide ausformulierte Fließtext-Absätze.' },
        list:       { emoji: '•', label: 'Liste',
            addition: 'Gib die Antwort ausschließlich als Aufzählung (Bullet Points) aus. Jeder Punkt soll kurz und prägnant sein.' },
        checklist:  { emoji: '☑️', label: 'Checkliste',
            addition: 'Verwandle das Thema in eine direkt umsetzbare To-do-Liste mit Markdown-Checkboxen ("- [ ] Aufgabe"). Formuliere jeden Punkt als konkrete, ausführbare Handlung.' },
        steps:      { emoji: '🔢', label: 'Schritte',
            addition: 'Liefere eine chronologische Schritt-für-Schritt-Anleitung mit durchnummerierten Schritten (1., 2., 3., …). Jeder Schritt soll genau eine Handlung beschreiben.' },
        code:       { emoji: '💻', label: 'Code',
            addition: 'Gib die gesamte Antwort ausschließlich als Programmiercode in einem einzigen Code-Block aus. Kein erklärender Fließtext außerhalb des Code-Blocks, außer knappen Kommentaren im Code selbst.' },
        json:       { emoji: '{ }', label: 'JSON',
            addition: 'Formatiere die gesamte Antwort strikt als valides, maschinenlesbares JSON. Kein Fließtext, keine Markdown-Formatierung, keine Kommentare – nur das reine JSON-Objekt bzw. -Array.' },
        tldr:       { emoji: '⚡', label: 'TL;DR',
            addition: 'Erstelle ausschließlich eine ultrakurze Zusammenfassung (TL;DR) in 2–3 Sätzen. Beschränke dich auf die wichtigste Kernaussage.' },
        summary:    { emoji: '📝', label: 'Zusammenfassung',
            addition: 'Liefere eine klassische, ausgewogene Zusammenfassung des Inhalts in mehreren zusammenhängenden Sätzen bzw. einem kurzen Absatz, die die wesentlichen Punkte abdeckt.' },
        short:      { emoji: '✂️', label: 'Kurz',
            addition: 'Antworte extrem prägnant und ohne Floskeln, Einleitungen oder Wiederholungen. Komm sofort zum Punkt und nutze so wenige Worte wie möglich.' },
        brief:      { aliasOf: 'short' },
        expand:     { emoji: '➕', label: 'Ausbauen',
            addition: 'Nimm den bereitgestellten Input (auch wenn er nur aus kurzen Stichpunkten oder Notizen besteht) und baue ihn zu einem ausführlichen, detaillierten Text mit Kontext, Erklärungen und Beispielen aus.' },
        eli5:       { emoji: '🧒', label: 'ELI5',
            addition: 'Erkläre das Thema so, wie man es einem Kind erklären würde (Explain Like I\'m 5): einfache Sprache, kurze Sätze, anschauliche Alltagsanalogien, keine Fachbegriffe ohne Erklärung.' },
        deep:       { emoji: '🎓', label: 'Tiefgehend',
            addition: 'Antworte auf akademischem Niveau mit einer tiefgehenden, wissenschaftlich fundierten Analyse. Beziehe relevante Theorien, Fachbegriffe, Nuancen und ggf. gegensätzliche Standpunkte mit ein.' },
        adv:        { aliasOf: 'deep' },
        tech:       { emoji: '⚙️', label: 'Technisch',
            addition: 'Gib eine rein technische Erklärung mit präzisen Fachbegriffen, Systemdetails und Implementierungsaspekten. Setze Fachwissen der Leserschaft voraus.' },
        examples:   { emoji: '💡', label: 'Beispiele',
            addition: 'Erkläre das Thema primär anhand von konkreten, praktischen Beispielen aus der echten Welt statt abstrakter Theorie. Jede Kernaussage soll durch mindestens ein Beispiel veranschaulicht werden.' },
        human:      { emoji: '🙂', label: 'Menschlich',
            addition: 'Schreibe in einem lockeren, natürlichen, abwechslungsreichen Stil, der wie ein Mensch klingt. Vermeide typische KI-Floskeln (z. B. "Es ist wichtig zu beachten", "Zusammenfassend lässt sich sagen") und generische Übergänge.' },
        expert:     { emoji: '🎩', label: 'Experte',
            addition: 'Schreibe formell, hochprofessionell und nutze branchenüblichen Fachjargon, wie es in einem professionellen Kontext (z. B. Fachpublikation, Businessbericht) erwartet wird.' },
        pro:        { aliasOf: 'expert' },
        casual:     { emoji: '😎', label: 'Locker',
            addition: 'Nutze einen freundlichen, entspannten, informellen Ton – wie in einer lockeren Chat-Nachricht oder einem Social-Media-Post. Kurze Sätze, gerne mit Umgangssprache oder passenden Emojis.' },
        rewrite:    { emoji: '🔁', label: 'Umformulieren',
            addition: 'Formuliere den vom Nutzer bereitgestellten Text stilistisch um und verbessere ihn (Klarheit, Lesefluss, Wortwahl), ohne den inhaltlichen Sinn zu verändern. Gib nur den überarbeiteten Text aus.' },
        proscons:   { emoji: '⚖️', label: 'Pro/Contra',
            addition: 'Analysiere die genannte Idee oder Entscheidung sofort strukturiert nach Vor- und Nachteilen (z. B. als zwei Listen "Vorteile" und "Nachteile"). Ziehe am Ende ein kurzes Fazit.' },
        brainstorm: { emoji: '🌪️', label: 'Brainstorm',
            addition: 'Generiere eine möglichst lange Liste kreativer, unkonventioneller und vielfältiger Ideen zum genannten Stichwort. Bewerte die Ideen nicht, sondern liefere zunächst nur eine breite Auswahl.' },
        factcheck:  { emoji: '🔍', label: 'Faktencheck',
            addition: 'Prüfe die im Text enthaltene(n) Behauptung(en) gezielt auf ihren Wahrheitsgehalt. Suche nach stützenden oder widerlegenden Belegen, benenne Unsicherheiten und gib eine klare Einschätzung (wahr/falsch/unklar) mit Begründung.' },
        critic:     { emoji: '🧐', label: 'Kritiker',
            addition: 'Nimm die Rolle eines kritischen Prüfers ein und suche gezielt nach Schwachstellen, Risiken, Widersprüchen und logischen Fehlern in der vorliegenden Argumentation. Sei konstruktiv, aber schone die Argumentation nicht.' },
    };

    /** Resolve an alias (e.g. "brief") to its canonical command key (e.g. "short"). */
    function resolveCommand(name) {
        let def = PROMPT_COMMANDS[name];
        let key = name;
        while (def && def.aliasOf) { key = def.aliasOf; def = PROMPT_COMMANDS[key]; }
        return def ? key : null;
    }

    /* Prompt functions active for the next message, in the order they were added. */
    let activeCommands = [];

    function renderCommandPills() {
        if (!cmdPillsEl) return;
        cmdPillsEl.innerHTML = '';
        activeCommands.forEach(key => {
            const def = PROMPT_COMMANDS[key];
            const pill = document.createElement('span');
            pill.className = 'cmd-pill';
            pill.title = 'Prompt-Funktion: /' + key;

            const labelEl = document.createElement('span');
            labelEl.textContent = def.emoji + ' ' + def.label;
            pill.appendChild(labelEl);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.textContent = '×';
            removeBtn.title = def.label + ' entfernen';
            removeBtn.setAttribute('aria-label', def.label + ' entfernen');
            removeBtn.addEventListener('click', () => {
                activeCommands = activeCommands.filter(k => k !== key);
                renderCommandPills();
                setStatus(def.label + ' entfernt.', 'info');
                userInput.focus();
            });
            pill.appendChild(removeBtn);

            cmdPillsEl.appendChild(pill);
        });
    }

    /** Turn one or more leading "/command" tokens in the input into pills.
     *  Unrecognized "/word" tokens are skipped over (left untouched) so that
     *  a later recognized command is still picked up, e.g. in
     *  "/unknown /tldr Text …" the "/tldr" is still activated. */
    function applyCommandPrefixFromInput() {
        const re = /^\s*\/([a-zA-Z0-9]+)(\s+|$)/;
        let text = userInput.value;
        let consumed = 0;
        let changed = false;
        while (true) {
            const m = re.exec(text.slice(consumed));
            if (!m) break;
            const key = resolveCommand(m[1].toLowerCase());
            if (key) {
                text = text.slice(0, consumed) + text.slice(consumed + m[0].length);
                if (!activeCommands.includes(key)) activeCommands.push(key);
                changed = true;
            } else {
                consumed += m[0].length;
            }
        }
        if (!changed) return;
        userInput.value = text;
        renderCommandPills();
        setStatus('Prompt-Funktion(en) aktiv: ' + activeCommands.map(k => PROMPT_COMMANDS[k].label).join(', '), 'info');
        autoResizeTextarea(userInput);
    }

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

    /** Rebuild the visible chat + in-memory history from a loaded session
     *  payload. Shared by loadSession() (sidebar click) and the page-load
     *  restore, so both keep sessionId and history in sync and never let a
     *  stale/empty history silently overwrite a stored conversation. */
    function applyLoadedSession(sid, data) {
        sessionId = sid;
        sessionStorage.setItem('chat_session_id', sessionId);

        // Restore the intelligence group of the loaded chat.
        activeGroup  = (intelligenceGroupEnabled && typeof data.intelligence_group === 'string') ? data.intelligence_group : '';
        groupChanged = false;
        renderGroupPill();

        // Rebuild the visible chat from stored messages.
        clearUpgradePrompt();
        chatArea.innerHTML = '';
        history = [];

        const msgs = data.messages || [];
        for (const msg of msgs) {
            if (msg.role === 'user' || msg.role === 'assistant') {
                const content = msg.content;
                const bubble = appendMessage(msg.role, content);
                if (msg.role === 'assistant' && Array.isArray(msg.sources)) {
                    setSourcePillsForBubble(bubble, msg.sources);
                }
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

            applyLoadedSession(sid, data);
            setStatus('Chat geladen.', 'ok');
        } catch (_) {
            setStatus('Fehler beim Laden des Chats.', 'error');
        }
    }

    /** Restore the chat tied to the session ID already stored in
     *  sessionStorage (e.g. after a page reload or a full-page navigation
     *  such as login). Without this, `history` would start out empty while
     *  `sessionId` still points at an existing conversation – sending a new
     *  message would then silently overwrite that stored conversation under
     *  its old title instead of starting a fresh, separate chat. */
    async function restoreCurrentSession() {
        if (!loggedIn || !sessionId) return;
        try {
            const res = await fetch('api/chat_sessions.php?action=load&session_id=' + encodeURIComponent(sessionId));
            if (!res.ok) return; // Unknown/new session id – nothing to restore, start empty.
            const data = await res.json();
            if (data.error) return;
            applyLoadedSession(sessionId, data);
        } catch (_) { /* ignore – fall back to the empty welcome screen */ }
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
        // Block while a response is still streaming: switching sessionId/history
        // out from under an in-flight request would let its late completion
        // handler write its reply into this new, unrelated chat.
        if (isStreaming) return;
        history = [];
        chatArea.innerHTML = '';
        clearUpgradePrompt();
        removeActiveGroup(false);
        activeCommands = [];
        renderCommandPills();
        pendingImages = [];
        if (typeof renderAttachPreview === 'function') renderAttachPreview();
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

    /* Auto-follow is paused as soon as the user scrolls up (e.g. to re-read
       older text while an answer is still being generated) and resumes once
       the view is back at the bottom. */
    let autoScrollEnabled = true;
    const AUTO_SCROLL_THRESHOLD = 40;

    function isChatAreaAtBottom() {
        return chatArea.scrollHeight - chatArea.scrollTop - chatArea.clientHeight <= AUTO_SCROLL_THRESHOLD;
    }

    chatArea.addEventListener('scroll', () => {
        autoScrollEnabled = isChatAreaAtBottom();
    });

    function scrollToBottom(force = true) {
        if (!force && !autoScrollEnabled) return;
        autoScrollEnabled = true;
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
        // Explicit line break <br> / <br/> / <br /> (escaped by escapeHtmlContent
        // beforehand, so this restores it as a real line break instead of literal text).
        str = str.replace(/&lt;br\s*\/?&gt;/gi, '<br>');
        // Strikethrough ~~text~~
        str = str.replace(/~~(.+?)~~/g, '<del>$1</del>');
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

    /* ── LaTeX-ish math rendering ($...$ inline, $$...$$ block) ──── */

    const MATH_SYMBOLS = {
        alpha: 'α', beta: 'β', gamma: 'γ', delta: 'δ', epsilon: 'ε', varepsilon: 'ε',
        zeta: 'ζ', eta: 'η', theta: 'θ', vartheta: 'ϑ', iota: 'ι', kappa: 'κ',
        lambda: 'λ', mu: 'μ', nu: 'ν', xi: 'ξ', pi: 'π', varpi: 'ϖ', rho: 'ρ',
        varrho: 'ϱ', sigma: 'σ', varsigma: 'ς', tau: 'τ', upsilon: 'υ', phi: 'φ',
        varphi: 'ϕ', chi: 'χ', psi: 'ψ', omega: 'ω',
        Gamma: 'Γ', Delta: 'Δ', Theta: 'Θ', Lambda: 'Λ', Xi: 'Ξ', Pi: 'Π',
        Sigma: 'Σ', Upsilon: 'Υ', Phi: 'Φ', Psi: 'Ψ', Omega: 'Ω',
        pm: '±', mp: '∓', times: '×', div: '÷', cdot: '·', ast: '∗',
        approx: '≈', neq: '≠', ne: '≠', leq: '≤', le: '≤', geq: '≥', ge: '≥',
        ll: '≪', gg: '≫', equiv: '≡', sim: '∼', simeq: '≃', propto: '∝',
        infty: '∞', partial: '∂', nabla: '∇', forall: '∀', exists: '∃',
        in: '∈', notin: '∉', ni: '∋', subset: '⊂', subseteq: '⊆', supset: '⊃',
        supseteq: '⊇', cup: '∪', cap: '∩', emptyset: '∅', varnothing: '∅',
        wedge: '∧', vee: '∨', neg: '¬', therefore: '∴', because: '∵',
        cdots: '⋯', ldots: '…', dots: '…', vdots: '⋮', ddots: '⋱',
        to: '→', rightarrow: '→', leftarrow: '←', leftrightarrow: '↔',
        Rightarrow: '⇒', Leftarrow: '⇐', Leftrightarrow: '⇔',
        sum: '∑', prod: '∏', int: '∫', oint: '∮', degree: '°',
        angle: '∠', perp: '⊥', parallel: '∥', hbar: 'ℏ', ell: 'ℓ',
        Re: 'ℜ', Im: 'ℑ', aleph: 'ℵ', imath: 'ı', jmath: 'ȷ', prime: '′'
    };

    function renderMathBody(escapedLatex) {
        let s = escapedLatex.trim();

        // Strip \left / \right sizing prefixes on delimiters.
        s = s.replace(/\\left([([{|])/g, '$1').replace(/\\right([)\]}|])/g, '$1');
        s = s.replace(/\\left\\?\{/g, '{').replace(/\\right\\?\}/g, '}');
        s = s.replace(/\\left\./g, '').replace(/\\right\./g, '');

        // \text{...}, \mathrm{...}, \mathbf{...} → keep inner content.
        s = s.replace(/\\(?:text|mathrm|operatorname)\{([^{}]*)\}/g, '$1');
        s = s.replace(/\\mathbf\{([^{}]*)\}/g, '<strong>$1</strong>');
        s = s.replace(/\\(?:mathit|mathnormal)\{([^{}]*)\}/g, '<em>$1</em>');

        // \sqrt{x} and \sqrt[n]{x}
        s = s.replace(/\\sqrt\[([^\]]*)\]\{([^{}]*)\}/g, '<sup>$1</sup>&radic;($2)');
        s = s.replace(/\\sqrt\{([^{}]*)\}/g, '&radic;($1)');
        s = s.replace(/\\sqrt(\S)/g, '&radic;$1');

        // \frac{a}{b} (single level of nesting supported).
        for (let i = 0; i < 3; i++) {
            s = s.replace(/\\(?:d|t)?frac\{([^{}]*)\}\{([^{}]*)\}/g,
                '<span class="math-frac"><span class="math-frac-num">$1</span><span class="math-frac-den">$2</span></span>');
        }

        // Superscript / subscript.
        s = s.replace(/\^\{([^{}]*)\}/g, '<sup>$1</sup>');
        s = s.replace(/_\{([^{}]*)\}/g, '<sub>$1</sub>');
        s = s.replace(/\^(\\?[a-zA-Z0-9])/g, function (_, c) { return '<sup>' + (MATH_SYMBOLS[c] || c) + '</sup>'; });
        s = s.replace(/_(\\?[a-zA-Z0-9])/g, function (_, c) { return '<sub>' + (MATH_SYMBOLS[c] || c) + '</sub>'; });

        // Greek letters & symbol commands: \pi, \approx, \ldots, etc.
        s = s.replace(/\\([a-zA-Z]+)/g, function (_, name) {
            return Object.prototype.hasOwnProperty.call(MATH_SYMBOLS, name) ? MATH_SYMBOLS[name] : name;
        });

        // Drop now-meaningless grouping braces left over from other commands.
        s = s.replace(/[{}]/g, '');

        return s;
    }

    function renderMath(rawLatex, displayMode) {
        const escaped = escapeHtmlContent(rawLatex);
        const body = renderMathBody(escaped);
        return displayMode
            ? '<span class="math-block">' + body + '</span>'
            : '<span class="math-inline">' + body + '</span>';
    }

    // Pull $$...$$ and $...$ math segments out of the raw text before any
    // other markdown processing, so LaTeX control characters (\, _, *, {})
    // are never mangled by the list/emphasis parsing below.
    function extractMath(text) {
        const store = [];
        const marker = i => '\uE000MATH' + i + '\uE001';

        // Block math: $$ ... $$ (can span multiple lines).
        text = text.replace(/\$\$([\s\S]+?)\$\$/g, function (_, latex) {
            const idx = store.length;
            store.push(renderMath(latex, true));
            return marker(idx);
        });

        // Inline math: $ ... $ (single line, not empty).
        text = text.replace(/\$([^\s$][^$\n]*?)\$/g, function (_, latex) {
            const idx = store.length;
            store.push(renderMath(latex, false));
            return marker(idx);
        });

        return { text, store };
    }

    function reinsertMath(html, store) {
        if (!store.length) return html;
        // Unwrap a stray <p> around a solitary block/inline math token.
        html = html.replace(/<p>(\uE000MATH\d+\uE001)<\/p>/g, '$1');
        return html.replace(/\uE000MATH(\d+)\uE001/g, function (_, idx) {
            return store[Number(idx)] !== undefined ? store[Number(idx)] : '';
        });
    }

    function renderMarkdown(text) {
        const { text: textWithoutMath, store: mathStore } = extractMath(text);
        const lines = textWithoutMath.split('\n');
        let html = '';
        let inCodeBlock = false;
        let codeContent = '';
        let listStack = []; // { type: 'ul'|'ol', indent: number }
        let bqDepth = 0;

        function closeLists() {
            while (listStack.length) {
                html += '</li>';
                html += listStack.pop().type === 'ol' ? '</ol>' : '</ul>';
            }
        }

        function closeBlockquotes(toDepth) {
            while (bqDepth > toDepth) {
                html += '</blockquote>';
                bqDepth--;
            }
        }

        function closeBlocks() {
            closeLists();
            closeBlockquotes(0);
        }

        function addListItem(type, indent, contentHtml) {
            if (listStack.length === 0) {
                html += type === 'ol' ? '<ol>' : '<ul>';
                listStack.push({ type: type, indent: indent });
                html += '<li>' + contentHtml;
                return;
            }
            const top = listStack[listStack.length - 1];
            if (indent > top.indent) {
                html += type === 'ol' ? '<ol>' : '<ul>';
                listStack.push({ type: type, indent: indent });
                html += '<li>' + contentHtml;
                return;
            }
            while (listStack.length && indent < listStack[listStack.length - 1].indent) {
                html += '</li>';
                html += listStack.pop().type === 'ol' ? '</ol>' : '</ul>';
            }
            const matched = listStack[listStack.length - 1];
            if (matched && matched.indent === indent) {
                if (matched.type !== type) {
                    html += '</li>';
                    html += matched.type === 'ol' ? '</ol>' : '</ul>';
                    listStack.pop();
                    html += type === 'ol' ? '<ol>' : '<ul>';
                    listStack.push({ type: type, indent: indent });
                    html += '<li>' + contentHtml;
                } else {
                    html += '</li><li>' + contentHtml;
                }
            } else {
                html += type === 'ol' ? '<ol>' : '<ul>';
                listStack.push({ type: type, indent: indent });
                html += '<li>' + contentHtml;
            }
        }

        function splitTableRow(row) {
            let r = row.trim();
            if (r.startsWith('|')) r = r.slice(1);
            if (r.endsWith('|')) r = r.slice(0, -1);
            return r.split('|').map(c => c.trim());
        }

        const tableSeparatorRe = /^\s*\|?\s*:?-{1,}:?\s*\|\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/;

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];

            // Fenced code block (``` or ~~~), tolerating up to 3 leading spaces
            if (/^\s{0,3}(`{3,}|~{3,})/.test(line)) {
                if (!inCodeBlock) {
                    closeBlocks();
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
                closeBlocks();
                html += '<hr>';
                continue;
            }

            // Table: header row followed by a "|---|---|" alignment row
            if (/\|/.test(line) && i + 1 < lines.length && tableSeparatorRe.test(lines[i + 1])) {
                closeBlocks();
                const headers = splitTableRow(line);
                const aligns = splitTableRow(lines[i + 1]).map(c => {
                    const left = c.startsWith(':');
                    const right = c.endsWith(':');
                    if (left && right) return 'center';
                    if (right) return 'right';
                    if (left) return 'left';
                    return '';
                });
                let tableHtml = '<table><thead><tr>';
                headers.forEach((h, idx) => {
                    const style = aligns[idx] ? ` style="text-align:${aligns[idx]}"` : '';
                    tableHtml += `<th${style}>${inlineMarkdown(escapeHtmlContent(h))}</th>`;
                });
                tableHtml += '</tr></thead><tbody>';
                let j = i + 2;
                while (j < lines.length && /\|/.test(lines[j]) && lines[j].trim() !== '') {
                    const cells = splitTableRow(lines[j]);
                    tableHtml += '<tr>';
                    cells.forEach((c, idx) => {
                        const style = aligns[idx] ? ` style="text-align:${aligns[idx]}"` : '';
                        tableHtml += `<td${style}>${inlineMarkdown(escapeHtmlContent(c))}</td>`;
                    });
                    tableHtml += '</tr>';
                    j++;
                }
                tableHtml += '</tbody></table>';
                html += tableHtml;
                i = j - 1;
                continue;
            }

            // Headings
            const hMatch = line.match(/^(#{1,4}) (.+)$/);
            if (hMatch) {
                closeBlocks();
                const level = hMatch[1].length;
                html += `<h${level}>${inlineMarkdown(escapeHtmlContent(hMatch[2]))}</h${level}>`;
                continue;
            }

            // Blockquote (supports nesting via repeated '>')
            const bqMatch = line.trimStart().match(/^(>+) ?(.*)$/);
            if (bqMatch) {
                closeLists();
                const depth = bqMatch[1].length;
                if (depth > bqDepth) {
                    while (bqDepth < depth) { html += '<blockquote>'; bqDepth++; }
                } else if (depth < bqDepth) {
                    closeBlockquotes(depth);
                }
                html += inlineMarkdown(escapeHtmlContent(bqMatch[2]));
                continue;
            }
            if (bqDepth > 0) {
                closeBlockquotes(0);
            }

            // Ordered list item (1. text), leading whitespace = nesting level
            const olMatch = line.match(/^(\s*)\d+\.\s+(.+)$/);
            if (olMatch) {
                addListItem('ol', olMatch[1].length, inlineMarkdown(escapeHtmlContent(olMatch[2])));
                continue;
            }

            // Unordered list item (*, -, +), leading whitespace = nesting level
            const ulMatch = line.match(/^(\s*)[*\-+]\s+(.+)$/);
            if (ulMatch) {
                addListItem('ul', ulMatch[1].length, inlineMarkdown(escapeHtmlContent(ulMatch[2])));
                continue;
            }

            // Empty line → close open blocks or add spacing
            if (line.trim() === '') {
                if (listStack.length || bqDepth > 0) {
                    closeBlocks();
                } else {
                    // Only add break if the previous output doesn't already end with a block element
                    if (html && !/(<\/(?:h[1-4]|p|ul|ol|pre|blockquote|table)>|<hr>)$/.test(html)) {
                        html += '<br>';
                    }
                }
                continue;
            }

            // Regular line
            closeBlocks();
            html += `<p>${inlineMarkdown(escaped)}</p>`;
        }

        closeBlocks();
        if (inCodeBlock) {
            html += '<pre><code>' + escapeHtmlContent(codeContent) + '</code></pre>';
        }

        return reinsertMath(html, mathStore);
    }

    /* ── Make trailing "what next?" questions clickable ──── */

    // If the assistant's answer ends with a list (e.g. "Wie soll es weitergehen?"
    // with numbered options), let the user click an item to send it as their
    // next message instead of having to retype it.
    function makeAnswerListsClickable(containerEl) {
        if (!containerEl) return;
        const lists = containerEl.querySelectorAll(':scope > ol, :scope > ul');
        if (!lists.length) return;
        const lastList = lists[lists.length - 1];

        lastList.querySelectorAll(':scope > li').forEach((li) => {
            if (li.dataset.clickableBound === '1') return;
            li.dataset.clickableBound = '1';
            li.classList.add('clickable-question');
            li.setAttribute('role', 'button');
            li.setAttribute('tabindex', '0');

            const activate = () => {
                if (isStreaming) return;
                const text = li.textContent.trim();
                if (!text) return;
                userInput.value = text;
                autoResizeTextarea(userInput);
                sendMessage();
            };

            li.addEventListener('click', activate);
            li.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    activate();
                }
            });
        });
    }

    /* ── Message content helpers (text / image parts) ────── */

    /** Splits an OpenAI-style message content (string or content-parts array)
     *  into its plain text and the list of attached image data URLs. */
    function extractMessageParts(content) {
        if (typeof content === 'string') return { text: content, images: [] };
        if (Array.isArray(content)) {
            let text = '';
            const images = [];
            for (const part of content) {
                if (!part || typeof part !== 'object') continue;
                if (part.type === 'text' && typeof part.text === 'string') {
                    text += (text ? '\n' : '') + part.text;
                } else if (part.type === 'image_url' && part.image_url && typeof part.image_url.url === 'string') {
                    images.push(part.image_url.url);
                }
            }
            return { text, images };
        }
        return { text: '', images: [] };
    }

    /* ── Attach-image button (next to "Chatverlauf löschen") ─────── */

    function renderAttachPreview() {
        attachPreview.innerHTML = '';
        attachPreview.classList.toggle('visible', pendingImages.length > 0);
        attachImageBtn.classList.toggle('has-images', pendingImages.length > 0);
        if (attachDetailSelect) {
            attachDetailSelect.classList.toggle('visible', pendingImages.length > 0);
        }

        pendingImages.forEach((img, index) => {
            const thumb = document.createElement('div');
            thumb.className = 'attach-thumb';

            const imgEl = document.createElement('img');
            imgEl.src = img.dataUrl;
            imgEl.alt = img.name;
            thumb.appendChild(imgEl);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'attach-thumb-remove';
            removeBtn.title = 'Bild entfernen';
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', () => {
                pendingImages.splice(index, 1);
                renderAttachPreview();
            });
            thumb.appendChild(removeBtn);

            attachPreview.appendChild(thumb);
        });
    }

    function readFileAsDataUrl(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(file);
        });
    }

    /* Downscales a data URL image via <canvas> so it stays within the
     * model's context window. Animated GIFs are left untouched (canvas
     * would flatten them to a single frame). Returns { dataUrl, mimeType }. */
    function downscaleImageDataUrl(dataUrl, mimeType, maxDim) {
        return new Promise((resolve) => {
            if (mimeType === 'image/gif') {
                resolve({ dataUrl, mimeType });
                return;
            }
            const img = new Image();
            img.onload = () => {
                const { naturalWidth: width, naturalHeight: height } = img;
                if (!width || !height || (width <= maxDim && height <= maxDim)) {
                    resolve({ dataUrl, mimeType });
                    return;
                }
                const scale = maxDim / Math.max(width, height);
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(width * scale));
                canvas.height = Math.max(1, Math.round(height * scale));
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                resolve({
                    dataUrl: canvas.toDataURL('image/jpeg', ATTACH_JPEG_QUALITY),
                    mimeType: 'image/jpeg',
                });
            };
            img.onerror = () => resolve({ dataUrl, mimeType });
            img.src = dataUrl;
        });
    }

    if (attachImageBtn && attachImageInput) {
        attachImageBtn.addEventListener('click', () => attachImageInput.click());

        attachImageInput.addEventListener('change', async () => {
            const files = Array.from(attachImageInput.files || []);
            attachImageInput.value = ''; // allow re-selecting the same file

            for (const file of files) {
                if (pendingImages.length >= MAX_ATTACH_IMAGES) {
                    setStatus(`Maximal ${MAX_ATTACH_IMAGES} Bilder pro Nachricht.`, 'error');
                    break;
                }
                if (!file.type.startsWith('image/')) {
                    setStatus('Nur Bilddateien können angehängt werden.', 'error');
                    continue;
                }
                if (file.size > MAX_ATTACH_FILE_MB * 1024 * 1024) {
                    setStatus(`Bild "${file.name}" ist zu groß (max. ${MAX_ATTACH_FILE_MB} MB).`, 'error');
                    continue;
                }
                try {
                    const rawDataUrl = await readFileAsDataUrl(file);
                    const { dataUrl, mimeType } = await downscaleImageDataUrl(rawDataUrl, file.type, MAX_ATTACH_DIMENSION);
                    pendingImages.push({ dataUrl, mimeType, name: file.name });
                } catch (_) {
                    setStatus(`Bild "${file.name}" konnte nicht gelesen werden.`, 'error');
                }
            }

            renderAttachPreview();
        });
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
            if (content && !streaming) makeAnswerListsClickable(bubble);
        } else {
            const { text, images } = extractMessageParts(content);
            if (images.length) {
                const imagesWrap = document.createElement('div');
                imagesWrap.className = 'msg-images';
                images.forEach(src => {
                    const imgEl = document.createElement('img');
                    imgEl.src = src;
                    imagesWrap.appendChild(imgEl);
                });
                bubble.appendChild(imagesWrap);
            }
            if (text) {
                const textEl = document.createElement('div');
                textEl.textContent = text;
                bubble.appendChild(textEl);
            }
        }

        messageContent.appendChild(bubble);

        if (role === 'assistant') {
            const sourcePills = document.createElement('div');
            sourcePills.className = 'source-pills';
            sourcePills.style.display = 'none';
            bubble._sourcePillsEl = sourcePills;
            messageContent.appendChild(sourcePills);

            const detailsWrap = document.createElement('details');
            detailsWrap.className = 'response-details';

            const summary = document.createElement('summary');
            const summaryLabel = document.createElement('span');
            summaryLabel.textContent = 'Details zur Antwort';
            const summaryCircles = document.createElement('span');
            summaryCircles.className = 'context-usage-summary';
            summary.appendChild(summaryLabel);
            summary.appendChild(summaryCircles);

            const body = document.createElement('div');
            body.className = 'response-details-body';
            body.innerHTML = 'Antwort bearbeitet durch: <strong>Unbekannt</strong>';

            bubble._responseDetailsBodyEl = body;
            bubble._responseDetailsSummaryCirclesEl = summaryCircles;

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

    function thinkingRobotHtml(live) {
        return '<div class="thinking-robot" aria-hidden="true">' +
            '<div class="thinking-thought"><span></span><span></span><span></span></div>' +
            '<div class="thinking-face' + (live ? ' live' : '') + '">' +
                '<img class="tf-a" src="assets/img/thinking-robot-a.png" alt="">' +
                '<img class="tf-b" src="assets/img/thinking-robot-b.png" alt="">' +
                '<img class="tf-c" src="assets/img/thinking-robot-c.png" alt="">' +
                '<img class="tf-done" src="assets/img/ai-mascot.png" alt="">' +
            '</div>' +
        '</div>';
    }

    // While a bubble is still thinking, the three robot images cross-fade into each
    // other in sequence. A single global recursive timer advances a "data-step"
    // counter (0 → 1 → 2 → 0 …) on every currently live ".thinking-face" in the
    // DOM (querySelectorAll re-scans the live document each tick, so finished/
    // removed bubbles never leak timers). Robot C (step 2) stays on screen 3x as
    // long as A/B (steps 0/1) before cycling back.
    const THINKING_STEP_MS = 1300;
    let thinkingStep = 0;
    (function tickThinkingRobot() {
        document.querySelectorAll('.thinking-face.live').forEach(function (el) {
            el.dataset.step = String(thinkingStep);
        });
        // Robot C (step 2) dwells 3x as long as A/B before cycling back to A.
        const dwell = thinkingStep === 2 ? THINKING_STEP_MS * 3 : THINKING_STEP_MS;
        thinkingStep = (thinkingStep + 1) % 3;
        setTimeout(tickThinkingRobot, dwell);
    })();

    const THINKING_ROBOT_HTML = thinkingRobotHtml(false);

    function thinkingLineHtml(line) {
        return inlineMarkdown(escapeHtmlContent(line));
    }

    function renderBubbleContent(thinking, text) {
        let html = '';
        if (thinking) {
            const lines = thinking.split('\n')
                .filter(l => l.trim() !== '')
                .map(l => '<div class="thinking-line">' + thinkingLineHtml(l) + '</div>')
                .join('');
            html += '<div class="thinking-bubble done">' + THINKING_ROBOT_HTML +
                '<div class="thinking-content">' + lines + '</div></div>';
        }
        if (text) {
            html += renderMarkdown(text);
        }
        return html;
    }

    // Incrementally updates a streaming bubble: the robot's thinking lines fly in
    // one by one (already rendered lines keep their state, no re-animation), while
    // the answer part below is re-rendered as markdown on every token.
    function updateStreamingBubble(bubble, thinking, text) {
        let thinkEl  = bubble.querySelector(':scope > .thinking-bubble');
        let answerEl = bubble.querySelector(':scope > .bubble-answer');
        if (!answerEl) {
            bubble.innerHTML = '';
            answerEl = document.createElement('div');
            answerEl.className = 'bubble-answer';
            bubble.appendChild(answerEl);
            thinkEl = null;
        }
        if (thinking) {
            if (!thinkEl) {
                thinkEl = document.createElement('div');
                thinkEl.className = 'thinking-bubble';
                thinkEl.innerHTML = thinkingRobotHtml(true) + '<div class="thinking-content"></div>';
                thinkEl._linesDone = 0;
                thinkEl._currentLineEl = null;
                bubble.insertBefore(thinkEl, answerEl);
            }
            const contentEl = thinkEl.querySelector('.thinking-content');
            const lines = thinking.split('\n');
            const completedCount = lines.length - 1;
            while (thinkEl._linesDone < completedCount) {
                const lineText = lines[thinkEl._linesDone];
                if (thinkEl._currentLineEl) {
                    if (lineText.trim() !== '') {
                        thinkEl._currentLineEl.innerHTML = thinkingLineHtml(lineText);
                    } else {
                        thinkEl._currentLineEl.remove();
                    }
                    thinkEl._currentLineEl = null;
                } else if (lineText.trim() !== '') {
                    const div = document.createElement('div');
                    div.className = 'thinking-line';
                    div.innerHTML = thinkingLineHtml(lineText);
                    contentEl.appendChild(div);
                }
                thinkEl._linesDone++;
            }
            const currentLine = lines[completedCount];
            if (currentLine.trim() !== '') {
                if (!thinkEl._currentLineEl) {
                    thinkEl._currentLineEl = document.createElement('div');
                    thinkEl._currentLineEl.className = 'thinking-line';
                    contentEl.appendChild(thinkEl._currentLineEl);
                }
                thinkEl._currentLineEl.innerHTML = thinkingLineHtml(currentLine);
            }
            contentEl.scrollTop = contentEl.scrollHeight;
        }
        answerEl.innerHTML = text ? renderMarkdown(text) : '';
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

            // Reasoning output is only rendered when it was explicitly
            // requested for this prompt (via the "!!" prefix); otherwise any
            // thinking tokens a backend still emits are dropped.
            const thinkingDelta = payload.reasoning === true
                ? (obj.choices?.[0]?.delta?.reasoning_content ?? '')
                : '';
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
            if ((delta || thinkingDelta) && streamingEnabled) {
                // Live rendering: thinking lines fly in one by one next to the
                // robot, the answer text grows continuously as tokens arrive.
                updateStreamingBubble(bubble, accumulatedThinking, accumulated);
                scrollToBottom(false);
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

        return { accumulated, accumulatedThinking, upgradeSuggestion, responseDetails };
    }

    /**
     * Builds the small "closing circle" indicator for a context-usage value:
     * an unobtrusive dot whose colored arc grows clockwise as more of the
     * limit is consumed, fully closing when the limit is reached. Returns
     * null when no limit is configured for this endpoint/session.
     */
    function buildContextCircleHtml(labelPrefix, used, max) {
        if (typeof max !== 'number' || max <= 0 || typeof used !== 'number') {
            return null;
        }
        const pct = Math.max(0, Math.min(100, Math.round((used / max) * 100)));
        const angle = (pct / 100) * 360;
        const critical = pct >= 90;
        const color = critical ? 'var(--error)' : 'var(--text-muted)';
        const title = `${labelPrefix} ${used} von ${max} Token`;
        return '<span class="context-usage-circle' + (critical ? ' context-usage-critical' : '') + '" ' +
            'title="' + escapeHtmlContent(title) + '" ' +
            'style="background: conic-gradient(' + color + ' ' + angle + 'deg, transparent ' + angle + 'deg)"></span>';
    }

    /**
     * Returns a favicon <img> URL for a source's domain via Google's public
     * favicon service, so pills show a recognizable site icon without the
     * server having to fetch and cache favicons itself.
     */
    function faviconUrlForSource(url) {
        try {
            const host = new URL(url).hostname;
            return 'https://www.google.com/s2/favicons?sz=32&domain=' + encodeURIComponent(host);
        } catch {
            return '';
        }
    }

    /**
     * Renders the "used sources" pills (favicon + linked title) under an
     * assistant bubble. Accepts the plain sources array as stored both in
     * response_details.search_sources (live/streamed answer) and on a
     * persisted assistant message (msg.sources, restored on chat reload).
     */
    function setSourcePillsForBubble(bubble, sources) {
        if (!bubble || !bubble._sourcePillsEl) {
            return;
        }
        const list = Array.isArray(sources) ? sources : [];
        const el = bubble._sourcePillsEl;
        if (list.length === 0) {
            el.innerHTML = '';
            el.style.display = 'none';
            return;
        }
        el.innerHTML = list.map((source) => {
            const url = typeof source?.url === 'string' ? source.url : '';
            if (!url) return '';
            const title = typeof source?.title === 'string' && source.title.trim() ? source.title.trim() : url;
            const favicon = faviconUrlForSource(url);
            const img = favicon ? `<img src="${escapeHtmlContent(favicon)}" alt="" loading="lazy">` : '';
            return `<a class="source-pill" href="${escapeHtmlContent(url)}" target="_blank" rel="noopener noreferrer" title="${escapeHtmlContent(url)}">${img}<span>${escapeHtmlContent(title)}</span></a>`;
        }).join('');
        el.style.display = 'flex';
    }

    function setResponseDetailsForBubble(bubble, responseDetails) {
        setSourcePillsForBubble(bubble, responseDetails && responseDetails.search_sources);
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

        const endpointCircle = buildContextCircleHtml('Endpunkt-Kontext',
            responseDetails?.endpoint_context_used, responseDetails?.endpoint_context_max);
        const sessionCircle = buildContextCircleHtml('Session-Kontext',
            responseDetails?.session_context_used, responseDetails?.session_context_limit);

        if (endpointCircle) {
            html += `<div class="context-usage-row">${endpointCircle} Endpunkt-Kontext: ${responseDetails.endpoint_context_used} / ${responseDetails.endpoint_context_max} Token</div>`;
        }
        if (sessionCircle) {
            html += `<div class="context-usage-row">${sessionCircle} Session-Kontext: ${responseDetails.session_context_used} / ${responseDetails.session_context_limit} Token</div>`;
        }

        bubble._responseDetailsBodyEl.innerHTML = html;

        if (bubble._responseDetailsSummaryCirclesEl) {
            bubble._responseDetailsSummaryCirclesEl.innerHTML = [endpointCircle, sessionCircle].filter(Boolean).join('');
        }

        // Context limit exhausted: don't let the answer just silently trail
        // off – show a clear, dedicated notice right under the message.
        const detailsWrap = bubble._responseDetailsBodyEl.parentElement;
        if (detailsWrap && detailsWrap.parentElement) {
            let notice = detailsWrap.parentElement.querySelector(':scope > .context-limit-notice');
            if (responseDetails && responseDetails.context_limit_reached) {
                if (!notice) {
                    notice = document.createElement('div');
                    notice.className = 'context-limit-notice';
                    detailsWrap.parentElement.insertBefore(notice, detailsWrap);
                }
                notice.textContent = '⚠ Der Kontext ist vollständig ausgeschöpft – die Antwort wurde deshalb an dieser Stelle beendet. Bitte kürzen Sie die Unterhaltung oder starten Sie einen neuen Chat.';
            } else if (notice) {
                notice.remove();
            }
        }
    }

    function showUpgradePrompt(upgrade, requestMessages, historyAssistantIndex, responseDetails) {
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
            fetch('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'decline_intelligence_upgrade' }),
                keepalive: true,
            }).catch(() => {});
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
                    intelligence_upgrade_accepted: true,
                };
                const upgradeSearchQuery = (responseDetails && typeof responseDetails.search_query === 'string')
                    ? responseDetails.search_query.trim()
                    : '';
                if (upgradeSearchQuery) {
                    retryPayload.force_search_query = upgradeSearchQuery;
                }
                const result = await executeStreamingRequest(retryPayload, bubble);
                let finalText = result.accumulated;
                let finalThinking = result.accumulatedThinking;
                let retryResponseDetails = result.responseDetails;

                if (!finalText) {
                    setStatus('Leere Antwort – Wiederholungsversuch …', 'info');
                    bubble.innerHTML = '';
                    const retryResult = await executeStreamingRequest(retryPayload, bubble);
                    finalText = retryResult.accumulated;
                    finalThinking = retryResult.accumulatedThinking;
                    retryResponseDetails = retryResult.responseDetails || retryResponseDetails;
                }

                finalText = finalText || '(Leere Antwort)';
                bubble.innerHTML = renderBubbleContent(finalThinking, finalText);
                makeAnswerListsClickable(bubble);
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
        // A "!!" / "@@" / "/command" prefix may still be in the box when the
        // message is sent without an intermediate input event (e.g.
        // programmatic input).
        applyReasoningPrefixFromInput();
        applyCommandPrefixFromInput();
        const text  = userInput.value.trim();
        const model = defaultModel;

        if (!text && pendingImages.length === 0) { userInput.focus(); return; }
        if (!model) { setStatus('Kein Standardmodell konfiguriert. Bitte im Admin-Bereich ein Modell festlegen.', 'error'); return; }
        if (isStreaming) return;

        clearUpgradePrompt();

        // Build the outgoing content: plain text, or an OpenAI-style content-parts
        // array (image_url + text) when images were attached via the 🖼 button.
        let userContent = text;
        if (pendingImages.length > 0) {
            const detail = attachDetailSelect ? attachDetailSelect.value : 'auto';
            userContent = pendingImages.map(img => {
                const image_url = { url: img.dataUrl };
                if (detail === 'low' || detail === 'high') image_url.detail = detail;
                return { type: 'image_url', image_url };
            });
            if (text) userContent.push({ type: 'text', text });
        }

        // Add user message to UI + history.
        appendMessage('user', userContent);
        history.push({ role: 'user', content: userContent });
        userInput.value = '';
        autoResizeTextarea(userInput);
        pendingImages = [];
        renderAttachPreview();

        // Build message array (optionally prepend system prompt).
        const messages = [];
        let sysPrompt = systemPromptTA.value.trim();
        // Prompt functions selected via "/command" prefixes each contribute a
        // fixed instruction that is appended to the system prompt for this
        // single request only.
        if (activeCommands.length > 0) {
            const additions = activeCommands.map(k => PROMPT_COMMANDS[k].addition).join('\n\n');
            sysPrompt = sysPrompt ? sysPrompt + '\n\n' + additions : additions;
            activeCommands = [];
            renderCommandPills();
        }
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

        // Only transmit the group when it changed – otherwise the server keeps
        // the group that is already stored for this chat session.
        if (loggedIn && intelligenceGroupEnabled && groupChanged) {
            payload.intelligence_group = activeGroup;
            groupChanged = false;
        }

        // Reasoning is off by default and only enabled for the single prompt
        // that was prefixed with "!!".
        const reasoningForThisPrompt = reasoningActive;
        if (reasoningForThisPrompt) payload.reasoning = true;
        reasoningActive = false;
        renderReasoningPill();

        try {
            let result = await executeStreamingRequest(payload, bubble);
            let accumulated = result.accumulated;
            let accumulatedThinking = result.accumulatedThinking;
            let responseDetails = result.responseDetails;
            let upgradeSuggestion = result.upgradeSuggestion;

            if (!accumulated) {
                // First retry: same model/endpoint
                setStatus('Leere Antwort – Wiederholungsversuch …', 'info');
                bubble.innerHTML = '';
                const retryResult = await executeStreamingRequest(payload, bubble);
                accumulated = retryResult.accumulated;
                accumulatedThinking = retryResult.accumulatedThinking;
                responseDetails = retryResult.responseDetails || responseDetails;
                upgradeSuggestion = retryResult.upgradeSuggestion || upgradeSuggestion;

                if (!accumulated && upgradeSuggestion?.available && upgradeSuggestion?.suggested_model) {
                    // Second retry: next smarter model (automatic)
                    setStatus(`Leere Antwort – Versuche mit ${upgradeSuggestion.suggested_intelligence || 'größerem'} Modell …`, 'info');
                    bubble.innerHTML = '';
                    const upgradePayload = { ...payload, model: upgradeSuggestion.suggested_model };
                    const upgradeResult = await executeStreamingRequest(upgradePayload, bubble);
                    accumulated = upgradeResult.accumulated;
                    accumulatedThinking = upgradeResult.accumulatedThinking;
                    responseDetails = upgradeResult.responseDetails || responseDetails;
                    upgradeSuggestion = null; // upgrade was already used automatically
                }
            }

            bubble.innerHTML = renderBubbleContent(accumulatedThinking, accumulated || '(Leere Antwort)');
            makeAnswerListsClickable(bubble);
            setResponseDetailsForBubble(bubble, responseDetails);
            bubble.classList.remove('streaming');

            // Store assistant reply in history.
            history.push({ role: 'assistant', content: accumulated });
            const assistantHistoryIndex = history.length - 1;
            showUpgradePrompt(upgradeSuggestion, messages, assistantHistoryIndex, responseDetails);
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

    userInput.addEventListener('input', () => {
        if (loggedIn && intelligenceGroupEnabled) applyGroupPrefixFromInput();
        applyReasoningPrefixFromInput();
        applyCommandPrefixFromInput();
        autoResizeTextarea(userInput);
    });

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
        // Restore the conversation tied to the current session ID (e.g. after
        // a page reload or a full-page navigation such as login) so a stale
        // sessionId never gets silently overwritten by a shorter, unrelated
        // history. This also restores the active intelligence group.
        restoreCurrentSession();
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

<?php if ($canUploadDocuments && $visionModelConfigured): ?>
<script>
/* ─────────────────────────────────────────────────────────────
   Document upload & notification panel
   ─────────────────────────────────────────────────────────────*/
(function () {
    'use strict';

    const CSRF          = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    // ── Notification panel ────────────────────────────────────
    const notifBtn      = document.getElementById('notif-btn');
    const notifPanel    = document.getElementById('notif-panel');
    const notifPanelClose = document.getElementById('notif-panel-close');
    const notifList     = document.getElementById('notif-list');
    const notifBadge    = document.getElementById('notif-badge');

    // ── Upload modal ──────────────────────────────────────────
    const uploadBtn     = document.getElementById('upload-btn');
    const uploadModal   = document.getElementById('upload-modal');
    const uploadClose   = document.getElementById('upload-close');
    const dropZone      = document.getElementById('upload-drop-zone');
    const fileInput     = document.getElementById('upload-file-input');
    const uploadPreview = document.getElementById('upload-preview');
    const uploadProgress = document.getElementById('upload-progress');
    const progressFill  = document.getElementById('upload-progress-fill');
    const uploadMsg     = document.getElementById('upload-msg');
    const submitBtn     = document.getElementById('upload-submit-btn');
    const globalRagCb   = document.getElementById('upload-global-rag');

    let selectedFile = null;
    let panelOpen = false;

    // Status icons
    function statusIcon(status) {
        if (status === 'done')       return '✅';
        if (status === 'error')      return '❌';
        if (status === 'processing') return '⏳';
        return '🕐'; // pending
    }

    function statusLabel(status) {
        if (status === 'done')       return 'Analysiert';
        if (status === 'error')      return 'Fehler';
        if (status === 'processing') return 'Wird analysiert …';
        return 'Wartend';
    }

    function formatDate(ts) {
        if (!ts) return '';
        try {
            const d = new Date(ts.replace(' ', 'T'));
            return d.toLocaleString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
        } catch (_) { return ts; }
    }

    function formatBytes(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return Math.round(b / 1024) + ' KB';
        return (b / 1024 / 1024).toFixed(1) + ' MB';
    }

    // Render notification list
    function renderUploads(uploads) {
        if (!notifList) return;
        if (!uploads || uploads.length === 0) {
            notifList.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:.82rem">Noch keine Dokumente hochgeladen.</div>';
            updateBadge(0, 0);
            return;
        }

        let pending = 0, errors = 0;
        const html = uploads.map(u => {
            if (u.status === 'pending' || u.status === 'processing') pending++;
            if (u.status === 'error') errors++;

            const icon = statusIcon(u.status);
            const label = statusLabel(u.status);
            const name = u.original_name || 'Unbekannt';
            const date = formatDate(u.uploaded_at);
            const size = formatBytes(parseInt(u.file_size) || 0);
            const globalScope = parseInt(u.is_global_rag, 10) === 1 ? 'Global' : 'Privat';

            let extra = '';
            if (u.status === 'error' && u.error_message) {
                extra = `<div class="notif-error">${escHtml(u.error_message)}</div>`;
            }

            return `<div class="notif-item">
                <span class="notif-status-icon">${icon}</span>
                <div class="notif-info">
                    <div class="notif-name" title="${escHtml(name)}">${escHtml(name)}</div>
                    <div class="notif-meta">${escHtml(label)} · ${escHtml(globalScope)} · ${escHtml(size)} · ${escHtml(date)}</div>
                    ${extra}
                </div>
            </div>`;
        }).join('');

        notifList.innerHTML = html;
        updateBadge(pending, errors);
    }

    function updateBadge(pending, errors) {
        if (!notifBadge) return;
        const total = pending + errors;
        if (total === 0) {
            notifBadge.style.display = 'none';
        } else {
            notifBadge.style.display = 'flex';
            notifBadge.textContent = total > 9 ? '9+' : String(total);
            notifBadge.className = 'badge' + (errors > 0 ? ' badge-error' : (pending > 0 ? ' badge-warn' : ''));
        }
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Load status from server
    async function loadStatus() {
        try {
            const res = await fetch('api/document_status.php');
            const data = await res.json();
            if (data.ok) {
                renderUploads(data.uploads);
                // Auto-refresh if any are pending
                const hasPending = (data.uploads || []).some(u => u.status === 'pending' || u.status === 'processing');
                if (hasPending) {
                    setTimeout(loadStatus, 5000);
                }
            }
        } catch (_) {}
    }

    // Initial load for badge
    loadStatus();

    // Toggle notification panel
    if (notifBtn) {
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (panelOpen) {
                closePanel();
            } else {
                openPanel();
            }
        });
    }

    function openPanel() {
        if (!notifPanel) return;
        notifPanel.classList.add('open');
        panelOpen = true;
        loadStatus();
    }

    function closePanel() {
        if (!notifPanel) return;
        notifPanel.classList.remove('open');
        panelOpen = false;
    }

    if (notifPanelClose) notifPanelClose.addEventListener('click', closePanel);

    document.addEventListener('click', function (e) {
        if (panelOpen && notifPanel && !notifPanel.contains(e.target) && e.target !== notifBtn) {
            closePanel();
        }
    });

    // ── Upload modal ──────────────────────────────────────────

    function openUploadModal() {
        if (!uploadModal) return;
        resetUpload();
        uploadModal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeUploadModal() {
        if (!uploadModal) return;
        uploadModal.classList.remove('open');
        document.body.style.overflow = '';
    }

    function resetUpload() {
        selectedFile = null;
        if (fileInput)     fileInput.value = '';
        if (uploadPreview) uploadPreview.textContent = '';
        if (uploadProgress) uploadProgress.style.display = 'none';
        if (progressFill)  progressFill.style.width = '0%';
        if (uploadMsg)   { uploadMsg.textContent = ''; uploadMsg.className = ''; }
        if (globalRagCb)   globalRagCb.checked = true;
        if (submitBtn)     submitBtn.disabled = true;
    }

    if (uploadBtn)  uploadBtn.addEventListener('click', openUploadModal);
    if (uploadClose) uploadClose.addEventListener('click', closeUploadModal);

    if (uploadModal) {
        uploadModal.addEventListener('click', function (e) {
            if (e.target === uploadModal) closeUploadModal();
        });
    }

    // Drop zone
    if (dropZone) {
        dropZone.addEventListener('click', function () {
            if (fileInput) fileInput.click();
        });

        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', function () {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const f = e.dataTransfer.files[0];
            if (f) setFile(f);
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (this.files[0]) setFile(this.files[0]);
        });
    }

    function setFile(f) {
        const allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif', 'application/pdf'];
        if (!allowed.includes(f.type)) {
            if (uploadPreview) {
                uploadPreview.style.color = 'var(--error)';
                uploadPreview.textContent = '✗ Nicht unterstütztes Format. Erlaubt: PNG, JPG, WEBP, GIF, PDF.';
            }
            if (submitBtn) submitBtn.disabled = true;
            return;
        }
        if (f.size > 20 * 1024 * 1024) {
            if (uploadPreview) {
                uploadPreview.style.color = 'var(--error)';
                uploadPreview.textContent = '✗ Datei zu groß (max. 20 MB).';
            }
            if (submitBtn) submitBtn.disabled = true;
            return;
        }
        selectedFile = f;
        if (uploadPreview) {
            uploadPreview.style.color = 'var(--text-muted)';
            uploadPreview.textContent = '📄 ' + f.name + ' (' + (f.size < 1024 * 1024 ? Math.round(f.size / 1024) + ' KB' : (f.size / 1024 / 1024).toFixed(1) + ' MB') + ')';
        }
        if (submitBtn) submitBtn.disabled = false;
        if (uploadMsg)   { uploadMsg.textContent = ''; uploadMsg.className = ''; }
        if (uploadProgress) uploadProgress.style.display = 'none';
    }

    // Submit upload
    if (submitBtn) {
        submitBtn.addEventListener('click', async function () {
            if (!selectedFile) return;

            submitBtn.disabled = true;
            if (uploadProgress) uploadProgress.style.display = 'block';
            if (progressFill)   progressFill.style.width = '20%';
            if (uploadMsg) { uploadMsg.textContent = 'Wird hochgeladen und analysiert …'; uploadMsg.className = ''; }

            const fd = new FormData();
            fd.append('file', selectedFile, selectedFile.name);
            fd.append('csrf_token', CSRF);
            fd.append('global_rag', globalRagCb && globalRagCb.checked ? '1' : '0');

            try {
                if (progressFill) progressFill.style.width = '50%';
                const res  = await fetch('api/upload_document.php', { method: 'POST', body: fd });
                if (progressFill) progressFill.style.width = '90%';
                const data = await res.json();
                if (progressFill) progressFill.style.width = '100%';

                if (data.ok) {
                    if (uploadMsg) { uploadMsg.textContent = '✓ ' + data.message; uploadMsg.className = 'ok'; }
                    loadStatus(); // refresh notification list
                    setTimeout(closeUploadModal, 1800);
                } else {
                    if (uploadMsg) { uploadMsg.textContent = '✗ ' + data.message; uploadMsg.className = 'error'; }
                    submitBtn.disabled = false;
                }
            } catch (e) {
                if (progressFill) progressFill.style.width = '100%';
                if (uploadMsg) { uploadMsg.textContent = '✗ Netzwerkfehler: ' + e.message; uploadMsg.className = 'error'; }
                submitBtn.disabled = false;
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php if ($loginBannerEnabled && $loginBannerText !== ''): ?>
<!-- ── Login banner overlay (shown once per browser session) ── -->
<div id="login-banner-overlay">
    <div id="login-banner-box">
        <div class="banner-content"><?= $loginBannerText /* HTML is intentional – admin-only setting */ ?></div>
        <button id="login-banner-ok">OK</button>
    </div>
</div>
<script>
(function () {
    const STORAGE_KEY = 'login_banner_shown';
    if (!sessionStorage.getItem(STORAGE_KEY)) {
        const overlay = document.getElementById('login-banner-overlay');
        if (overlay) {
            overlay.classList.add('open');
            document.getElementById('login-banner-ok').addEventListener('click', function () {
                overlay.classList.remove('open');
                sessionStorage.setItem(STORAGE_KEY, '1');
            });
        }
    }
})();
</script>
<?php endif; ?>

<!-- ── Info overlay (footer credit) ─────────────────────────── -->
<div id="info-overlay" role="dialog" aria-modal="true" aria-labelledby="footer-info-link">
    <div id="info-box">
        <div class="info-content">
            Ihr Chat-Assistent läuft lokal auf Ihrem Gerät, in unserem Netzwerk – das bedeutet: Ihre Daten bleiben bei uns!
            Wir teilen keine Informationen mit Dritten. Damit Sie immer die besten Antworten erhalten,
            greift LLMInt zusätzlich auf Wissen aus verschiedenen Suchmaschinenquellen zurück.
            Die Suchanfragen werden so weitergegeben, dass kein Rückschluss auf die Quelle der Anfrage
            möglich ist. Ein Teil der Software ist KI-gestützt entwickelt (Claude Opus 5, Fable 5;
            OpenAI GPT5.5, 5.6).
        </div>
        <button id="info-close">Schließen</button>
    </div>
</div>
<script>
(function () {
    const overlay = document.getElementById('info-overlay');
    const link    = document.getElementById('footer-info-link');
    const closeBtn= document.getElementById('info-close');
    if (!overlay || !link) return;

    function close() { overlay.classList.remove('open'); }

    link.addEventListener('click', function () { overlay.classList.add('open'); });
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) close();
    });
})();
</script>
</body>
</html>
