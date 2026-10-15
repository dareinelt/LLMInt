<?php

/**
 * admin/log_config.php
 *
 * Protokollierung - Loglevel und Aufbewahrungsdauer.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'log_config';
$adminPageTitle = 'Protokollierung';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('log-level', 'Mindest-Log-Level',
    'Einträge unterhalb des gewählten Levels werden nicht gespeichert.');

admin_help_text('log-retention', 'Aufbewahrungsdauer (Tage)',
    'Log-Einträge, die älter als diese Anzahl von Tagen sind, werden automatisch gelöscht.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Logging – Configuration
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="log-config-card">
        <details class="config-panel" id="log-config" open>
            <summary>⚙️ Protokollierung – Konfiguration</summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     value="save_log_config">

                <div class="form-group">
                    <label for="log-level">Mindest-Log-Level <?php admin_help_button('log-level'); ?></label>
                    <select id="log-level" name="log_level" style="max-width:200px">
                        <option value="info"    <?= $logLevel === 'info'    ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $logLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="error"   <?= $logLevel === 'error'   ? 'selected' : '' ?>>Error</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="log-retention">Aufbewahrungsdauer (Tage) <?php admin_help_button('log-retention'); ?></label>
                    <input type="number" id="log-retention" name="log_retention_days"
                           min="1" max="3650"
                           value="<?= htmlspecialchars((string) $logRetentionDays) ?>"
                           style="max-width:120px">
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                </div>
            </form>
        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
