<?php

/**
 * admin/request_handling.php
 *
 * Anfragenhandling - Streaming, Intelligenz-Gruppe, Vision-Modell und Standardmodelle.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'request_handling';
$adminPageTitle = 'Anfragenhandling';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('guest-default-model', 'Standard-Modell / Modellgruppe für neue Anfragen',
    'Gilt für neue Anfragen nicht angemeldeter Benutzer im Chat. Zur Auswahl stehen aktive Modellgruppen '
    . 'aus dem Bereich <strong>Endpunkte</strong>.');

admin_help_text('new-user-default-model', 'Standard-Modell für neu registrierte Benutzer',
    'Dieses Modell wird neu registrierten Benutzern automatisch als persönliches Standard-Modell '
    . 'zugewiesen. Leer lassen, um das systemweite Standard-Modell zu verwenden.');

admin_help_text('streaming-enabled', 'Streaming der Antworten',
    'Wenn aktiviert, wird die Antwort im Chat live Wort für Wort angezeigt, sobald sie vom Modell '
    . 'erzeugt wird (der blinkende Cursor wird dabei fortlaufend durch den generierten Text ersetzt). '
    . 'Wenn deaktiviert, bleibt der blinkende Cursor sichtbar und die Antwort erscheint erst vollständig, '
    . 'sobald sie fertig generiert wurde.');

admin_help_text('intelligence-group-enabled', 'Direkte Modellwahl über Intelligenzgruppen',
    'Wenn aktiviert, können angemeldete Benutzer in der Chat-Eingabezeile mit dem Präfix '
    . '<code>@@35b</code> eine Intelligenzgruppe direkt ansprechen. Die Auswahl überschreibt '
    . 'Benutzer- und Standardmodelle sowie die regelbasierte Modellauswahl und bleibt im Chat aktiv. '
    . 'Wenn deaktiviert, wird das Präfix nicht ausgewertet und bleibt Teil der Nachricht.');

admin_help_text('vision-model', 'Vision-Modell für Dokument-Upload',
    'Dieses Vision-fähige Modell analysiert hochgeladene Bilder und PDF-Seiten und extrahiert deren '
    . 'Inhalt. Wird in jeder Anfrage als Datenquelle per Tool-Aufruf <code>query_documents</code> '
    . 'bereitgestellt. Leer lassen, um den Dokument-Upload zu deaktivieren.');

admin_help_text('pdf-vision-enabled', 'PDF-Seiten als Bilder per Vision-Modell auswerten',
    'Jede PDF-Seite wird mit <code>pdftoppm</code> in ein JPEG umgewandelt und einzeln vom '
    . 'Vision-Modell gelesen. Damit werden auch gescannte PDFs, Tabellen, Formulare und Diagramme '
    . 'erfasst. Schlägt die Analyse einer Seite fehl, wird auf deren Textebene zurückgegriffen; '
    . 'ist die Option deaktiviert, wird ausschließlich <code>pdftotext</code> verwendet.');

admin_help_text('pdf-vision-dpi', 'Render-Auflösung (DPI)',
    'Höhere Werte verbessern die Lesbarkeit kleiner Schrift, vergrößern aber Bilder und '
    . 'Verarbeitungszeit. Empfohlen: 150 DPI (72–300).');

admin_help_text('pdf-vision-max-pages', 'Maximal analysierte Seiten pro PDF',
    'Begrenzt die Laufzeit eines Uploads: Jede Seite ist eine eigene Modellanfrage. '
    . 'Überzählige Seiten werden übersprungen und im extrahierten Text vermerkt.');

?>
    <div class="card" id="config-request-handling-card">
       <details class="config-panel" id="config-request-handling" open>
           <summary>📨 Anfragenhandling</summary>

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_request_handling">

               <div class="form-group">
                   <label for="guest-default-model">Standard-Modell / Modellgruppe für neue Anfragen <?php admin_help_button('guest-default-model'); ?></label>
                   <select id="guest-default-model" name="guest_default_model">
                       <option value="" <?= $guestDefaultModel === '' ? 'selected' : '' ?>>
                           Automatisch: erstes aktives Modell verwenden
                       </option>
                       <?php foreach ($availableGuestModels as $model): ?>
                           <?php $intelligence = modelIntelligenceLabel($model); ?>
                           <option value="<?= htmlspecialchars($model) ?>"
                               <?= $guestDefaultModel === $model ? 'selected' : '' ?>>
                               <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                           </option>
                       <?php endforeach; ?>
                       <?php if ($guestDefaultModel !== '' && !isset($availableGuestModels[$guestDefaultModel])): ?>
                           <option value="<?= htmlspecialchars($guestDefaultModel) ?>" selected>
                               <?= htmlspecialchars($guestDefaultModel) ?> · derzeit nicht über aktive Endpunkte verfügbar
                           </option>
                       <?php endif; ?>
                   </select>
                   <?php if (empty($availableGuestModels)): ?>
                       <p class="hint" style="color:var(--warning)">
                           Aktuell sind keine aktiven Modellgruppen verfügbar. Bitte zuerst unter <strong>Endpunkte</strong> mindestens ein Standard-Modell konfigurieren.
                       </p>
                   <?php endif; ?>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>

           <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_new_user_model">

               <div class="form-group">
                   <label for="new-user-default-model">Standard-Modell für neu registrierte Benutzer <?php admin_help_button('new-user-default-model'); ?></label>
                   <select id="new-user-default-model" name="new_user_default_model">
                       <option value="" <?= $newUserDefaultModel === '' ? 'selected' : '' ?>>
                           Kein Standard-Modell (systemweites Standard-Modell verwenden)
                       </option>
                       <?php foreach ($availableGuestModels as $model): ?>
                           <?php $intelligence = modelIntelligenceLabel($model); ?>
                           <option value="<?= htmlspecialchars($model) ?>"
                               <?= $newUserDefaultModel === $model ? 'selected' : '' ?>>
                               <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                           </option>
                       <?php endforeach; ?>
                       <?php if ($newUserDefaultModel !== '' && !isset($availableGuestModels[$newUserDefaultModel])): ?>
                           <option value="<?= htmlspecialchars($newUserDefaultModel) ?>" selected>
                               <?= htmlspecialchars($newUserDefaultModel) ?> · derzeit nicht verfügbar
                           </option>
                       <?php endif; ?>
                   </select>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>

           <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_streaming_settings">

               <div class="form-group">
                   <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                       <input type="checkbox" name="streaming_enabled"
                              <?= $streamingEnabled ? 'checked' : '' ?>>
                       Streaming der Antworten aktivieren
                       <?php admin_help_button('streaming-enabled'); ?>
                   </label>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>

           <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_intelligence_group_settings">

               <div class="form-group">
                   <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                       <input type="checkbox" name="intelligence_group_enabled"
                              <?= $intelligenceGroupEnabled ? 'checked' : '' ?>>
                       Direkte Modellwahl über Intelligenzgruppen aktivieren
                       <?php admin_help_button('intelligence-group-enabled'); ?>
                   </label>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>

           <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_vision_settings">

               <div class="form-group">
                   <label for="vision-model">Vision-Modell für Dokument-Upload <?php admin_help_button('vision-model'); ?></label>
                   <select id="vision-model" name="vision_model">
                       <option value="" <?= $visionModel === '' ? 'selected' : '' ?>>
                           Kein Vision-Modell (Dokument-Upload deaktiviert)
                       </option>
                       <?php foreach ($availableGuestModels as $model): ?>
                           <?php $intelligence = modelIntelligenceLabel($model); ?>
                           <option value="<?= htmlspecialchars($model) ?>"
                               <?= $visionModel === $model ? 'selected' : '' ?>>
                               <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                           </option>
                       <?php endforeach; ?>
                       <?php if ($visionModel !== '' && !isset($availableGuestModels[$visionModel])): ?>
                           <option value="<?= htmlspecialchars($visionModel) ?>" selected>
                               <?= htmlspecialchars($visionModel) ?> · derzeit nicht verfügbar
                           </option>
                       <?php endif; ?>
                   </select>
               </div>

               <div class="form-group">
                   <label>
                       <input type="checkbox" name="pdf_vision_enabled" value="1"
                              <?= $pdfVisionEnabled ? 'checked' : '' ?>>
                       PDF-Seiten als Bilder per Vision-Modell auswerten
                       <?php admin_help_button('pdf-vision-enabled'); ?>
                   </label>
               </div>

               <div class="form-group">
                   <label for="pdf-vision-dpi">Render-Auflösung (DPI) <?php admin_help_button('pdf-vision-dpi'); ?></label>
                   <input type="number" id="pdf-vision-dpi" name="pdf_vision_dpi" min="72" max="300" step="1"
                          value="<?= (int) $pdfVisionDpi ?>">
               </div>

               <div class="form-group">
                   <label for="pdf-vision-max-pages">Maximal analysierte Seiten pro PDF <?php admin_help_button('pdf-vision-max-pages'); ?></label>
                   <input type="number" id="pdf-vision-max-pages" name="pdf_vision_max_pages" min="1" max="200" step="1"
                          value="<?= (int) $pdfVisionMaxPages ?>">
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>
       </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
