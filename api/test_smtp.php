<?php

/**
 * api/test_smtp.php
 *
 * Admin-only AJAX endpoint to test the configured SMTP settings.
 * Sends a test e-mail to the given address.
 *
 * POST body (JSON):
 *   { "to": "admin@example.com" }
 * or query param:
 *   ?to=admin@example.com
 *
 * Returns JSON:
 *   { "ok": true,  "message": "…" }
 *   { "ok": false, "message": "…" }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
requireAdminOrJson403();

require_once __DIR__ . '/../lib/mailer.php';

$body = (string) file_get_contents('php://input');
$data = json_decode($body, true);
$to   = trim($data['to'] ?? $_GET['to'] ?? '');

if ($to === '') {
    echo json_encode(['ok' => false, 'message' => 'Bitte eine Empfänger-E-Mail-Adresse angeben.']);
    exit;
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Ungültige E-Mail-Adresse.']);
    exit;
}

try {
    $siteName = getSetting('smtp_from_name', 'LLMInt');

    $textBody = "Dies ist eine Test-E-Mail von {$siteName}.\r\n\r\n"
        . "Wenn du diese Nachricht siehst, funktioniert dein SMTP-Server korrekt.";

    $htmlBody = '<p>Dies ist eine <strong>Test-E-Mail</strong> von <strong>'
        . htmlspecialchars($siteName) . '</strong>.</p>'
        . '<p>Wenn du diese Nachricht siehst, funktioniert dein SMTP-Server korrekt. ✓</p>';

    sendMail($to, '', "SMTP-Test – {$siteName}", $textBody, $htmlBody);

    echo json_encode(['ok' => true, 'message' => "Test-E-Mail erfolgreich an {$to} gesendet."]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
