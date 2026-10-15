<?php

/**
 * admin/rag_stats.php
 *
 * RAG-Statistik - Statistik der Embeddings und der Hybrid-Suche.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'rag_stats';
$adminPageTitle = 'RAG-Statistik';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         RAG-Statistik / Monitoring
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="embedding-stats-card">
        <details class="config-panel" open>
            <summary>📊 RAG-Statistik &amp; Monitoring</summary>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px">
                <div class="stat-box">
                    <span class="stat-label">Chunks gesamt</span>
                    <span class="stat-value"><?= number_format($embeddingStatsRow['total_chunks']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Mit Embedding</span>
                    <span class="stat-value" style="color:var(--success)"><?= number_format($embeddingStatsRow['chunks_with_embed']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ohne Embedding</span>
                    <span class="stat-value" style="color:<?= $embeddingStatsRow['chunks_missing'] > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>">
                        <?= number_format($embeddingStatsRow['chunks_missing']) ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Uploads ✓</span>
                    <span class="stat-value" style="color:var(--success)"><?= number_format($embeddingStatsRow['uploads_done']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ausstehend</span>
                    <span class="stat-value"><?= number_format($embeddingStatsRow['uploads_pending']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Fehler</span>
                    <span class="stat-value" style="color:<?= $embeddingStatsRow['uploads_error'] > 0 ? 'var(--error)' : 'var(--text-muted)' ?>">
                        <?= number_format($embeddingStatsRow['uploads_error']) ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Cache-Einträge</span>
                    <span class="stat-value"><?= number_format($embeddingStatsRow['cache_entries']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ø Query-Zeit (24h)</span>
                    <span class="stat-value">
                        <?= $embeddingStatsRow['avg_duration_ms'] !== null ? $embeddingStatsRow['avg_duration_ms'] . ' ms' : '–' ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ø Rerank-Zeit (24h)</span>
                    <span class="stat-value">
                        <?= $embeddingStatsRow['avg_rerank_ms'] !== null ? $embeddingStatsRow['avg_rerank_ms'] . ' ms' : '–' ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Fehler (24h)</span>
                    <span class="stat-value" style="color:<?= $embeddingStatsRow['error_count'] > 0 ? 'var(--error)' : 'var(--text-muted)' ?>">
                        <?= number_format($embeddingStatsRow['error_count']) ?>
                    </span>
                </div>
            </div>

            <p class="hint">
                Zeigt den Status der Hybrid-RAG-Pipeline. Embedding-Anfragen werden in
                <code>embedding_logs</code> protokolliert.
                Die Statistiken beziehen sich auf die letzten 24 Stunden.
            </p>
        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
