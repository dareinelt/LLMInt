<?php
declare(strict_types=1);

/**
 * tests/db/test_conversation_sessions.php
 *
 * Integrationstests für die Chat-Session-Persistenz aus db.php:
 * saveConversationSession(), loadConversationSession(),
 * loadUserConversationSession(), setSessionUpgradeModel(),
 * getActiveSessionUpgradeModel() und purgeExpiredConversationSessions().
 *
 * Diese Funktionen fangen alle DB-Fehler ab und sind dadurch ohne Datenbank
 * stumm erfolgreich – nur mit echter Datenbank lässt sich prüfen, dass sie
 * wirklich schreiben, lesen und die Zeitfenster einhalten. Genau das macht
 * diese Suite.
 *
 * Bewusst NICHT geprüft: die Nebenläufigkeit zweier gleichzeitiger Saves für
 * dieselbe Session (der Upsert ist atomar, ein echter Parallelitätstest wäre
 * nicht deterministisch).
 *
 * Voraussetzung: erreichbare MySQL-Instanz, siehe tests/db/bootstrap.php.
 */

require __DIR__ . '/bootstrap.php';

test_db_cleanup();

test_case('saveConversationSession und loadConversationSession bilden den Verlauf ab', function (): void {
    $id = test_db_session_id('roundtrip');
    $messages = [
        ['role' => 'system', 'content' => 'Du bist ein Assistent.'],
        ['role' => 'user', 'content' => 'Hallo'],
        ['role' => 'assistant', 'content' => 'Guten Tag!'],
    ];

    saveConversationSession($id, 'qwen3-35b', $messages);
    $loaded = loadConversationSession($id);

    assert_true(is_array($loaded), 'Session ist ladbar');
    assert_same('qwen3-35b', $loaded['model'] ?? null);
    assert_same($messages, $loaded['messages'] ?? null, 'Reihenfolge und Rollen bleiben erhalten');
});

test_case('Umlaute und Emoji im Verlauf bleiben unverändert', function (): void {
    $id = test_db_session_id('unicode');
    $messages = [['role' => 'user', 'content' => 'Grüße – „Anführung" ✓ 🚀']];

    saveConversationSession($id, 'modell-ä', $messages);
    $loaded = loadConversationSession($id);

    assert_same('modell-ä', $loaded['model'] ?? null);
    assert_same('Grüße – „Anführung" ✓ 🚀', $loaded['messages'][0]['content'] ?? null);
});

test_case('ein erneutes Speichern ersetzt den Verlauf vollständig', function (): void {
    $id = test_db_session_id('ersetzt');
    saveConversationSession($id, 'modell-a', [['role' => 'user', 'content' => 'eins']]);
    saveConversationSession($id, 'modell-b', [['role' => 'user', 'content' => 'zwei']]);

    $loaded = loadConversationSession($id);
    assert_same('modell-b', $loaded['model'] ?? null);
    assert_count(1, $loaded['messages'] ?? null);
    assert_same('zwei', $loaded['messages'][0]['content'] ?? null);
});

test_case('loadConversationSession liefert null für leere und unbekannte IDs', function (): void {
    assert_same(null, loadConversationSession(''));
    assert_same(null, loadConversationSession(test_db_session_id('gibt-es-nicht')));
});

test_case('saveConversationSession ignoriert eine leere Session-ID', function (): void {
    $before = (int) test_db_fetchValue('SELECT COUNT(*) FROM conversation_sessions');
    saveConversationSession('', 'modell', [['role' => 'user', 'content' => 'x']]);
    $after = (int) test_db_fetchValue('SELECT COUNT(*) FROM conversation_sessions');

    assert_same($before, $after, 'leere Session-ID legt keine Zeile an');
});

test_case('loadConversationSession liefert null bei kaputtem JSON', function (): void {
    $id = test_db_session_id('kaputt');
    test_db_connect()->prepare(
        'INSERT INTO conversation_sessions (session_id, model, messages) VALUES (?, ?, ?)'
    )->execute([$id, 'modell', 'kein-json']);

    assert_same(null, loadConversationSession($id));
});

test_case('der Titel wird aus der ersten Nutzernachricht abgeleitet', function (): void {
    $id = test_db_session_id('titel');
    saveConversationSession($id, 'modell', [
        ['role' => 'system', 'content' => 'Systemnachricht'],
        ['role' => 'user', 'content' => '  Wie wird das Wetter?  '],
    ], 42);

    assert_same(
        'Wie wird das Wetter?',
        test_db_fetchValue('SELECT title FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('ein langer Titel wird auf 80 Zeichen gekürzt und mit Auslassungszeichen markiert', function (): void {
    $id = test_db_session_id('titel-lang');
    $long = str_repeat('a', 100);
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => $long]], 42);

    $title = (string) test_db_fetchValue('SELECT title FROM conversation_sessions WHERE session_id = ?', [$id]);
    assert_same(str_repeat('a', 80) . '…', $title);
});

test_case('die Kürzung zählt Zeichen, nicht Bytes', function (): void {
    $id = test_db_session_id('titel-multibyte');
    $long = str_repeat('ä', 90);
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => $long]], 42);

    $title = (string) test_db_fetchValue('SELECT title FROM conversation_sessions WHERE session_id = ?', [$id]);
    assert_same(81, mb_strlen($title, 'UTF-8'), 'Länge in Zeichen (80 + Auslassungszeichen)');
    assert_same(str_repeat('ä', 80) . '…', $title);
});

test_case('ein bereits gespeicherter Titel wird nicht überschrieben', function (): void {
    $id = test_db_session_id('titel-stabil');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'Erste Frage']], 42);
    saveConversationSession($id, 'modell', [
        ['role' => 'user', 'content' => 'Erste Frage'],
        ['role' => 'assistant', 'content' => 'Antwort'],
        ['role' => 'user', 'content' => 'Zweite Frage'],
    ], 42);

    assert_same(
        'Erste Frage',
        test_db_fetchValue('SELECT title FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('ohne Nutzer-ID bleibt der Titel leer', function (): void {
    $id = test_db_session_id('titel-anonym');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'Anonyme Frage']]);

    assert_same(
        '',
        test_db_fetchValue('SELECT title FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('nicht-textueller Nachrichteninhalt führt zu keinem Fehler', function (): void {
    $id = test_db_session_id('titel-array');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => ['nicht', 'text']]], 42);

    assert_same(
        '',
        test_db_fetchValue('SELECT title FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('eine anonyme Session bleibt ohne Nutzer-ID', function (): void {
    $id = test_db_session_id('anonym');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'hallo']]);

    assert_same(
        null,
        test_db_fetchValue('SELECT user_id FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('ein späteres Speichern verknüpft eine anonyme Session mit dem Nutzer', function (): void {
    $id = test_db_session_id('nachverknuepft');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'hallo']]);
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'hallo']], 7);

    assert_same(
        7,
        (int) test_db_fetchValue('SELECT user_id FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('die Eigentümerschaft einer verknüpften Session wird nicht auf einen anderen Nutzer umgeschrieben', function (): void {
    $id = test_db_session_id('eigentum-stabil');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'hallo']], 7);
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'hallo']], 9);

    assert_same(
        7,
        (int) test_db_fetchValue('SELECT user_id FROM conversation_sessions WHERE session_id = ?', [$id])
    );
});

test_case('loadUserConversationSession liefert die Session nur dem Eigentümer', function (): void {
    $id = test_db_session_id('eigentum');
    $messages = [['role' => 'user', 'content' => 'geheim']];
    saveConversationSession($id, 'modell', $messages, 7);

    $own = loadUserConversationSession($id, 7);
    assert_true(is_array($own), 'Eigentümer bekommt die Session');
    assert_same($messages, $own['messages'] ?? null);

    assert_same(null, loadUserConversationSession($id, 9), 'fremder Nutzer bekommt nichts');
});

test_case('loadUserConversationSession lehnt leere ID und ungültige Nutzer-ID ab', function (): void {
    $id = test_db_session_id('eigentum-guard');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'x']], 7);

    assert_same(null, loadUserConversationSession('', 7));
    assert_same(null, loadUserConversationSession($id, 0));
    assert_same(null, loadUserConversationSession($id, -1));
});

test_case('eine anonyme Session ist über loadUserConversationSession nicht erreichbar', function (): void {
    $id = test_db_session_id('eigentum-anonym');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'x']]);

    assert_same(null, loadUserConversationSession($id, 7));
});

test_case('ein akzeptiertes Upgrade-Modell ist sofort aktiv', function (): void {
    $id = test_db_session_id('upgrade');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'x']]);
    setSessionUpgradeModel($id, 'grosses-modell');

    assert_same('grosses-modell', getActiveSessionUpgradeModel($id));
});

test_case('ein Upgrade-Modell läuft nach 20 Minuten ab', function (): void {
    $id = test_db_session_id('upgrade-ablauf');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'x']]);
    setSessionUpgradeModel($id, 'grosses-modell');

    test_db_connect()->prepare(
        'UPDATE conversation_sessions SET upgrade_accepted_at = DATE_SUB(NOW(3), INTERVAL 19 MINUTE)
          WHERE session_id = ?'
    )->execute([$id]);
    assert_same('grosses-modell', getActiveSessionUpgradeModel($id), 'nach 19 Minuten noch aktiv');

    test_db_connect()->prepare(
        'UPDATE conversation_sessions SET upgrade_accepted_at = DATE_SUB(NOW(3), INTERVAL 21 MINUTE)
          WHERE session_id = ?'
    )->execute([$id]);
    assert_same(null, getActiveSessionUpgradeModel($id), 'nach 21 Minuten abgelaufen');
});

test_case('setSessionUpgradeModel ohne vorhandene Session wirkt nicht', function (): void {
    $id = test_db_session_id('upgrade-ohne-session');
    setSessionUpgradeModel($id, 'grosses-modell');

    assert_same(0, (int) test_db_fetchValue(
        'SELECT COUNT(*) FROM conversation_sessions WHERE session_id = ?',
        [$id]
    ));
    assert_same(null, getActiveSessionUpgradeModel($id));
});

test_case('setSessionUpgradeModel und getActiveSessionUpgradeModel ignorieren leere Argumente', function (): void {
    $id = test_db_session_id('upgrade-guard');
    saveConversationSession($id, 'modell', [['role' => 'user', 'content' => 'x']]);

    setSessionUpgradeModel('', 'grosses-modell');
    setSessionUpgradeModel($id, '');

    assert_same(null, getActiveSessionUpgradeModel($id));
    assert_same(null, getActiveSessionUpgradeModel(''));
});

test_case('purgeExpiredConversationSessions löscht nur alte anonyme Sessions', function (): void {
    $alt = test_db_session_id('purge-alt-anonym');
    $frisch = test_db_session_id('purge-frisch-anonym');
    $altNutzer = test_db_session_id('purge-alt-nutzer');

    saveConversationSession($alt, 'modell', [['role' => 'user', 'content' => 'alt']]);
    saveConversationSession($frisch, 'modell', [['role' => 'user', 'content' => 'frisch']]);
    saveConversationSession($altNutzer, 'modell', [['role' => 'user', 'content' => 'alt']], 7);

    $pdo = test_db_connect();
    $stmt = $pdo->prepare(
        'UPDATE conversation_sessions SET updated_at = DATE_SUB(NOW(), INTERVAL ? MINUTE) WHERE session_id = ?'
    );
    $stmt->execute([31, $alt]);
    $stmt->execute([29, $frisch]);
    $stmt->execute([31, $altNutzer]);

    purgeExpiredConversationSessions();

    assert_same(0, (int) test_db_fetchValue(
        'SELECT COUNT(*) FROM conversation_sessions WHERE session_id = ?',
        [$alt]
    ), 'alte anonyme Session wurde gelöscht');
    assert_same(1, (int) test_db_fetchValue(
        'SELECT COUNT(*) FROM conversation_sessions WHERE session_id = ?',
        [$frisch]
    ), 'junge anonyme Session bleibt erhalten');
    assert_same(1, (int) test_db_fetchValue(
        'SELECT COUNT(*) FROM conversation_sessions WHERE session_id = ?',
        [$altNutzer]
    ), 'alte Session eines angemeldeten Nutzers bleibt erhalten');
});

$code = test_summary('DB: Chat-Sessions');
test_db_cleanup();
exit($code);
