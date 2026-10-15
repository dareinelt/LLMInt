<?php

/**
 * admin/routing.php
 *
 * Modellrouting - Modellrouting-Einstellungen.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'routing';
$adminPageTitle = 'Modellrouting';

require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('routing-decision-flow', 'Entscheidungsmodell und Kategorie-Routing',
    'Das <strong>Entscheidungsmodell</strong> analysiert jeden Nutzer-Prompt und ordnet ihn einer '
    . 'Kategorie zu. Der Prompt wird mit den unter <strong>Entscheidungsfindung</strong> konfigurierten '
    . 'Kategorien als Systemnachricht an das Entscheidungsmodell gesendet. Anschließend wird der '
    . 'ursprüngliche Nutzer-Prompt an das Modell weitergeleitet, das der ermittelten Kategorie '
    . 'zugeordnet ist. Ist kein Entscheidungsmodell konfiguriert, wird das reguläre Modellrouting '
    . 'verwendet.');

admin_help_text('routing-decision-model', 'Entscheidungsmodell',
    'Dieses Modell klassifiziert den Nutzer-Prompt in eine der folgenden Kategorien. Es sollte ein '
    . 'schnelles, kompaktes Modell sein.');

admin_help_text('routing-category-assignment', 'Kategorie-Zuordnung',
    'Jeder Kategorie kann ein Zielmodell zugeordnet werden. Leer lassen, um auf das reguläre '
    . 'Standard-Modell zurückzufallen.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         Modellrouting
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-routing-card">
        <details class="config-panel" id="config-routing" open>
        <summary>🧭 Modellrouting <?php admin_help_button('routing-decision-flow'); ?></summary>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_routing_settings">

            <div class="form-group">
                <label for="routing-decision-model">Entscheidungsmodell <?php admin_help_button('routing-decision-model'); ?></label>
                <select id="routing-decision-model" name="routing_decision_model">
                    <option value="" <?= $routingDecisionModel === '' ? 'selected' : '' ?>>
                        Kein Entscheidungsmodell (regelbasiertes Routing deaktiviert)
                    </option>
                    <?php foreach ($availableGuestModels as $model): ?>
                        <?php $intelligence = modelIntelligenceLabel($model); ?>
                        <option value="<?= htmlspecialchars($model) ?>"
                            <?= $routingDecisionModel === $model ? 'selected' : '' ?>>
                            <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($routingDecisionModel !== '' && !isset($availableGuestModels[$routingDecisionModel])): ?>
                        <option value="<?= htmlspecialchars($routingDecisionModel) ?>" selected>
                            <?= htmlspecialchars($routingDecisionModel) ?> · derzeit nicht verfügbar
                        </option>
                    <?php endif; ?>
                </select>
            </div>

            <?php if (empty($routingCategories)): ?>
                <p class="hint" style="color:var(--warning)">
                    Keine Kategorien gefunden. Füge unter <strong>Entscheidungsfindung</strong> Kategorien hinzu.
                </p>
            <?php else: ?>
                <h3>Kategorie-Zuordnung <?php admin_help_button('routing-category-assignment'); ?></h3>
                <table class="data-table" style="margin-bottom:18px">
                    <thead>
                        <tr>
                            <th>Kategorie</th>
                            <th>Zielmodell (Knowhow)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routingCategories as $cat): ?>
                            <?php
                            $fieldName     = 'routing_model_' . preg_replace('/[^a-zA-Z0-9]/', '_', $cat);
                            $assignedModel = $routingRules[$cat] ?? '';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($cat) ?></strong>
                                </td>
                                <td>
                                    <select name="<?= htmlspecialchars($fieldName) ?>"
                                            style="width:100%;max-width:480px;padding:6px 10px;
                                                   background:var(--bg);border:1px solid var(--border);
                                                   border-radius:var(--radius);color:var(--text);
                                                   font-size:.85rem;font-family:var(--font)">
                                        <option value="" <?= $assignedModel === '' ? 'selected' : '' ?>>
                                            – Standard-Modell verwenden –
                                        </option>
                                        <?php foreach ($availableGuestModels as $model): ?>
                                            <?php $intelligence = modelIntelligenceLabel($model); ?>
                                            <option value="<?= htmlspecialchars($model) ?>"
                                                <?= $assignedModel === $model ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($assignedModel !== '' && !isset($availableGuestModels[$assignedModel])): ?>
                                            <option value="<?= htmlspecialchars($assignedModel) ?>" selected>
                                                <?= htmlspecialchars($assignedModel) ?> · derzeit nicht verfügbar
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="action-row">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
            </div>
        </form>
        </details>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
