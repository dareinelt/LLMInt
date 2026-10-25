<?php
declare(strict_types=1);

/**
 * tests/php/test_vector_helpers.php
 *
 * Unit tests for the configuration-independent helpers of api/vector_store.php:
 * the settings override layer, mode/label/URL resolution, the collection name
 * for an embedding model and the two formatters that turn search hits into LLM
 * context or tool results.
 *
 * The override layer (vectorSettingOverrides()) lets the tests exercise the
 * configuration logic without a database. Not covered here (they need a
 * database, Milvus or the docvecwizard API): all HTTP functions, the search
 * paths, vectorStoreStatus() and the query statistics.
 *
 * Run from the repository root:  php tests/php/test_vector_helpers.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../api/vector_store.php';

/** Führt $fn mit gesetzten Settings-Overrides aus und räumt danach auf. */
function withVectorSettings(array $overrides, callable $fn)
{
    $previous = vectorSettingOverrides();
    vectorSettingOverrides($overrides);
    try {
        return $fn();
    } finally {
        vectorSettingOverrides($previous);
    }
}

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

/** Ein normalisierter Suchtreffer, wie ihn vectorStoreSearch() liefert. */
function vectorHit(array $overrides = []): array
{
    return array_merge([
        'filename'    => 'handbuch.pdf',
        'page_start'  => 3,
        'page_end'    => 3,
        'chunk_index' => 0,
        'score'       => 0.5,
        'text'        => 'Inhalt des Abschnitts.',
    ], $overrides);
}

// ── vectorSettingOverrides() / vectorSetting() ────────────────────────────────

test_case('vectorSettingOverrides() speichert und ersetzt Overrides', function (): void {
    vectorSettingOverrides(['vector_store_mode' => 'local']);
    try {
        assert_same(['vector_store_mode' => 'local'], vectorSettingOverrides());
        vectorSettingOverrides(['vector_store_mode' => 'remote', 'vector_top_k' => '7']);
        assert_count(2, vectorSettingOverrides());
        assert_same('remote', vectorSettingOverrides()['vector_store_mode']);
    } finally {
        vectorSettingOverrides([]);
    }
    assert_same([], vectorSettingOverrides(), 'Leeres Array setzt alle Overrides zurück');
});

test_case('vectorSetting() liest den Override statt der Einstellung', function (): void {
    withVectorSettings(['vector_store_mode' => 'local'], function (): void {
        assert_same('local', vectorSetting('vector_store_mode', 'off'));
        assert_same('fallback', vectorSetting('unbekannt', 'fallback'), 'Ohne Override greift der Default');
    });
});

// ── vectorStoreMode() / vectorStoreEnabled() ──────────────────────────────────

test_case('vectorStoreMode() akzeptiert nur local, remote und off', function (): void {
    withVectorSettings(['vector_store_mode' => 'local'], function (): void {
        assert_same('local', vectorStoreMode());
        assert_true(vectorStoreEnabled());
    });
    withVectorSettings(['vector_store_mode' => 'remote'], function (): void {
        assert_same('remote', vectorStoreMode());
        assert_true(vectorStoreEnabled());
    });
    withVectorSettings(['vector_store_mode' => 'off'], function (): void {
        assert_same('off', vectorStoreMode());
        assert_false(vectorStoreEnabled());
    });
});

test_case('vectorStoreMode() fällt bei unbekannten Werten auf off zurück', function (): void {
    foreach (['milvus', 'LOCAL', '', '  ', 'on'] as $value) {
        withVectorSettings(['vector_store_mode' => $value], function () use ($value): void {
            assert_same('off', vectorStoreMode(), 'Wert: "' . $value . '"');
        });
    }
});

// ── vectorStoreTopK() ─────────────────────────────────────────────────────────

test_case('vectorStoreTopK() begrenzt auf 1 bis 50', function (): void {
    withVectorSettings(['vector_top_k' => '12'], function (): void {
        assert_same(12, vectorStoreTopK());
    });
    withVectorSettings(['vector_top_k' => '0'], function (): void {
        assert_same(1, vectorStoreTopK(), 'Untergrenze');
    });
    withVectorSettings(['vector_top_k' => '-5'], function (): void {
        assert_same(1, vectorStoreTopK(), 'Untergrenze');
    });
    withVectorSettings(['vector_top_k' => '999'], function (): void {
        assert_same(50, vectorStoreTopK(), 'Obergrenze');
    });
    withVectorSettings(['vector_top_k' => 'keine Zahl'], function (): void {
        assert_same(1, vectorStoreTopK(), 'Nicht-numerische Werte werden zu 0 und dann angehoben');
    });
});

// ── vectorStoreLabel() ────────────────────────────────────────────────────────

test_case('vectorStoreLabel() benennt die Betriebsarten', function (): void {
    assert_same('docvecwizard API', vectorStoreLabel('remote'));
    assert_same('Milvus (lokal)', vectorStoreLabel('local'));
    assert_same('Vektordatenbank', vectorStoreLabel('off'));
    assert_same('Vektordatenbank', vectorStoreLabel('unbekannt'), 'Unbekannte Modi erhalten den Neutraltext');
});

test_case('vectorStoreLabel() nutzt ohne Argument den aktiven Modus', function (): void {
    withVectorSettings(['vector_store_mode' => 'local'], function (): void {
        assert_same('Milvus (lokal)', vectorStoreLabel());
    });
    withVectorSettings(['vector_store_mode' => 'remote'], function (): void {
        assert_same('docvecwizard API', vectorStoreLabel());
    });
});

// ── milvusBaseUrl() / milvusMetricsUrl() / vectorStoreBaseUrl() ───────────────

test_case('milvusBaseUrl() liest die Einstellung und entfernt Schrägstriche', function (): void {
    withVectorSettings(['milvus_url' => 'http://milvus.lan:19530/'], function (): void {
        assert_same('http://milvus.lan:19530', milvusBaseUrl());
    });
});

test_case('milvusBaseUrl() fällt auf die Umgebungsvariable bzw. den Standard zurück', function (): void {
    withVectorSettings(['milvus_url' => ''], function (): void {
        withEnv('MILVUS_URL', 'http://milvus-env:19530/', function (): void {
            assert_same('http://milvus-env:19530', milvusBaseUrl());
        });
        withEnv('MILVUS_URL', null, function (): void {
            assert_same('http://milvus:19530', milvusBaseUrl(), 'Standardwert ohne Konfiguration');
        });
    });
});

test_case('milvusMetricsUrl() liest die eigene Einstellung', function (): void {
    withVectorSettings(['milvus_metrics_url' => 'http://milvus.lan:9091/'], function (): void {
        assert_same('http://milvus.lan:9091', milvusMetricsUrl());
    });
});

test_case('milvusMetricsUrl() leitet den Port 9091 aus der Milvus-URL ab', function (): void {
    withVectorSettings(['milvus_metrics_url' => '', 'milvus_url' => 'http://milvus.lan:19530'], function (): void {
        withEnv('MILVUS_METRICS_URL', null, function (): void {
            assert_same('http://milvus.lan:9091', milvusMetricsUrl());
        });
    });
});

test_case('milvusMetricsUrl() bevorzugt die Umgebungsvariable', function (): void {
    withVectorSettings(['milvus_metrics_url' => ''], function (): void {
        withEnv('MILVUS_METRICS_URL', 'http://metrics.lan:9091/', function (): void {
            assert_same('http://metrics.lan:9091', milvusMetricsUrl());
        });
    });
});

test_case('vectorStoreBaseUrl() liefert die URL des aktiven Modus', function (): void {
    withVectorSettings(['docvec_api_url' => 'http://docvec.lan:8080/', 'milvus_url' => 'http://milvus.lan:19530'], function (): void {
        assert_same('http://docvec.lan:8080', vectorStoreBaseUrl('remote'));
        assert_same('http://milvus.lan:19530', vectorStoreBaseUrl('local'));
        assert_same('', vectorStoreBaseUrl('off'));
    });
});

test_case('vectorStoreBaseUrl() nutzt ohne Argument den aktiven Modus', function (): void {
    withVectorSettings(['vector_store_mode' => 'remote', 'docvec_api_url' => 'http://docvec.lan'], function (): void {
        assert_same('http://docvec.lan', vectorStoreBaseUrl());
    });
});

// ── milvusCollectionForModel() ────────────────────────────────────────────────

test_case('milvusCollectionForModel() bildet Modellnamen auf Sammlungen ab', function (): void {
    assert_same('docvec_baai_bge_m3', milvusCollectionForModel('baai/bge-m3'));
    assert_same('docvec_text_embedding_3_small', milvusCollectionForModel('text-embedding-3-small'));
    assert_same('docvec_my_model_2', milvusCollectionForModel('My Model 2'));
});

test_case('milvusCollectionForModel() nutzt default für leere Namen', function (): void {
    assert_same('docvec_default', milvusCollectionForModel(''));
    assert_same('docvec_default', milvusCollectionForModel('///'));
    assert_same('docvec_default', milvusCollectionForModel('---'));
});

test_case('milvusCollectionForModel() erzeugt immer gültige Bezeichner', function (): void {
    foreach (['baai/bge-m3', 'ümlaut/modell', 'a b  c', 'MODEL'] as $model) {
        $collection = milvusCollectionForModel($model);
        assert_true((bool) preg_match('/^[a-z0-9_]+$/', $collection), 'Ungültige Sammlung: ' . $collection);
        assert_true(str_starts_with($collection, 'docvec_'));
    }
});

// ── vectorHitsToToolResults() ─────────────────────────────────────────────────

test_case('vectorHitsToToolResults() formt Treffer für das Tool-Ergebnis um', function (): void {
    $results = vectorHitsToToolResults([vectorHit()]);

    assert_count(1, $results);
    assert_keys(['document', 'source', 'page', 'chunk', 'relevance', 'content'], $results[0]);
    assert_same('handbuch.pdf', $results[0]['document']);
    assert_same('Vektordatenbank', $results[0]['source']);
    assert_same(3, $results[0]['page']);
    assert_same(1, $results[0]['chunk'], 'chunk_index ist nullbasiert');
    assert_same(0.5, $results[0]['relevance']);
    assert_same('Inhalt des Abschnitts.', $results[0]['content']);
});

test_case('vectorHitsToToolResults() setzt die Seite ohne Seitenangabe auf null', function (): void {
    $results = vectorHitsToToolResults([vectorHit(['page_start' => 0, 'page_end' => 0])]);
    assert_same(null, $results[0]['page']);
});

test_case('vectorHitsToToolResults() rundet die Relevanz auf vier Stellen', function (): void {
    $results = vectorHitsToToolResults([vectorHit(['score' => 0.123456])]);
    assert_same(0.1235, $results[0]['relevance']);
});

test_case('vectorHitsToToolResults() kürzt lange Inhalte auf 1500 Zeichen', function (): void {
    $results = vectorHitsToToolResults([vectorHit(['text' => str_repeat('x', 2000)])]);
    assert_same(1500, mb_strlen($results[0]['content']));
    assert_same(str_repeat('x', 1500), $results[0]['content']);

    $short = vectorHitsToToolResults([vectorHit(['text' => str_repeat('x', 1500)])]);
    assert_same(1500, mb_strlen($short[0]['content']));
});

test_case('vectorHitsToToolResults() behält die Reihenfolge und Anzahl der Treffer', function (): void {
    $results = vectorHitsToToolResults([
        vectorHit(['filename' => 'a.pdf']),
        vectorHit(['filename' => 'b.pdf', 'chunk_index' => 4]),
    ]);

    assert_count(2, $results);
    assert_same(['a.pdf', 'b.pdf'], array_column($results, 'document'));
    assert_same([1, 5], array_column($results, 'chunk'));
});

// ── buildVectorContextSystemPrompt() ─────────────────────────────────────────

test_case('buildVectorContextSystemPrompt() liefert ohne Treffer einen leeren String', function (): void {
    assert_same('', buildVectorContextSystemPrompt([]));
});

test_case('buildVectorContextSystemPrompt() beginnt mit der Kontextanweisung', function (): void {
    $prompt = buildVectorContextSystemPrompt([vectorHit()]);

    assert_contains('Wissensdatenbank-Kontext:', $prompt);
    assert_contains('Erfinde keine Inhalte', $prompt);
});

test_case('buildVectorContextSystemPrompt() nummeriert die Quellen mit Abschnitt und Relevanz', function (): void {
    $prompt = buildVectorContextSystemPrompt([
        vectorHit(['filename' => 'handbuch.pdf', 'chunk_index' => 4, 'score' => 0.5, 'page_start' => 3, 'page_end' => 3]),
        vectorHit(['filename' => 'notizen.md', 'chunk_index' => 0, 'score' => 0.987, 'page_start' => 0]),
    ]);

    assert_contains('[Quelle 1: "handbuch.pdf", Seite 3, Abschnitt 5, Relevanz 0.50]', $prompt);
    assert_contains('[Quelle 2: "notizen.md", Abschnitt 1, Relevanz 0.99]', $prompt);
    assert_not_contains('Seite 0', $prompt);
});

test_case('buildVectorContextSystemPrompt() nennt Seitenbereiche mit Gedankenstrich', function (): void {
    $prompt = buildVectorContextSystemPrompt([vectorHit(['page_start' => 3, 'page_end' => 5])]);
    assert_contains('Seite 3–5', $prompt);

    $same = buildVectorContextSystemPrompt([vectorHit(['page_start' => 3, 'page_end' => 3])]);
    assert_contains('Seite 3,', $same);
    assert_not_contains('Seite 3–3', $same);
});

test_case('buildVectorContextSystemPrompt() trennt die Auszüge mit Leerzeilen', function (): void {
    $prompt = buildVectorContextSystemPrompt([
        vectorHit(['text' => 'Erster Auszug.']),
        vectorHit(['text' => 'Zweiter Auszug.']),
    ]);

    assert_contains('Erster Auszug.', $prompt);
    assert_contains('Zweiter Auszug.', $prompt);

    // Jeder Auszug steht in einer Untrusted-Klammer; zwischen den Quellen
    // bleibt eine Leerzeile als Trennung erhalten.
    preg_match('/<<(UNTRUSTED-[0-9A-Fa-f]+) label=/', $prompt, $matches);
    $boundary = (string) ($matches[1] ?? '');
    assert_true($boundary !== '', 'Der Prompt enthält eine Untrusted-Klammer');
    assert_contains('Erster Auszug.' . "\n" . '<</' . $boundary . '>>' . "\n\n[Quelle 2", $prompt);
});

test_case('buildVectorContextSystemPrompt() kürzt zu lange Auszüge', function (): void {
    // Budget 100 bei einem Treffer → mindestens 400 Zeichen pro Auszug.
    $prompt = buildVectorContextSystemPrompt([vectorHit(['text' => str_repeat('a', 450)])], 100);

    assert_contains(str_repeat('a', 400) . ' […]', $prompt);
    assert_not_contains(str_repeat('a', 401), $prompt);
});

test_case('buildVectorContextSystemPrompt() lässt kurze Auszüge unverändert', function (): void {
    $prompt = buildVectorContextSystemPrompt([vectorHit(['text' => 'kurz'])], 100);
    assert_contains('kurz', $prompt);
    assert_not_contains('[…]', $prompt);
});

exit(test_summary('test_vector_helpers'));
