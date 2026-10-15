<?php
declare(strict_types=1);

/**
 * tests/php/test_admin_split.php
 *
 * Guards the split of the former admin one-pager (admin/index.php, 8156 lines)
 * into one page per entry of the left navigation.
 *
 * The navigation (admin/partials/nav.php) doubles as the page index: every
 * entry points at its own file, and every page pulls the shared bootstrap,
 * action dispatcher and page shell from admin/partials/. The tests below are
 * static - they read the markup and the scripts - and check the contracts the
 * split has to keep:
 *
 *   - navigation and page files stay in sync (no dead link, no orphan page),
 *   - every page keeps the bootstrap -> head -> body -> foot layout contract,
 *   - every card and every element id lives on exactly one page, so nothing
 *     was duplicated or lost while slicing,
 *   - every JavaScript hook (getElementById) and every label for= still finds
 *     its element on the same page - the classic breakage of such a split,
 *   - no anchor jumps to a card that now lives on a different page,
 *   - forms keep posting to the page they are rendered on, with an action
 *     value the shared dispatcher still knows,
 *   - no page redirects to another one: each page renders its own result,
 *   - the deep links the topology builds still hit a card that exists.
 *
 * Run from the repository root:  php tests/php/test_admin_split.php
 */

require __DIR__ . '/bootstrap.php';

/**
 * Navigation slug => [page file, card id] of every page of the split admin
 * area, in navigation order. The order is part of the contract: the sidebar
 * keeps the grouping and the sequence of the former one-pager.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function admin_split_pages(): array
{
    return [
        'dashboard'        => ['index.php', 'dashboard-card'],
        'usage'            => ['usage.php', 'usage-stats-card'],
        'smtp'             => ['smtp.php', 'config-smtp-card'],
        'ldap'             => ['ldap.php', 'config-ldap-card'],
        'speech'           => ['speech.php', 'config-speech-card'],
        'image'            => ['image.php', 'config-image-card'],
        'search'           => ['search.php', 'config-searxng-card'],
        'endpoints'        => ['endpoints.php', 'config-endpoints-card'],
        'request_handling' => ['request_handling.php', 'config-request-handling-card'],
        'system_prompt'    => ['system_prompt.php', 'config-global-system-prompt-card'],
        'balancer'         => ['balancer.php', 'config-balancer-card'],
        'routing'          => ['routing.php', 'config-routing-card'],
        'decision'         => ['decision.php', 'config-decision-card'],
        'vector_store'     => ['vector_store.php', 'config-vector-store-card'],
        'embedding'        => ['embedding.php', 'config-embedding-card'],
        'hybrid_search'    => ['hybrid_search.php', 'config-hybrid-search-card'],
        'reranker'         => ['reranker.php', 'config-reranker-card'],
        'rag_stats'        => ['rag_stats.php', 'embedding-stats-card'],
        'system_messages'  => ['system_messages.php', 'config-system-messages-card'],
        'log_config'       => ['log_config.php', 'log-config-card'],
        'log_viewer'       => ['log_viewer.php', 'log-viewer-card'],
        'users'            => ['users.php', 'users-card'],
        'openai_api'       => ['openai_api.php', 'openai-api-card'],
        'api_keys'         => ['api_keys.php', 'api-keys-card'],
        'password'         => ['password.php', 'password-card'],
    ];
}

function admin_split_root(): string
{
    return dirname(__DIR__, 2);
}

function admin_split_read(string $relative): string
{
    $path = admin_split_root() . '/' . $relative;
    if (!is_file($path)) {
        test_fail('fehlende Datei ' . $relative);
    }
    $source = file_get_contents($path);
    if ($source === false) {
        test_fail('unlesbare Datei ' . $relative);
    }

    // The admin pages are checked in with CRLF; the comparisons below work on
    // line feeds so that they do not depend on the checkout.
    return str_replace("\r\n", "\n", $source);
}

/**
 * Every source of the admin area (the pages and the shared partials), keyed by
 * the path relative to the repository root.
 *
 * @return array<string, string>
 */
function admin_split_sources(): array
{
    $sources = [];
    foreach ([admin_split_root() . '/admin/*.php', admin_split_root() . '/admin/partials/*.php'] as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                test_fail('unlesbare Datei ' . $path);
            }
            $relative = str_replace('\\', '/', substr($path, strlen(admin_split_root()) + 1));
            $sources[$relative] = str_replace("\r\n", "\n", $source);
        }
    }
    ksort($sources);

    return $sources;
}

/** Every id="..." of $source, in the order of appearance. */
function admin_split_ids(string $source): array
{
    preg_match_all('~(?<![-\w])id="([^"]+)"~', $source, $matches);

    return $matches[1];
}

/** Every value of a plain attribute of $source, e.g. 'for' or 'name'. */
function admin_split_attr(string $source, string $attribute): array
{
    preg_match_all('~(?<![-\w])' . preg_quote($attribute, '~') . '="([^"]*)"~', $source, $matches);

    return $matches[1];
}

/** Every id handed to getElementById() as a literal string. */
function admin_split_js_ids(string $source): array
{
    preg_match_all("~getElementById\\(\\s*(['\"])([^'\"]+)\\1\\s*\\)~", $source, $matches);

    return $matches[2];
}

/**
 * The sidebar of admin/partials/nav.php as an ordered list of
 * ['href' => ..., 'slug' => ...|null, 'label' => ...]. The PHP guard that adds
 * the active class is stripped before the link is parsed.
 *
 * @return list<array{href: string, slug: string|null, label: string}>
 */
function admin_split_nav_links(): array
{
    $nav   = admin_split_read('admin/partials/nav.php');
    $links = [];

    foreach (preg_split('~\R~', $nav) ?: [] as $line) {
        // The active class is added by a PHP guard between the href and the
        // closing bracket; replacing it with a space keeps the markup parseable.
        $plain = (string) preg_replace('~<\?=.*?\?>~', ' ', $line);
        if (preg_match('~<a href="([^"]+)"[^>]*>([^<]+)</a>~u', $plain, $match) !== 1) {
            continue;
        }
        $slug = preg_match('~\$active === \'([^\']+)\'~', $line, $guard) === 1 ? $guard[1] : null;

        $links[] = ['href' => $match[1], 'slug' => $slug, 'label' => trim($match[2])];
    }

    return $links;
}

/** Page file => card id of every page of the split admin area. */
function admin_split_cards(): array
{
    $cards = [];
    foreach (admin_split_pages() as $page) {
        $cards[$page[1]] = $page[0];
    }

    return $cards;
}

/** Navigation slug => page file of every page of the split admin area. */
function admin_split_files(): array
{
    $files = [];
    foreach (admin_split_pages() as $slug => $page) {
        $files[$slug] = $page[0];
    }

    return $files;
}

/** The ids of the shared page shell (layout_head.php / layout_foot.php). */
function admin_split_shell_ids(): array
{
    return array_merge(
        admin_split_ids(admin_split_read('admin/partials/layout_head.php')),
        admin_split_ids(admin_split_read('admin/partials/layout_foot.php'))
    );
}

/** The page body of $file, i.e. everything behind the shared page head. */
function admin_split_page_body(string $file): string
{
    return admin_split_read('admin/' . $file);
}

// ── Navigation and pages ──────────────────────────────────────────────────────

test_case('Die Seitenleiste verlinkt jede Seite als eigene Datei', function () {
    $hrefs    = array_column(admin_split_nav_links(), 'href');
    $expected = array_values(admin_split_files());

    // The technical endpoint page is a separate page of its own since before
    // the split; it stays the last entry of the sidebar.
    assert_same(array_merge($expected, ['endpoint_tech.php']), $hrefs, 'Reihenfolge der Seitenleiste');
});

test_case('Jedes Navigationsziel ist eine vorhandene Datei', function () {
    foreach (admin_split_nav_links() as $link) {
        assert_true(
            is_file(admin_split_root() . '/admin/' . $link['href']),
            'Navigationsziel ohne Datei: ' . $link['href']
        );
    }
});

test_case('Jeder Navigationseintrag hat eine sichtbare Beschriftung', function () {
    foreach (admin_split_nav_links() as $link) {
        assert_not_same('', $link['label'], 'Beschriftung von ' . $link['href']);
    }
});

test_case('Jede Seite markiert ihren eigenen Navigationseintrag', function () {
    $slugs = [];
    foreach (admin_split_nav_links() as $link) {
        if ($link['slug'] !== null) {
            $slugs[$link['href']] = $link['slug'];
        }
    }

    foreach (admin_split_pages() as $slug => $page) {
        assert_key($page[0], $slugs, 'Aktiv-Markierung für ' . $page[0]);
        assert_same($slug, $slugs[$page[0]], 'Aktiv-Slug von ' . $page[0]);
    }
});

test_case('Keine Seite benutzt das Layout, ohne in der Navigation zu stehen', function () {
    $found = [];
    foreach (admin_split_sources() as $name => $source) {
        if (strpos($name, 'admin/partials/') === 0) {
            continue;
        }
        if (strpos($source, 'partials/layout_head.php') !== false) {
            $found[] = basename($name);
        }
    }

    $expected = array_values(admin_split_files());
    sort($found);
    sort($expected);
    assert_same($expected, $found, 'Seiten mit dem gemeinsamen Seitenkopf');
});

// ── Layout contract of every page ─────────────────────────────────────────────

test_case('Jede Seite hält den Seitenvertrag ein', function () {
    $foot = "<?php require __DIR__ . '/partials/layout_foot.php';";

    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $source = admin_split_page_body($file);
        $name   = 'admin/' . $file;

        assert_true(str_starts_with($source, "<?php\n"), $name . ' beginnt mit dem PHP-Kopf');
        assert_same(
            1,
            preg_match('~^\$adminPage\s*=\s*' . preg_quote(var_export($slug, true), '~') . ';~m', $source),
            $name . ' setzt $adminPage = ' . $slug
        );
        assert_same(
            1,
            substr_count($source, "require __DIR__ . '/partials/bootstrap.php';"),
            $name . ' bindet das Bootstrap genau einmal ein'
        );
        assert_same(
            1,
            substr_count($source, "require __DIR__ . '/partials/layout_head.php';"),
            $name . ' bindet den Seitenkopf genau einmal ein'
        );
        assert_same(
            1,
            substr_count($source, $foot),
            $name . ' bindet den Seitenfuß genau einmal ein'
        );
        assert_true(
            strpos($source, 'partials/bootstrap.php') < strpos($source, 'partials/layout_head.php'),
            $name . ' bindet das Bootstrap vor dem Seitenkopf ein'
        );
        assert_true(str_ends_with(trim($source), $foot), $name . ' endet mit dem Seitenfuß');
    }
});

test_case('Jede Seite trägt einen eigenen Titel ohne Escape-Reste', function () {
    $titles = [];

    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $name   = 'admin/' . $file;
        assert_same(
            1,
            preg_match("~^\\\$adminPageTitle\\s*=\\s*'([^']+)';~mu", admin_split_page_body($file), $match),
            $name . ' setzt $adminPageTitle'
        );
        assert_not_same('', trim($match[1]), 'Titel von ' . $name);
        assert_not_contains('\\u{', $match[1], $name . ' enthält keinen unmaskierten Unicode-Escape');
        $titles[$file] = $match[1];
    }

    assert_count(25, array_unique($titles), 'Titel müssen eindeutig sein');
});

test_case('Die Seiten überlassen Sitzung, Anmeldung und Aktionen den Partials', function () {
    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $name   = 'admin/' . $file;
        $source = admin_split_page_body($file);

        assert_not_contains('session_start(', $source, $name . ' startet keine eigene Sitzung');
        assert_not_contains('requireAdminOrRedirect', $source, $name . ' prüft die Anmeldung nicht selbst');
        assert_not_contains('partials/actions.php', $source, $name . ' bindet den Dispatcher nicht selbst ein');
        assert_not_contains('partials/data.php', $source, $name . ' bindet die Datenabfrage nicht selbst ein');
        assert_not_contains("header('Location", $source, $name . ' leitet nicht auf eine andere Seite um');
        assert_not_contains('header("Location', $source, $name . ' leitet nicht auf eine andere Seite um');
    }
});

test_case('Das Bootstrap lädt Aktionen und Daten genau einmal', function () {
    $bootstrap = admin_split_read('admin/partials/bootstrap.php');

    assert_same(1, substr_count($bootstrap, "require_once __DIR__ . '/actions.php';"), 'actions.php');
    assert_same(1, substr_count($bootstrap, "require_once __DIR__ . '/data.php';"), 'data.php');
    assert_contains("requireAdminOrRedirect('login.php')", $bootstrap, 'das Bootstrap prüft die Anmeldung');
});

test_case('Das Layout rendert die Navigation aus nav.php', function () {
    assert_contains(
        'admin_nav($adminPage);',
        admin_split_read('admin/partials/layout_head.php'),
        'layout_head ruft admin_nav mit dem Aktiv-Slug auf'
    );
    assert_contains(
        'function admin_nav(string $active): void',
        admin_split_read('admin/partials/nav.php'),
        'nav.php deklariert admin_nav'
    );
});

// ── Cards and element ids ─────────────────────────────────────────────────────

test_case('Jede Seite enthält genau ihre eigene Karte', function () {
    $cards = admin_split_cards();

    foreach (admin_split_pages() as $slug => $page) {
        [$file, $card] = $page;
        $ids  = admin_split_ids(admin_split_page_body($file));
        $name = 'admin/' . $file;

        assert_same(1, count(array_keys($ids, $card, true)), $name . ' enthält ' . $card . ' genau einmal');
        foreach ($cards as $other => $otherFile) {
            if ($other === $card) {
                continue;
            }
            assert_same(0, count(array_keys($ids, $other, true)), $name . ' enthält nicht die Karte ' . $other);
        }
    }
});

test_case('Keine Karte des Adminbereichs existiert zweimal', function () {
    $cards  = admin_split_cards();
    $counts = [];

    foreach (admin_split_sources() as $name => $source) {
        foreach (admin_split_ids($source) as $id) {
            if (isset($cards[$id])) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
    }

    foreach ($cards as $card => $file) {
        assert_same(1, $counts[$card] ?? 0, $card . ' kommt im Adminbereich genau einmal vor (erwartet in ' . $file . ')');
    }
});

test_case('Jedes JavaScript-Handle findet sein Element auf derselben Seite', function () {
    $shell = admin_split_shell_ids();

    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $name   = 'admin/' . $file;
        $source = admin_split_page_body($file);
        $ids    = array_merge(admin_split_ids($source), $shell);

        foreach (admin_split_js_ids($source) as $id) {
            assert_true(
                in_array($id, $ids, true),
                $name . ": getElementById('" . $id . "') findet kein Element auf dieser Seite"
            );
        }
    }
});

test_case('Jedes label for= zeigt auf ein Feld derselben Seite', function () {
    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $name   = 'admin/' . $file;
        $source = admin_split_page_body($file);
        $ids    = admin_split_ids($source);

        foreach (admin_split_attr($source, 'for') as $target) {
            assert_true(
                in_array($target, $ids, true),
                $name . ': label for="' . $target . '" hat kein Feld auf dieser Seite'
            );
        }
    }
});

// ── Anchors and forms ─────────────────────────────────────────────────────────

test_case('Kein Ankersprung zeigt auf die Karte einer anderen Seite', function () {
    $cards = admin_split_cards();

    foreach (admin_split_pages() as $slug => $page) {
        [$file, $card] = $page;
        $source = admin_split_page_body($file);
        preg_match_all('~(?:href|action)="#([^"]+)"~', $source, $matches);

        foreach ($matches[1] as $fragment) {
            if (!isset($cards[$fragment])) {
                continue;
            }
            assert_same(
                $card,
                $fragment,
                'admin/' . $file . ' verweist per #' . $fragment . ' auf eine fremde Karte'
            );
        }
    }
});

test_case('Kein Verweis zeigt auf eine Karte, die index.php verlassen hat', function () {
    $cards = admin_split_cards();

    foreach (admin_split_sources() as $name => $source) {
        preg_match_all('~index\.php#([A-Za-z0-9_-]+)~', $source, $matches);

        foreach ($matches[1] as $fragment) {
            if (!isset($cards[$fragment])) {
                continue;
            }
            assert_same(
                'index.php',
                $cards[$fragment],
                $name . ' verweist auf index.php#' . $fragment . ', die Karte liegt aber in ' . $cards[$fragment]
            );
        }
    }
});

test_case('Jedes Formular sendet an seine eigene Seite', function () {
    $pages = array_flip(admin_split_files());

    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $name   = 'admin/' . $file;
        preg_match_all('~<form\b[^>]*>~', admin_split_page_body($file), $matches);

        foreach ($matches[0] as $form) {
            if (preg_match('~action="([^"]*)"~', $form, $action) !== 1) {
                continue; // no action: the form posts to its own page
            }
            $target = $action[1];
            if ($target === '' || $target[0] === '#' || strpos($target, '<?') !== false) {
                continue; // empty action, in-page anchor or a computed value
            }
            assert_true(!isset($pages[$target]), $name . ': Formular sendet an ' . $target . ' statt an sich selbst');
        }
    }
});

test_case('Jeder Formular-Action-Name ist im Dispatcher bekannt', function () {
    preg_match_all("~\\\$action === '([^']+)'~", admin_split_read('admin/partials/actions.php'), $matches);
    $known = $matches[1];
    assert_true(count($known) > 0, 'der Dispatcher kennt Aktionen');

    foreach (admin_split_pages() as $slug => $page) {
        [$file] = $page;
        $name   = 'admin/' . $file;
        preg_match_all('~name="action"[^>]*value="([^"]+)"~', admin_split_page_body($file), $found);

        foreach ($found[1] as $value) {
            if (strpos($value, '<?') !== false) {
                continue; // computed by PHP, e.g. add_/update_ depending on the state
            }
            assert_true(
                in_array($value, $known, true),
                $name . ': action=' . $value . ' hat keinen Zweig im Dispatcher'
            );
        }
    }
});

test_case('Jeder Topologie-Deeplink zeigt auf die Seite mit der Karte', function () {
    require_once admin_split_root() . '/lib/topology.php';
    $cards = admin_split_cards();

    preg_match_all("~topology_admin_link\\('([^']+)'\\)~", admin_split_read('lib/topology.php'), $matches);
    $anchors = array_values(array_unique($matches[1]));
    assert_true(count($anchors) > 0, 'lib/topology.php erzeugt Deeplinks in den Adminbereich');

    foreach ($anchors as $anchor) {
        assert_key($anchor, $cards, 'die Topologie verweist auf die unbekannte Karte #' . $anchor);
        $file = $cards[$anchor];
        assert_same(
            $file . '#' . $anchor,
            topology_admin_link($anchor),
            'die Topologie verweist fuer #' . $anchor . ' auf ' . topology_admin_link($anchor)
        );
        assert_contains(
            'id="' . $anchor . '"',
            admin_split_read('admin/' . $file),
            'admin/' . $file . ' enthaelt die Karte #' . $anchor
        );
    }

    // Anchors without a page of their own keep the dashboard page, which is
    // where the topology view itself is rendered.
    assert_same('index.php#nicht-vorhanden', topology_admin_link('nicht-vorhanden'));
});

exit(test_summary('Admin-Aufteilung'));
