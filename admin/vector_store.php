<?php

/**
 * admin/vector_store.php
 *
 * Wissensdatenbank - Vektorstore (lokal oder Milvus), Import und Status.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'vector_store';
$adminPageTitle = 'Wissensdatenbank';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('vector-store-overview', 'Wissensdatenbank (Vektordatenbank)',
    'Gemeinsames Wissen für alle Nutzer kommt ausschließlich aus einer Milvus-Vektordatenbank, die von '
    . '<a href="https://github.com/dareinelt/docvecwizard" target="_blank" rel="noopener">docvecwizard</a> '
    . 'befüllt wird. Nutzer-Uploads bleiben privat. Bei jeder Chat-Anfrage werden passende Abschnitte '
    . 'aus der aktiven Vektordatenbank abgerufen und dem Modell als Kontext mitgegeben.');

admin_help_text('vector-store-milvus-instance', 'Eigene Milvus-Instanz',
    'Der Dienst <code>milvus</code> aus <code>docker-compose.yml</code> ist unter <code>'
    . htmlspecialchars(milvusBaseUrl()) . '</code> erreichbar. Felder leer lassen, um die '
    . 'Compose-Standardwerte zu verwenden.');

admin_help_text('vector-store-import', 'docvecwizard-Export importieren',
    'Exportarchiv (<code>.tar.gz</code>, Format 1.x) aus docvecwizard hochladen. Chunk-Texte landen in '
    . 'MySQL, die Vektoren unverändert in der lokalen Milvus-Collection. Große Exporte können '
    . 'alternativ in den Ordner <code>vector_imports/</code> des Web-Containers gelegt und unten '
    . 'ausgewählt werden.');

admin_help_text('vector-store-embedding-model', 'Embedding-Modell',
    '⚠️ Für die Anfrage-Vektorisierung muss der Embedding-Endpunkt von LLMInt dasselbe Modell liefern, '
    . 'mit dem der Export in docvecwizard erzeugt wurde'
    . (empty($vectorLocalDocs['models'])
        ? '.'
        : ' (importiert: ' . htmlspecialchars(implode(', ', array_map(static fn(array $m): string => $m['embedding_model'] . ' / ' . $m['embedding_dimension'] . ' Dim.', $vectorLocalDocs['models']))) . ').'));

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Vektordatenbank (docvecwizard / Milvus)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-vector-store-card">
        <details class="config-panel" open>
            <summary>🧠 Wissensdatenbank (Vektordatenbank) <?php admin_help_button('vector-store-overview'); ?></summary>

            <form method="POST" id="vector-store-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_vector_store_settings">

                <div class="form-group">
                    <label style="font-weight:600">Modus</label>
                    <div class="vector-mode-grid">
                        <label class="vector-mode-option<?= $vectorStoreMode === 'off' ? ' active' : '' ?>">
                            <input type="radio" name="vector_store_mode" value="off" <?= $vectorStoreMode === 'off' ? 'checked' : '' ?>>
                            <span><strong>Aus</strong><small>Keine zentrale Wissensdatenbank</small></span>
                        </label>
                        <label class="vector-mode-option<?= $vectorStoreMode === 'remote' ? ' active' : '' ?>">
                            <input type="radio" name="vector_store_mode" value="remote" <?= $vectorStoreMode === 'remote' ? 'checked' : '' ?>>
                            <span><strong>docvecwizard-API</strong><small>Entfernte Instanz über REST abfragen (deren Milvus)</small></span>
                        </label>
                        <label class="vector-mode-option<?= $vectorStoreMode === 'local' ? ' active' : '' ?>">
                            <input type="radio" name="vector_store_mode" value="local" <?= $vectorStoreMode === 'local' ? 'checked' : '' ?>>
                            <span><strong>Eigenes Milvus</strong><small>docvecwizard-Export in die lokale Milvus-Instanz importieren</small></span>
                        </label>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group">
                        <label for="vector-top-k">Treffer pro Anfrage (Top-K)</label>
                        <input type="number" id="vector-top-k" name="vector_top_k" min="1" max="50" value="<?= (int) $vectorTopK ?>">
                    </div>
                    <div class="form-group">
                        <label for="vector-min-score">Mindest-Ähnlichkeit (0–1, 0 = aus)</label>
                        <input type="number" id="vector-min-score" name="vector_min_score" step="0.05" min="0" max="1" value="<?= htmlspecialchars($vectorMinScore) ?>">
                    </div>
                </div>

                <!-- ── Remote: docvecwizard API ── -->
                <fieldset class="vector-mode-fields" data-mode="remote" <?= $vectorStoreMode === 'remote' ? '' : 'hidden' ?>>
                    <legend>🌐 docvecwizard-API</legend>
                    <div class="form-group">
                        <label for="docvec-api-url">Basis-URL</label>
                        <input type="url" id="docvec-api-url" name="docvec_api_url" placeholder="https://docvec.example.org:8443"
                               value="<?= htmlspecialchars($docvecApiUrl) ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label for="docvec-api-username">Benutzername</label>
                            <input type="text" id="docvec-api-username" name="docvec_api_username" autocomplete="off"
                                   value="<?= htmlspecialchars($docvecApiUsername) ?>">
                        </div>
                        <div class="form-group">
                            <label for="docvec-api-password">Passwort <?php if ($docvecApiPassword !== ''): ?><small style="color:var(--text-muted)">(gespeichert – leer lassen zum Beibehalten)</small><?php endif; ?></label>
                            <input type="password" id="docvec-api-password" name="docvec_api_password" autocomplete="new-password" placeholder="<?= $docvecApiPassword !== '' ? '••••••••' : '' ?>">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:end">
                        <div class="form-group">
                            <label for="docvec-api-timeout">Timeout (Sekunden)</label>
                            <input type="number" id="docvec-api-timeout" name="docvec_api_timeout" min="3" max="300" value="<?= (int) $docvecApiTimeout ?>">
                        </div>
                        <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                            <input type="checkbox" id="docvec-api-verify-tls" name="docvec_api_verify_tls" <?= $docvecApiVerifyTls ? 'checked' : '' ?>>
                            <label for="docvec-api-verify-tls" style="margin:0">TLS-Zertifikat prüfen <small style="color:var(--text-muted)">(aus bei selbstsignierten Zertifikaten)</small></label>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <button type="button" class="btn btn-secondary" id="vector-test-remote-btn">🔌 Verbindung testen</button>
                        <span id="vector-test-remote-result" style="font-size:.85rem"></span>
                    </div>
                </fieldset>

                <!-- ── Local: own Milvus ── -->
                <fieldset class="vector-mode-fields" data-mode="local" <?= $vectorStoreMode === 'local' ? '' : 'hidden' ?>>
                    <legend>🗄️ Eigene Milvus-Instanz <?php admin_help_button('vector-store-milvus-instance'); ?></legend>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label for="milvus-url">Milvus-URL (REST)</label>
                            <input type="url" id="milvus-url" name="milvus_url" placeholder="http://milvus:19530"
                                   value="<?= htmlspecialchars($milvusUrlSetting) ?>">
                        </div>
                        <div class="form-group">
                            <label for="milvus-metrics-url">Health-URL</label>
                            <input type="url" id="milvus-metrics-url" name="milvus_metrics_url" placeholder="http://milvus:9091"
                                   value="<?= htmlspecialchars($milvusMetricsSetting) ?>">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label for="milvus-token">Token <small style="color:var(--text-muted)">(optional, user:pass)</small></label>
                            <input type="text" id="milvus-token" name="milvus_token" autocomplete="off" value="<?= htmlspecialchars($milvusToken) ?>">
                        </div>
                        <div class="form-group">
                            <label for="milvus-timeout">Timeout (Sekunden)</label>
                            <input type="number" id="milvus-timeout" name="milvus_timeout" min="3" max="600" value="<?= (int) $milvusTimeout ?>">
                        </div>
                        <div class="form-group">
                            <label for="milvus-collection">Collection <small style="color:var(--text-muted)">(leer = automatisch)</small><?php admin_help_button('vector-store-embedding-model'); ?></label>
                            <input type="text" id="milvus-collection" name="milvus_collection" placeholder="docvec_<?= htmlspecialchars($vectorLocalDocs['models'][0]['collection_name'] ?? 'modell') ?>"
                                   value="<?= htmlspecialchars($milvusCollection) ?>">
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <button type="button" class="btn btn-secondary" id="vector-test-local-btn">🔌 Verbindung testen</button>
                        <span id="vector-test-local-result" style="font-size:.85rem"></span>
                    </div>
                </fieldset>

                <button type="submit" class="btn" style="margin-top:12px">💾 Speichern</button>
            </form>

            <!-- ── Import of docvecwizard exports (local mode) ── -->
            <div id="vector-import-section" <?= $vectorStoreMode === 'local' ? '' : 'hidden' ?>>
                <hr style="border-color:var(--border);margin:24px 0">
                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">📦 docvecwizard-Export importieren <?php admin_help_button('vector-store-import'); ?></h3>
                <form id="vector-import-form" style="margin-top:12px">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                        <div class="form-group">
                            <label for="vector-import-file">Archiv hochladen</label>
                            <input type="file" id="vector-import-file" accept=".gz,.tgz,application/gzip,application/x-gzip">
                        </div>
                        <div class="form-group">
                            <label for="vector-import-server-file">… oder Datei aus <code>vector_imports/</code></label>
                            <select id="vector-import-server-file">
                                <option value="">– keine –</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="flex-direction:row;align-items:center;gap:16px;flex-wrap:wrap">
                        <label style="margin:0;display:flex;align-items:center;gap:6px"><input type="radio" name="vector_import_strategy" value="skip" checked> Vorhandene Dokumente überspringen</label>
                        <label style="margin:0;display:flex;align-items:center;gap:6px"><input type="radio" name="vector_import_strategy" value="overwrite"> Vorhandene Dokumente überschreiben</label>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <button type="submit" class="btn" id="vector-import-btn">📥 Importieren</button>
                        <span id="vector-import-result" style="font-size:.85rem"></span>
                    </div>
                    <div id="vector-import-progress" style="display:none;margin-top:10px;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                        <div id="vector-import-progress-fill" style="height:100%;width:0;background:var(--accent);transition:width .2s"></div>
                    </div>
                </form>

                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:16px">
                    <div class="stat-box">
                        <div class="stat-val stat-done"><?= number_format($vectorLocalDocs['documents']) ?></div>
                        <div class="stat-lbl">Dokumente (lokal)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-val stat-tokens"><?= number_format($vectorLocalDocs['chunks']) ?></div>
                        <div class="stat-lbl">Chunks (lokal)</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-val stat-tokens"><?= $vectorStatus['mode'] === 'local' && $vectorStatus['vectors'] !== null ? number_format((int) $vectorStatus['vectors']) : '–' ?></div>
                        <div class="stat-lbl">Vektoren in Milvus</div>
                    </div>
                </div>

                <?php if (!empty($vectorImports)): ?>
                <table style="margin-top:16px">
                    <thead><tr><th>#</th><th>Archiv</th><th>Status</th><th>Dok.</th><th>Chunks</th><th>Vektoren</th><th>Übersprungen</th><th>Zeitpunkt</th></tr></thead>
                    <tbody>
                    <?php foreach ($vectorImports as $imp): ?>
                        <tr title="<?= htmlspecialchars((string) ($imp['message'] ?? '')) ?>">
                            <td><?= (int) $imp['id'] ?></td>
                            <td><?= htmlspecialchars((string) $imp['filename']) ?></td>
                            <td><?= $imp['status'] === 'done' ? '✅' : ($imp['status'] === 'error' ? '❌' : '⏳') ?> <?= htmlspecialchars((string) $imp['status']) ?></td>
                            <td><?= (int) $imp['documents'] ?></td>
                            <td><?= number_format((int) $imp['chunks']) ?></td>
                            <td><?= number_format((int) $imp['vectors']) ?></td>
                            <td><?= (int) $imp['skipped'] ?></td>
                            <td><?= htmlspecialchars((string) $imp['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <form method="POST" style="margin-top:16px"
                      onsubmit="return confirm('Alle importierten Dokumente, Chunks und Milvus-Collections des lokalen Vektorspeichers löschen?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="vector_store_reset_local">
                    <button type="submit" class="btn btn-danger">🗑 Lokalen Vektorspeicher leeren</button>
                </form>
            </div>
        </details>
    </div>

<script>
(function () {
    'use strict';

    const form = document.getElementById('vector-store-form');
    if (!form) { return; }

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const modeRadios   = form.querySelectorAll('input[name="vector_store_mode"]');
    const modeFields   = form.querySelectorAll('.vector-mode-fields');
    const importSect   = document.getElementById('vector-import-section');

    function syncMode() {
        let mode = 'off';
        modeRadios.forEach(function (r) { if (r.checked) { mode = r.value; } });
        modeRadios.forEach(function (r) {
            r.closest('.vector-mode-option').classList.toggle('active', r.checked);
        });
        modeFields.forEach(function (fs) { fs.hidden = fs.getAttribute('data-mode') !== mode; });
        if (importSect) { importSect.hidden = mode !== 'local'; }
    }
    modeRadios.forEach(function (r) { r.addEventListener('change', syncMode); });
    syncMode();

    function val(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

    function bindTest(btnId, resultId, mode, collect) {
        const btn = document.getElementById(btnId);
        const out = document.getElementById(resultId);
        if (!btn || !out) { return; }
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            btn.textContent = '⟳ Teste …';
            out.textContent = '';
            try {
                const body = collect();
                body.mode = mode;
                const res  = await fetch('../api/test_vector_store.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });
                const data = await res.json();
                out.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                out.textContent = (data.ok ? '✓ ' : '✗ ') + (data.message || '');
            } catch (e) {
                out.style.color = 'var(--error)';
                out.textContent = '✗ Netzwerkfehler: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = '🔌 Verbindung testen';
            }
        });
    }

    bindTest('vector-test-remote-btn', 'vector-test-remote-result', 'remote', function () {
        const tls = document.getElementById('docvec-api-verify-tls');
        return {
            docvec_api_url:        val('docvec-api-url'),
            docvec_api_username:   val('docvec-api-username'),
            docvec_api_password:   (document.getElementById('docvec-api-password') || {}).value || '',
            docvec_api_timeout:    val('docvec-api-timeout'),
            docvec_api_verify_tls: (tls && tls.checked) ? '1' : '0',
        };
    });
    bindTest('vector-test-local-btn', 'vector-test-local-result', 'local', function () {
        return {
            milvus_url:         val('milvus-url'),
            milvus_metrics_url: val('milvus-metrics-url'),
            milvus_token:       val('milvus-token'),
            milvus_timeout:     val('milvus-timeout'),
            milvus_collection:  val('milvus-collection'),
        };
    });

    // ── Import ────────────────────────────────────────────────────────────
    const importForm   = document.getElementById('vector-import-form');
    const fileInput    = document.getElementById('vector-import-file');
    const serverSelect = document.getElementById('vector-import-server-file');
    const importBtn    = document.getElementById('vector-import-btn');
    const importOut    = document.getElementById('vector-import-result');
    const progress     = document.getElementById('vector-import-progress');
    const progressFill = document.getElementById('vector-import-progress-fill');
    if (!importForm) { return; }

    function formatBytes(b) {
        b = parseInt(b, 10) || 0;
        if (b < 1024 * 1024) { return Math.round(b / 1024) + ' KB'; }
        if (b < 1024 * 1024 * 1024) { return (b / 1024 / 1024).toFixed(1) + ' MB'; }
        return (b / 1024 / 1024 / 1024).toFixed(2) + ' GB';
    }

    async function loadServerFiles() {
        if (!serverSelect) { return; }
        try {
            const res  = await fetch('../api/vector_import.php');
            const data = await res.json();
            const files = (data && data.ok && Array.isArray(data.files)) ? data.files : [];
            serverSelect.innerHTML = '<option value="">– keine –</option>' + files.map(function (f) {
                const name = String(f.name).replace(/[<>&"]/g, function (c) {
                    return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c];
                });
                return '<option value="' + name + '">' + name + ' (' + formatBytes(f.size) + ')</option>';
            }).join('');
        } catch (_) { /* leave the default option */ }
    }
    loadServerFiles();

    function resetImportUi() {
        importBtn.disabled = false;
        importBtn.textContent = '📥 Importieren';
        if (progress) { progress.style.display = 'none'; }
    }

    importForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const file       = fileInput && fileInput.files && fileInput.files[0];
        const serverFile = serverSelect ? serverSelect.value : '';
        if (!file && !serverFile) {
            importOut.style.color = 'var(--error)';
            importOut.textContent = '✗ Bitte ein Archiv auswählen.';
            return;
        }
        const strategyEl = importForm.querySelector('input[name="vector_import_strategy"]:checked');

        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('strategy', strategyEl ? strategyEl.value : 'skip');
        if (file) { fd.append('archive', file, file.name); } else { fd.append('server_file', serverFile); }

        importBtn.disabled = true;
        importBtn.textContent = '⟳ Importiere …';
        importOut.style.color = 'var(--text-muted)';
        importOut.textContent = file ? 'Archiv wird hochgeladen …' : 'Archiv wird verarbeitet …';
        if (progress) { progress.style.display = 'block'; progressFill.style.width = '0%'; }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '../api/vector_import.php');
        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable || !progressFill) { return; }
            const pct = Math.round(ev.loaded / ev.total * 100);
            progressFill.style.width = pct + '%';
            if (pct >= 100) { importOut.textContent = 'Archiv wird verarbeitet (Prüfsummen, MySQL, Milvus) – bitte warten …'; }
        });
        xhr.addEventListener('load', function () {
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (_) { data = null; }
            if (data && data.ok) {
                importOut.style.color = 'var(--success)';
                importOut.textContent = '✓ ' + data.message;
                window.setTimeout(function () {
                    window.location.hash = '#config-vector-store-card';
                    window.location.reload();
                }, 1500);
            } else {
                importOut.style.color = 'var(--error)';
                importOut.textContent = '✗ ' + ((data && data.message) || ('Import fehlgeschlagen (HTTP ' + xhr.status + ').'));
                resetImportUi();
            }
        });
        xhr.addEventListener('error', function () {
            importOut.style.color = 'var(--error)';
            importOut.textContent = '✗ Netzwerkfehler beim Import.';
            resetImportUi();
        });
        xhr.send(fd);
    });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
