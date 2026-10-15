<?php
declare(strict_types=1);

/**
 * tests/php/support/fake_smtp.php
 *
 * Minimaler Fake-SMTP-Server für tests/php/test_mailer.php.
 *
 * Kein test_*.php-Skript, damit tests/run.sh es nicht als Testsuite einsammelt:
 * es ist ein Hilfsprogramm, das der Test als Kindprozess startet.
 *
 * Aufruf:
 *   php tests/php/support/fake_smtp.php <transcript-datei> [modus]
 *
 *   modus = ok               (Standard) vollständiger, erfolgreicher Dialog
 *   modus = reject-starttls  antwortet auf STARTTLS mit einem 5xx-Fehler
 *
 * Ablauf:
 *   1. bindet an 127.0.0.1 auf einem vom Betriebssystem vergebenen Port,
 *      schreibt die Portnummer als erste Zeile auf STDOUT und flusht,
 *   2. nimmt genau EINE Verbindung an, spielt den Dialog und beendet sich.
 *
 * Alle empfangenen Zeilen werden protokolliert. Das Protokoll ist zeilenweise
 * normalisiert: CR/LF am Zeilenende wird entfernt und durch "\n" ersetzt, damit
 * der Test mit einfachen Zeilen-Strings arbeiten kann.
 *
 * Der Server kann nicht hängen bleiben: jede Socket-Operation hat einen
 * Timeout, zusätzlich greift ein Gesamt-Zeitlimit (GUARD_SECONDS).
 */

const GUARD_SECONDS = 20.0;

$transcriptPath = $argv[1] ?? '';
$mode           = $argv[2] ?? 'ok';

if ($transcriptPath === '') {
    fwrite(STDERR, "fake_smtp: Transcript-Pfad fehlt\n");
    exit(2);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "fake_smtp: bind fehlgeschlagen: {$errstr} ({$errno})\n");
    exit(2);
}

$name = (string) stream_socket_get_name($server, false);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

fwrite(STDOUT, $port . "\n");
fflush(STDOUT);

$log = fopen($transcriptPath, 'wb');
if ($log === false) {
    fwrite(STDERR, "fake_smtp: Transcript nicht schreibbar: {$transcriptPath}\n");
    fclose($server);
    exit(2);
}

/** Sendet eine Antwortzeile (immer CRLF). */
function smtpSay($conn, string $line): void
{
    fwrite($conn, $line . "\r\n");
    fflush($conn);
}

/** Liest eine Zeile ohne CR/LF; null bei EOF, Timeout oder Fehler. */
function smtpRead($conn): ?string
{
    $line = fgets($conn, 8192);
    if ($line === false) {
        return null;
    }
    return rtrim($line, "\r\n");
}

/** Protokolliert eine empfangene Zeile. */
function smtpLog($log, string $line): void
{
    fwrite($log, $line . "\n");
    fflush($log);
}

$deadline = microtime(true) + GUARD_SECONDS;
$conn     = @stream_socket_accept($server, 10);
fclose($server);

if ($conn === false) {
    fclose($log);
    fwrite(STDERR, "fake_smtp: keine Verbindung angenommen\n");
    exit(3);
}
stream_set_timeout($conn, 5);

smtpSay($conn, '220 fake_smtp ready');

$inData = false;
while (microtime(true) < $deadline) {
    $line = smtpRead($conn);
    if ($line === null) {
        break;
    }
    smtpLog($log, $line);

    if ($inData) {
        if ($line === '.') {
            $inData = false;
            smtpSay($conn, '250 2.0.0 OK: message queued');
        }
        continue;
    }

    $command = strtoupper($line);
    if (str_starts_with($command, 'EHLO')) {
        smtpSay($conn, '250-fake_smtp greets you');
        smtpSay($conn, '250-AUTH LOGIN PLAIN');
        smtpSay($conn, '250 OK');
    } elseif (str_starts_with($command, 'HELO')) {
        smtpSay($conn, '250 fake_smtp greets you');
    } elseif (str_starts_with($command, 'STARTTLS')) {
        if ($mode === 'reject-starttls') {
            smtpSay($conn, '550 5.7.1 STARTTLS not available');
        } else {
            // Für die Tests wird kein echter TLS-Handshake benötigt.
            smtpSay($conn, '454 4.7.0 TLS not available');
        }
    } elseif (str_starts_with($command, 'AUTH LOGIN')) {
        smtpSay($conn, '334 VXNlcm5hbWU6');   // "Username:"
        $user = smtpRead($conn);
        if ($user === null) {
            break;
        }
        smtpLog($log, $user);
        smtpSay($conn, '334 UGFzc3dvcmQ6');   // "Password:"
        $pass = smtpRead($conn);
        if ($pass === null) {
            break;
        }
        smtpLog($log, $pass);
        smtpSay($conn, '235 2.7.0 Authentication successful');
    } elseif (str_starts_with($command, 'AUTH PLAIN')) {
        smtpSay($conn, '235 2.7.0 Authentication successful');
    } elseif (str_starts_with($command, 'MAIL FROM')) {
        smtpSay($conn, '250 2.1.0 OK');
    } elseif (str_starts_with($command, 'RCPT TO')) {
        smtpSay($conn, '250 2.1.5 OK');
    } elseif ($command === 'DATA') {
        smtpSay($conn, '354 End data with <CR><LF>.<CR><LF>');
        $inData = true;
    } elseif ($command === 'QUIT') {
        smtpSay($conn, '221 2.0.0 Bye');
        break;
    } else {
        smtpSay($conn, '250 2.0.0 OK');
    }
}

fflush($log);
fclose($log);
fclose($conn);
exit(0);
