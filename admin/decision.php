<?php

/**
 * admin/decision.php
 *
 * Entscheidungsfindung - Kategorien der Entscheidungsfindung samt prompt.txt-Import.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'decision';
$adminPageTitle = 'Entscheidungsfindung';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Entscheidungsfindung – Kategorien & Definitionen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-decision-card">
        <details class="config-panel" id="config-decision" open>
        <summary>🗂️ Entscheidungsfindung</summary>

        <p class="hint" style="margin-bottom:18px">
            Verwalte die Kategorien, nach denen das <strong>Entscheidungsmodell</strong> Nutzer-Prompts klassifiziert.
            Jede Kategorie besitzt eine <em>Definition</em> (erklärt dem Modell, wann diese Kategorie zutrifft)
            und eine <em>Entscheidungsregel</em> (nummerierter Eintrag in der Prioritätsliste).
            Änderungen wirken sich sofort auf den erzeugten Klassifikations-Prompt aus.
        </p>

        <!-- Category list -->
        <?php if (empty($routingCategoriesData)): ?>
            <p class="hint" style="color:var(--warning)">Noch keine Kategorien vorhanden. Füge unten eine neue Kategorie hinzu.</p>
        <?php else: ?>
        <table class="data-table" id="rc-table" style="margin-bottom:24px">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Kategorie</th>
                    <th>Definition</th>
                    <th style="width:60px">Anz.</th>
                    <th style="width:110px">Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($routingCategoriesData as $rc): ?>
                <tr id="rc-row-<?= (int)$rc['id'] ?>">
                    <td style="text-align:center;color:var(--muted);font-size:.8rem"><?= (int)$rc['sort_order'] ?></td>
                    <td><strong><?= htmlspecialchars($rc['name']) ?></strong></td>
                    <td style="font-size:.85rem;color:var(--muted);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($rc['definition']) ?>">
                        <?= htmlspecialchars($rc['definition']) ?>
                    </td>
                    <td style="text-align:center;font-size:.8rem;color:var(--muted)"><?= (int)$rc['decision_priority'] ?></td>
                    <td>
                        <button type="button" class="btn" style="padding:4px 10px;font-size:.8rem"
                                onclick="rcEdit(<?= (int)$rc['id'] ?>,
                                    <?= htmlspecialchars(json_encode($rc['name']), ENT_QUOTES) ?>,
                                    <?= htmlspecialchars(json_encode($rc['definition']), ENT_QUOTES) ?>,
                                    <?= htmlspecialchars(json_encode($rc['decision_rule']), ENT_QUOTES) ?>,
                                    <?= (int)$rc['sort_order'] ?>,
                                    <?= (int)$rc['decision_priority'] ?>)">
                            ✏️ Bearbeiten
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Edit / Add form (hidden by default) -->
        <div id="rc-form-wrapper" style="display:none;background:var(--card-bg,#1e1e1e);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-bottom:24px">
            <h3 id="rc-form-title" style="margin:0 0 16px">Kategorie bearbeiten</h3>
            <form method="POST" id="rc-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" id="rc-action" value="update_routing_category">
                <input type="hidden" name="rc_id" id="rc-id" value="0">

                <div class="form-group">
                    <label for="rc-name">Kategoriename <small style="color:var(--muted)">(nur Buchstaben, Ziffern, Unterstriche)</small></label>
                    <input type="text" id="rc-name" name="rc_name" required
                           pattern="[A-Za-z0-9_]+"
                           placeholder="z.&thinsp;B. Programming"
                           style="max-width:320px">
                </div>

                <div class="form-group">
                    <label for="rc-definition">Definition</label>
                    <textarea id="rc-definition" name="rc_definition" rows="3"
                              placeholder="Beschreibe, wann diese Kategorie zutrifft …"
                              style="width:100%;max-width:700px;resize:vertical"></textarea>
                    <p class="hint">Erscheint als <code>* Kategorie: …</code> im Klassifikations-Prompt.</p>
                </div>

                <div class="form-group">
                    <label for="rc-decision-rule">Entscheidungsregel</label>
                    <textarea id="rc-decision-rule" name="rc_decision_rule" rows="2"
                              placeholder="z.&thinsp;B. Else if the primary task is programming, return Programming."
                              style="width:100%;max-width:700px;resize:vertical"></textarea>
                    <p class="hint">Vollständiger Regeltext inkl. „If / Else if / Otherwise" und „, return Kategorie."</p>
                </div>

                <div style="display:flex;gap:24px;flex-wrap:wrap">
                    <div class="form-group" style="flex:0 0 160px">
                        <label for="rc-sort-order">Anzeigereihenfolge</label>
                        <input type="number" id="rc-sort-order" name="rc_sort_order" min="0" max="999" value="0"
                               style="max-width:100px">
                        <p class="hint">Kleinere Zahl = weiter oben in der Kategorieliste.</p>
                    </div>
                    <div class="form-group" style="flex:0 0 160px">
                        <label for="rc-decision-priority">Entscheidungspriorität</label>
                        <input type="number" id="rc-decision-priority" name="rc_decision_priority" min="0" max="999" value="0"
                               style="max-width:100px">
                        <p class="hint">1 = höchste Priorität in den Entscheidungsregeln.</p>
                    </div>
                </div>

                <div class="action-row" style="margin-top:8px;gap:10px">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="rcCancel()">✕ Abbrechen</button>
                    <button type="button" id="rc-delete-btn" class="btn" style="display:none;margin-left:auto;color:var(--danger,#ef4444);border-color:var(--danger,#ef4444)"
                            onclick="rcDelete()">🗑 Kategorie löschen</button>
                </div>
            </form>
        </div>

        <!-- Prompt preview -->
        <details style="margin-bottom:16px">
            <summary style="cursor:pointer;font-size:.9rem;color:var(--muted);user-select:none">🔍 Erzeugten Klassifikations-Prompt anzeigen</summary>
            <pre id="rc-prompt-preview" style="margin-top:12px;padding:16px;background:var(--code-bg,#111);border:1px solid var(--border);border-radius:var(--radius);font-size:.78rem;white-space:pre-wrap;word-break:break-word;color:var(--text)"><?= htmlspecialchars(buildRoutingPrompt()) ?></pre>
        </details>

        <!-- Add new category button -->
        <div>
            <button type="button" class="btn btn-primary" onclick="rcAdd()">＋ Neue Kategorie hinzufügen</button>
        </div>

        <!-- Import from prompt.txt -->
        <hr style="margin:28px 0;border:none;border-top:1px solid var(--border)">
        <h3 style="margin:0 0 10px;font-size:1rem">📥 Import aus prompt.txt</h3>
        <p class="hint" style="margin-bottom:14px">
            Lade eine Textdatei hoch, die dem Schema der <code>lib/prompt.txt</code> entspricht.
            Die Datei wird analysiert und du erhältst eine Vorschau der erkannten Kategorien,
            bevor die Einstellungen gespeichert werden.
        </p>

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px">
            <label class="btn" style="cursor:pointer;margin:0" for="rc-import-file">
                📂 Datei auswählen …
            </label>
            <input type="file" id="rc-import-file" accept=".txt,text/plain"
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden"
                   onchange="rcImportPreview(this)">
            <span id="rc-import-filename" style="font-size:.85rem;color:var(--muted)">Keine Datei ausgewählt</span>
        </div>

        <!-- Preview panel (hidden until file is selected and parsed) -->
        <div id="rc-import-preview" style="display:none;margin-top:16px">
            <div style="background:rgba(239,68,68,.12);border:1px solid var(--danger,#ef4444);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:1.2rem;line-height:1.2">⚠️</span>
                <div>
                    <strong style="color:var(--danger,#ef4444)">Achtung:</strong>
                    Durch den Import werden <strong>alle vorhandenen Kategorien und Entscheidungsregeln unwiderruflich überschrieben</strong>.
                    Modellzuordnungen (Routing-Regeln) werden ebenfalls gelöscht.
                    Bitte prüfe die Vorschau sorgfältig.
                </div>
            </div>

            <p style="font-size:.9rem;font-weight:600;margin-bottom:8px">Erkannte Kategorien (<span id="rc-import-count">0</span>):</p>
            <div id="rc-import-error" style="display:none;color:var(--danger,#ef4444);margin-bottom:10px;font-size:.9rem"></div>

            <table class="data-table" id="rc-import-table" style="margin-bottom:20px;font-size:.85rem">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:130px">Kategorie</th>
                        <th>Definition</th>
                        <th style="width:60px">Anz.</th>
                        <th>Entscheidungsregel</th>
                    </tr>
                </thead>
                <tbody id="rc-import-tbody"></tbody>
            </table>

            <!-- Hidden form for confirmed import -->
            <form method="POST" id="rc-import-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="import_prompt_txt">
                <input type="hidden" name="prompt_categories" id="rc-import-json" value="">
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary" id="rc-import-confirm">✅ Import bestätigen</button>
                    <button type="button" class="btn" onclick="rcImportCancel()">✕ Abbrechen</button>
                </div>
            </form>
        </div>

        </details>
    </div>

<script>
/* ── Entscheidungsfindung: category CRUD ─────────────────────────────────── */
(function () {
    const wrapper   = document.getElementById('rc-form-wrapper');
    const title     = document.getElementById('rc-form-title');
    const actionIn  = document.getElementById('rc-action');
    const idIn      = document.getElementById('rc-id');
    const nameIn    = document.getElementById('rc-name');
    const defIn     = document.getElementById('rc-definition');
    const ruleIn    = document.getElementById('rc-decision-rule');
    const sortIn    = document.getElementById('rc-sort-order');
    const prioIn    = document.getElementById('rc-decision-priority');
    const delBtn    = document.getElementById('rc-delete-btn');

    window.rcEdit = function (id, name, definition, rule, sortOrder, priority) {
        title.textContent    = 'Kategorie bearbeiten';
        actionIn.value       = 'update_routing_category';
        idIn.value           = id;
        nameIn.value         = name;
        defIn.value          = definition;
        ruleIn.value         = rule;
        sortIn.value         = sortOrder;
        prioIn.value         = priority;
        delBtn.style.display = 'inline-flex';
        wrapper.style.display = 'block';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.rcAdd = function () {
        title.textContent    = 'Neue Kategorie hinzufügen';
        actionIn.value       = 'add_routing_category';
        idIn.value           = '0';
        nameIn.value         = '';
        defIn.value          = '';
        ruleIn.value         = '';
        sortIn.value         = '<?= !empty($routingCategoriesData) ? (max(array_column($routingCategoriesData, 'sort_order')) + 1) : 1 ?>';
        prioIn.value         = '<?= !empty($routingCategoriesData) ? (max(array_column($routingCategoriesData, 'decision_priority')) + 1) : 1 ?>';
        delBtn.style.display = 'none';
        wrapper.style.display = 'block';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
        nameIn.focus();
    };

    window.rcCancel = function () {
        wrapper.style.display = 'none';
    };

    window.rcDelete = function () {
        if (!confirm('Kategorie „' + nameIn.value + '" wirklich löschen?\nDie zugehörige Modellzuordnung wird ebenfalls entfernt.')) {
            return;
        }
        actionIn.value = 'delete_routing_category';
        document.getElementById('rc-form').submit();
    };
}());
</script>

<script>
/* ── Entscheidungsfindung: prompt.txt import ─────────────────────────────── */
(function () {

    /**
     * Parse the text content of a prompt.txt file into structured category objects.
     * Returns { ok: true, categories: [...] } or { ok: false, error: '...' }.
     */
    function parsePromptTxt(text) {
        const lines = text.split(/\r?\n/);
        let section = 'header';

        const categoryNames = [];   // ordered from "Classify…" section
        const definitions   = {};   // name → definition string
        const rules         = [];   // { priority, text }
        const validOutputs  = [];   // names from "Valid outputs only:" section

        for (const rawLine of lines) {
            const trimmed = rawLine.trim();

            // Section markers
            if (trimmed === 'Classify the user\'s input into exactly one of these categories:') {
                section = 'categories'; continue;
            }
            if (trimmed === 'Definitions:') { section = 'definitions'; continue; }
            if (/^Decision rules/i.test(trimmed))  { section = 'rules';        continue; }
            if (trimmed === 'Output rules:')        { section = 'output_rules'; continue; }
            if (trimmed === 'Valid outputs only:')  { section = 'valid_outputs'; continue; }
            if (trimmed === '') continue; // skip blank lines in any section

            if (section === 'categories') {
                if (/^[A-Za-z0-9_]+$/.test(trimmed)) categoryNames.push(trimmed);
            } else if (section === 'definitions') {
                const m = trimmed.match(/^\*\s+([A-Za-z0-9_]+):\s*(.*)$/);
                if (m) definitions[m[1]] = m[2];
            } else if (section === 'rules') {
                const m = trimmed.match(/^(\d+)\.\s+(.+)$/);
                if (m) rules.push({ priority: parseInt(m[1], 10), text: m[2] });
            } else if (section === 'valid_outputs') {
                if (/^[A-Za-z0-9_]+$/.test(trimmed)) validOutputs.push(trimmed);
            }
        }

        // Validation
        if (categoryNames.length === 0) {
            return { ok: false, error: 'Keine Kategorien in der Datei gefunden. Prüfe, ob der Abschnitt „Classify the user\'s input into exactly one of these categories:" vorhanden ist.' };
        }
        if (validOutputs.length === 0) {
            return { ok: false, error: 'Kein Abschnitt „Valid outputs only:" gefunden.' };
        }
        const catSet   = new Set(categoryNames);
        const validSet = new Set(validOutputs);
        const missing  = [...catSet].filter(n => !validSet.has(n));
        const extra    = [...validSet].filter(n => !catSet.has(n));
        if (missing.length || extra.length) {
            return { ok: false, error: 'Kategorien-Liste und „Valid outputs only:" stimmen nicht überein.' };
        }
        const noDef = categoryNames.filter(n => !definitions[n]);
        if (noDef.length) {
            return { ok: false, error: 'Fehlende Definitionen für: ' + noDef.join(', ') };
        }

        // Map each rule to a category via "return CategoryName" at the end
        const ruleByCategory = {};
        for (const rule of rules) {
            const m = rule.text.match(/return\s+([A-Za-z0-9_]+)\.?\s*$/);
            if (m && catSet.has(m[1])) {
                ruleByCategory[m[1]] = rule;
            }
        }

        const categories = categoryNames.map((name, idx) => ({
            name,
            definition:        definitions[name] || '',
            decision_rule:     ruleByCategory[name] ? ruleByCategory[name].text : '',
            sort_order:        idx + 1,
            decision_priority: ruleByCategory[name] ? ruleByCategory[name].priority : 0,
        }));

        return { ok: true, categories };
    }

    window.rcImportPreview = function (input) {
        const file = input.files[0];
        if (!file) return;

        document.getElementById('rc-import-filename').textContent = file.name;

        const reader = new FileReader();
        reader.onload = function (e) {
            const result = parsePromptTxt(e.target.result || '');
            const previewEl  = document.getElementById('rc-import-preview');
            const errorEl    = document.getElementById('rc-import-error');
            const countEl    = document.getElementById('rc-import-count');
            const tbody      = document.getElementById('rc-import-tbody');
            const jsonInput  = document.getElementById('rc-import-json');
            const confirmBtn = document.getElementById('rc-import-confirm');

            previewEl.style.display = 'block';

            if (!result.ok) {
                errorEl.textContent    = '⚠️ ' + result.error;
                errorEl.style.display  = 'block';
                tbody.innerHTML        = '';
                countEl.textContent    = '0';
                jsonInput.value        = '';
                confirmBtn.disabled    = true;
                return;
            }

            errorEl.style.display = 'none';
            confirmBtn.disabled   = false;
            countEl.textContent   = result.categories.length;
            jsonInput.value       = JSON.stringify(result.categories);

            tbody.innerHTML = result.categories.map(cat => {
                const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                return '<tr>' +
                    '<td style="text-align:center;color:var(--muted)">' + cat.sort_order + '</td>' +
                    '<td><strong>' + esc(cat.name) + '</strong></td>' +
                    '<td style="color:var(--muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(cat.definition) + '">' + esc(cat.definition) + '</td>' +
                    '<td style="text-align:center;color:var(--muted)">' + cat.decision_priority + '</td>' +
                    '<td style="font-size:.8rem;color:var(--muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(cat.decision_rule) + '">' + esc(cat.decision_rule || '–') + '</td>' +
                    '</tr>';
            }).join('');

            previewEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        reader.readAsText(file);
    };

    window.rcImportCancel = function () {
        document.getElementById('rc-import-preview').style.display = 'none';
        document.getElementById('rc-import-filename').textContent  = 'Keine Datei ausgewählt';
        document.getElementById('rc-import-file').value            = '';
        document.getElementById('rc-import-json').value            = '';
    };
}());
</script>

<?php require __DIR__ . '/partials/layout_foot.php';
