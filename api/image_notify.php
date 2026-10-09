<?php

/**
 * api/image_notify.php
 *
 * Records the user's answer to the consent question shown for a running image
 * job and queues the notification mail. Called by the chat frontend only after
 * the user explicitly clicked "Ja, per E-Mail benachrichtigen".
 *
 * POST (form or JSON):
 *   session_id – chat session the job belongs to (required)
 *   job_id     – ImageInt job id (required)
 *
 * Returns JSON:
 *   { ok, already, message, email, status }
 *
 * The row in image_notifications is what api/image_notify_worker.php picks up;
 * nothing is sent from here, because the render has not finished yet. A second
 * call for the same job is idempotent (UNIQUE KEY uniq_job_user), so a double
 * click or a reload never produces a second mail.
 *
 * Requires an active user session.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nicht authentifiziert.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Nur POST erlaubt.']);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/image_generation.php';

$userId = (int) $_SESSION['admin_id'];

$raw  = file_get_contents('php://input');
$body = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
if ($body === [] && $_POST !== []) {
    $body = $_POST;
}

$sessionId = isset($body['session_id']) ? trim((string) $body['session_id']) : '';
$jobId     = isset($body['job_id']) ? trim((string) $body['job_id']) : '';

if (!preg_match('/^[a-f0-9]{8,128}$/', $sessionId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $jobId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unvollständige Anfrage.']);
    exit;
}

// Same ownership guarantee as api/image_status.php: only the owner of the
// session may queue a notification for a job stored in it.
$session = loadUserConversationSession($sessionId, $userId);
if ($session === null) {
    http_response_code(404);
    echo json_encode([
        'ok'      => false,
        'message' => 'Dieser Chat existiert nicht mehr oder gehört zu einem anderen Konto.',
    ]);
    exit;
}

$record = imageIntFindJobInMessages($session['messages'], $jobId);
if ($record === null) {
    http_response_code(404);
    echo json_encode([
        'ok'      => false,
        'message' => 'Zu diesem Chat ist kein Bildauftrag mit dieser Kennung gespeichert.',
    ]);
    exit;
}

$email = '';
try {
    $stmt = getDb()->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $email = trim((string) ($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    $email = '';
}

if (!imageNotifyEnabled()) {
    echo json_encode([
        'ok'      => false,
        'already' => false,
        'email'   => '',
        'status'  => '',
        'message' => 'Die E-Mail-Benachrichtigung ist in dieser Installation abgeschaltet.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($email === '') {
    echo json_encode([
        'ok'      => false,
        'already' => false,
        'email'   => '',
        'status'  => '',
        'message' => 'In Deinem Profil ist keine E-Mail-Adresse hinterlegt – bitte trage '
            . 'zuerst eine Adresse nach.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$queued = imageIntQueueNotification(
    $jobId,
    $userId,
    $sessionId,
    (string) ($record['prompt'] ?? ''),
    (string) ($record['status_url'] ?? ''),
    (string) ($record['image_url'] ?? '')
);

if (!$queued['ok']) {
    writeLog('warning', 'Bildbenachrichtigung für Auftrag ' . $jobId . ' konnte nicht vorgemerkt werden: ' . $queued['message']);
    echo json_encode([
        'ok'      => false,
        'already' => false,
        'email'   => $email,
        'status'  => '',
        'message' => $queued['message'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$status = imageIntNotificationStatus($jobId, $userId);
if ($status === '') {
    $status = 'pending';
}

writeLog('info', 'Bildbenachrichtigung für Auftrag ' . $jobId . ' vorgemerkt ('
    . ($queued['already'] ? 'bereits vorhanden' : 'neu') . ').');

echo json_encode([
    'ok'      => true,
    'already' => $queued['already'],
    'email'   => $email,
    'status'  => $status,
    'message' => $queued['already'] && $queued['message'] !== ''
        ? $queued['message']
        : 'Alles klar – wir schreiben an ' . $email . ', sobald das Bild fertig ist.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
