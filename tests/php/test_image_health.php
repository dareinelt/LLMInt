<?php
declare(strict_types=1);

/**
 * tests/php/test_image_health.php
 *
 * Unit tests for the tolerant ImageInt component/progress normalisation that
 * feeds the download bars in the admin area. ImageInt reports a `progress`
 * block while a model downloads; the important guarantees are that the parser
 * degrades to an indeterminate bar instead of failing, that it produces a
 * percentage from bytes, files or a percentage, and that it carries the
 * transfer rate (`bytes_per_second`) through for the MB/s display.
 *
 * Deliberately not covered here (they need a live endpoint): imageIntHealth()
 * and imageIntModels(). imageIntReady() is covered only for the transport
 * contract, against a local fake server: a host that accepts the connection but
 * stays silent is `busy` (it is rendering), a refused port stays `unreachable`.
 *
 * Run from the repository root:  php tests/php/test_image_health.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/image_generation.php';

// ── Fake HTTP server ─────────────────────────────────────────────────────────

/**
 * Startet tests/php/support/fake_http.php mit $args, führt $fn($port) aus und
 * beendet den Kindprozess wieder – auch wenn $fn eine Ausnahme wirft.
 *
 * @param array<int,string> $args Zusätzliche Argumente (Status, Body oder `stall`, Sekunden).
 * @param callable          $fn   function (int $port): void
 */
function withFakeHttp(array $args, callable $fn): void
{
    $proc  = null;
    $pipes = [];

    try {
        $command = array_merge([PHP_BINARY, __DIR__ . '/support/fake_http.php'], $args);
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Fake-HTTP-Server konnte nicht gestartet werden.');
        }

        stream_set_timeout($pipes[1], 10);
        $portLine = fgets($pipes[1]);
        if ($portLine === false) {
            throw new RuntimeException('Fake-HTTP-Server hat keinen Port gemeldet: '
                . trim((string) stream_get_contents($pipes[2])));
        }
        $port = (int) trim($portLine);
        if ($port <= 0) {
            throw new RuntimeException('Fake-HTTP-Server meldete einen ungültigen Port.');
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

/** Ein Port, auf dem garantiert nichts lauscht (Verbindung wird abgelehnt). */
function freeTcpPort(): int
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException('Kein freier Port gefunden: ' . $errstr);
    }
    $name = (string) stream_socket_get_name($server, false);
    fclose($server);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

// ── imageIntNumeric() ─────────────────────────────────────────────────────────

test_case('imageIntNumeric() akzeptiert Zahlen, Komma und Prozentzeichen', function (): void {
    assert_same(42.0, imageIntNumeric(42));
    assert_same(42.5, imageIntNumeric(42.5));
    assert_same(42.5, imageIntNumeric('42,5'));
    assert_same(42.5, imageIntNumeric('42,5 %'));
    assert_same(42.5, imageIntNumeric(' 42.5% '));
    assert_same(1234.5, imageIntNumeric("1 234,5"));
    assert_same(1234.5, imageIntNumeric("1\u{00A0}234,5"));
    assert_same(1234.5, imageIntNumeric("1\u{202F}234,5"));
});

test_case('imageIntNumeric() liefert null für alles Unbrauchbare', function (): void {
    assert_same(null, imageIntNumeric(null));
    assert_same(null, imageIntNumeric(''));
    assert_same(null, imageIntNumeric('gleich fertig'));
    assert_same(null, imageIntNumeric(true));
    assert_same(null, imageIntNumeric([]));
    assert_same(null, imageIntNumeric(NAN));
});

// ── imageIntStateLabel() ──────────────────────────────────────────────────────

test_case('imageIntStateLabel() übersetzt die ImageInt-Zustände', function (): void {
    assert_same('bereit', imageIntStateLabel('ready'));
    assert_same('lädt', imageIntStateLabel('LOADING'));
    assert_same('startet', imageIntStateLabel('starting'));
    assert_same('untätig', imageIntStateLabel('idle'));
    assert_same('Fehler', imageIntStateLabel('error'));
    assert_same('nicht erreichbar', imageIntStateLabel('unreachable'));
    assert_same('nicht konfiguriert', imageIntStateLabel('unconfigured'));
});

test_case('imageIntStateLabel() fällt auf den Servertext oder den Rohwert zurück', function (): void {
    assert_same('Servertext', imageIntStateLabel('mystery', 'Servertext'));
    assert_same('mystery', imageIntStateLabel('mystery'));
    assert_same('unbekannt', imageIntStateLabel(''));
});

// ── imageIntComponentLabel() ──────────────────────────────────────────────────

test_case('imageIntComponentLabel() bevorzugt das Label des Servers', function (): void {
    assert_same('Eigener Name', imageIntComponentLabel('enhancer', ['label' => 'Eigener Name']));
    assert_same('Prompt-Enhancer', imageIntComponentLabel('enhancer'));
    assert_same('Bildmodell', imageIntComponentLabel('image'));
    assert_same('Bildmodell', imageIntComponentLabel('renderer'));
    assert_same('vae', imageIntComponentLabel('vae'));
    assert_same('Komponente', imageIntComponentLabel(''));
});

// ── imageIntComponentProgress() ───────────────────────────────────────────────

test_case('imageIntComponentProgress() bleibt ohne Zahlen unbestimmt', function (): void {
    $progress = imageIntComponentProgress(['state' => 'loading', 'since_seconds' => 12]);

    assert_same(false, $progress['known']);
    assert_same(null, $progress['percent']);
    assert_same(null, $progress['bytes_downloaded']);
    assert_same(null, $progress['bytes_total']);
    assert_same(false, $progress['bytes_inconsistent']);
    assert_same(null, $progress['files_downloaded']);
    assert_same(null, $progress['files_total']);
    assert_same('', $progress['current_file']);
    assert_same(null, $progress['eta_seconds']);
    assert_same(null, $progress['rate_bytes_per_second']);
});

test_case('imageIntComponentProgress() setzt eine bereite Komponente auf 100 %', function (): void {
    $ready = imageIntComponentProgress(['state' => 'ready']);
    assert_same(true, $ready['known']);
    assert_same(100.0, $ready['percent']);

    $flagged = imageIntComponentProgress(['ready' => true, 'state' => 'starting']);
    assert_same(100.0, $flagged['percent'], 'ready=true schlägt den Zustand');
});

test_case('imageIntComponentProgress() übernimmt ein geliefertes Prozent', function (): void {
    assert_close(42.5, imageIntComponentProgress(['progress' => ['percent' => '42,5 %']])['percent']);
    assert_close(7.0, imageIntComponentProgress(['percent' => 7])['percent']);
    assert_close(33.0, imageIntComponentProgress(['download_percent' => '33'])['percent']);
});

test_case('imageIntComponentProgress() rechnet Bytes in Prozent um', function (): void {
    $progress = imageIntComponentProgress([
        'progress' => ['bytes_downloaded' => 250, 'bytes_total' => 1000],
    ]);

    assert_same(true, $progress['known']);
    assert_close(25.0, $progress['percent']);
    assert_same(250, $progress['bytes_downloaded']);
    assert_same(1000, $progress['bytes_total']);
});

test_case('imageIntComponentProgress() rechnet Dateien in Prozent um', function (): void {
    $progress = imageIntComponentProgress([
        'download' => ['files_downloaded' => 3, 'files_total' => 4, 'current_file' => 'model-00003.safetensors'],
    ]);

    assert_close(75.0, $progress['percent']);
    assert_same(3, $progress['files_downloaded']);
    assert_same(4, $progress['files_total']);
    assert_same('model-00003.safetensors', $progress['current_file']);
});

test_case('imageIntComponentProgress() liest auch flache Felder und die ETA', function (): void {
    $progress = imageIntComponentProgress([
        'state'            => 'loading',
        'bytes_downloaded' => '1 GB',
        'bytes_total'      => 4 * 1024 * 1024 * 1024,
        'eta_seconds'      => '90',
    ]);

    // "1 GB" ist keine reine Zahl und wird ignoriert, die Bytes bleiben also offen.
    assert_same(false, $progress['known']);
    assert_same(4 * 1024 * 1024 * 1024, $progress['bytes_total']);
    assert_same(90, $progress['eta_seconds']);

    $flat = imageIntComponentProgress(['bytes_downloaded' => 512, 'bytes_total' => 2048]);
    assert_close(25.0, $flat['percent']);
});

test_case('imageIntComponentProgress() übernimmt die Übertragungsrate', function (): void {
    $progress = imageIntComponentProgress([
        'progress' => [
            'bytes_downloaded'  => 1048576,
            'bytes_total'       => 4194304,
            'bytes_per_second'  => 524288.5,
        ],
    ]);

    assert_close(25.0, $progress['percent']);
    assert_close(524288.5, $progress['rate_bytes_per_second']);

    // Die Rate ist unabhängig von einer bekannten Gesamtgröße.
    $withoutTotal = imageIntComponentProgress([
        'progress' => ['bytes_downloaded' => 10, 'bytes_per_second' => 2048],
    ]);
    assert_same(false, $withoutTotal['known']);
    assert_close(2048.0, $withoutTotal['rate_bytes_per_second']);
});

test_case('imageIntComponentProgress() liest die Rate auch flach und über Aliase', function (): void {
    assert_close(1024.0, imageIntComponentProgress(['bytes_per_second' => 1024])['rate_bytes_per_second']);
    assert_close(1024.0, imageIntComponentProgress(['rate_bytes_per_second' => '1024'])['rate_bytes_per_second']);
    assert_close(1024.0, imageIntComponentProgress(['speed_bytes_per_second' => 1024.0])['rate_bytes_per_second']);
    assert_close(1024.0, imageIntComponentProgress(['download' => ['bytes_per_second' => 1024]])['rate_bytes_per_second']);
});

test_case('imageIntComponentProgress() verwirft eine unbrauchbare Rate und klemmt sie bei 0', function (): void {
    assert_same(null, imageIntComponentProgress(['bytes_per_second' => 'schnell'])['rate_bytes_per_second']);
    assert_same(null, imageIntComponentProgress(['bytes_per_second' => null])['rate_bytes_per_second']);
    assert_same(0.0, imageIntComponentProgress(['bytes_per_second' => -5])['rate_bytes_per_second']);
});

test_case('imageIntComponentProgress() begrenzt Prozentwerte auf 0 bis 100', function (): void {
    assert_same(100.0, imageIntComponentProgress(['progress' => ['percent' => 150]])['percent']);
    assert_same(0.0, imageIntComponentProgress(['progress' => ['percent' => -5]])['percent']);
});

test_case('imageIntComponentProgress() nutzt bei mehr Bytes als Gesamtgröße die Dateizahl', function (): void {
    $progress = imageIntComponentProgress([
        'state'    => 'loading',
        'progress' => [
            'percent'          => 100.0,
            'bytes_downloaded' => 24000,
            'bytes_total'      => 18000,
            'files_downloaded' => 5,
            'files_total'      => 15,
        ],
    ]);

    assert_same(true, $progress['bytes_inconsistent']);
    assert_close(33.3333, $progress['percent'], 0.01);
    assert_same(24000, $progress['bytes_downloaded'], 'Der Rohwert bleibt für die Anzeige erhalten');
    assert_same(18000, $progress['bytes_total']);
    assert_same(5, $progress['files_downloaded']);
});

test_case('imageIntComponentProgress() bleibt ohne Dateizahl unbestimmt, wenn die Bytes nicht passen', function (): void {
    $progress = imageIntComponentProgress([
        'progress' => [
            'percent'          => 100,
            'bytes_downloaded' => 24000,
            'bytes_total'      => 18000,
        ],
    ]);

    assert_same(true, $progress['bytes_inconsistent']);
    assert_same(false, $progress['known']);
    assert_same(null, $progress['percent'], '100 % aus dem unbrauchbaren Byte-Verhältnis wird verworfen');
});

test_case('imageIntComponentProgress() klemmt mehr Dateien als Dateien gesamt', function (): void {
    $progress = imageIntComponentProgress(['files_downloaded' => 18, 'files_total' => 15]);

    assert_same(15, $progress['files_downloaded']);
    assert_close(100.0, $progress['percent']);
});

test_case('imageIntComponentProgress() ignoriert eine Division durch null', function (): void {
    $progress = imageIntComponentProgress(['bytes_downloaded' => 10, 'bytes_total' => 0]);
    assert_same(false, $progress['known']);
    assert_same(null, $progress['percent']);
});

test_case('imageIntComponentProgress() verschachteltes Prozent hat Vorrang vor Bytes', function (): void {
    $progress = imageIntComponentProgress([
        'percent'  => 99,
        'progress' => ['percent' => 10, 'bytes_downloaded' => 1, 'bytes_total' => 10],
    ]);

    assert_close(10.0, $progress['percent']);
});

// ── imageIntParseComponents() ─────────────────────────────────────────────────

test_case('imageIntParseComponents() normalisiert den components-Block', function (): void {
    $components = imageIntParseComponents([
        'components' => [
            'enhancer' => [
                'label'         => 'Prompt-Enhancer',
                'state'         => 'READY',
                'ready'         => true,
                'model'         => 'Qwen3-4B',
                'since_seconds' => 12,
                'load_seconds'  => '3,5',
                'loaded_at'     => '2024-05-01T10:00:00Z',
            ],
            'image' => [
                'state'     => 'loading',
                'transient' => true,
                'message'   => 'lädt Gewichte',
                'progress'  => ['percent' => 40],
            ],
        ],
    ]);

    assert_count(2, $components);
    assert_keys(['enhancer', 'image'], $components);

    $enhancer = $components['enhancer'];
    assert_same('enhancer', $enhancer['component']);
    assert_same('Prompt-Enhancer', $enhancer['label']);
    assert_same('ready', $enhancer['state'], 'Zustand wird kleingeschrieben');
    assert_same('bereit', $enhancer['state_label']);
    assert_same(true, $enhancer['ok']);
    assert_same(false, $enhancer['transient']);
    assert_same('Qwen3-4B', $enhancer['model']);
    assert_same(12.0, $enhancer['since_seconds']);
    assert_same(3.5, $enhancer['load_seconds']);
    assert_same('2024-05-01T10:00:00Z', $enhancer['loaded_at']);
    assert_same(100.0, $enhancer['progress']['percent'], 'ready wird zu 100 %');

    $image = $components['image'];
    assert_same('Bildmodell', $image['label']);
    assert_same('lädt', $image['state_label']);
    assert_same(false, $image['ok']);
    assert_same(true, $image['transient']);
    assert_same('lädt Gewichte', $image['message']);
    assert_close(40.0, $image['progress']['percent']);
});

test_case('imageIntParseComponents() versteht die flachen enhancer-/renderer-Blöcke', function (): void {
    $components = imageIntParseComponents([
        'enhancer' => ['state' => 'loading', 'since_seconds' => 5],
        'renderer' => ['state' => 'ready', 'ready' => true],
    ]);

    assert_count(2, $components);
    assert_same('Prompt-Enhancer', $components['enhancer']['label']);
    assert_same(true, $components['enhancer']['transient'], 'loading gilt als transient');
    assert_same('Bildmodell', $components['renderer']['label']);
    assert_same('bereit', $components['renderer']['state_label']);
});

test_case('imageIntParseComponents() lässt den ersten Treffer eines Keys gewinnen', function (): void {
    $components = imageIntParseComponents([
        'components' => ['image' => ['state' => 'ready', 'ready' => true]],
        'models'     => ['image' => ['state' => 'error']],
    ]);

    assert_count(1, $components);
    assert_same('bereit', $components['image']['state_label']);
});

test_case('imageIntParseComponents() ignoriert Nicht-Arrays und liefert bei leerer Antwort []', function (): void {
    assert_same([], imageIntParseComponents([]));
    assert_same([], imageIntParseComponents(['components' => 'ready']));

    $components = imageIntParseComponents([
        'components' => ['image' => 'ready', 'enhancer' => ['state' => 'idle']],
    ]);

    assert_count(1, $components);
    assert_same('untätig', $components['enhancer']['state_label']);
});

test_case('imageIntParseComponents() übernimmt ein eigenes state_label', function (): void {
    $components = imageIntParseComponents([
        'components' => ['image' => ['state' => 'downloading', 'state_label' => 'wird geladen']],
    ]);

    assert_same('wird geladen', $components['image']['state_label']);
    assert_same(false, $components['image']['transient'], 'unbekannter Zustand ist nicht transient');
});

// ── Transport: "beschäftigt" ist nicht "nicht erreichbar" ─────────────────────

test_case('imageIntReady() meldet einen stillen Host als beschäftigt, nicht als nicht erreichbar', function (): void {
    // Der Fake-Server nimmt die Verbindung an, antwortet aber nicht: genau das
    // Bild, das ein rechnender ImageInt-Host bietet.
    withFakeHttp(['stall', '8'], function (int $port): void {
        $health = imageIntReady('http://127.0.0.1:' . $port, '', ['timeout' => 5, 'connect_timeout' => 3]);

        assert_same(true, $health['busy'], 'Zeitüberschreitung nach aufgebauter Verbindung');
        assert_same(true, $health['reachable'], 'der Host hat die Verbindung angenommen');
        assert_same(false, $health['ready']);
        assert_same(false, $health['loading']);
        assert_same('timeout', $health['error']);
        assert_contains('rechnet vermutlich noch', $health['message']);
        assert_not_contains('nicht erreichbar', $health['message']);
    });
});

test_case('imageIntReady() meldet einen abgelehnten Port weiterhin als nicht erreichbar', function (): void {
    $health = imageIntReady('http://127.0.0.1:' . freeTcpPort(), '', ['timeout' => 5, 'connect_timeout' => 3]);

    assert_same(false, $health['busy'], 'ohne Verbindung ist der Host nicht bloß beschäftigt');
    assert_same(false, $health['reachable']);
    assert_same(false, $health['ready']);
    assert_same('unreachable', $health['error']);
    assert_contains('nicht erreichbar', $health['message']);
});

test_case('imageIntReady() behandelt ein transient 503 weiterhin als laden', function (): void {
    $body = '{"status":"loading","transient":true,"message":"Die Gewichte werden geladen."}';

    withFakeHttp(['503', $body], function (int $port): void {
        $health = imageIntReady('http://127.0.0.1:' . $port, '', ['timeout' => 5, 'connect_timeout' => 3]);

        assert_same(true, $health['loading']);
        assert_same(false, $health['busy'], 'eine Antwort ist keine Zeitüberschreitung');
        assert_same(true, $health['reachable']);
        assert_same(false, $health['ready']);
        assert_same('Die Gewichte werden geladen.', $health['message']);
    });
});

exit(test_summary('ImageInt-Health'));
