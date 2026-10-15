<?php

/**
 * admin/partials/help.php
 *
 * The "?" descriptions of the admin area.
 *
 * A page used to print the description of a field or of a section below the
 * control it belongs to. Those texts now live in one popup that the "?"
 * button next to the label or the section title opens; the popup shows only
 * the text of the button that was clicked.
 *
 * A page registers its texts at the top with admin_help_text() and places the
 * matching "?" with admin_help_button() in the markup. The popup itself is
 * rendered once per page by layout_foot.php, so no page has to carry its
 * markup. tests/php/test_admin_split.php keeps both sides together.
 */

/**
 * The descriptions registered for the current request, keyed by the id their
 * "?" button passes to admin_help_button().
 *
 * @return array<string, array{title: string, body: string}>
 */
function &admin_help_registry(): array
{
    static $texts = [];

    return $texts;
}

/**
 * Registers the description that the "?" of $id opens. $title heads the popup
 * and stays plain text: the popup writes it as text, and the button repeats it
 * in its aria-label. $body is the text of the popup and may use the same inline
 * markup as the page around it (<code>, <strong>, <em>).
 */
function admin_help_text(string $id, string $title, string $body): void
{
    $texts      = &admin_help_registry();
    $texts[$id] = ['title' => $title, 'body' => $body];
}

/** Renders the "?" of a described field or section. */
function admin_help_button(string $id): void
{
    $texts = admin_help_registry();
    $title = $texts[$id]['title'] ?? $id;

    printf(
        '<button type="button" class="field-help" data-help="%s" title="Beschreibung anzeigen"'
        . ' aria-label="Beschreibung zu %s anzeigen">?</button>',
        htmlspecialchars($id, ENT_QUOTES),
        htmlspecialchars($title, ENT_QUOTES)
    );
}

/** Renders the popup that every "?" button of the page opens. */
function admin_help_overlay(): void
{
    ?>
<div id="field-help-overlay" class="field-help-overlay" role="dialog" aria-modal="true"
     aria-labelledby="field-help-title">
    <div class="field-help-panel">
        <button type="button" id="field-help-close" class="field-help-close" aria-label="Schließen">✕</button>
        <h4 id="field-help-title" class="field-help-title"></h4>
        <div id="field-help-text" class="field-help-text"></div>
    </div>
</div>
<script>
/* Descriptions of this page, keyed by the "?" button that opens them. */
window.fieldHelpTexts = <?= json_encode(
    admin_help_registry(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

(function () {
    'use strict';

    const overlay = document.getElementById('field-help-overlay');
    const title   = document.getElementById('field-help-title');
    const text    = document.getElementById('field-help-text');
    const close   = document.getElementById('field-help-close');
    if (!overlay) { return; }

    /** Show the description of $id only, on top of everything else. */
    function openFieldHelp(id) {
        const entry = window.fieldHelpTexts[id];
        if (!entry) { return; }
        title.textContent     = entry.title;
        text.innerHTML        = entry.body;
        overlay.style.display = 'flex';
    }

    function closeFieldHelp() {
        overlay.style.display = 'none';
    }

    // One "?" per described field or section, each showing its own text.
    // preventDefault keeps the click from also activating the surrounding
    // <label>, <summary> or <a>.
    document.querySelectorAll('.field-help').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openFieldHelp(btn.getAttribute('data-help'));
        });
    });

    close.addEventListener('click', closeFieldHelp);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { closeFieldHelp(); }
    });

    // Capture on window: the popup lies above the overlays of the pages, so
    // Escape has to close it before a page's own Escape handler sees the key.
    window.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || overlay.style.display !== 'flex') { return; }
        closeFieldHelp();
        e.stopPropagation();
    }, true);

    window.openFieldHelp  = openFieldHelp;
    window.closeFieldHelp = closeFieldHelp;
}());
</script>
    <?php
}
