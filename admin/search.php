<?php

/**
 * admin/search.php
 *
 * Websuche - SearXNG-Basis-URL fuer die Web-Suche.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'search';
$adminPageTitle = 'Websuche';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <div class="card" id="config-searxng-card">
        <details class="config-panel" id="config-searxng" open>
            <summary>🔎 Websuche</summary>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_search_settings">

            <div class="form-group">
                <label for="searxng-base-url">SearXNG-URL</label>
                <input type="url" id="searxng-base-url" name="searxng_base_url"
                       placeholder="https://search.example.org"
                       value="<?= htmlspecialchars($searxngBaseUrl) ?>">
                <p class="hint">
                    Nur die Basis-URL angeben, <strong>ohne</strong> den Pfad <code>/search</code> – dieser wird automatisch ergänzt.<br>
                    Beispiele: <code>https://search.example.org</code> oder <code>http://192.168.1.10:8080</code><br>
                    Leer lassen, um die Websuche zu deaktivieren.
                </p>
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="searxng-test-btn" class="btn">🔌 Verbindung testen</button>
                <span id="searxng-test-result" style="font-size:.85rem"></span>
            </div>
            </form>
        </details>
    </div>

<script>
(function () {
    'use strict';

    const testBtn    = document.getElementById('searxng-test-btn');
    const testResult = document.getElementById('searxng-test-result');
    const urlInput   = document.getElementById('searxng-base-url');

    if (!testBtn) { return; }

    testBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Bitte zuerst eine URL eingeben.';
            return;
        }

        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Teste …';
        testResult.textContent = '';

        try {
            const res  = await fetch('../api/test_searxng.php?url=' + encodeURIComponent(url));
            const data = await res.json();
            if (data.ok) {
                testResult.style.color = 'var(--success)';
                testResult.textContent = '✓ ' + data.message;
            } else {
                testResult.style.color = 'var(--error)';
                testResult.textContent = '✗ ' + data.message;
            }
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Verbindung testen';
        }
    });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
