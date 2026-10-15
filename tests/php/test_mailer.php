<?php
declare(strict_types=1);

/**
 * tests/php/test_mailer.php
 *
 * Tests für lib/mailer.php: den SMTP-Dialog und den Nachrichtenaufbau der
 * Klasse Mailer sowie die Settings-Helfer mailerFromSettings() und sendMail().
 *
 * Der SMTP-Dialog läuft gegen tests/php/support/fake_smtp.php, einen kleinen
 * Fake-Server, den der Test als Kindprozess startet und der jede empfangene
 * Zeile protokolliert. Geprüft wird ausschließlich dieses Protokoll (plus die
 * geworfenen Ausnahmen), nicht das Innenleben der Klasse.
 *
 * Bewusst nicht abgedeckt:
 *   - encryption 'ssl' (impliziter TLS-Start) und der erfolgreiche
 *     STARTTLS-Aufstieg: beides bräuchte einen TLS-fähigen Server mit
 *     Zertifikat. Vom STARTTLS-Pfad wird deshalb nur die Ablehnung geprüft.
 *   - der AUTH-PLAIN-Rückfall: er greift nur, wenn ein Server AUTH LOGIN
 *     ablehnt; der Fake-Server akzeptiert es immer.
 *   - Dot-Stuffing (Zeilen, die mit '.' beginnen): quoted_printable_encode()
 *     erzeugt nie eine führende '.', der Zweig ist über die öffentliche API
 *     nicht erreichbar.
 *   - die Meldung "Absender-E-Mail (smtp_from_email) ist nicht konfiguriert."
 *     in sendMail(): ohne Datenbank wirft mailerFromSettings() vorher.
 *   - die datenbankgestützten SMTP-Einstellungen: im Container fehlt der
 *     pdo_mysql-Treiber, getSetting() fängt die PDOException ab und liefert
 *     den Standardwert. Genau dieser Zustand wird hier geprüft.
 *
 * Run from the repository root:  php tests/php/test_mailer.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/mailer.php';

/**
 * Startet tests/php/support/fake_smtp.php, führt $fn($port) aus und liefert
 * das empfangene SMTP-Protokoll (eine Zeile pro Empfang, ohne CR/LF) zurück.
 *
 * Der Kindprozess wird immer beendet und die Protokolldatei entfernt — auch
 * wenn $fn eine Ausnahme wirft. Der Port wird vom Betriebssystem vergeben und
 * vom Server auf STDOUT gemeldet, damit der Test ihn vor dem Mailer kennt.
 *
 * @param string   $mode 'ok' oder 'reject-starttls'
 * @param callable $fn   function (int $port): void
 */
function withFakeSmtp(string $mode, callable $fn): string
{
    $transcript = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'llmint_fake_smtp_' . bin2hex(random_bytes(6)) . '.txt';

    $proc  = null;
    $pipes = [];

    try {
        // PHP_BINARY ist die laufende php-Binary — im Container /usr/local/bin/php.
        $command = [PHP_BINARY, __DIR__ . '/support/fake_smtp.php', $transcript, $mode];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Fake-SMTP-Server konnte nicht gestartet werden.');
        }

        stream_set_timeout($pipes[1], 10);
        $portLine = fgets($pipes[1]);
        if ($portLine === false) {
            throw new RuntimeException(
                'Fake-SMTP-Server hat keinen Port gemeldet: ' . trim((string) stream_get_contents($pipes[2]))
            );
        }
        $port = (int) trim($portLine);
        if ($port <= 0) {
            throw new RuntimeException("Fake-SMTP-Server meldete einen ungültigen Port: '{$portLine}'");
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

    $log = is_file($transcript) ? (string) file_get_contents($transcript) : '';
    @unlink($transcript);

    return $log;
}

/**
 * Führt $fn aus und liefert die geworfene RuntimeException oder null.
 *
 * PHP-Warnungen werden dabei unterdrückt: mailer.php meldet einen
 * fehlgeschlagenen Verbindungsaufbau zusätzlich als E_WARNING, im Test soll
 * nur die Ausnahme sichtbar sein. Assertionsfehler werden nicht verschluckt.
 */
function expectRuntimeException(callable $fn): ?RuntimeException
{
    set_error_handler(static function (): bool {
        return true;
    });
    try {
        $fn();
    } catch (TestFailure $e) {
        throw $e;
    } catch (RuntimeException $e) {
        return $e;
    } finally {
        restore_error_handler();
    }
    return null;
}

/** Prüft, dass alle Nadeln in dieser Reihenfolge im Protokoll vorkommen. */
function assert_in_order(array $needles, string $haystack, string $message = ''): void
{
    $offset = 0;
    foreach ($needles as $needle) {
        $pos = strpos($haystack, $needle, $offset);
        if ($pos === false) {
            test_fail(($message !== '' ? $message . ': ' : '')
                . test_describe($needle) . ' fehlt (oder in falscher Reihenfolge) im Protokoll');
        }
        $offset = $pos + strlen($needle);
    }
}

// ── Mailer::send(): erfolgreicher Dialog ──────────────────────────────────────

test_case('Mailer::send() führt den vollständigen SMTP-Dialog mit AUTH LOGIN', function (): void {
    $user = 'smtp-user';
    $pass = 'geheim-123';

    $transcript = withFakeSmtp('ok', function (int $port) use ($user, $pass): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', $user, $pass, 5);
        $mailer->send(
            'absender@example.com',
            'Absender',
            'empfaenger@example.com',
            'Empfaenger',
            'Testbetreff',
            'Hallo Welt'
        );
    });

    assert_contains('EHLO ', $transcript, 'Der Mailer muss sich per EHLO vorstellen');
    assert_contains('AUTH LOGIN', $transcript);
    assert_contains(base64_encode($user), $transcript, 'Der Benutzername muss base64-kodiert gesendet werden');
    assert_contains(base64_encode($pass), $transcript, 'Das Passwort muss base64-kodiert gesendet werden');
    assert_contains('MAIL FROM:<absender@example.com>', $transcript);
    assert_contains('RCPT TO:<empfaenger@example.com>', $transcript);
    assert_contains('DATA', $transcript);
    assert_contains('QUIT', $transcript);

    assert_in_order(
        [
            'EHLO ',
            'AUTH LOGIN',
            base64_encode($user),
            base64_encode($pass),
            'MAIL FROM:<absender@example.com>',
            'RCPT TO:<empfaenger@example.com>',
            'DATA',
            'QUIT',
        ],
        $transcript,
        'SMTP-Reihenfolge'
    );
});

test_case('Mailer::send() überspringt AUTH LOGIN ohne Benutzernamen', function (): void {
    $transcript = withFakeSmtp('ok', function (int $port): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send('absender@example.com', 'Absender', 'empfaenger@example.com', 'Empfaenger', 'Testbetreff', 'Hallo');
    });

    assert_contains('MAIL FROM:<absender@example.com>', $transcript, 'Der Dialog muss trotzdem durchlaufen');
    assert_not_contains('AUTH', $transcript, 'Ohne Benutzernamen darf nicht authentifiziert werden');
});

// ── Mailer::send(): Nachrichtenaufbau ─────────────────────────────────────────

test_case('Mailer::send() überträgt Absender, Empfänger und Nachrichtenkopf', function (): void {
    $transcript = withFakeSmtp('ok', function (int $port): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send(
            'absender@example.com',
            'Absender',
            'empfaenger@example.com',
            'Empfaenger',
            'Testbetreff',
            'Hallo Welt'
        );
    });

    assert_contains('From: Absender <absender@example.com>', $transcript);
    assert_contains('To: Empfaenger <empfaenger@example.com>', $transcript);
    assert_contains('Subject: Testbetreff', $transcript);
    assert_contains('MIME-Version: 1.0', $transcript);
    assert_contains('Content-Type: text/plain; charset=UTF-8', $transcript);
    assert_contains('Content-Transfer-Encoding: quoted-printable', $transcript);
    assert_contains("\nHallo Welt\n", $transcript, 'Der Text muss im Rumpf stehen');

    assert_true(
        (bool) preg_match('/^Date: \w{3}, \d{1,2} \w{3} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/m', $transcript),
        'Der Date-Header muss RFC-2822-Form haben: ' . test_describe($transcript)
    );
    assert_true(
        (bool) preg_match('/^Message-ID: <[0-9a-f]{24}@127\.0\.0\.1>$/m', $transcript),
        'Die Message-ID muss aus 24 Hex-Zeichen und dem Host bestehen'
    );
});

test_case('Mailer::send() kodiert Umlaute im Text quoted-printable', function (): void {
    $transcript = withFakeSmtp('ok', function (int $port): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send('absender@example.com', 'Absender', 'empfaenger@example.com', 'Empfaenger', 'Testbetreff', 'Grüße aus München');
    });

    assert_contains('Gr=C3=BC=C3=9Fe aus M=C3=BCnchen', $transcript, 'Umlaute müssen quoted-printable kodiert sein');
    assert_not_contains('Grüße', $transcript, 'Der Rumpf darf keine rohen Umlaute enthalten');
});

test_case('Mailer::send() lässt einen ASCII-Betreff unverändert', function (): void {
    $transcript = withFakeSmtp('ok', function (int $port): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send('absender@example.com', 'Absender', 'empfaenger@example.com', 'Empfaenger', 'Testbetreff', 'Hallo Welt');
    });

    assert_contains('Subject: Testbetreff', $transcript);
    assert_not_contains('=?UTF-8?B?', $transcript, 'Ein reiner ASCII-Betreff darf nicht kodiert werden');
});

test_case('Mailer::send() kodiert einen Betreff mit Umlaut als RFC-2047-Wort', function (): void {
    $subject = 'Grüße';

    $transcript = withFakeSmtp('ok', function (int $port) use ($subject): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send('absender@example.com', 'Absender', 'empfaenger@example.com', 'Empfaenger', $subject, 'Hallo Welt');
    });

    $matches = [];
    $found   = preg_match('/^Subject: =\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=$/m', $transcript, $matches);
    assert_same(1, $found, 'Der Betreff muss als RFC-2047-Wort kodiert sein: ' . test_describe($transcript));
    assert_same($subject, base64_decode($matches[1], true), 'Das kodierte Wort muss den Betreff ergeben');
    assert_not_contains($subject, $transcript, 'Der Betreff darf nicht roh übertragen werden');
});

test_case('Mailer::send() setzt Sonderzeichen im Anzeigenamen in Anführungszeichen', function (): void {
    $transcript = withFakeSmtp('ok', function (int $port): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send(
            'absender@example.com',
            'Firma, "Sonder" <Zeichen>:;()',
            'empfaenger@example.com',
            'Empfaenger',
            'Testbetreff',
            'Hallo Welt'
        );
    });

    assert_contains(
        'From: "Firma, \"Sonder\" <Zeichen>:;()" <absender@example.com>',
        $transcript,
        'Sonderzeichen im Anzeigenamen müssen in Anführungszeichen stehen'
    );
});

test_case('Mailer::send() erzeugt mit HTML-Text eine multipart/alternative-Nachricht', function (): void {
    $transcript = withFakeSmtp('ok', function (int $port): void {
        $mailer = new Mailer('127.0.0.1', $port, 'none', '', '', 5);
        $mailer->send(
            'absender@example.com',
            'Absender',
            'empfaenger@example.com',
            'Empfaenger',
            'Testbetreff',
            'Nur Text',
            '<p>Mit HTML</p>'
        );
    });

    $matches = [];
    $found   = preg_match('/^Content-Type: multipart\/alternative; boundary="([^"]+)"$/m', $transcript, $matches);
    assert_same(1, $found, 'Der Multipart-Kopf muss vorhanden sein: ' . test_describe($transcript));
    $boundary = $matches[1];

    assert_same(3, substr_count($transcript, '--' . $boundary), 'Zwei Teile plus Abschluss-Boundary');
    assert_same(1, substr_count($transcript, '--' . $boundary . '--'), 'Die Abschluss-Boundary darf nur einmal vorkommen');
    assert_contains("--{$boundary}\nContent-Type: text/plain; charset=UTF-8", $transcript);
    assert_contains("--{$boundary}\nContent-Type: text/html; charset=UTF-8", $transcript);
    assert_same(2, substr_count($transcript, 'Content-Transfer-Encoding: quoted-printable'), 'Beide Teile sind quoted-printable');
    assert_contains('Nur Text', $transcript, 'Der Textteil muss im Rumpf stehen');
    assert_contains('<p>Mit HTML</p>', $transcript, 'Der HTML-Teil muss im Rumpf stehen');
});

// ── Mailer::send(): Fehlerpfade ───────────────────────────────────────────────

test_case('Mailer::send() bricht bei abgelehntem STARTTLS mit RuntimeException ab', function (): void {
    $error = null;

    $transcript = withFakeSmtp('reject-starttls', function (int $port) use (&$error): void {
        $mailer = new Mailer('127.0.0.1', $port, 'tls', 'smtp-user', 'geheim-123', 5);
        $error  = expectRuntimeException(function () use ($mailer): void {
            $mailer->send('absender@example.com', 'Absender', 'empfaenger@example.com', 'Empfaenger', 'Testbetreff', 'Hallo');
        });
    });

    assert_true($error instanceof RuntimeException, 'Der abgelehnte STARTTLS muss eine RuntimeException auslösen');
    assert_true(
        str_starts_with($error->getMessage(), 'STARTTLS rejected: '),
        'Die Meldung muss mit "STARTTLS rejected: " beginnen, war aber ' . test_describe($error->getMessage())
    );
    assert_contains('550', $error->getMessage(), 'Der Antwortcode des Servers muss in der Meldung stehen');

    assert_contains('STARTTLS', $transcript, 'Der Mailer muss STARTTLS gesendet haben');
    assert_not_contains('AUTH', $transcript, 'Nach dem STARTTLS-Fehler darf nicht authentifiziert werden');
    assert_not_contains('MAIL FROM', $transcript, 'Nach dem STARTTLS-Fehler darf keine Nachricht folgen');
});

test_case('Mailer::send() meldet einen fehlgeschlagenen Verbindungsaufbau', function (): void {
    $mailer = new Mailer('127.0.0.1', 1, 'none', '', '', 2);
    $error  = expectRuntimeException(function () use ($mailer): void {
        $mailer->send('absender@example.com', 'Absender', 'empfaenger@example.com', 'Empfaenger', 'Testbetreff', 'Hallo');
    });

    assert_true($error instanceof RuntimeException, 'Ein fehlender Server muss eine RuntimeException auslösen');
    assert_true(
        str_starts_with($error->getMessage(), 'SMTP connect failed'),
        'Die Meldung muss mit "SMTP connect failed" beginnen, war aber ' . test_describe($error->getMessage())
    );
});

// ── Settings-Helfer (ohne Datenbank gelten die Standardwerte) ─────────────────

test_case('mailerFromSettings() verlangt einen konfigurierten SMTP-Host', function (): void {
    $error = expectRuntimeException(function (): void {
        mailerFromSettings();
    });

    assert_true($error instanceof RuntimeException, 'Ohne SMTP-Host muss eine RuntimeException fliegen');
    assert_same('SMTP-Host ist nicht konfiguriert.', $error->getMessage());
});

test_case('sendMail() scheitert ohne konfigurierten SMTP-Host', function (): void {
    $error = expectRuntimeException(function (): void {
        sendMail('empfaenger@example.com', 'Empfaenger', 'Testbetreff', 'Hallo Welt');
    });

    assert_true($error instanceof RuntimeException, 'Ohne SMTP-Host muss eine RuntimeException fliegen');
    assert_same('SMTP-Host ist nicht konfiguriert.', $error->getMessage());
});

exit(test_summary('test_mailer'));
