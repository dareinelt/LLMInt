<?php
declare(strict_types=1);

/**
 * tests/php/test_admin_payloads.php
 *
 * Guards the two places that assemble the statistics payload handed to
 * topology_build():
 *
 *   - admin/load_stats.php  (the 15 s poll, also serves admin/topology.php)
 *   - admin/index.php       (the server-rendered dashboard card)
 *
 * Both must expose the same sections. Forgetting one of them in a single place
 * is invisible in the unit tests - the graph simply renders that branch empty
 * until the next poll - but it does break the dashboard. This test reads the
 * array literals straight from the source and compares their top-level keys.
 *
 * Run from the repository root:  php tests/php/test_admin_payloads.php
 */

require __DIR__ . '/bootstrap.php';

const ADMIN_PAYLOAD_SECTIONS = [
    'endpoints',
    'searxng',
    'clients',
    'api',
    'totals',
    'image_endpoints',
    'image_status',
    'vector_store',
    'speech_endpoints',
    'speech_status',
];

/**
 * Collects the top-level keys of the array literal that starts at the first
 * "[" at or after $offset. Nested arrays are skipped, string literals and
 * escaped quotes are honoured.
 *
 * @return string[]
 */
function admin_payload_array_keys(string $source, int $offset): array
{
    $length = strlen($source);
    $depth  = 0;
    $keys   = [];
    $i      = $offset;

    while ($i < $length && $source[$i] !== '[') {
        $i++;
    }
    if ($i >= $length) {
        return [];
    }

    for (; $i < $length; $i++) {
        $char = $source[$i];

        if ($char === "'" || $char === '"') {
            $quote  = $char;
            $buffer = '';
            $i++;
            while ($i < $length && $source[$i] !== $quote) {
                if ($source[$i] === '\\') {
                    $i++;
                }
                $buffer .= $source[$i];
                $i++;
            }

            if ($depth === 1) {
                // Look ahead for "=>" to tell a key from a plain value. Both
                // files pad the gap before the arrow for alignment.
                if (preg_match('/\G\s*=>/', $source, $match, 0, $i + 1) === 1) {
                    $keys[] = $buffer;
                }
            }
            continue;
        }

        if ($char === '[' || $char === '(') {
            $depth++;
            continue;
        }
        if ($char === ']' || $char === ')') {
            $depth--;
            if ($depth <= 0) {
                break;
            }
            continue;
        }
    }

    return $keys;
}

/** Reads the payload array that admin/load_stats.php hands to topology_build(). */
function admin_load_stats_sections(): array
{
    $source = (string) file_get_contents(__DIR__ . '/../../admin/load_stats.php');
    $start  = strpos($source, '$payload = [');
    if ($start === false) {
        test_fail('admin/load_stats.php: kein "$payload = [" gefunden');
    }

    return admin_payload_array_keys($source, $start);
}

/** Reads the inline payload array of the dashboard card in admin/index.php. */
function admin_index_sections(): array
{
    $source = (string) file_get_contents(__DIR__ . '/../../admin/index.php');
    $start  = strpos($source, 'topology_build([');
    if ($start === false) {
        test_fail('admin/index.php: kein "topology_build([" gefunden');
    }

    return admin_payload_array_keys($source, $start);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test_case('admin/load_stats.php übergibt alle Abschnitte an topology_build()', function (): void {
    $sections = admin_load_stats_sections();
    foreach (ADMIN_PAYLOAD_SECTIONS as $section) {
        assert_true(in_array($section, $sections, true),
            'Abschnitt ' . $section . ' fehlt in admin/load_stats.php (gefunden: ' . implode(', ', $sections) . ')');
    }
});

test_case('admin/index.php übergibt alle Abschnitte an topology_build()', function (): void {
    $sections = admin_index_sections();
    foreach (ADMIN_PAYLOAD_SECTIONS as $section) {
        assert_true(in_array($section, $sections, true),
            'Abschnitt ' . $section . ' fehlt in admin/index.php (gefunden: ' . implode(', ', $sections) . ')');
    }
});

test_case('Poll und Dashboard-Karte liefern denselben Abschnittsatz', function (): void {
    // "ok" is a transport flag of the JSON poll and has no counterpart in the
    // server-rendered card; "ts" exists in both and stays part of the compare.
    $transport = ['ok'];

    $poll = array_values(array_diff(admin_load_stats_sections(), $transport));
    $card = array_values(array_diff(admin_index_sections(), $transport));

    sort($poll);
    sort($card);

    $missingInCard = array_values(array_diff($poll, $card));
    $missingInPoll = array_values(array_diff($card, $poll));

    assert_same([], $missingInCard, 'nur im Poll vorhanden');
    assert_same([], $missingInPoll, 'nur in der Dashboard-Karte vorhanden');
});

test_case('Der API-Abschnitt wird in beiden Aufbaustellen gefüllt', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../../admin/load_stats.php');
    assert_contains("'api'              => \$apiUsage", $source,
        'admin/load_stats.php muss den API-Abschnitt aus listApiKeyUsage() füllen');

    $card = (string) file_get_contents(__DIR__ . '/../../admin/index.php');
    assert_contains("'api'              => \$apiUsage", $card,
        'admin/index.php muss den API-Abschnitt aus listApiKeyUsage() füllen');
});

exit(test_summary('admin payloads'));
