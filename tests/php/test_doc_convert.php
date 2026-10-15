<?php
declare(strict_types=1);

/**
 * tests/php/test_doc_convert.php
 *
 * Unit tests for the configuration and format helpers of api/doc_convert.php:
 * the MIME→extension map, the accepted/fallback extension lists and the
 * environment-driven base URL, token and timeout resolution.
 *
 * Deliberately not covered here (they need a database or the converter
 * container): docConvertEnabled(), docConvertHealth(), convertDocumentViaService()
 * and convertPlainTextLocally().
 *
 * Run from the repository root:  php tests/php/test_doc_convert.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../api/doc_convert.php';

/** Führt $fn mit gesetzter bzw. entfernter Umgebungsvariable aus. */
function withEnv(string $name, ?string $value, callable $fn)
{
    $previous = getenv($name);
    if ($value === null) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
    try {
        return $fn();
    } finally {
        if ($previous === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $previous);
        }
    }
}

// ── docConvertMimeMap() ───────────────────────────────────────────────────────

test_case('docConvertMimeMap() ordnet die Office-Formate zu', function (): void {
    $map = docConvertMimeMap();

    assert_same('docx', $map['application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    assert_same('xlsx', $map['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    assert_same('xlsm', $map['application/vnd.ms-excel.sheet.macroEnabled.12']);
    assert_same('xls', $map['application/vnd.ms-excel']);
    assert_same('pptx', $map['application/vnd.openxmlformats-officedocument.presentationml.presentation']);
});

test_case('docConvertMimeMap() ordnet OpenDocument- und RTF-Formate zu', function (): void {
    $map = docConvertMimeMap();

    assert_same('odt', $map['application/vnd.oasis.opendocument.text']);
    assert_same('ods', $map['application/vnd.oasis.opendocument.spreadsheet']);
    assert_same('odp', $map['application/vnd.oasis.opendocument.presentation']);
    assert_same('rtf', $map['application/rtf']);
    assert_same('rtf', $map['text/rtf']);
});

test_case('docConvertMimeMap() ordnet Text- und Datenformate zu', function (): void {
    $map = docConvertMimeMap();

    assert_same('txt', $map['text/plain']);
    assert_same('md', $map['text/markdown']);
    assert_same('csv', $map['text/csv']);
    assert_same('csv', $map['application/csv']);
    assert_same('tsv', $map['text/tab-separated-values']);
    assert_same('json', $map['application/json']);
    assert_same('json', $map['text/json']);
    assert_same('xml', $map['application/xml']);
    assert_same('xml', $map['text/xml']);
    assert_same('html', $map['text/html']);
    assert_same('yaml', $map['application/yaml']);
    assert_same('yaml', $map['text/yaml']);
    assert_same('yaml', $map['application/x-yaml']);
});

test_case('docConvertMimeMap() behandelt den generischen OLE-Typ als xls', function (): void {
    assert_same('xls', docConvertMimeMap()['application/vnd.ms-office']);
});

test_case('docConvertMimeMap() enthält nur kurze Endungen', function (): void {
    foreach (docConvertMimeMap() as $mime => $extension) {
        assert_true((bool) preg_match('/^[a-z0-9]+$/', $extension), 'Unerwartete Endung für ' . $mime . ': ' . $extension);
    }
});

// ── docConvertExtensions() / docConvertLocalFallbackExtensions() ──────────────

test_case('docConvertExtensions() listet jede Endung genau einmal', function (): void {
    $extensions = docConvertExtensions();

    assert_same([
        'docx', 'xlsx', 'xlsm', 'xls', 'pptx', 'odt', 'ods', 'odp',
        'rtf', 'txt', 'md', 'csv', 'tsv', 'json', 'xml', 'html', 'yaml',
    ], $extensions);
    assert_same($extensions, array_values(array_unique($extensions)), 'Keine Duplikate');
});

test_case('docConvertExtensions() deckt alle MIME-Zuordnungen ab', function (): void {
    $extensions = docConvertExtensions();

    foreach (docConvertMimeMap() as $extension) {
        assert_true(in_array($extension, $extensions, true), 'Fehlende Endung: ' . $extension);
    }
});

test_case('docConvertLocalFallbackExtensions() enthält nur lokal lesbare Textformate', function (): void {
    assert_same(['txt', 'md', 'csv', 'tsv', 'json', 'xml', 'html', 'yaml'], docConvertLocalFallbackExtensions());
});

test_case('docConvertLocalFallbackExtensions() ist eine Teilmenge der unterstützten Endungen', function (): void {
    $supported = docConvertExtensions();

    foreach (docConvertLocalFallbackExtensions() as $extension) {
        assert_true(in_array($extension, $supported, true), 'Nicht unterstützte Endung im Fallback: ' . $extension);
    }
});

// ── docConvertBaseUrl() ───────────────────────────────────────────────────────

test_case('docConvertBaseUrl() liest die Umgebungsvariable und entfernt Schrägstriche', function (): void {
    withEnv('DOCCONVERT_URL', 'http://docconvert:8000/', function (): void {
        assert_same('http://docconvert:8000', docConvertBaseUrl());
    });
    withEnv('DOCCONVERT_URL', '  http://docconvert:8000///  ', function (): void {
        assert_same('http://docconvert:8000', docConvertBaseUrl());
    });
});

// ── docConvertToken() ─────────────────────────────────────────────────────────

test_case('docConvertToken() liest die Umgebungsvariable und trimmt sie', function (): void {
    withEnv('DOCCONVERT_TOKEN', 'geheim', function (): void {
        assert_same('geheim', docConvertToken());
    });
    withEnv('DOCCONVERT_TOKEN', '  geheim  ', function (): void {
        assert_same('geheim', docConvertToken());
    });
});

// ── docConvertTimeout() ───────────────────────────────────────────────────────

test_case('docConvertTimeout() übernimmt einen gültigen Wert aus der Umgebung', function (): void {
    withEnv('DOCCONVERT_TIMEOUT', '30', function (): void {
        assert_same(30, docConvertTimeout());
    });
    withEnv('DOCCONVERT_TIMEOUT', '600', function (): void {
        assert_same(600, docConvertTimeout());
    });
});

test_case('docConvertTimeout() begrenzt auf 10 bis 600 Sekunden', function (): void {
    withEnv('DOCCONVERT_TIMEOUT', '5', function (): void {
        assert_same(10, docConvertTimeout(), 'Untergrenze');
    });
    withEnv('DOCCONVERT_TIMEOUT', '1', function (): void {
        assert_same(10, docConvertTimeout(), 'Untergrenze');
    });
    withEnv('DOCCONVERT_TIMEOUT', '9999', function (): void {
        assert_same(600, docConvertTimeout(), 'Obergrenze');
    });
});

exit(test_summary('test_doc_convert'));
