<?php
declare(strict_types=1);

/**
 * tests/php/test_embedding_helpers.php
 *
 * Unit tests for the pure helpers of api/embedding.php: cosine similarity,
 * decoding of stored embeddings and the reranker's graceful empty fallback.
 *
 * Deliberately not covered here (they need a database or a live embedding
 * service): pickEmbeddingEndpoint(), hasActiveEmbeddingEndpoint(),
 * generateEmbedding(), generateEmbeddingAuto(), the query-embedding cache and
 * the reranking HTTP path of rerankDocuments().
 *
 * Run from the repository root:  php tests/php/test_embedding_helpers.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../api/embedding.php';

// ── cosineSimilarity() ────────────────────────────────────────────────────────

test_case('cosineSimilarity() liefert 1.0 für identische Vektoren', function (): void {
    assert_same(1.0, cosineSimilarity([1.0, 0.0, 0.0], [1.0, 0.0, 0.0]));
    assert_close(1.0, cosineSimilarity([3.0, 4.0], [3.0, 4.0]), 1e-12);
    assert_close(1.0, cosineSimilarity([0.5, 0.5], [2.0, 2.0]), 1e-12, 'Die Länge ist unerheblich');
});

test_case('cosineSimilarity() liefert 0.0 für orthogonale Vektoren', function (): void {
    assert_same(0.0, cosineSimilarity([1.0, 0.0], [0.0, 1.0]));
    assert_same(0.0, cosineSimilarity([1.0, 0.0, 0.0], [0.0, 0.0, 1.0]));
});

test_case('cosineSimilarity() liefert -1.0 für entgegengesetzte Vektoren', function (): void {
    assert_same(-1.0, cosineSimilarity([1.0, 0.0], [-1.0, 0.0]));
    assert_close(-1.0, cosineSimilarity([2.0, 3.0], [-2.0, -3.0]), 1e-12);
});

test_case('cosineSimilarity() berechnet den Zwischenwinkel', function (): void {
    // 45° zwischen [1,1] und [1,0] → cos = 1/√2
    assert_close(0.7071067811865475, cosineSimilarity([1.0, 1.0], [1.0, 0.0]), 1e-9);
    assert_close(0.5, cosineSimilarity([1.0, 0.0], [1.0, 1.7320508075688772]), 1e-9, '60° → cos = 0.5');
});

test_case('cosineSimilarity() liefert 0.0 bei Null- oder Leervektoren', function (): void {
    assert_same(0.0, cosineSimilarity([0.0, 0.0], [1.0, 1.0]), 'Nullvektor links');
    assert_same(0.0, cosineSimilarity([1.0, 1.0], [0.0, 0.0]), 'Nullvektor rechts');
    assert_same(0.0, cosineSimilarity([], []));
    assert_same(0.0, cosineSimilarity([], [1.0, 2.0]));
    assert_same(0.0, cosineSimilarity([1e-12, 0.0], [1.0, 0.0]), 'Unter der Schwelle 1e-10');
});

test_case('cosineSimilarity() vergleicht nur den gemeinsamen Präfix', function (): void {
    // min(count($a), count($b)) – der Rest wird ignoriert.
    assert_same(1.0, cosineSimilarity([1.0, 0.0, 0.0], [1.0, 0.0]));
    assert_same(1.0, cosineSimilarity([1.0, 0.0], [1.0, 0.0, 9.0, 9.0]));
});

test_case('cosineSimilarity() akzeptiert numerische Strings', function (): void {
    assert_same(1.0, cosineSimilarity(['1', '0'], [1.0, 0.0]));
});

test_case('cosineSimilarity() bleibt im Wertebereich [-1, 1]', function (): void {
    foreach ([[1.0, 2.0], [-3.0, 4.0], [0.001, 0.002], [123.0, 456.0]] as $vector) {
        $value = cosineSimilarity($vector, [1.0, 1.0]);
        assert_true($value >= -1.0 && $value <= 1.0, 'Wert außerhalb des Bereichs: ' . $value);
    }
});

// ── embeddingFromJson() ───────────────────────────────────────────────────────

test_case('embeddingFromJson() liest ein gespeichertes Vektor-Array', function (): void {
    assert_same([0.1, 0.2, 0.3], embeddingFromJson('[0.1,0.2,0.3]'));
    assert_same([1.0, 2.0], embeddingFromJson('[1,2]'), 'Ganzzahlen werden zu floats');
});

test_case('embeddingFromJson() liefert null bei leerer Eingabe', function (): void {
    assert_same(null, embeddingFromJson(null));
    assert_same(null, embeddingFromJson(''));
    assert_same(null, embeddingFromJson('[]'));
});

test_case('embeddingFromJson() liefert null bei ungültigem JSON', function (): void {
    assert_same(null, embeddingFromJson('kaputt'));
    assert_same(null, embeddingFromJson('[1,2'));
    assert_same(null, embeddingFromJson('null'));
    assert_same(null, embeddingFromJson('123'), 'Skalare sind kein Vektor');
    assert_same(null, embeddingFromJson('"abc"'));
});

test_case('embeddingFromJson() wandelt Objektwerte in floats', function (): void {
    assert_same(['a' => 1.0, 'b' => 2.5], embeddingFromJson('{"a":1,"b":2.5}'));
});

// ── rerankDocuments() ─────────────────────────────────────────────────────────

test_case('rerankDocuments() reicht eine leere Kandidatenliste unverändert zurück', function (): void {
    // Ohne Kandidaten wird weder die Konfiguration gelesen noch ein HTTP-Aufruf gestartet.
    assert_same([], rerankDocuments('Suchbegriff', []));
    assert_same([], rerankDocuments('Suchbegriff', [], 3));
});

exit(test_summary('test_embedding_helpers'));
