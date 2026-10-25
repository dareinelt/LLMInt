<?php

/**
 * admin/openai_api.php
 *
 * OpenAI-API - Basis-URLs der OpenAI-kompatiblen API.
 *
 * One page of the split admin area; the left navigation
 * (admin/partials/nav.php) links here. Request handling and data loading
 * live in admin/partials/bootstrap.php.
 */

$adminPage      = 'openai_api';
$adminPageTitle = 'OpenAI-API';

require __DIR__ . '/partials/bootstrap.php';

// Request handling and data loading live in admin/partials/bootstrap.php; a POST
// has already been applied by the time this body runs.
$apiAllowAnonymous   = getSetting('openai_api_allow_anonymous', '1') === '1';
$toolsAllowAnonymous = getSetting('openai_tools_allow_anonymous', '0') === '1';

require __DIR__ . '/partials/layout_head.php';

/**
 * Descriptions behind the "?" buttons of this page; the popup itself comes
 * from admin/partials/help.php.
 */
admin_help_text('openai-api-intro', 'OpenAI-kompatible API',
    'Externe Applikationen greifen über diese Basis-URLs auf die KI zu. API-Zugriffe verhalten sich '
    . 'wie ein nicht angemeldeter Benutzer: Sie verwenden das Gast-Standardmodell '
    . '(<code>' . htmlspecialchars(getGuestDefaultModel() !== '' ? getGuestDefaultModel() : '– nicht konfiguriert –') . '</code>), '
    . 'durchlaufen Routing und Lastverteilung wie ein direkter Zugriff und werden im Log mit '
    . '<code>API</code> gekennzeichnet. Ein <a href="api_keys.php">API-Key</a> ist für die URL '
    . '<em>ohne Tools</em> optional. Er dient der Zuordnung im Log und kann optional ein festes Modell '
    . 'festlegen; ohne Modell gilt das Standardmodell. '
    . 'Die URL <em>mit Tools</em> erreicht Websuche, Dokumente und Bilderzeugung und verlangt deshalb '
    . 'standardmäßig einen gültigen API-Key. '
    . 'Über die Einstellung <code>openai_api_allow_anonymous</code> (<code>0</code>) lässt sich ein '
    . 'API-Key für <em>alle</em> Aufrufe erzwingen, über <code>openai_tools_allow_anonymous</code> '
    . '(<code>1</code>) die Key-Pflicht für die Tool-URL wieder abschalten.');

?>
    <!-- ═══════════════════════════════════════════════════════════════════════
         OpenAI-compatible API (links for external applications)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="openai-api-card">
        <h2>🔌 OpenAI-kompatible API <?php admin_help_button('openai-api-intro'); ?></h2>
        <?php foreach ([
            'openai-api-url'       => ['Ohne Tools', openaiPublicBaseUrl(false)],
            'openai-tools-api-url' => ['Mit Tools (Websuche, Dokumente, Bilder)', openaiPublicBaseUrl(true)],
        ] as $apiUrlId => [$apiUrlLabel, $apiUrlValue]): ?>
            <div class="form-group" style="max-width:740px">
                <label for="<?= $apiUrlId ?>"><?= htmlspecialchars($apiUrlLabel) ?></label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="<?= $apiUrlId ?>" value="<?= htmlspecialchars($apiUrlValue) ?>" readonly
                           onfocus="this.select()" style="flex:1;font-family:monospace">
                    <button type="button" class="btn" data-copy-target="<?= $apiUrlId ?>">📋 Kopieren</button>
                </div>
            </div>
        <?php endforeach; ?>
        <script>
        document.querySelectorAll('#openai-api-card [data-copy-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-copy-target'));
                var done = function () {
                    var old = btn.textContent;
                    btn.textContent = '✓ Kopiert';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(done, function () {
                        input.select(); document.execCommand('copy'); done();
                    });
                } else {
                    input.select(); document.execCommand('copy'); done();
                }
            });
        });
        </script>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         API access control
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="openai-api-auth-card">
        <h2>🔑 Zugriffsschutz</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_openai_api_settings">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="openai_tools_allow_anonymous"
                       id="openaiToolsAllowAnonymous" <?= $toolsAllowAnonymous ? 'checked' : '' ?>>
                <label class="form-check-label" for="openaiToolsAllowAnonymous">
                    Tool-URL (<code>/api/openai-tools/v1</code>) auch ohne API-Key zulassen
                </label>
                <div class="form-text">
                    Nicht empfohlen: Aufrufe der Tool-URL erreichen Websuche, Dokumente und Bilderzeugung.
                    Ohne Key kann jeder im Netz LLMInt als offenen Proxy nutzen.
                </div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="openai_api_allow_anonymous"
                       id="openaiApiAllowAnonymous" <?= $apiAllowAnonymous ? 'checked' : '' ?>>
                <label class="form-check-label" for="openaiApiAllowAnonymous">
                    API-Aufrufe ganz ohne API-Key zulassen
                </label>
                <div class="form-text">
                    Abwählen, um für <em>alle</em> API-Aufrufe einen gültigen Key zu verlangen.
                    Viele OpenAI-Clients senden einen Platzhalter-Key; dieser gilt als nicht angemeldet.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">💾 Speichern</button>
        </form>
    </div>

<?php require __DIR__ . '/partials/layout_foot.php';
