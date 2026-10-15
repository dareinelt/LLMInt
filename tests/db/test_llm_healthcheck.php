<?php
declare(strict_types=1);

/**
 * tests/db/test_llm_healthcheck.php
 *
 * Tests für lib/healthcheck.php: die aktive Erreichbarkeitsprüfung der
 * LLM-Endpunkte (probeLlmEndpoints()) und den kurzen Settings-Cache, über den
 * isAnyLlmEndpointHealthy() die Prüfergebnisse für nachfolgende Seitenaufrufe
 * teilt.
 *
 * Diese Suite braucht eine echte MySQL-Instanz, weil genau die datenbank-
 * gestützten Pfade geprüft werden: das Auslesen der aktiven Endpunkte, das
 * Schreiben und Lesen des Cache-Eintrags in `settings` sowie dessen Ablauf
 * nach LLM_HEALTHCHECK_CACHE_TTL_SECONDS.
 *
 * Für den Erfolgsfall (HTTP 2xx) startet der Test tests/php/support/fake_http.php
 * als Kindprozess: ohne einen echten Server ließe sich nur der Fehlerfall
 * prüfen. Die Prüfung selbst läuft ausschließlich gegen 127.0.0.1, es gehen
 * keine Anfragen nach außen. Den Erstaufbau des Laufzeitschemas prüft
 * tests/db/support/seed_probe.php in einem eigenen Prozess, weil
 * ensureRuntimeSchema() pro Prozess nur einmal greift.
 *
 * Erwartet eine Datenbank, die ausschließlich den Suiten dient (so wie sie der
 * Wegwerf-Container von `./tests/run.sh db` bereitstellt): die Suite leert die
 * `endpoints`-Tabelle, weil der Fall "keine Endpunkte" sonst nicht prüfbar wäre.
 * Grund dafür ist ensureRuntimeSchema(): beim ersten Aufbau legt sie genau
 * einmal einen Vorgabe-Endpunkt (http://localhost:1234/v1) an und merkt das über
 * die Einstellung endpoints_bootstrapped. Gegen eine bestehende
 * Entwicklungsdatenbank mit eigenen Endpunkten würde die Suite diese Zeilen
 * löschen.
 *
 * Bewusst nicht abgedeckt:
 *   - ein Timeout eines erreichbaren, aber nicht antwortenden Servers: der
 *     Fake-Server antwortet immer sofort, ein echter Hänger wäre ein
 *     Zeitverhaltenstest und damit unzuverlässig.
 *   - TLS-Endpunkte (https://): bräuchten einen Server mit Zertifikat; die
 *     Prüfung selbst unterscheidet nicht zwischen http und https.
 *   - die Nebenläufigkeit mehrerer gleichzeitiger Aufrufe (curl_multi läuft
 *     hier mit einem bis drei Handles).
 *
 * Run from the repository root:  ./tests/run.sh db
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/healthcheck.php';

/**
 * Startet tests/php/support/fake_http.php, führt $fn($port) aus und beendet den
 * Kindprozess anschließend wieder — auch wenn $fn eine Ausnahme wirft.
 *
 * @param int      $status HTTP-Statuscode, mit dem geantwortet wird.
 * @param callable $fn     function (int $port): void
 */
function withFakeHttp(int $status, callable $fn): void
{
    $proc  = null;
    $pipes = [];

    try {
        $command = [PHP_BINARY, __DIR__ . '/../php/support/fake_http.php', (string) $status];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Fake-HTTP-Server konnte nicht gestartet werden.');
        }

        stream_set_timeout($pipes[1], 10);
        $portLine = fgets($pipes[1]);
        if ($portLine === false) {
            throw new RuntimeException(
                'Fake-HTTP-Server hat keinen Port gemeldet: ' . trim((string) stream_get_contents($pipes[2]))
            );
        }
        $port = (int) trim($portLine);
        if ($port <= 0) {
            throw new RuntimeException("Fake-HTTP-Server meldete einen ungültigen Port: '{$portLine}'");
        }

        $fn($port);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            if ($status['running']) {
                proc_terminate($proc);
                $deadline = microtime(true) + 2.0;
                while ($status['running'] && microtime(true) < $deadline) {
                    usleep(20000);
                    $status = proc_get_status($proc);
                }
                if ($status['running']) {
                    proc_terminate($proc, 9);
                }
            }
            proc_close($proc);
        }
    }
}

/** Löscht die Endpunkt-Attrappen und den Healthcheck-Cache-Eintrag. */
function healthcheckReset(): void
{
    test_db_cleanup();

    // Bewusst die ganze Tabelle: ensureRuntimeSchema() hat beim ersten Aufbau
    // einen Vorgabe-Endpunkt angelegt, der sonst jede Prüfung "ohne Endpunkte"
    // verfälscht. Nur zulässig, weil diese Suite eine reine Testdatenbank
    // voraussetzt (siehe Kopfkommentar).
    test_db_connect()->exec('DELETE FROM endpoints');

    setSetting(LLM_HEALTHCHECK_CACHE_KEY, '');
}

/**
 * Beschränkt das Prüfergebnis auf die Endpunkte dieser Suite, damit die
 * Zusicherungen auch dann stimmen, wenn die Zieldatenbank weitere Endpunkte
 * enthält.
 *
 * @param array<int, array{id:int, alias:string, ok:bool}> $results
 * @param array<int, int>                                  $ids
 */
function healthcheckResultsFor(array $results, array $ids): array
{
    return array_values(array_filter(
        $results,
        static fn(array $row): bool => in_array($row['id'], $ids, true)
    ));
}

/** Liest den Healthcheck-Cache-Eintrag als Array (oder null). */
function healthcheckCache(): ?array
{
    $decoded = json_decode(getSetting(LLM_HEALTHCHECK_CACHE_KEY, ''), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Baut den Ausgangszustand einer Erstinstallation auf (keine Endpunkte, kein
 * Bootstrap-Vermerk) und lässt tests/db/support/seed_probe.php das Laufzeit-
 * schema in einem frischen Prozess aufbauen.
 *
 * @return array{endpoints?: list<array<string, mixed>>, flag?: string}
 */
function healthcheckSeedProbe(): array
{
    $pdo = test_db_connect();
    $pdo->exec('DELETE FROM endpoints');
    $pdo->exec("DELETE FROM settings WHERE setting_key = 'endpoints_bootstrapped'");
    // Die Vorgabe-URL wird aus lmstudio_base_url abgeleitet; der Eintrag wird
    // entfernt, damit die erwartete Seed-URL nicht von Resten abhängt.
    $pdo->exec("DELETE FROM settings WHERE setting_key = 'lmstudio_base_url'");

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/seed_probe.php');
    $output  = shell_exec($command);

    $decoded = json_decode(trim((string) $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('seed_probe.php lieferte kein JSON: ' . var_export($output, true));
    }
    return $decoded;
}

test_case('Ein frisch aufgebautes Laufzeitschema erhält genau einen Vorgabe-Endpunkt', function (): void {
    $probe = healthcheckSeedProbe();

    assert_same('1', (string) ($probe['flag'] ?? ''), 'der Aufbau wird über endpoints_bootstrapped vermerkt');
    assert_count(1, $probe['endpoints'] ?? [], 'genau ein Vorgabe-Endpunkt');
    assert_same('http://localhost:1234/v1', $probe['endpoints'][0]['base_url'] ?? null);
    assert_same(1, (int) ($probe['endpoints'][0]['is_active'] ?? 0), 'der Vorgabe-Endpunkt ist aktiv');
    assert_same(0, (int) ($probe['endpoints'][0]['sort_order'] ?? -1), 'der Vorgabe-Endpunkt steht auf sort_order 0');
});

healthcheckReset();

test_case('Ohne Endpunkte liefert probeLlmEndpoints() ein leeres Array', function (): void {
    assert_same([], probeLlmEndpoints(1));
});

test_case('Nur aktive Endpunkte werden geprüft', function (): void {
    $active   = test_db_insert_endpoint('health-active', 'gpt-oss-20b', 1);
    $inactive = test_db_insert_endpoint('health-inactive', 'gpt-oss-20b', 0);

    $results = healthcheckResultsFor(probeLlmEndpoints(1), [$active, $inactive]);

    assert_count(1, $results, 'nur der aktive Endpunkt darf geprüft werden');
    assert_same($active, $results[0]['id']);
    assert_same(TEST_DB_ENDPOINT_PREFIX . 'health-active', $results[0]['alias']);
    assert_false($results[0]['ok'], 'ein nicht erreichbarer Endpunkt gilt nicht als gesund');
});

test_case('Ein Ergebnis besteht aus id, alias und ok mit den erwarteten Typen', function (): void {
    $id      = test_db_insert_endpoint('health-types', 'gpt-oss-20b', 1);
    $results = healthcheckResultsFor(probeLlmEndpoints(1), [$id]);

    assert_count(1, $results);
    assert_keys(['id', 'alias', 'ok'], $results[0]);
    assert_true(is_int($results[0]['id']), 'id muss eine Zahl sein');
    assert_true(is_string($results[0]['alias']), 'alias muss eine Zeichenkette sein');
    assert_true(is_bool($results[0]['ok']), 'ok muss ein boolescher Wert sein');
});

test_case('Mehrere Endpunkte werden in der Reihenfolge der Abfrage geliefert', function (): void {
    $first  = test_db_insert_endpoint('health-order-1', 'gpt-oss-20b', 1);
    $second = test_db_insert_endpoint('health-order-2', 'qwen3-120b', 1);

    $ids = array_column(healthcheckResultsFor(probeLlmEndpoints(1), [$first, $second]), 'id');

    assert_same([$first, $second], $ids, 'sort_order ist bei allen Attrappen 0, also entscheidet die id');
});

test_case('Ein antwortender Endpunkt wird als gesund gemeldet', function (): void {
    healthcheckReset();

    withFakeHttp(200, function (int $port): void {
        $id      = test_db_insert_endpoint('health-ok', 'gpt-oss-20b', 1, "http://127.0.0.1:{$port}/v1");
        $results = healthcheckResultsFor(probeLlmEndpoints(1), [$id]);

        assert_count(1, $results);
        assert_true($results[0]['ok'], 'HTTP 200 muss als gesund gelten');
    });
});

test_case('Ein 503-Endpunkt gilt nicht als gesund', function (): void {
    healthcheckReset();

    withFakeHttp(503, function (int $port): void {
        $id      = test_db_insert_endpoint('health-503', 'gpt-oss-20b', 1, "http://127.0.0.1:{$port}/v1");
        $results = healthcheckResultsFor(probeLlmEndpoints(1), [$id]);

        assert_count(1, $results);
        assert_false($results[0]['ok'], 'HTTP 503 darf nicht als gesund gelten');
    });
});

test_case('isAnyLlmEndpointHealthy() ist false, wenn kein Endpunkt antwortet', function (): void {
    healthcheckReset();
    test_db_insert_endpoint('health-down', 'gpt-oss-20b', 1);

    assert_false(isAnyLlmEndpointHealthy());
});

test_case('isAnyLlmEndpointHealthy() ist true, sobald ein Endpunkt antwortet', function (): void {
    healthcheckReset();

    withFakeHttp(200, function (int $port): void {
        test_db_insert_endpoint('health-up', 'gpt-oss-20b', 1, "http://127.0.0.1:{$port}/v1");

        assert_true(isAnyLlmEndpointHealthy(), 'ein antwortender Endpunkt muss genügen');
    });
});

test_case('Das Prüfergebnis wird mit ts, available und endpoints zwischengespeichert', function (): void {
    $cache = healthcheckCache();

    assert_true(is_array($cache), 'der Cache-Eintrag muss existieren');
    assert_keys(['ts', 'available', 'endpoints'], $cache);
    assert_true(is_int($cache['ts']), 'ts muss eine Zahl sein');
    assert_close((float) time(), $cache['ts'], 5.0, 'ts muss der Zeitpunkt der Prüfung sein');
    assert_true($cache['available'], 'der letzte Lauf hatte einen gesunden Endpunkt');
    assert_true(is_array($cache['endpoints']), 'endpoints muss eine Liste sein');
    assert_true($cache['endpoints'] !== [], 'die geprüften Endpunkte müssen enthalten sein');
});

test_case('Ein frischer Cache-Eintrag wird ohne erneute Prüfung verwendet', function (): void {
    healthcheckReset();
    setSetting(LLM_HEALTHCHECK_CACHE_KEY, json_encode([
        'ts'        => time(),
        'available' => true,
        'endpoints' => [],
    ]));

    // Ohne Endpunkte wäre eine echte Prüfung false - true beweist den Cache.
    assert_true(isAnyLlmEndpointHealthy(), 'der frische Cache-Eintrag muss übernommen werden');
});

test_case('Auch ein frischer Cache-Eintrag mit available=false gewinnt', function (): void {
    healthcheckReset();

    withFakeHttp(200, function (int $port): void {
        test_db_insert_endpoint('health-cached-false', 'gpt-oss-20b', 1, "http://127.0.0.1:{$port}/v1");
        setSetting(LLM_HEALTHCHECK_CACHE_KEY, json_encode([
            'ts'        => time(),
            'available' => false,
            'endpoints' => [],
        ]));

        assert_false(isAnyLlmEndpointHealthy(), 'ein antwortender Endpunkt darf den frischen Cache nicht überstimmen');
    });
});

test_case('Ein abgelaufener Cache-Eintrag wird ignoriert und ersetzt', function (): void {
    healthcheckReset();
    $stale = time() - LLM_HEALTHCHECK_CACHE_TTL_SECONDS - 1;
    setSetting(LLM_HEALTHCHECK_CACHE_KEY, json_encode([
        'ts'        => $stale,
        'available' => true,
        'endpoints' => [],
    ]));

    // Der abgelaufene Eintrag behauptet "gesund"; ohne Endpunkte liefert die
    // echte Prüfung false und überschreibt ihn.
    assert_false(isAnyLlmEndpointHealthy(), 'der abgelaufene Eintrag darf nicht mehr gelten');

    $cache = healthcheckCache();
    assert_true(is_array($cache));
    assert_true($cache['ts'] > $stale, 'ts muss nach der Neuprüfung aktualisiert sein');
    assert_false($cache['available'], 'available muss das neue Ergebnis widerspiegeln');
});

test_case('Ein Cache-Eintrag gilt genau bis zum Ablauf der TTL', function (): void {
    healthcheckReset();
    setSetting(LLM_HEALTHCHECK_CACHE_KEY, json_encode([
        'ts'        => time() - (LLM_HEALTHCHECK_CACHE_TTL_SECONDS - 1),
        'available' => true,
        'endpoints' => [],
    ]));

    assert_true(isAnyLlmEndpointHealthy(), 'innerhalb der TTL muss der Eintrag gelten');
});

test_case('Unbrauchbare Cache-Werte werden ignoriert', function (): void {
    $broken = [
        'kein-json',
        '',
        '[]',
        'null',
        '"available"',
        '{"available":true}',
        '{"ts":1,"available":true}',
        '{"ts":"abc","available":true}',
    ];

    foreach ($broken as $value) {
        healthcheckReset();
        setSetting(LLM_HEALTHCHECK_CACHE_KEY, $value);

        assert_false(
            isAnyLlmEndpointHealthy(),
            "Cache-Wert '{$value}' darf nicht als frisches Ergebnis gelten"
        );
    }
});

test_case('Nach der Prüfung wird der Cache erneut geschrieben', function (): void {
    healthcheckReset();
    setSetting(LLM_HEALTHCHECK_CACHE_KEY, 'kein-json');

    assert_false(isAnyLlmEndpointHealthy());

    $cache = healthcheckCache();
    assert_true(is_array($cache), 'die Prüfung muss einen gültigen Eintrag hinterlassen');
    assert_keys(['ts', 'available', 'endpoints'], $cache);
    assert_close((float) time(), $cache['ts'], 5.0);
});

healthcheckReset();

exit(test_summary('LLM-Healthcheck'));
