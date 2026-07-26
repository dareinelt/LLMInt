<?php

/**
 * api/admin_user_action.php
 *
 * Admin-only AJAX endpoint for user management actions:
 *
 *   action=send_password_reset  { user_id }
 *     → Generates a reset token, sends a password-reset e-mail.
 *
 *   action=set_user_model       { user_id, model }
 *     → Updates the user's default_model.
 *
 * All requests require an active admin session and a valid CSRF token.
 * Returns JSON { ok, message }.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_user'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nicht authentifiziert.']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/mailer.php';

$body   = (string) file_get_contents('php://input');
$data   = json_decode($body, true) ?: [];
$action = $data['action'] ?? $_POST['action'] ?? '';
$csrf   = $data['csrf_token'] ?? $_POST['csrf_token'] ?? '';

// CSRF check
if (empty($_SESSION['csrf_token']) || $csrf !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger CSRF-Token.']);
    exit;
}

$db = getDb();

// ── send_password_reset ───────────────────────────────────────────────────────
if ($action === 'send_password_reset') {
    $userId = (int) ($data['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Ungültige Benutzer-ID.']);
        exit;
    }

    $stmt = $db->prepare('SELECT id, username, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Benutzer nicht gefunden.']);
        exit;
    }
    if (empty($user['email'])) {
        echo json_encode(['ok' => false, 'message' => 'Für diesen Benutzer ist keine E-Mail-Adresse hinterlegt.']);
        exit;
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));

    $db->prepare(
        'UPDATE users
            SET password_reset_token = ?,
                password_reset_expires = ?,
                requires_password_change = 1
          WHERE id = ?'
    )->execute([$token, $expires, $userId]);

    $proto      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir  = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    $resetUrl   = "{$proto}://{$host}{$scriptDir}/api/reset_password.php?token=" . urlencode($token);

    $siteName = getSetting('smtp_from_name', 'LLMInt');

    $textBody = "Hallo {$user['username']},\r\n\r\n"
        . "dein Passwort bei {$siteName} wurde von einem Administrator zurückgesetzt.\r\n\r\n"
        . "Bitte klicke auf den folgenden Link, um ein neues Passwort zu vergeben:\r\n"
        . "{$resetUrl}\r\n\r\n"
        . "Der Link ist 2 Stunden gültig.\r\n\r\n"
        . "Falls du diese Anfrage nicht erwartest, wende dich bitte an deinen Administrator.\r\n\r\n"
        . "Viele Grüße,\r\nDein {$siteName}-Team";

    $htmlBody = '<p>Hallo <strong>' . htmlspecialchars($user['username']) . '</strong>,</p>'
        . '<p>dein Passwort bei <strong>' . htmlspecialchars($siteName) . '</strong> wurde von einem Administrator zurückgesetzt.</p>'
        . '<p>Bitte vergib ein neues Passwort durch Klick auf den Button:</p>'
        . '<p><a href="' . htmlspecialchars($resetUrl) . '" '
        . 'style="display:inline-block;padding:10px 20px;background:#6c63ff;color:#fff;'
        . 'text-decoration:none;border-radius:8px;font-weight:600;">Neues Passwort vergeben</a></p>'
        . '<p>Oder kopiere diesen Link: <a href="' . htmlspecialchars($resetUrl) . '">'
        . htmlspecialchars($resetUrl) . '</a></p>'
        . '<p>Der Link ist 2 Stunden gültig.</p>'
        . '<p>Viele Grüße,<br>Dein ' . htmlspecialchars($siteName) . '-Team</p>';

    try {
        sendMail($user['email'], $user['username'], "Passwort zurücksetzen – {$siteName}", $textBody, $htmlBody);
        echo json_encode(['ok' => true, 'message' => "Passwort-Reset-E-Mail wurde an {$user['email']} gesendet."]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage()]);
    }
    exit;
}

// ── set_user_model ────────────────────────────────────────────────────────────
if ($action === 'set_user_model') {
    $userId = (int) ($data['user_id'] ?? 0);
    $model  = trim($data['model'] ?? '');

    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Ungültige Benutzer-ID.']);
        exit;
    }

    $stmt = $db->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Benutzer nicht gefunden.']);
        exit;
    }

    $db->prepare('UPDATE users SET default_model = ? WHERE id = ?')
       ->execute([$model, $userId]);

    $msg = $model === ''
        ? "Standard-Modell für {$user['username']} zurückgesetzt (systemweites Standard-Modell wird verwendet)."
        : "Standard-Modell für {$user['username']} auf \"{$model}\" gesetzt.";

    echo json_encode(['ok' => true, 'message' => $msg]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion.']);
