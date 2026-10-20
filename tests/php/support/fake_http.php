<?php
declare(strict_types=1);

/**
 * tests/php/support/fake_http.php
 *
 * Minimaler Fake-HTTP-Server für die Healthcheck-Tests
 * (tests/db/test_llm_healthcheck.php).
 *
 * Kein test_*.php-Skript, damit tests/run.sh es nicht als Testsuite einsammelt:
 * es ist ein Hilfsprogramm, das der Test als Kindprozess startet.
 *
 * Aufruf:
 *   php tests/php/support/fake_http.php <status> [antwort-body]
 *   php tests/php/support/fake_http.php stall <sekunden>
 *
 * Ablauf:
 *   1. bindet an 127.0.0.1 auf einem vom Betriebssystem vergebenen Port,
 *      schreibt die Portnummer als erste Zeile auf STDOUT und flusht,
 *   2. beantwortet jede eingehende Anfrage mit dem übergebenen Statuscode und
 *      Body (JSON, Connection: close); im Modus `stall` nimmt er die Anfrage
 *      an, wartet die angegebenen Sekunden und schließt dann ohne Antwort –
 *      so lässt sich eine Zeitüberschreitung nach aufgebauter Verbindung
 *      prüfen (das Bild eines rechnenden ImageInt-Hosts),
 *   3. beendet sich nach MAX_REQUESTS Antworten oder wenn ACCEPT_TIMEOUT
 *      Sekunden lang keine Verbindung mehr eingeht.
 *
 * Punkt 3 ist die Absicherung gegen hängende Kindprozesse: stirbt der Test,
 * endet der Server spätestens nach ACCEPT_TIMEOUT von selbst.
 */

const MAX_REQUESTS   = 20;
const ACCEPT_TIMEOUT = 10;

$mode   = (string) ($argv[1] ?? '200');
$stall  = 0;
if ($mode === 'stall') {
    $stall  = max(1, (int) ($argv[2] ?? 10));
    $status = 200;
    $body   = '';
} else {
    $status = (int) $mode;
    $body   = (string) ($argv[2] ?? '{"object":"list","data":[]}');
}

$reason = match ($status) {
    200 => 'OK',
    404 => 'Not Found',
    503 => 'Service Unavailable',
    default => 'Status',
};

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "fake_http: bind fehlgeschlagen: {$errstr} ({$errno})\n");
    exit(2);
}

$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

fwrite(STDOUT, $port . "\n");
fflush(STDOUT);

$response = "HTTP/1.1 {$status} {$reason}\r\n"
    . "Content-Type: application/json\r\n"
    . 'Content-Length: ' . strlen($body) . "\r\n"
    . "Connection: close\r\n"
    . "\r\n"
    . $body;

for ($served = 0; $served < MAX_REQUESTS; $served++) {
    $conn = @stream_socket_accept($server, ACCEPT_TIMEOUT);
    if ($conn === false) {
        break;
    }
    stream_set_timeout($conn, 5);

    // Kopfzeilen vollständig lesen, damit curl nicht in einen Sendefehler
    // läuft, weil wir die Verbindung zu früh schließen.
    while (($line = fgets($conn, 8192)) !== false) {
        if (rtrim($line, "\r\n") === '') {
            break;
        }
    }

    if ($stall > 0) {
        // Annehmen, schweigen, schließen: der Client läuft in seine
        // Zeitüberschreitung, obwohl die Verbindung stand.
        sleep($stall);
        fclose($conn);
        continue;
    }

    fwrite($conn, $response);
    fflush($conn);
    fclose($conn);
}

fclose($server);
exit(0);
