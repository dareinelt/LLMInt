<?php
declare(strict_types=1);

/**
 * tests/php/test_dictation_commands.php
 *
 * Unit tests for the deterministic speech-dictation helpers of
 * lib/speech_dictation.php: the command table, the local command processing
 * (used when the dictation model fails), output cleanup, token masking,
 * context limiting and log redaction.
 *
 * speechDictationCommands() reads a setting; without a database it falls back
 * to speechDictationDefaultCommands(), which is exactly the state these tests
 * exercise. No database, no HTTP, no network.
 *
 * Run from the repository root:  php tests/php/test_dictation_commands.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/speech_dictation.php';

/** Look up the default command row for a phrase. */
function dictationCmd(string $phrase): array
{
    foreach (speechDictationDefaultCommands() as $cmd) {
        if ($cmd['phrase'] === $phrase) {
            return $cmd;
        }
    }
    return [];
}

// ── speechDictationDefaultCommands() / speechDictationCommands() ──────────────

test_case('speechDictationDefaultCommands() enthält die 17 Standardkommandos', function (): void {
    $commands = speechDictationDefaultCommands();
    assert_count(17, $commands);

    $phrases = [];
    foreach ($commands as $cmd) {
        assert_keys(['phrase', 'type', 'value'], $cmd);
        assert_true($cmd['phrase'] !== '', 'Jedes Kommando braucht einen Auslöser');
        assert_true(
            in_array($cmd['type'], ['insert', 'newline', 'paragraph', 'delete_word', 'delete_sentence'], true),
            'Unbekannter Kommandotyp: ' . $cmd['type']
        );
        $phrases[] = $cmd['phrase'];
    }
    assert_same($phrases, array_values(array_unique($phrases)), 'Auslöser sind eindeutig');
});

test_case('speechDictationDefaultCommands() deckt Satzzeichen, Absätze und Löschbefehle ab', function (): void {
    assert_same(['type' => 'insert', 'value' => '.'], ['type' => dictationCmd('punkt')['type'], 'value' => dictationCmd('punkt')['value']]);
    assert_same(',', dictationCmd('komma')['value']);
    assert_same('?', dictationCmd('fragezeichen')['value']);
    assert_same('!', dictationCmd('ausrufezeichen')['value']);
    assert_same('newline', dictationCmd('neue zeile')['type']);
    assert_same('paragraph', dictationCmd('neuer absatz')['type']);
    assert_same('delete_word', dictationCmd('lösche letztes wort')['type']);
    assert_same('delete_sentence', dictationCmd('lösche letzten satz')['type']);
});

test_case('speechDictationCommands() fällt ohne Konfiguration auf die Standardliste zurück', function (): void {
    // Ohne Datenbank ist speech_dictation_commands nicht gesetzt.
    assert_same(speechDictationDefaultCommands(), speechDictationCommands());
});

// ── speechDictationCommandPreview() ───────────────────────────────────────────

test_case('speechDictationCommandPreview() beschreibt die Sonderbefehle', function (): void {
    assert_same('Zeilenumbruch', speechDictationCommandPreview(['type' => 'newline']));
    assert_same('Absatzumbruch (Leerzeile)', speechDictationCommandPreview(['type' => 'paragraph']));
    assert_same('lösche das zuletzt gesprochene Wort', speechDictationCommandPreview(['type' => 'delete_word']));
    assert_same('lösche den zuletzt gesprochenen Satz', speechDictationCommandPreview(['type' => 'delete_sentence']));
});

test_case('speechDictationCommandPreview() setzt Einfügungen in Anführungszeichen', function (): void {
    assert_same('"."', speechDictationCommandPreview(['type' => 'insert', 'value' => '.']));
    assert_same('"v"', speechDictationCommandPreview(['type' => 'unbekannt', 'value' => 'v']));
    assert_same('', speechDictationCommandPreview(['type' => 'insert', 'value' => '']));
});

// ── speechDictationMaskToken() ────────────────────────────────────────────────

test_case('speechDictationMaskToken() verbirgt Token bis auf die letzten vier Zeichen', function (): void {
    assert_same('••••••••ijkl', speechDictationMaskToken('abcdefghijkl'));
});

test_case('speechDictationMaskToken() verdeckt kurze Token vollständig', function (): void {
    assert_same('•••', speechDictationMaskToken('abc'));
    assert_same('••••', speechDictationMaskToken('abcd'));
});

test_case('speechDictationMaskToken() kennzeichnet ein fehlendes Token', function (): void {
    assert_same('– kein Token –', speechDictationMaskToken(''));
    assert_same('– kein Token –', speechDictationMaskToken('   '));
});

test_case('speechDictationMaskToken() gibt niemals mehr als die letzten vier Zeichen preis', function (): void {
    $masked = speechDictationMaskToken('sk-1234567890abcdef');
    assert_not_contains('sk-1234', $masked);
    assert_true(substr($masked, -4) === 'cdef', 'Nur die letzten vier Zeichen bleiben sichtbar');
});

// ── speechDictationSourceLabel() / speechDictationEndpointLabel() ─────────────

test_case('speechDictationSourceLabel() benennt die Konfigurationsquellen', function (): void {
    assert_same('Umgebungsvariable (docker-compose)', speechDictationSourceLabel('env'));
    assert_same('Speech-Endpunkt in der Datenbank', speechDictationSourceLabel('endpoint'));
    assert_same('nicht konfiguriert', speechDictationSourceLabel(''));
    assert_same('nicht konfiguriert', speechDictationSourceLabel('sonstiges'));
});

test_case('speechDictationEndpointLabel() bevorzugt den Alias', function (): void {
    assert_same('Lokal', speechDictationEndpointLabel(['alias' => ' Lokal ', 'base_url' => 'http://speech:8080']));
});

test_case('speechDictationEndpointLabel() leitet sonst Host und Port aus der URL ab', function (): void {
    assert_same('speech:8080', speechDictationEndpointLabel(['base_url' => 'http://speech:8080']));
    assert_same('speech.example', speechDictationEndpointLabel(['base_url' => 'https://speech.example/v1']));
});

test_case('speechDictationEndpointLabel() fällt auf die ID zurück, wenn keine URL brauchbar ist', function (): void {
    assert_same('Endpunkt #7', speechDictationEndpointLabel(['id' => 7]));
    assert_same('nonsense', speechDictationEndpointLabel(['id' => 7, 'base_url' => 'nonsense']));
});

// ── speechDictationCleanModelOutput() ─────────────────────────────────────────

test_case('speechDictationCleanModelOutput() entfernt Denkblöcke', function (): void {
    assert_same('Hello', speechDictationCleanModelOutput('<think>hmm</think>Hello'));
    assert_same('Hello', speechDictationCleanModelOutput('Hello<thinking>hmm</thinking>'));
    assert_same('Hello', speechDictationCleanModelOutput('<reasoning>a</reasoning>Hello'));
    assert_same('', speechDictationCleanModelOutput('<think>x</think>'));
});

test_case('speechDictationCleanModelOutput() verwirft einen abgeschnittenen Denkblock', function (): void {
    // Bricht die Ausgabe mitten im Denkblock ab, darf nichts davon ankommen.
    assert_same('Hello', speechDictationCleanModelOutput('Hello<reasoning>unfinished'));
});

test_case('speechDictationCleanModelOutput() packt einen Codeblock aus', function (): void {
    assert_same('Hello', speechDictationCleanModelOutput("```\nHello\n```"));
    assert_same('Hello', speechDictationCleanModelOutput("```text\nHello\n```"));
});

test_case('speechDictationCleanModelOutput() entfernt Modell-Labels am Anfang', function (): void {
    assert_same('Hello', speechDictationCleanModelOutput('Ausgabe: Hello'));
    assert_same('Hello', speechDictationCleanModelOutput('output: Hello'));
    assert_same('Hello', speechDictationCleanModelOutput('Transkript: Hello'));
});

test_case('speechDictationCleanModelOutput() entfernt nur umschließende Anführungszeichen', function (): void {
    assert_same('Hello', speechDictationCleanModelOutput('"Hello"'));
    // Enthält der Text selbst Anführungszeichen, bleibt alles stehen.
    assert_same('"He said "hi""', speechDictationCleanModelOutput('"He said "hi""'));
});

test_case('speechDictationCleanModelOutput() normalisiert Zeilenumbrüche', function (): void {
    assert_same("Hello\nWorld", speechDictationCleanModelOutput("Hello   \nWorld"));
    assert_same("A\n\nB", speechDictationCleanModelOutput("A\n\n\n\nB"));
});

test_case('speechDictationCleanModelOutput() trimmt das Ergebnis', function (): void {
    assert_same('Hello world', speechDictationCleanModelOutput("  Hello world  "));
    assert_same('', speechDictationCleanModelOutput('   '));
});

// ── speechDictationApplyCommand() ─────────────────────────────────────────────

test_case('speechDictationApplyCommand() fügt Werte direkt an', function (): void {
    assert_same('hallo.', speechDictationApplyCommand('hallo', ['type' => 'insert', 'value' => '.']));
    assert_same('hallo (', speechDictationApplyCommand('hallo', ['type' => 'insert', 'value' => ' (']));
    assert_same('hallo', speechDictationApplyCommand('hallo', ['type' => 'insert', 'value' => '']));
});

test_case('speechDictationApplyCommand() unterdrückt ein führendes Leerzeichen am Zeilenanfang', function (): void {
    assert_same('hallo (', speechDictationApplyCommand('hallo ', ['type' => 'insert', 'value' => ' (']));
    assert_same("hallo\n(", speechDictationApplyCommand("hallo\n", ['type' => 'insert', 'value' => ' (']));
    assert_same('(', speechDictationApplyCommand('', ['type' => 'insert', 'value' => ' (']));
});

test_case('speechDictationApplyCommand() behandelt unbekannte Typen als Einfügung', function (): void {
    assert_same('hallox', speechDictationApplyCommand('hallo', ['type' => 'quatsch', 'value' => 'x']));
});

test_case('speechDictationApplyCommand() setzt Zeilen- und Absatzumbrüche', function (): void {
    assert_same("hallo\n", speechDictationApplyCommand('hallo', ['type' => 'newline']));
    assert_same("hallo\n\n", speechDictationApplyCommand('hallo', ['type' => 'paragraph']));
    assert_same("hallo\n", speechDictationApplyCommand('hallo   ', ['type' => 'newline']));
});

test_case('speechDictationApplyCommand() löscht das letzte Wort samt Satzzeichen', function (): void {
    assert_same('hallo', speechDictationApplyCommand('hallo welt', ['type' => 'delete_word']));
    assert_same('hallo', speechDictationApplyCommand('hallo welt.', ['type' => 'delete_word']));
    assert_same('', speechDictationApplyCommand('hallo', ['type' => 'delete_word']));
    assert_same('...', speechDictationApplyCommand('...', ['type' => 'delete_word']));
});

test_case('speechDictationApplyCommand() löscht den letzten Satz', function (): void {
    assert_same('Erster Satz', speechDictationApplyCommand('Erster Satz. Zweiter', ['type' => 'delete_sentence']));
    assert_same('Erster', speechDictationApplyCommand("Erster\nZweiter", ['type' => 'delete_sentence']));
    assert_same('', speechDictationApplyCommand('Ohne Satzende', ['type' => 'delete_sentence']));
});

// ── speechDictationAppendWord() ───────────────────────────────────────────────

test_case('speechDictationAppendWord() trennt Wörter mit einem Leerzeichen', function (): void {
    assert_same('hallo welt', speechDictationAppendWord('hallo', 'welt'));
    assert_same('welt', speechDictationAppendWord('', 'welt'));
    assert_same('hallo welt', speechDictationAppendWord('hallo ', 'welt'));
    assert_same("hallo\nwelt", speechDictationAppendWord("hallo\n", 'welt'));
});

// ── speechDictationApplyCommands() ────────────────────────────────────────────

test_case('speechDictationApplyCommands() ersetzt Auslöser durch Satzzeichen', function (): void {
    assert_same('hallo welt.', speechDictationApplyCommands('hallo welt punkt'));
    assert_same('hallo, welt', speechDictationApplyCommands('hallo komma welt'));
    assert_same('.', speechDictationApplyCommands('PUNKT'));
});

test_case('speechDictationApplyCommands() erkennt Kommandos mit mehreren Wörtern', function (): void {
    assert_same("hallo\nwelt", speechDictationApplyCommands('hallo neue zeile welt'));
    assert_same('hallo', speechDictationApplyCommands('hallo welt lösche letztes wort'));
    assert_same(
        'Erster Satz',
        speechDictationApplyCommands('Erster Satz punkt Zweiter Satz lösche letzten satz')
    );
});

test_case('speechDictationApplyCommands() lässt Teilwörter unangetastet', function (): void {
    assert_same('hallo punktwelt', speechDictationApplyCommands('hallo punktwelt'));
});

test_case('speechDictationApplyCommands() normalisiert Leerraum', function (): void {
    assert_same('hallo welt.', speechDictationApplyCommands('hallo  welt   punkt'));
    assert_same('', speechDictationApplyCommands('   '));
});

// ── speechDictationLimitContext() / speechDictationLogText() ──────────────────

test_case('speechDictationLimitContext() behält kurze Kontexte unverändert', function (): void {
    assert_same('hallo', speechDictationLimitContext('  hallo  '));
});

test_case('speechDictationLimitContext() kürzt lange Kontexte auf das Ende', function (): void {
    $context = str_repeat('ab', 300);
    $limited = speechDictationLimitContext($context);

    assert_same(SPEECH_DICTATION_CONTEXT_CHARS, mb_strlen($limited, 'UTF-8'));
    assert_same(substr($context, -SPEECH_DICTATION_CONTEXT_CHARS), $limited, 'Das Ende des Kontexts bleibt erhalten');
});

test_case('speechDictationLogText() protokolliert nur Länge und Wortzahl', function (): void {
    assert_same('0 Zeichen / 0 Wörter', speechDictationLogText(''));
    assert_same('13 Zeichen / 3 Wörter', speechDictationLogText('hallo welt du'));
    // Hinweis: Die Einzahl wird derzeit ebenfalls als "Wörter" ausgegeben.
    assert_same('5 Zeichen / 1 Wörter', speechDictationLogText('hallo'));
});

test_case('speechDictationLogText() gibt den diktierten Text nicht preis', function (): void {
    $secret = 'geheimes Passwort';
    assert_not_contains('geheimes', speechDictationLogText($secret));
    assert_not_contains('Passwort', speechDictationLogText($secret));
});

exit(test_summary('test_dictation_commands'));
