<?php

/**
 * admin/reranker.php
 *
 * Reranker - Reranker-Endpunkt und -Modell.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'reranker';
$adminPageTitle = 'Reranker';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('reranker-intro', 'Reranker',
    'Ein Reranker bewertet die fusionierten Suchergebnisse neu und gibt eine präzisere Rangfolge '
    . 'zurück. Kompatibel mit: Cohere Rerank, Jina Reranker, lokalen Rerank-APIs. Falls nicht '
    . 'konfiguriert oder nicht erreichbar, werden die RRF-Ergebnisse direkt verwendet.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Reranker Einstellungen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-reranker-card">
        <details class="config-panel" open>
            <summary>🏆 Reranker <?php admin_help_button('reranker-intro'); ?></summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_reranker_settings">

                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="reranker-enabled" name="reranker_enabled"
                           <?= $rerankerEnabled ? 'checked' : '' ?>>
                    <label for="reranker-enabled" style="margin:0;font-weight:600">Reranker aktivieren</label>
                </div>
                <div class="form-group">
                    <label for="reranker-endpoint">Reranker-Endpunkt <small style="color:var(--text-muted)">(POST /v1/rerank)</small></label>
                    <input type="url" id="reranker-endpoint" name="reranker_endpoint"
                           value="<?= htmlspecialchars($rerankerEndpoint) ?>"
                           placeholder="http://localhost:8080">
                </div>
                <div class="form-group">
                    <label for="reranker-model">Reranker-Modell</label>
                    <input type="text" id="reranker-model" name="reranker_model"
                           value="<?= htmlspecialchars($rerankerModel) ?>"
                           placeholder="rerank-multilingual-v3.0">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group">
                        <label for="reranker-timeout">Timeout (Sekunden)</label>
                        <input type="number" id="reranker-timeout" name="reranker_timeout"
                               value="<?= (int)$rerankerTimeout ?>" min="5" max="300">
                    </div>
                    <div class="form-group">
                        <label for="reranker-top-k">Top-K Ergebnisse</label>
                        <input type="number" id="reranker-top-k" name="reranker_top_k"
                               value="<?= (int)$rerankerTopK ?>" min="1" max="50">
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="btn">💾 Speichern</button>
                    <button type="button" class="btn btn-secondary" id="test-reranker-btn">🔌 Verbindung testen</button>
                </div>
                <div id="reranker-test-result" style="margin-top:10px;font-size:.85rem"></div>
            </form>
        </details>
    </div>

<script>
/* ── Reranker connection test ─────────────────────────────────────────────── */
(function () {
    'use strict';
    const btn = document.getElementById('test-reranker-btn');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        const endpointEl = document.getElementById('reranker-endpoint');
        const modelEl    = document.getElementById('reranker-model');
        const resultEl   = document.getElementById('reranker-test-result');

        const endpoint = endpointEl ? endpointEl.value.trim() : '';
        const model    = modelEl    ? modelEl.value.trim()    : '';

        if (!endpoint) {
            if (resultEl) { resultEl.textContent = '✗ Bitte zuerst eine Endpunkt-URL eingeben.'; resultEl.style.color = 'var(--error)'; }
            return;
        }

        btn.disabled = true;
        btn.textContent = '⟳ Teste …';
        if (resultEl) resultEl.textContent = '';

        try {
            const res = await fetch(endpoint.replace(/\/$/, '') + '/v1/rerank', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ model: model || 'test', query: 'test', documents: ['hello world'] }),
                signal: AbortSignal.timeout(10000),
            });
            if (resultEl) {
                if (res.ok || res.status === 422) {
                    resultEl.textContent = '✓ Reranker erreichbar (HTTP ' + res.status + ')';
                    resultEl.style.color = 'var(--success)';
                } else {
                    resultEl.textContent = '⚠ HTTP ' + res.status + ' – Endpunkt antwortet, aber mit Fehler.';
                    resultEl.style.color = 'var(--warning)';
                }
            }
        } catch (err) {
            if (resultEl) {
                resultEl.textContent = '✗ Nicht erreichbar: ' + err.message;
                resultEl.style.color = 'var(--error)';
            }
        } finally {
            btn.disabled = false;
            btn.textContent = '🔌 Verbindung testen';
        }
    });
}());
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
