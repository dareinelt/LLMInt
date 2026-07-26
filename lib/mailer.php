<?php

/**
 * lib/mailer.php
 *
 * Minimal self-contained SMTP mailer.
 * Supports plain SMTP, STARTTLS and implicit SSL (port 465).
 * Implements AUTH LOGIN and AUTH PLAIN.
 *
 * Usage:
 *   $m = new Mailer($host, $port, $encryption, $user, $pass);
 *   $m->send($fromEmail, $fromName, $toEmail, $toName, $subject, $body);
 */

class Mailer
{
    /** @var resource|false */
    private $sock = false;
    private string $host;
    private int    $port;
    private string $encryption; // 'none' | 'tls' | 'ssl'
    private string $user;
    private string $pass;
    private int    $timeout;

    public function __construct(
        string $host,
        int    $port       = 587,
        string $encryption = 'tls',
        string $user       = '',
        string $pass       = '',
        int    $timeout    = 15
    ) {
        $this->host       = $host;
        $this->port       = $port;
        $this->encryption = strtolower($encryption);
        $this->user       = $user;
        $this->pass       = $pass;
        $this->timeout    = $timeout;
    }

    /**
     * Send one e-mail.
     *
     * @throws RuntimeException on any SMTP error.
     */
    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml = ''
    ): void {
        $this->connect();

        try {
            $this->ehlo();
            if ($this->user !== '') {
                $this->auth();
            }
            $this->mailFrom($fromEmail);
            $this->rcptTo($toEmail);

            $raw = $this->buildRawMessage(
                $fromEmail, $fromName, $toEmail, $toName, $subject, $bodyText, $bodyHtml
            );
            $this->data($raw);
            $this->quit();
        } finally {
            $this->close();
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function connect(): void
    {
        $wrapper = ($this->encryption === 'ssl') ? 'ssl' : 'tcp';
        $addr    = "{$wrapper}://{$this->host}:{$this->port}";

        $ctx  = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);
        $sock = stream_socket_client($addr, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);

        if ($sock === false) {
            throw new RuntimeException("SMTP connect failed ({$this->host}:{$this->port}): {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;

        $banner = $this->readLine();
        if (!str_starts_with($banner, '2')) {
            throw new RuntimeException("Unexpected SMTP banner: {$banner}");
        }
    }

    private function ehlo(): void
    {
        $hostname = gethostname() ?: 'localhost';
        $this->sendLine("EHLO {$hostname}");
        $caps = $this->readMultiLine();

        if ($this->encryption === 'tls') {
            // Upgrade to TLS via STARTTLS
            $this->sendLine('STARTTLS');
            $resp = $this->readLine();
            if (!str_starts_with($resp, '2')) {
                throw new RuntimeException("STARTTLS rejected: {$resp}");
            }
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS handshake failed.');
            }
            // Re-issue EHLO after TLS upgrade
            $this->sendLine("EHLO {$hostname}");
            $caps = $this->readMultiLine();
        }
    }

    private function auth(): void
    {
        $this->sendLine('AUTH LOGIN');
        $resp = $this->readLine();
        if (!str_starts_with($resp, '3')) {
            // Fall back to AUTH PLAIN
            $plain = base64_encode("\0{$this->user}\0{$this->pass}");
            $this->sendLine("AUTH PLAIN {$plain}");
            $resp2 = $this->readLine();
            if (!str_starts_with($resp2, '2')) {
                throw new RuntimeException("SMTP AUTH failed: {$resp2}");
            }
            return;
        }
        // AUTH LOGIN: server sends two 334 challenges
        $this->sendLine(base64_encode($this->user));
        $resp = $this->readLine();
        if (!str_starts_with($resp, '3')) {
            throw new RuntimeException("AUTH LOGIN username rejected: {$resp}");
        }
        $this->sendLine(base64_encode($this->pass));
        $resp = $this->readLine();
        if (!str_starts_with($resp, '2')) {
            throw new RuntimeException("AUTH LOGIN failed: {$resp}");
        }
    }

    private function mailFrom(string $email): void
    {
        $this->sendLine("MAIL FROM:<{$email}>");
        $resp = $this->readLine();
        if (!str_starts_with($resp, '2')) {
            throw new RuntimeException("MAIL FROM rejected: {$resp}");
        }
    }

    private function rcptTo(string $email): void
    {
        $this->sendLine("RCPT TO:<{$email}>");
        $resp = $this->readLine();
        if (!str_starts_with($resp, '2')) {
            throw new RuntimeException("RCPT TO rejected: {$resp}");
        }
    }

    private function data(string $raw): void
    {
        $this->sendLine('DATA');
        $resp = $this->readLine();
        if (!str_starts_with($resp, '3')) {
            throw new RuntimeException("DATA not accepted: {$resp}");
        }

        // Dot-stuff: lines starting with '.' must be escaped
        $lines = explode("\r\n", $raw);
        $stuffed = implode("\r\n", array_map(
            fn(string $l) => str_starts_with($l, '.') ? '.' . $l : $l,
            $lines
        ));
        fwrite($this->sock, $stuffed . "\r\n.\r\n");

        $resp = $this->readLine();
        if (!str_starts_with($resp, '2')) {
            throw new RuntimeException("Message rejected by server: {$resp}");
        }
    }

    private function quit(): void
    {
        $this->sendLine('QUIT');
        $this->readLine(); // 221 Bye — ignore errors
    }

    private function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
        $this->sock = false;
    }

    private function sendLine(string $line): void
    {
        fwrite($this->sock, $line . "\r\n");
    }

    private function readLine(): string
    {
        $line = fgets($this->sock, 512);
        return $line !== false ? rtrim($line) : '';
    }

    /**
     * Read a multi-line SMTP response (lines ending with '-', last ending with ' ').
     * Returns the concatenated response.
     */
    private function readMultiLine(): string
    {
        $full = '';
        do {
            $line = $this->readLine();
            $full .= $line . "\n";
        } while (strlen($line) >= 4 && $line[3] === '-');
        return $full;
    }

    private function buildRawMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml
    ): string {
        $date      = date('r');
        $msgId     = '<' . bin2hex(random_bytes(12)) . '@' . ($this->host ?: 'localhost') . '>';
        $fromHdr   = $fromName !== '' ? $this->encodeHeader($fromName) . " <{$fromEmail}>" : "<{$fromEmail}>";
        $toHdr     = $toName   !== '' ? $this->encodeHeader($toName)   . " <{$toEmail}>"   : "<{$toEmail}>";
        $subjHdr   = $this->encodeHeader($subject);

        if ($bodyHtml !== '') {
            $boundary = '=_Part_' . bin2hex(random_bytes(8));
            $headers  =
                "From: {$fromHdr}\r\n" .
                "To: {$toHdr}\r\n" .
                "Subject: {$subjHdr}\r\n" .
                "Date: {$date}\r\n" .
                "Message-ID: {$msgId}\r\n" .
                "MIME-Version: 1.0\r\n" .
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

            $body =
                "--{$boundary}\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                quoted_printable_encode($bodyText) . "\r\n" .
                "--{$boundary}\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                quoted_printable_encode($bodyHtml) . "\r\n" .
                "--{$boundary}--";
        } else {
            $headers =
                "From: {$fromHdr}\r\n" .
                "To: {$toHdr}\r\n" .
                "Subject: {$subjHdr}\r\n" .
                "Date: {$date}\r\n" .
                "Message-ID: {$msgId}\r\n" .
                "MIME-Version: 1.0\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: quoted-printable\r\n";
            $body = quoted_printable_encode($bodyText);
        }

        return $headers . "\r\n" . $body;
    }

    /** RFC 2047 encoded-word for non-ASCII header values. */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        // Quoted if it contains special chars
        if (preg_match('/[",;:<>()]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}

/**
 * Build a Mailer from the stored SMTP settings.
 *
 * @throws RuntimeException when no host is configured.
 */
function mailerFromSettings(): Mailer
{
    $host       = getSetting('smtp_host', '');
    $port       = (int) getSetting('smtp_port', '587');
    $encryption = getSetting('smtp_encryption', 'tls');
    $user       = getSetting('smtp_user', '');
    $pass       = getSetting('smtp_pass', '');

    if ($host === '') {
        throw new RuntimeException('SMTP-Host ist nicht konfiguriert.');
    }

    return new Mailer($host, $port, $encryption, $user, $pass);
}

/**
 * Send an e-mail using the stored SMTP settings and from address.
 *
 * @throws RuntimeException on configuration or delivery error.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $bodyText, string $bodyHtml = ''): void
{
    $mailer   = mailerFromSettings();
    $fromAddr = getSetting('smtp_from_email', '');
    $fromName = getSetting('smtp_from_name', 'LLMInt');

    if ($fromAddr === '') {
        throw new RuntimeException('Absender-E-Mail (smtp_from_email) ist nicht konfiguriert.');
    }

    $mailer->send($fromAddr, $fromName, $toEmail, $toName, $subject, $bodyText, $bodyHtml);
}
