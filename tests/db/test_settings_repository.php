<?php
declare(strict_types=1);

/**
 * tests/db/test_settings_repository.php
 *
 * Integrationstests für den Settings-Store aus db.php (getSetting/setSetting).
 *
 * Geprüft wird das Verhalten, das nur mit echter Datenbank sichtbar ist:
 * Schema-Anlage beim ersten Verbindungsaufbau, INSERT … ON DUPLICATE KEY UPDATE
 * als echter Upsert (inklusive eindeutigem Schlüssel statt Duplikaten) und die
 * Unterscheidung zwischen "Schlüssel fehlt" und "Schlüssel ist leer".
 *
 * Bewusst NICHT geprüft: das Verhalten bei nicht erreichbarer Datenbank
 * (getSetting fängt PDOException ab und liefert den Default) – das ist der
 * Zustand "keine DB" und wird in tests/php/ abgedeckt, nicht hier.
 *
 * Voraussetzung: erreichbare MySQL-Instanz, siehe tests/db/bootstrap.php.
 */

require __DIR__ . '/bootstrap.php';

test_db_cleanup();

test_case('getSetting liefert den Default für einen unbekannten Schlüssel', function (): void {
    assert_same('vorgabe', getSetting(test_db_setting_key('fehlt'), 'vorgabe'));
});

test_case('getSetting liefert den Default ohne zweiten Parameter als leerer String', function (): void {
    assert_same('', getSetting(test_db_setting_key('fehlt-ohne-default')));
});

test_case('setSetting legt einen neuen Schlüssel an und getSetting liest ihn zurück', function (): void {
    $key = test_db_setting_key('roundtrip');
    setSetting($key, 'erster-wert');
    assert_same('erster-wert', getSetting($key, 'ignoriert'));
});

test_case('setSetting überschreibt einen vorhandenen Wert (Upsert)', function (): void {
    $key = test_db_setting_key('upsert');
    setSetting($key, 'alt');
    setSetting($key, 'neu');
    assert_same('neu', getSetting($key, 'ignoriert'));
});

test_case('setSetting legt dank eindeutigem Schlüssel keine Duplikatzeilen an', function (): void {
    $key = test_db_setting_key('eindeutig');
    setSetting($key, 'a');
    setSetting($key, 'b');
    setSetting($key, 'c');

    $count = test_db_fetchValue(
        'SELECT COUNT(*) FROM settings WHERE setting_key = ?',
        [$key]
    );
    assert_same(1, (int) $count, 'settings-Einträge für einen Schlüssel');
});

test_case('ein gespeicherter leerer Wert gewinnt gegen den Default', function (): void {
    $key = test_db_setting_key('leer');
    setSetting($key, '');
    assert_same('', getSetting($key, 'default-darf-nicht-kommen'));
});

test_case('ein NULL-Wert in der Tabelle fällt auf den Default zurück', function (): void {
    $key = test_db_setting_key('null');
    test_db_connect()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, NULL)')
        ->execute([$key]);
    assert_same('default-bei-null', getSetting($key, 'default-bei-null'));
});

test_case('Sonderzeichen, Zeilenumbrüche und Emoji überleben den Roundtrip', function (): void {
    $key = test_db_setting_key('unicode');
    $value = "Zeile 1\nZeile 2\t\"Zitat\" \\ 'Apostroph' – Ümlaut ✓ 🚀";
    setSetting($key, $value);
    assert_same($value, getSetting($key, ''));
});

test_case('ein JSON-Wert bleibt unverändert lesbar', function (): void {
    $key = test_db_setting_key('json');
    $value = json_encode(['ts' => 1700000000, 'available' => false, 'endpoints' => [['id' => 3]]]);
    setSetting($key, (string) $value);
    $back = json_decode(getSetting($key, ''), true);
    assert_true(is_array($back), 'Wert ist wieder dekodierbar');
    assert_same(1700000000, $back['ts'] ?? null);
    assert_same(false, $back['available'] ?? null);
});

test_case('lange Werte (TEXT-Spalte) werden vollständig gespeichert', function (): void {
    $key = test_db_setting_key('lang');
    $value = str_repeat('ä', 5000);
    setSetting($key, $value);
    $back = getSetting($key, '');
    assert_same(5000, mb_strlen($back, 'UTF-8'), 'Länge des Rückgabewerts');
    assert_same($value, $back);
});

test_case('numerische Werte bleiben unverändert (keine Typkonvertierung)', function (): void {
    $key = test_db_setting_key('numerisch');
    setSetting($key, '007');
    assert_same('007', getSetting($key, ''));
});

test_case('unterschiedliche Schlüssel kollidieren nicht', function (): void {
    $a = test_db_setting_key('kollision-a');
    $b = test_db_setting_key('kollision-b');
    setSetting($a, 'wert-a');
    setSetting($b, 'wert-b');
    assert_same('wert-a', getSetting($a, ''));
    assert_same('wert-b', getSetting($b, ''));
});

$code = test_summary('DB: Einstellungen');
test_db_cleanup();
exit($code);
