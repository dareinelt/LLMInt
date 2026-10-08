<?php

/**
 * api/image_status.php
 *
 * Resolves a running ImageInt job for the chat frontend. The browser calls this
 * while a job is `queued`/`running` and again when the user follows the deep
 * link from the notification mail.
 *
 * The job record lives on the assistant message (`image_job`), which is what
 * ties the job to a chat session and therefore to its owner: the session is
 * loaded with loadUserConversationSession(), so a foreign session id is
 * rejected before any job is touched.
 *
 * GET parameters:
 *   session_id – chat session the job belongs to (required)
 *   job_id     – ImageInt job id (required)
 *
 * Returns JSON:
 *   { ok, status, stage, stage_label, message, image_url, image_markdown,
 *     width, height, duration_ms, duration, poll_after_seconds, expired,
 *     notify: { enabled, email, consent_text, requested, status } }
 *
 * `ok` is false for a failed or expired job, together with a German message the
 * chat renders as an error bubble – never as an empty image.
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

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/image_generation.php';

$userId    = (int) $_SESSION['admin_id'];
$sessionId = isset($_GET['session_id']) ? trim((string) $_GET['session_id']) : '';
$jobId     = isset($_GET['job_id']) ? trim((string) $_GET['job_id']) : '';

if (!preg_match('/^[a-f0-9]{8,128}$/', $sessionId) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $jobId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unvollständige Anfrage.']);
    exit;
}

// Ownership check: the session must belong to the logged-in user. This is the
// same guarantee api/chat_sessions.php gives; a link from someone else's mail
// must not open their chat.
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

$prompt = (string) ($record['prompt'] ?? '');
$notify = imageIntNotifyClientPayload($userId, $prompt);
$notify['status']    = imageIntNotificationStatus($jobId, $userId);
$notify['requested'] = $notify['status'] !== '';

$resolved = imageIntResolveJob($record);

$payload = [
    'ok'                 => false,
    'job_id'             => $jobId,
    'session_id'         => $sessionId,
    'status'             => $resolved['status'],
    'stage'              => $resolved['stage'],
    'stage_label'        => imageIntStageLabel($resolved['stage']),
    'message'            => $resolved['message'],
    'image_url'          => '',
    'image_markdown'     => '',
    'duration_ms'        => 0,
    'duration'           => '',
    'poll_after_seconds' => IMAGE_INT_POLL_INTERVAL_DEFAULT,
    'expired'            => $resolved['expired'],
    'notify'             => $notify,
];

if ($resolved['expired']) {
    // ImageInt keeps a job for IMAGEINT_JOB_RETENTION_SECONDS only. Saying so
    // is more useful than a link that leads nowhere.
    $payload['message'] = $resolved['message'] !== ''
        ? $resolved['message']
        : 'Dieser Bildauftrag ist nicht mehr abrufbar: Der Dienst bewahrt fertige '
            . 'Bilder nur für eine begrenzte Zeit auf.';
    writeLog('warning', 'Bildauftrag ' . $jobId . ' ist abgelaufen (Retention).');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($resolved['status'] ?? '') === 'error' || ($resolved['error'] ?? '') === 'image_error') {
    $payload['message'] = $resolved['message'] !== ''
        ? $resolved['message']
        : 'Die Bildgenerierung ist fehlgeschlagen.';
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($resolved['error'] ?? '') === 'image_unavailable') {
    $payload['status']  = 'done';
    $payload['message'] = $resolved['message'];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$resolved['ok'] || $resolved['status'] !== 'done') {
    // Still queued/running, or ImageInt is momentarily unreachable. Both mean
    // "keep polling", not "failed".
    if ($payload['message'] === '') {
        $payload['message'] = 'Das Bild wird noch erzeugt …';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Finished: cache the result in the conversation ───────────────────────────
// `$resolved['job']` is empty when the PNG was already cached; the values
// stored on the message then fill in width/height/duration.
$job = $resolved['job'];
$job['duration_ms'] = (int) ($job['duration_ms'] ?? 0);
if ($job['duration_ms'] <= 0) {
    $job['duration_ms'] = (int) ($record['duration_ms'] ?? 0);
}
if ($job['duration_ms'] <= 0) {
    $job['duration_ms'] = imageIntJobDurationMs($job);
}
foreach (['width', 'height'] as $dimension) {
    if ((int) ($job[$dimension] ?? 0) <= 0) {
        $job[$dimension] = (int) ($record[$dimension] ?? 0);
    }
}
$imageUrl = (string) $resolved['image_url'];
$markdown = imageIntMarkdown($imageUrl);

$messages = imageIntPatchJobInMessages($session['messages'], $jobId, [
    'status'      => 'done',
    'stage'       => 'done',
    'image_url'   => $imageUrl,
    'width'       => isset($job['width']) ? (int) $job['width'] : 0,
    'height'      => isset($job['height']) ? (int) $job['height'] : 0,
    'duration_ms' => $job['duration_ms'],
    'message'     => $resolved['message'],
]);

// Append the image as its own assistant message once, so the result is still
// there after a reload – and so the mail deep link has something to scroll to.
if (!imageIntMessagesContainImage($messages, $imageUrl)) {
    $messages[] = [
        'role'    => 'assistant',
        'content' => $markdown,
        'image'   => $imageUrl,
    ];
}

try {
    saveConversationSession($sessionId, $session['model'], $messages, $userId);
} catch (Throwable $e) {
    writeLog('warning', 'Bildauftrag ' . $jobId . ': Chat konnte nicht aktualisiert werden – ' . $e->getMessage());
}

$payload['ok']             = true;
$payload['status']         = 'done';
$payload['stage']          = 'done';
$payload['stage_label']    = imageIntStageLabel('done');
$payload['message']        = $resolved['message'];
$payload['image_url']      = $imageUrl;
$payload['image_markdown'] = $markdown;
$payload['width']          = isset($job['width']) ? (int) $job['width'] : 0;
$payload['height']         = isset($job['height']) ? (int) $job['height'] : 0;
$payload['duration_ms']    = $job['duration_ms'];
$payload['duration']       = imageIntFormatDuration($job['duration_ms']);

writeLog('info', 'Bildauftrag ' . $jobId . ' fertig (' . $payload['width'] . '×'
    . $payload['height'] . ', ' . $payload['duration'] . ').');

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
