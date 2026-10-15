<?php
declare(strict_types=1);

/**
 * tests/php/test_model_naming.php
 *
 * Unit tests for the pure model-name helpers of db.php: canonical model names,
 * the intelligence estimate, the group labels and the "@@<group>" / "!!"
 * prefixes of chat messages.
 *
 * These helpers decide which endpoints are pooled as "the same model" and which
 * intelligence group a chat runs in, so a regression here silently routes chats
 * to the wrong endpoint. No database, no HTTP, no network is involved.
 *
 * Run from the repository root:  php tests/php/test_model_naming.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../db.php';

// ── canonicalModelName() ──────────────────────────────────────────────────────

test_case('canonicalModelName() entfernt Verzeichnis, Endung und Quantisierung', function (): void {
    assert_same('gemma-4-e4b', canonicalModelName('gemma-4-E4B-it-Q4_K_M.gguf'));
    assert_same('llama-3.2-3b-instruct', canonicalModelName('llama-3.2-3b-instruct-fp16.bin'));
    assert_same('mixtral-8x7b-v0.1', canonicalModelName('  Mixtral-8x7B-v0.1-Q5_K_S.gguf  '));
    assert_same('deepseek-r1-distill-qwen-32b', canonicalModelName('deepseek-r1-distill-qwen-32b-iq2_xxs.gguf'));
    assert_same('upper-case', canonicalModelName('UPPER-CASE-Q8_0'));
});

test_case('canonicalModelName() ignoriert absolute Pfade (POSIX und Windows)', function (): void {
    assert_same('gemma-4-e4b', canonicalModelName('/opt/llama.cpp/models/gemma-4-E4B-it-Q4_K_M.gguf'));
    assert_same('qwen3.5-35b-a3b', canonicalModelName('C:\\models\\Qwen3.5-35B-A3B-Q8_0.gguf'));
});

test_case('canonicalModelName() lässt unbekannte Endungen und leere Eingaben unverändert', function (): void {
    assert_same('model', canonicalModelName('model.safetensors'));
    assert_same('plain', canonicalModelName('plain'));
    assert_same('m', canonicalModelName('m'));
    assert_same('', canonicalModelName(''));
});

test_case('canonicalModelName() fasst Datei- und Quantisierungsvarianten zusammen', function (): void {
    // Kernzweck der Funktion: Endpunkte mit unterschiedlichen Dateinamen, aber
    // identischem Grundmodell müssen denselben Schlüssel bekommen.
    assert_same(
        canonicalModelName('gemma-4-E4B-it-Q4_K_M.gguf'),
        canonicalModelName('/opt/models/Gemma-4-E4B-IT-Q8_0.gguf'),
        'Gleiches Modell in zwei Quantisierungen ergibt denselben Schlüssel'
    );
});

// ── modelIntelligenceScore() ──────────────────────────────────────────────────

test_case('modelIntelligenceScore() liest die Parameterzahl aus dem Namen', function (): void {
    assert_same(4.0, modelIntelligenceScore('gemma-4-E4B-it-Q4_K_M'));
    assert_same(8.0, modelIntelligenceScore('llama-3-8b'));
    assert_same(26.0, modelIntelligenceScore('gemma-4-26B-A4B'));
    assert_same(35.0, modelIntelligenceScore('Qwen3.5-35B-A3B'));
    assert_same(397.0, modelIntelligenceScore('Qwen3.5-397B-A17B'));
});

test_case('modelIntelligenceScore() akzeptiert Komma-Dezimaltrenner', function (): void {
    assert_same(1.5, modelIntelligenceScore('foo-1,5b'));
});

test_case('modelIntelligenceScore() nimmt die größte Zahl und meidet den Aktiv-Parameter-Marker', function (): void {
    // "A17B" ist die Zahl der aktiven Parameter und darf die Gesamtgröße
    // ("397B") nicht überschreiben.
    assert_same(397.0, modelIntelligenceScore('Qwen3.5-397B-A17B'));
    // Ohne Gesamtgröße bleibt der Marker die letzte Rettung.
    assert_same(3.0, modelIntelligenceScore('A3B'));
});

test_case('modelIntelligenceScore() liefert null ohne verwertbare Größe', function (): void {
    assert_same(null, modelIntelligenceScore('no-number'));
    assert_same(null, modelIntelligenceScore('x-0b'));
    assert_same(null, modelIntelligenceScore(''));
});

// ── intelligenceGroupLabel() / normalizeIntelligenceGroupLabel() ──────────────

test_case('intelligenceGroupLabel() formatiert ohne überflüssige Nullen', function (): void {
    assert_same('35b', intelligenceGroupLabel(35.0));
    assert_same('35.5b', intelligenceGroupLabel(35.5));
    assert_same('7.25b', intelligenceGroupLabel(7.25));
    assert_same('1b', intelligenceGroupLabel(1.0));
    assert_same('0.5b', intelligenceGroupLabel(0.5));
    assert_same('400b', intelligenceGroupLabel(400.0));
    assert_same('1234b', intelligenceGroupLabel(1234.0));
    assert_same('0b', intelligenceGroupLabel(0.0));
});

test_case('normalizeIntelligenceGroupLabel() vereinheitlicht Nutzereingaben', function (): void {
    assert_same('35b', normalizeIntelligenceGroupLabel('35B'));
    assert_same('35b', normalizeIntelligenceGroupLabel('35b'));
    assert_same('35b', normalizeIntelligenceGroupLabel('35'));
    assert_same('35.5b', normalizeIntelligenceGroupLabel('35,5'));
    assert_same('35.5b', normalizeIntelligenceGroupLabel('35,5b'));
    assert_same('1.5b', normalizeIntelligenceGroupLabel('1.5b'));
});

test_case('normalizeIntelligenceGroupLabel() weist unbrauchbare Eingaben zurück', function (): void {
    assert_same('', normalizeIntelligenceGroupLabel('0'));
    assert_same('', normalizeIntelligenceGroupLabel('-5'));
    assert_same('', normalizeIntelligenceGroupLabel('abc'));
    assert_same('', normalizeIntelligenceGroupLabel(''));
});

test_case('intelligenceGroupLabel() und normalizeIntelligenceGroupLabel() sind konsistent', function (): void {
    foreach ([0.5, 1.0, 7.0, 35.5, 397.0] as $score) {
        assert_same(
            intelligenceGroupLabel((float) $score),
            normalizeIntelligenceGroupLabel(intelligenceGroupLabel((float) $score)),
            'Label ' . intelligenceGroupLabel((float) $score) . ' muss sich normalisieren lassen'
        );
    }
});

// ── extractIntelligenceGroupPrefix() ──────────────────────────────────────────

test_case('extractIntelligenceGroupPrefix() trennt "@@<gruppe>" vom Text', function (): void {
    assert_same(['35b', 'hallo'], extractIntelligenceGroupPrefix('@@35b hallo'));
    assert_same(['35', 'hallo'], extractIntelligenceGroupPrefix('@@35 hallo'));
    assert_same(['35,5b', 'test'], extractIntelligenceGroupPrefix('@@35,5b test'));
    assert_same(['35b', ''], extractIntelligenceGroupPrefix('@@35b'));
});

test_case('extractIntelligenceGroupPrefix() normalisiert Mehrfach-Leerzeichen', function (): void {
    assert_same(['35b', 'hallo'], extractIntelligenceGroupPrefix('@@35b  hallo'));
});

test_case('extractIntelligenceGroupPrefix() lässt Text ohne gültiges Präfix unangetastet', function (): void {
    assert_same(['', '@@ 35b hallo'], extractIntelligenceGroupPrefix('@@ 35b hallo'));
    assert_same(['', 'hallo @@35b'], extractIntelligenceGroupPrefix('hallo @@35b'));
    assert_same(['', '@@abc'], extractIntelligenceGroupPrefix('@@abc'));
    assert_same(['', '@@-5 x'], extractIntelligenceGroupPrefix('@@-5 x'));
});

test_case('extractIntelligenceGroupPrefix() liefert ein normalisierbares Gruppentoken', function (): void {
    [$token, $text] = extractIntelligenceGroupPrefix('@@35,5b teste das');
    assert_same('35.5b', normalizeIntelligenceGroupLabel($token));
    assert_same('teste das', $text);
});

// ── extractReasoningPrefix() ──────────────────────────────────────────────────

test_case('extractReasoningPrefix() erkennt das "!!"-Präfix', function (): void {
    assert_same([true, 'hallo'], extractReasoningPrefix('!! hallo'));
    assert_same([true, 'hallo'], extractReasoningPrefix('!!hallo'));
    assert_same([true, ''], extractReasoningPrefix('!!'));
    assert_same([true, 'x'], extractReasoningPrefix("!! \n x"));
});

test_case('extractReasoningPrefix() verlangt das Präfix am Textanfang', function (): void {
    assert_same([false, 'hallo !!'], extractReasoningPrefix('hallo !!'));
    assert_same([false, '! hallo'], extractReasoningPrefix('! hallo'));
});

// ── reasoningEffortOptions() / normalizeReasoningEffort() ─────────────────────

test_case('reasoningEffortOptions() liefert die vier Stufen in fester Reihenfolge', function (): void {
    assert_same(['none', 'low', 'medium', 'high'], reasoningEffortOptions());
});

test_case('normalizeReasoningEffort() bildet gespeicherte Werte auf die API-Stufen ab', function (): void {
    assert_same('low', normalizeReasoningEffort(' low '));
    assert_same('low', normalizeReasoningEffort('LOW'));
    assert_same('medium', normalizeReasoningEffort('medium'));
    assert_same('high', normalizeReasoningEffort('high'));
});

test_case('normalizeReasoningEffort() liefert null, wenn nichts gesendet werden soll', function (): void {
    assert_same(null, normalizeReasoningEffort('none'));
    assert_same(null, normalizeReasoningEffort('NONE'));
    assert_same(null, normalizeReasoningEffort(''));
    assert_same(null, normalizeReasoningEffort(null));
    assert_same(null, normalizeReasoningEffort(5));
    assert_same(null, normalizeReasoningEffort(true));
});

test_case('normalizeReasoningEffort() fällt bei unbekannten Werten auf "high" zurück', function (): void {
    assert_same('high', normalizeReasoningEffort('bogus'));
    assert_same('high', normalizeReasoningEffort('ultra'));
});

exit(test_summary('test_model_naming'));
