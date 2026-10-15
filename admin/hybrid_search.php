<?php

/**
 * admin/hybrid_search.php
 *
 * Hybrid-Suche - Gewichtung von BM25 und Embeddings.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'hybrid_search';
$adminPageTitle = 'Hybrid-Suche';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Hybrid-Suche Einstellungen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-hybrid-search-card">
        <details class="config-panel" open>
            <summary>🔀 Hybrid-Suche &amp; Embedding</summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_hybrid_search_settings">

                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="hybrid-enabled" name="hybrid_search_enabled"
                           <?= $hybridSearchEnabled ? 'checked' : '' ?>>
                    <label for="hybrid-enabled" style="margin:0;font-weight:600">Hybrid-Suche aktivieren (BM25 + Embedding)</label>
                </div>
                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="embedding-enabled" name="embedding_enabled"
                           <?= $embeddingEnabled ? 'checked' : '' ?>>
                    <label for="embedding-enabled" style="margin:0">Embeddings aktivieren</label>
                </div>

                <div class="form-group">
                    <label for="embedding-model">Standard-Embedding-Modell <small style="color:var(--text-muted)">(für Query-Embedding-Cache)</small></label>
                    <input type="text" id="embedding-model" name="embedding_model"
                           value="<?= htmlspecialchars($embeddingModel) ?>"
                           placeholder="text-embedding-nomic-embed-text-v1.5">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group">
                        <label for="bm25-weight">BM25-Gewichtung (0–1)</label>
                        <input type="number" id="bm25-weight" name="bm25_weight" step="0.05" min="0" max="1"
                               value="<?= htmlspecialchars($bm25Weight) ?>">
                    </div>
                    <div class="form-group">
                        <label for="embedding-weight">Embedding-Gewichtung (0–1)</label>
                        <input type="number" id="embedding-weight" name="embedding_weight" step="0.05" min="0" max="1"
                               value="<?= htmlspecialchars($embeddingWeight) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="embedding-timeout">Embedding-Timeout (Sekunden)</label>
                    <input type="number" id="embedding-timeout" name="embedding_timeout"
                           value="<?= (int)$embeddingTimeout ?>" min="5" max="600">
                </div>
                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="embedding-cache-enabled" name="embedding_cache_enabled"
                           <?= $embeddingCacheEnabled ? 'checked' : '' ?>>
                    <label for="embedding-cache-enabled" style="margin:0">Query-Embedding-Cache aktivieren (Datenbank)</label>
                </div>
                <div class="form-group">
                    <label for="cache-ttl">Cache-Lebensdauer (Tage)</label>
                    <input type="number" id="cache-ttl" name="embedding_cache_ttl_days"
                           value="<?= (int)$embeddingCacheTtlDays ?>" min="1" max="365">
                </div>

                <button type="submit" class="btn">💾 Speichern</button>
            </form>

            <!-- Embeddings neu berechnen -->
            <hr style="border-color:var(--border);margin:24px 0">
            <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">🔄 Embeddings neu berechnen</h3>
            <p class="hint">Berechnet fehlende oder veraltete Embeddings für alle Dokument-Chunks.
                Läuft im Hintergrund und kann mehrere Minuten dauern.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <button type="button" class="btn" id="rebuild-emb-btn">🔄 Neu berechnen (fehlende)</button>
                <button type="button" class="btn btn-secondary" id="rebuild-emb-all-btn">♻️ Alle neu berechnen</button>
            </div>
            <div id="rebuild-emb-result" style="margin-top:10px;font-size:.85rem;color:var(--text-muted)"></div>
        </details>
    </div>

<script>
/* ── Hybrid-RAG: Embedding rebuild buttons ───────────────────────────────── */
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken) ?>;

    async function runRebuild(forceAll) {
        const resultEl = document.getElementById('rebuild-emb-result');
        if (resultEl) resultEl.textContent = '⟳ Verarbeite …';

        const body = new URLSearchParams({ csrf_token: csrfToken, batch_size: '200' });
        if (forceAll) body.set('force_all', '1');

        try {
            const res  = await fetch('../api/rebuild_embeddings.php', { method: 'POST', body });
            const data = await res.json();

            if (resultEl) {
                resultEl.textContent = data.ok
                    ? '✓ ' + (data.message || 'Fertig.')
                    : '✗ ' + (data.message || 'Fehler.');
                resultEl.style.color = data.ok ? 'var(--success)' : 'var(--error)';
            }
        } catch (err) {
            if (resultEl) {
                resultEl.textContent = '✗ Netzwerkfehler: ' + err.message;
                resultEl.style.color = 'var(--error)';
            }
        }
    }

    const rebuildBtn    = document.getElementById('rebuild-emb-btn');
    const rebuildAllBtn = document.getElementById('rebuild-emb-all-btn');

    if (rebuildBtn)    rebuildBtn.addEventListener('click', () => runRebuild(false));
    if (rebuildAllBtn) rebuildAllBtn.addEventListener('click', () => {
        if (!confirm('Alle Embeddings neu berechnen? Dies kann je nach Datenmenge sehr lange dauern.')) return;
        runRebuild(true);
    });
}());
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
