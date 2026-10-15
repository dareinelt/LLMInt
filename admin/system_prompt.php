<?php

/**
 * admin/system_prompt.php
 *
 * Systemprompt - Globaler Systemprompt fuer alle Anfragen.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'system_prompt';
$adminPageTitle = 'Systemprompt';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Globaler Systemprompt
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-global-system-prompt-card">
       <details class="config-panel" id="config-global-system-prompt" open>
           <summary>🧠 Systemprompt</summary>

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_global_system_prompt">

               <div class="form-group">
                   <label for="global-system-prompt">Applikationsweiter Systemprompt</label>
                   <textarea id="global-system-prompt" name="global_system_prompt"
                             rows="16" style="width:100%;resize:vertical;font-family:monospace;font-size:.85rem"
                   ><?= htmlspecialchars($globalSystemPrompt) ?></textarea>
                   <p class="hint">
                       Dieser Systemprompt wird jeder Anfrage an das Sprachmodell serverseitig vorangestellt –
                       unabhängig vom gewählten Modell oder Endpunkt. Er ist für Benutzer nicht sichtbar, wird
                       niemals im Chatverlauf gespeichert und nicht in API-Antworten zurückgegeben.
                       Leer lassen, um den Standardtext zu verwenden.
                   </p>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>
       </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
