<?php
declare(strict_types=1);

/**
 * tests/db/test_prompt_security_rules.php
 *
 * Integrationstests für die regelbasierte Prompt-Security (lib/prompt_security.php)
 * gegen die Tabellen prompt_security_rules und prompt_security_logs.
 *
 * Die reine Rechenlogik (Normalisierung, Scoring-Formel, Entscheidungsgrenzen)
 * ist ohne Datenbank in tests/php/test_prompt_security.php abgedeckt. Hier geht
 * es um das, was nur mit Datenbank sichtbar ist: das Laden und Sortieren der
 * Regeln, das Überspringen inaktiver Regeln, die vollständige Pipeline
 * psEvaluate() inklusive Protokollzeile und die Aufbewahrungsfrist von
 * psPurgeLogs().
 *
 * Wichtig für das Verständnis der Suite: psLoadRules() cacht die Regeln pro
 * Prozess. Alle Regel-Attrappen werden deshalb angelegt, *bevor* die erste
 * Funktion sie liest; Änderungen an Regeln nach dem ersten Aufruf wären
 * wirkungslos. Einstellungen (prompt_security_*) werden dagegen bei jedem
 * Aufruf frisch gelesen und dürfen zwischen den Tests wechseln.
 *
 * Bewusst NICHT geprüft: die optionale KI-Bewertung psAiEvaluate() – sie würde
 * einen erreichbaren Klassifizierer verlangen und damit einen echten LLM-Aufruf.
 *
 * Voraussetzung: erreichbare MySQL-Instanz, siehe tests/db/bootstrap.php.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/prompt_security.php';

/** Legt eine Regel-Attrappe an und liefert ihre ID. */
function ps_test_rule(string $name, string $pattern, int $severity, int $isRegex = 0, int $isActive = 1): int
{
    test_db_connect()->prepare(
        'INSERT INTO prompt_security_rules (category, name, pattern, is_regex, severity, is_active)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([TEST_DB_RULE_CATEGORY, $name, $pattern, $isRegex, $severity, $isActive]);

    return (int) test_db_connect()->lastInsertId();
}

/** Löscht die von dieser Suite gesetzten Einstellungen. */
function ps_test_reset_settings(): void
{
    $keys = [
        'prompt_security_enabled',
        'prompt_security_mode',
        'prompt_security_log',
        'prompt_security_log_input',
        'prompt_security_log_retention_days',
        'prompt_security_score_limit',
        'prompt_security_warn_limit',
        'prompt_security_block_message',
    ];

    $stmt = test_db_connect()->prepare('DELETE FROM settings WHERE setting_key = ?');
    foreach ($keys as $key) {
        $stmt->execute([$key]);
    }
}

/** Zählt die Protokollzeilen, die diese Suite geschrieben hat. */
function ps_test_log_count(): int
{
    return (int) test_db_fetchValue(
        'SELECT COUNT(*) FROM prompt_security_logs WHERE ip_address = ?',
        [TEST_DB_LOG_IP]
    );
}

test_db_cleanup();
ps_test_reset_settings();

// Alle Regel-Attrappen vor dem ersten psLoadRules()-Aufruf anlegen (siehe Kopf).
$ruleBlockA = ps_test_rule('db-block-a', 'gefährliches-muster', 100);
$ruleBlockB = ps_test_rule('db-block-b', 'muster-b', 100);
$ruleWarn   = ps_test_rule('db-warn', 'warn-muster', 70);
$ruleRegex  = ps_test_rule('db-regex', 'geheim-\d+', 70, 1);
$ruleInaktiv = ps_test_rule('db-inaktiv', 'inaktives-muster', 100, 0, 0);
$ruleLeer   = ps_test_rule('db-leer', '', 50);

test_case('psLoadRules liefert die aktiven Regeln und überspringt inaktive', function () use ($ruleBlockA, $ruleInaktiv): void {
    $ids = array_map(static fn(array $r): int => (int) $r['id'], psLoadRules());

    assert_true(in_array($ruleBlockA, $ids, true), 'aktive Testregel ist geladen');
    assert_false(in_array($ruleInaktiv, $ids, true), 'inaktive Testregel fehlt');
});

test_case('psLoadRules sortiert nach Schweregrad absteigend', function () use ($ruleBlockA, $ruleBlockB, $ruleWarn, $ruleRegex, $ruleLeer): void {
    $ids = [];
    foreach (psLoadRules() as $rule) {
        if (($rule['category'] ?? '') === TEST_DB_RULE_CATEGORY) {
            $ids[] = (int) $rule['id'];
        }
    }

    assert_same(
        [$ruleBlockA, $ruleBlockB, $ruleWarn, $ruleRegex, $ruleLeer],
        $ids,
        'Schweregrad absteigend, Gleichstand nach ID aufsteigend'
    );
});

test_case('die Regeln tragen alle Felder aus der Datenbank', function () use ($ruleBlockA): void {
    foreach (psLoadRules() as $rule) {
        if ((int) $rule['id'] !== $ruleBlockA) {
            continue;
        }
        assert_same(TEST_DB_RULE_CATEGORY, $rule['category'] ?? null);
        assert_same('db-block-a', $rule['name'] ?? null);
        assert_same('gefährliches-muster', $rule['pattern'] ?? null);
        assert_same('0', (string) ($rule['is_regex'] ?? ''), 'is_regex kommt als String aus PDO');
        assert_same('100', (string) ($rule['severity'] ?? ''));
        return;
    }
    test_fail('Testregel nicht in psLoadRules() gefunden');
});

test_case('psMatchRules findet eine einfache Regel unabhängig von der Großschreibung', function () use ($ruleBlockA): void {
    $normalised = psNormalise('Bitte GEFÄHRLICHES-MUSTER ausführen')['normalised'];
    $matched = psMatchRules($normalised);

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $matched);
    assert_true(in_array($ruleBlockA, $ids, true), 'Regel greift: ' . test_describe($ids));

    foreach ($matched as $rule) {
        if ((int) $rule['id'] === $ruleBlockA) {
            assert_same('gefährliches-muster', $rule['match_excerpt'] ?? null);
        }
    }
});

test_case('psMatchRules findet eine RegEx-Regel und liefert den Treffer als Auszug', function () use ($ruleRegex): void {
    $matched = psMatchRules(psNormalise('Der Code lautet geheim-4711!')['normalised']);

    foreach ($matched as $rule) {
        if ((int) $rule['id'] === $ruleRegex) {
            assert_same('geheim-4711', $rule['match_excerpt'] ?? null);
            return;
        }
    }
    test_fail('RegEx-Regel hat nicht gegriffen: ' . test_describe($matched));
});

test_case('psMatchRules ignoriert inaktive Regeln', function () use ($ruleInaktiv): void {
    $matched = psMatchRules(psNormalise('inaktives-muster')['normalised']);

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $matched);
    assert_false(in_array($ruleInaktiv, $ids, true), 'inaktive Regel greift nicht');
    assert_count(0, $matched, 'kein Treffer für den Text');
});

test_case('psMatchRules überspringt Regeln ohne Muster', function (): void {
    $matched = psMatchRules(psNormalise('ganz harmloser text')['normalised']);

    assert_count(0, $matched, 'leeres Muster erzeugt keinen Treffer');
});

test_case('psEvaluate blockiert einen Text, der mehrere schwere Regeln trifft', function () use ($ruleBlockA, $ruleBlockB): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_mode', 'active');
    setSetting('prompt_security_log', '1');
    setSetting('prompt_security_log_input', 'full');

    $before = ps_test_log_count();
    $result = psEvaluate('gefährliches-muster und muster-b', 5, test_db_session_id('security-block'), TEST_DB_LOG_IP);

    assert_same('block', $result['decision'] ?? null);
    assert_same(100, $result['score'] ?? null, 'Score ist bei mehreren schweren Treffern gedeckelt');

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $result['matched'] ?? []);
    assert_same([$ruleBlockA, $ruleBlockB], $ids, 'beide Regeln wurden gemeldet');

    assert_same($before + 1, ps_test_log_count(), 'genau eine Protokollzeile');
    $row = test_db_connect()->prepare(
        'SELECT * FROM prompt_security_logs WHERE ip_address = ? ORDER BY id DESC LIMIT 1'
    );
    $row->execute([TEST_DB_LOG_IP]);
    $log = $row->fetch();

    assert_same('block', $log['decision'] ?? null);
    assert_same(100, (int) ($log['score'] ?? 0));
    assert_same(5, (int) ($log['user_id'] ?? 0));
    assert_same(test_db_session_id('security-block'), $log['session_id'] ?? null);
    assert_same($ruleBlockA, (int) ($log['matched_rule'] ?? 0), 'die schwerste Regel wird protokolliert');
    assert_same(TEST_DB_RULE_CATEGORY, $log['matched_cat'] ?? null);
    assert_same('gefährliches-muster und muster-b', $log['input_text'] ?? null);
});

test_case('psEvaluate warnt bei einem mittleren Score', function (): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_mode', 'active');
    setSetting('prompt_security_score_limit', '81');
    setSetting('prompt_security_warn_limit', '51');

    $result = psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);

    assert_same('warn', $result['decision'] ?? null);
    assert_same(56, $result['score'] ?? null, '70 * 0.8 aufgerundet');
    assert_contains('Hinweis', (string) ($result['message'] ?? ''));
});

test_case('psEvaluate nutzt die in der Datenbank konfigurierten Grenzen', function (): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_mode', 'active');
    setSetting('prompt_security_score_limit', '50');
    setSetting('prompt_security_warn_limit', '10');

    $result = psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);
    assert_same('block', $result['decision'] ?? null, 'gleicher Text, strengere Grenze');
});

test_case('psEvaluate nutzt die konfigurierte Blockiermeldung', function (): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_score_limit', '50');
    setSetting('prompt_security_warn_limit', '10');
    setSetting('prompt_security_block_message', 'Bitte freundlicher formulieren.');

    assert_same('Bitte freundlicher formulieren.', psEvaluate('warn-muster')['message'] ?? null);
});

test_case('im passiven Modus wird nicht blockiert, aber protokolliert', function (): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_mode', 'passive');
    setSetting('prompt_security_score_limit', '50');
    setSetting('prompt_security_warn_limit', '10');

    $before = ps_test_log_count();
    $result = psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);

    assert_same('warn', $result['decision'] ?? null, 'block wird zu warn');
    assert_same($before + 1, ps_test_log_count());
});

test_case('ohne aktiviertes Modul wird nichts geprüft und nichts protokolliert', function (): void {
    setSetting('prompt_security_enabled', '0');

    $before = ps_test_log_count();
    $result = psEvaluate('gefährliches-muster und muster-b', null, '', TEST_DB_LOG_IP);

    assert_same('allow', $result['decision'] ?? null);
    assert_same(0, $result['score'] ?? null);
    assert_count(0, $result['matched'] ?? null);
    assert_same($before, ps_test_log_count(), 'keine neue Protokollzeile');
});

test_case('prompt_security_log_input steuert, was gespeichert wird', function (): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_mode', 'active');
    setSetting('prompt_security_log', '1');

    setSetting('prompt_security_log_input', 'none');
    psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);
    assert_same(null, test_db_fetchValue(
        'SELECT input_text FROM prompt_security_logs WHERE ip_address = ? ORDER BY id DESC LIMIT 1',
        [TEST_DB_LOG_IP]
    ), 'none speichert keinen Text');

    setSetting('prompt_security_log_input', 'anon');
    psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);
    assert_same('[11 chars]', test_db_fetchValue(
        'SELECT input_text FROM prompt_security_logs WHERE ip_address = ? ORDER BY id DESC LIMIT 1',
        [TEST_DB_LOG_IP]
    ), 'anon speichert nur die Länge');

    setSetting('prompt_security_log_input', 'full');
    psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);
    assert_same('warn-muster', test_db_fetchValue(
        'SELECT input_text FROM prompt_security_logs WHERE ip_address = ? ORDER BY id DESC LIMIT 1',
        [TEST_DB_LOG_IP]
    ));
});

test_case('prompt_security_log=0 unterdrückt die Protokollierung', function (): void {
    setSetting('prompt_security_enabled', '1');
    setSetting('prompt_security_mode', 'active');
    setSetting('prompt_security_log', '0');
    // Grenzen explizit setzen: der Test prüft die Protokollierung, nicht die
    // Schwellen, und die vorherigen Tests haben sie verändert.
    setSetting('prompt_security_score_limit', '81');
    setSetting('prompt_security_warn_limit', '51');

    $before = ps_test_log_count();
    $result = psEvaluate('warn-muster', null, '', TEST_DB_LOG_IP);

    assert_same('warn', $result['decision'] ?? null, 'die Entscheidung fällt trotzdem');
    assert_same($before, ps_test_log_count());
});

test_case('psPurgeLogs löscht nur Zeilen jenseits der Aufbewahrungsfrist', function (): void {
    $pdo = test_db_connect();
    $insert = $pdo->prepare(
        "INSERT INTO prompt_security_logs (ip_address, input_text, score, decision, created_at)
         VALUES (?, 'alt', 10, 'allow', DATE_SUB(NOW(), INTERVAL 40 DAY))"
    );
    $insert->execute([TEST_DB_LOG_IP]);
    $altId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO prompt_security_logs (ip_address, input_text, score, decision, created_at)
         VALUES (?, 'frisch', 10, 'allow', NOW())"
    )->execute([TEST_DB_LOG_IP]);
    $frischId = (int) $pdo->lastInsertId();

    setSetting('prompt_security_log_retention_days', '30');
    psPurgeLogs();

    assert_same(0, (int) test_db_fetchValue('SELECT COUNT(*) FROM prompt_security_logs WHERE id = ?', [$altId]));
    assert_same(1, (int) test_db_fetchValue('SELECT COUNT(*) FROM prompt_security_logs WHERE id = ?', [$frischId]));
});

test_case('psPurgeLogs behält alles bei einer sehr langen Frist', function (): void {
    setSetting('prompt_security_log_retention_days', '3650');

    $before = ps_test_log_count();
    psPurgeLogs();

    assert_same($before, ps_test_log_count(), 'nichts wurde gelöscht');
});

$code = test_summary('DB: Prompt-Security-Regeln');
test_db_cleanup();
ps_test_reset_settings();
exit($code);
