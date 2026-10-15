<?php
declare(strict_types=1);

/**
 * tests/db/bootstrap.php
 *
 * Gemeinsames Setup der datenbankgestützten Integrationstests in tests/db/.
 *
 * Anders als die reinen Helfer-Suiten in tests/php/ laufen diese Tests gegen
 * eine echte MySQL-Instanz, weil sie genau das prüfen, was sich ohne Datenbank
 * nicht prüfen lässt: Schema-Anlage, Upserts, Fensterlogik über Zeitstempel und
 * die Eigentums-/Ablaufregeln der Chat-Sessions.
 *
 * Verbindungsparameter kommen aus der Umgebung (DB_HOST, DB_PORT, DB_NAME,
 * DB_USER, DB_PASS). `tests/run.sh db` startet dafür einen Wegwerf-Container mit
 * mysql:8.0 und setzt die Variablen; ohne die Variablen werden die Werte der
 * docker-compose.yml als Vorgabe benutzt (127.0.0.1:3306, llmint/llmint).
 *
 * Fehlt die Datenbank, brechen die Suiten mit Exit-Code 1 und einer klaren
 * Meldung ab – ein nicht gelaufener Test darf niemals als bestanden gelten.
 */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../../db.php';

/** Präfix aller Session-IDs, die die Suiten anlegen. */
const TEST_DB_SESSION_PREFIX = 'test-db-';

/** Präfix aller Settings-Schlüssel, die die Suiten anlegen. */
const TEST_DB_SETTING_PREFIX = 'test-db-';

/** Kategorie, unter der die Suiten eigene Prompt-Security-Regeln anlegen. */
const TEST_DB_RULE_CATEGORY = 'test-db';

/** Alias-Präfix der Endpunkt-Attrappen. */
const TEST_DB_ENDPOINT_PREFIX = 'test-db-';

/** Absender-IP, unter der die Suiten Security-Logs schreiben. */
const TEST_DB_LOG_IP = '198.51.100.7';

/** Anzahl der Verbindungsversuche, bevor eine Suite aufgibt. */
const TEST_DB_CONNECT_ATTEMPTS = 15;

/**
 * Liefert eine Verbindung zur Testdatenbank und legt das Laufzeitschema an.
 *
 * Ein frischer MySQL-Container braucht einige Sekunden, bis er TCP-Verbindungen
 * annimmt; deshalb wird mehrfach versucht. Schlägt es dauerhaft fehl, beendet
 * sich der Prozess mit Exit-Code 1, damit ein fehlendes Backend nie als
 * erfolgreicher Testlauf durchgeht.
 */
function test_db_connect(): PDO
{
    $lastError = '';
    for ($attempt = 1; $attempt <= TEST_DB_CONNECT_ATTEMPTS; $attempt++) {
        try {
            return getDb();
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            sleep(1);
        }
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'llmint';
    fwrite(STDERR, "FEHLER: Keine Verbindung zur Testdatenbank {$host}:{$port}/{$name}.\n"
        . "        Letzter Fehler: {$lastError}\n"
        . "        Starten mit: ./tests/run.sh db\n");
    exit(1);
}

/**
 * Erzeugt einen eindeutigen Session-Schlüssel im Test-Namensraum.
 */
function test_db_session_id(string $suffix): string
{
    return TEST_DB_SESSION_PREFIX . $suffix;
}

/**
 * Erzeugt einen Settings-Schlüssel im Test-Namensraum.
 */
function test_db_setting_key(string $suffix): string
{
    return TEST_DB_SETTING_PREFIX . $suffix;
}

/**
 * Legt eine Endpunkt-Attrappe an, wie sie die Modell-/Gruppen-Auflösung braucht.
 *
 * @param string $alias        Eindeutiger Alias (wird mit Präfix versehen).
 * @param string $defaultModel Modellname, aus dem sich Gruppe und Score ableiten.
 * @param int    $isActive     1 = aktiv, 0 = deaktiviert.
 * @param string $baseUrl      Basis-URL; standardmäßig ein garantiert
 *                             unerreichbarer Port (für Healthcheck-Tests wird
 *                             hier die Adresse des Fake-Servers eingesetzt).
 * @return int Die ID der angelegten Zeile.
 */
function test_db_insert_endpoint(
    string $alias,
    string $defaultModel,
    int $isActive = 1,
    string $baseUrl = 'http://127.0.0.1:1/v1'
): int {
    $pdo = test_db_connect();
    $pdo->prepare(
        'INSERT INTO endpoints (alias, base_url, default_model, is_active, sort_order)
         VALUES (?, ?, ?, ?, 0)'
    )->execute([
        TEST_DB_ENDPOINT_PREFIX . $alias,
        $baseUrl,
        $defaultModel,
        $isActive,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Räumt ausschließlich die Testdaten auf.
 *
 * Bewusst kein TRUNCATE über die geteilten Tabellen: die von der Anwendung
 * angelegten Standardregeln und Einstellungen (z. B. die Prompt-Security-Regeln
 * aus ensureRuntimeSchema()) müssen erhalten bleiben. Gelöscht wird nur, was
 * über die Präfixe dieser Suiten erkennbar ist.
 */
function test_db_cleanup(): void
{
    $pdo = test_db_connect();

    $statements = [
        "DELETE FROM conversation_sessions WHERE session_id LIKE '" . TEST_DB_SESSION_PREFIX . "%'",
        "DELETE FROM prompt_security_rules WHERE category = '" . TEST_DB_RULE_CATEGORY . "'",
        "DELETE FROM prompt_security_logs WHERE ip_address = '" . TEST_DB_LOG_IP . "'",
        "DELETE FROM endpoints WHERE alias LIKE '" . TEST_DB_ENDPOINT_PREFIX . "%'",
        "DELETE FROM settings WHERE setting_key LIKE '" . TEST_DB_SETTING_PREFIX . "%'",
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Aufräumen ist Best-Effort – ein Fehler hier darf den Lauf nicht
            // verdecken, die eigentlichen Zusicherungen laufen vorher.
        }
    }
}

/**
 * Liest einen einzelnen Rohwert direkt aus einer Tabelle (ohne App-Logik).
 */
function test_db_fetchValue(string $sql, array $params = [])
{
    $stmt = test_db_connect()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}
