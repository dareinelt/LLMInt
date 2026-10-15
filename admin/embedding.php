<?php

/**
 * admin/embedding.php
 *
 * Embedding-Endpunkte - Embedding-Endpunkte und Modellwahl.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'embedding';
$adminPageTitle = 'Embedding-Endpunkte';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('embedding-intro', 'Embedding-Endpunkte',
    'Konfiguriere OpenAI-kompatible Embedding-Server (z.B. LM Studio, Ollama, OpenAI). '
    . 'Embeddings werden für jeden Dokument-Chunk erzeugt und ermöglichen die semantische Suche.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Embedding-Endpunkte (Hybrid-RAG)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-embedding-card">
        <details class="config-panel" open>
            <summary>🧬 Embedding-Endpunkte <?php admin_help_button('embedding-intro'); ?></summary>

            <?php if (empty($embeddingEndpoints)): ?>
                <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:16px">
                    Noch keine Embedding-Endpunkte konfiguriert.
                </p>
            <?php else: ?>
                <table class="data-table" style="margin-bottom:20px">
                    <thead>
                        <tr>
                            <th>Alias</th>
                            <th>URL</th>
                            <th>Modell</th>
                            <th>Timeout</th>
                            <th>Aktiv</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($embeddingEndpoints as $embEp): ?>
                        <tr>
                            <td><?= htmlspecialchars($embEp['alias'] !== '' ? $embEp['alias'] : '–') ?></td>
                            <td style="font-size:.82rem;color:var(--text-muted)"><?= htmlspecialchars($embEp['base_url']) ?></td>
                            <td><?= htmlspecialchars($embEp['model']) ?></td>
                            <td><?= (int)$embEp['timeout'] ?>s</td>
                            <td><?= $embEp['is_active'] ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--text-muted)">–</span>' ?></td>
                            <td>
                                <a href="?edit_emb=<?= (int)$embEp['id'] ?>#config-embedding-card"
                                   style="font-size:.8rem;color:var(--accent)">Bearbeiten</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Add / Edit form -->
            <div class="ep-form-section">
                <h3><?= $editEmbEp !== null ? '✏️ Endpunkt bearbeiten' : '➕ Endpunkt hinzufügen' ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action"
                           value="<?= $editEmbEp !== null ? 'update_embedding_endpoint' : 'add_embedding_endpoint' ?>">
                    <?php if ($editEmbEp !== null): ?>
                        <input type="hidden" name="emb_id" value="<?= (int)$editEmbEp['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="emb-alias">Alias (optional)</label>
                        <input type="text" id="emb-alias" name="emb_alias"
                               value="<?= htmlspecialchars($editEmbEp['alias'] ?? '') ?>" placeholder="z.B. LM Studio Embed">
                    </div>
                    <div class="form-group">
                        <label for="emb-base-url">Basis-URL <small style="color:var(--text-muted)">(ohne /v1/embeddings)</small></label>
                        <input type="url" id="emb-base-url" name="emb_base_url" required
                               value="<?= htmlspecialchars($editEmbEp['base_url'] ?? '') ?>"
                               placeholder="http://localhost:1234">
                    </div>
                    <div class="form-group">
                        <label for="emb-model">Modell</label>
                        <input type="text" id="emb-model" name="emb_model" required
                               value="<?= htmlspecialchars($editEmbEp['model'] ?? '') ?>"
                               placeholder="text-embedding-nomic-embed-text-v1.5">
                    </div>
                    <div class="form-group">
                        <label for="emb-api-key">API-Key <small style="color:var(--text-muted)">(leer lassen wenn nicht benötigt)</small></label>
                        <input type="password" id="emb-api-key" name="emb_api_key"
                               placeholder="<?= $editEmbEp !== null ? '(leer lassen = unverändert)' : '' ?>">
                    </div>
                    <div class="form-group">
                        <label for="emb-timeout">Timeout (Sekunden)</label>
                        <input type="number" id="emb-timeout" name="emb_timeout"
                               value="<?= (int)($editEmbEp['timeout'] ?? 60) ?>" min="5" max="600">
                    </div>
                    <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                        <input type="checkbox" id="emb-active" name="emb_is_active"
                               <?= ($editEmbEp === null || $editEmbEp['is_active']) ? 'checked' : '' ?>>
                        <label for="emb-active" style="margin:0">Endpunkt aktiv</label>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn">
                            <?= $editEmbEp !== null ? '💾 Speichern' : '➕ Hinzufügen' ?>
                        </button>
                        <?php if ($editEmbEp !== null): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_embedding_endpoint">
                                <input type="hidden" name="emb_id" value="<?= (int)$editEmbEp['id'] ?>">
                                <button type="submit" class="btn btn-danger"
                                        onclick="return confirm('Endpunkt wirklich löschen?')">🗑 Löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
