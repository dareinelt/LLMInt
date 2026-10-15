<?php
declare(strict_types=1);

/**
 * tests/db/test_intelligence_groups.php
 *
 * Integrationstests für die Intelligenzgruppen der Chat-Sessions
 * (listIntelligenceGroups(), resolveIntelligenceGroupModel(),
 * setSessionIntelligenceGroup(), clearSessionIntelligenceGroup(),
 * getSessionIntelligenceGroup()).
 *
 * Die Gruppen werden nicht gespeichert konfiguriert, sondern aus den Modellen
 * der aktiven Endpunkte abgeleitet. Deshalb braucht diese Suite echte
 * endpoints-Zeilen: nur so lässt sich prüfen, dass die Ableitung, das Anlegen
 * der Session-Zeile beim ersten Aufruf und die Neuauflösung nach einem
 * Modellwechsel tatsächlich funktionieren.
 *
 * Bewusst NICHT geprüft: modelIntelligenceScore()/intelligenceGroupLabel() als
 * reine Namensparser – das ist ohne Datenbank in tests/php/ abgedeckt.
 *
 * Voraussetzung: erreichbare MySQL-Instanz, siehe tests/db/bootstrap.php.
 */

require __DIR__ . '/bootstrap.php';

test_db_cleanup();

test_case('die Gruppen werden aus den Modellen der aktiven Endpunkte abgeleitet', function (): void {
    test_db_insert_endpoint('klein', 'gpt-oss-20b');
    test_db_insert_endpoint('gross', 'qwen3-120b');

    $groups = listIntelligenceGroups();

    assert_key('20b', $groups);
    assert_key('120b', $groups);
    assert_same('gpt-oss-20b', $groups['20b']);
    assert_same('qwen3-120b', $groups['120b']);
});

test_case('die Gruppen sind nach Intelligenz absteigend sortiert', function (): void {
    test_db_insert_endpoint('sort-klein', 'gpt-oss-20b');
    test_db_insert_endpoint('sort-gross', 'qwen3-120b');

    $labels = array_keys(listIntelligenceGroups());

    assert_true(
        array_search('120b', $labels, true) < array_search('20b', $labels, true),
        '120b steht vor 20b: ' . test_describe($labels)
    );
});

test_case('deaktivierte Endpunkte liefern keine Gruppe', function (): void {
    test_db_insert_endpoint('inaktiv', 'gpt-oss-999b', 0);

    assert_same(null, resolveIntelligenceGroupModel('999b'));
});

test_case('Modelle ohne Parameterangabe liefern keine Gruppe', function (): void {
    test_db_insert_endpoint('ohne-groesse', 'llama-3');

    $groups = listIntelligenceGroups();
    assert_false(isset($groups['3b']), 'llama-3 erzeugt keine 3b-Gruppe: ' . test_describe(array_keys($groups)));
});

test_case('resolveIntelligenceGroupModel normalisiert die Schreibweise', function (): void {
    test_db_insert_endpoint('normalisierung', 'gpt-oss-20b');

    assert_same('gpt-oss-20b', resolveIntelligenceGroupModel('20b'));
    assert_same('gpt-oss-20b', resolveIntelligenceGroupModel('20B'));
    assert_same('gpt-oss-20b', resolveIntelligenceGroupModel(' 20 '));
});

test_case('resolveIntelligenceGroupModel liefert null für unbekannte oder leere Angaben', function (): void {
    test_db_insert_endpoint('bekannt', 'gpt-oss-20b');

    assert_same(null, resolveIntelligenceGroupModel('7b'));
    assert_same(null, resolveIntelligenceGroupModel(''));
    assert_same(null, resolveIntelligenceGroupModel('ohne-zahl'));
    assert_same(null, resolveIntelligenceGroupModel('0'));
});

test_case('setSessionIntelligenceGroup legt die Session-Zeile beim ersten Aufruf an', function (): void {
    $id = test_db_session_id('gruppe-neu');
    setSessionIntelligenceGroup($id, '20b', 'gpt-oss-20b');

    $row = test_db_connect()->prepare(
        'SELECT model, messages, group_label, group_model FROM conversation_sessions WHERE session_id = ?'
    );
    $row->execute([$id]);
    $data = $row->fetch();

    assert_true(is_array($data), 'Session-Zeile existiert');
    assert_same('20b', $data['group_label'] ?? null);
    assert_same('gpt-oss-20b', $data['group_model'] ?? null);
    assert_same('[]', $data['messages'] ?? null, 'leerer Verlauf für den ersten Aufruf');
});

test_case('eine gesetzte Gruppe wird gelesen und wieder entfernt', function (): void {
    test_db_insert_endpoint('gruppe', 'gpt-oss-20b');
    $id = test_db_session_id('gruppe-roundtrip');

    setSessionIntelligenceGroup($id, '20b', 'gpt-oss-20b');
    assert_same(['label' => '20b', 'model' => 'gpt-oss-20b'], getSessionIntelligenceGroup($id));

    clearSessionIntelligenceGroup($id);
    assert_same(null, getSessionIntelligenceGroup($id));
});

test_case('das Entfernen der Gruppe lässt den Verlauf unangetastet', function (): void {
    test_db_insert_endpoint('gruppe-verlauf', 'gpt-oss-20b');
    $id = test_db_session_id('gruppe-verlauf');
    $messages = [['role' => 'user', 'content' => 'hallo']];

    saveConversationSession($id, 'gpt-oss-20b', $messages);
    setSessionIntelligenceGroup($id, '20b', 'gpt-oss-20b');
    clearSessionIntelligenceGroup($id);

    assert_same($messages, loadConversationSession($id)['messages'] ?? null);
});

test_case('die Gruppe überlebt ein weiteres Speichern des Verlaufs', function (): void {
    test_db_insert_endpoint('gruppe-stabil', 'gpt-oss-20b');
    $id = test_db_session_id('gruppe-stabil');

    setSessionIntelligenceGroup($id, '20b', 'gpt-oss-20b');
    saveConversationSession($id, 'gpt-oss-20b', [['role' => 'user', 'content' => 'hallo']]);

    assert_same(['label' => '20b', 'model' => 'gpt-oss-20b'], getSessionIntelligenceGroup($id));
});

test_case('ein zwischenzeitlich entferntes Modell wird über die Gruppe neu aufgelöst', function (): void {
    test_db_insert_endpoint('gruppe-neuaufloesung', 'gpt-oss-20b');
    $id = test_db_session_id('gruppe-neuaufloesung');

    setSessionIntelligenceGroup($id, '20b', 'gpt-oss-20b');
    test_db_connect()->prepare(
        "UPDATE conversation_sessions SET group_model = 'verschwundenes-modell' WHERE session_id = ?"
    )->execute([$id]);

    assert_same(['label' => '20b', 'model' => 'gpt-oss-20b'], getSessionIntelligenceGroup($id));
});

test_case('verschwindet die Gruppe selbst, wird null geliefert', function (): void {
    test_db_insert_endpoint('gruppe-verschwindet', 'gpt-oss-20b');
    $id = test_db_session_id('gruppe-verschwindet');

    setSessionIntelligenceGroup($id, '20b', 'gpt-oss-20b');
    test_db_connect()->exec(
        "UPDATE endpoints SET is_active = 0 WHERE alias LIKE '" . TEST_DB_ENDPOINT_PREFIX . "%'"
    );

    assert_same(null, getSessionIntelligenceGroup($id));
});

test_case('getSessionIntelligenceGroup liefert null ohne Gruppe und bei leerer ID', function (): void {
    test_db_insert_endpoint('gruppe-leer', 'gpt-oss-20b');
    $id = test_db_session_id('gruppe-leer');
    saveConversationSession($id, 'gpt-oss-20b', [['role' => 'user', 'content' => 'hallo']]);

    assert_same(null, getSessionIntelligenceGroup($id), 'Session ohne Gruppe');
    assert_same(null, getSessionIntelligenceGroup(''), 'leere Session-ID');
    assert_same(null, getSessionIntelligenceGroup(test_db_session_id('gibt-es-nicht')));
});

test_case('setSessionIntelligenceGroup und clearSessionIntelligenceGroup ignorieren leere IDs', function (): void {
    $before = (int) test_db_fetchValue('SELECT COUNT(*) FROM conversation_sessions');

    setSessionIntelligenceGroup('', '20b', 'gpt-oss-20b');
    clearSessionIntelligenceGroup('');

    assert_same(
        $before,
        (int) test_db_fetchValue('SELECT COUNT(*) FROM conversation_sessions'),
        'leere Session-ID legt keine Zeile an'
    );
});

$code = test_summary('DB: Intelligenzgruppen');
test_db_cleanup();
exit($code);
