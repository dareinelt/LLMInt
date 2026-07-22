<?php
/**
 * api/chat.php
 *
 * Proxies a POST /v1/chat/completions request to the best available
 * LM Studio endpoint, selected by the load balancer.
 *
 * The load balancer groups endpoints by their configured default_model.
 * Within a group it picks the endpoint with the fewest running tasks
 * (max 4 per endpoint), favouring the one that was used least recently.
 *
 * Every dispatched request is registered as a task in the DB.
 * Token counts from the LM Studio response are persisted on completion
 * so that statistics can be derived later.
 *
 * Streaming (stream: true) is supported – the response is forwarded
 * as Server-Sent Events (text/event-stream).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/balancer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ungültiger JSON-Body.']);
    exit;
}

if (empty($payload['model']) || empty($payload['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '"model" und "messages" sind Pflichtfelder.']);
    exit;
}

// Validate messages array.
foreach ($payload['messages'] as $msg) {
    if (!isset($msg['role'], $msg['content'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Jede Nachricht muss "role" und "content" enthalten.']);
        exit;
    }
    $allowed = ['system', 'user', 'assistant'];
    if (!in_array($msg['role'], $allowed, true)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Ungültige Rolle: ' . htmlspecialchars($msg['role'])]);
        exit;
    }
}

// ── Select endpoint via load balancer ─────────────────────────────────────────

$model = $payload['model'];

try {
    $slot = pickEndpointForModel($model);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
    exit;
}

if ($slot === null) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Kein Endpunkt verfügbar. Alle Kapazitäten sind belegt oder kein passender Endpunkt konfiguriert.']);
    exit;
}

$endpoint = $slot['endpoint'];
$taskId   = $slot['task_id'];
$baseUrl  = rtrim($endpoint['base_url'], '/');
$timeout  = max(1, (int) $endpoint['timeout']);

// Ensure the task is always marked finished, even on unexpected PHP termination.
$taskFinished = false;
register_shutdown_function(static function () use ($taskId, &$taskFinished): void {
    if (!$taskFinished) {
        try {
            completeTask($taskId, 'error');
        } catch (Throwable $e) {
            // Best-effort – nothing more we can do in a shutdown handler.
        }
    }
});

$stream = isset($payload['stream']) && $payload['stream'] === true;

// Forward only the fields LM Studio expects.
$forwardPayload = [
    'model'       => $payload['model'],
    'messages'    => $payload['messages'],
    'stream'      => $stream,
    'temperature' => $payload['temperature'] ?? 0.7,
    'max_tokens'  => $payload['max_tokens']  ?? -1,
];

$url = $baseUrl . '/chat/completions';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => json_encode($forwardPayload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

// ── Streaming path ────────────────────────────────────────────────────────────

if ($stream) {
    ignore_user_abort(true);
    @set_time_limit(0);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => 0,
    ]);

    // Tail buffer: keep the last 8 KB of SSE data so we can extract usage
    // information from the final event(s) once the stream has completed.
    $tailBuffer = '';

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use (&$tailBuffer): int {
        echo $data;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        $tailBuffer .= $data;
        if (strlen($tailBuffer) > 8192) {
            $tailBuffer = substr($tailBuffer, -8192);
        }
        return strlen($data);
    });

    curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Extract token usage from the last SSE data events.
    $promptTokens     = null;
    $completionTokens = null;
    $totalTokens      = null;
    if (preg_match_all('/^data:\s*(\{.+\})$/m', $tailBuffer, $matches)) {
        foreach (array_reverse($matches[1]) as $raw) {
            $obj = json_decode($raw, true);
            if (is_array($obj) && isset($obj['usage']['total_tokens'])) {
                $promptTokens     = isset($obj['usage']['prompt_tokens'])     ? (int) $obj['usage']['prompt_tokens']     : null;
                $completionTokens = isset($obj['usage']['completion_tokens']) ? (int) $obj['usage']['completion_tokens'] : null;
                $totalTokens      = (int) $obj['usage']['total_tokens'];
                break;
            }
        }
    }

    $taskFinished = true;
    completeTask($taskId, $curlErr !== '' ? 'error' : 'done', $promptTokens, $completionTokens, $totalTokens);

    if ($curlErr !== '') {
        echo "data: " . json_encode(['error' => $curlErr]) . "\n\n";
        flush();
    }
    exit;
}

// ── Non-streaming path ────────────────────────────────────────────────────────

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');

if ($curlErr !== '') {
    $taskFinished = true;
    completeTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200) {
    $data = json_decode($body, true);
    $msg  = isset($data['error']['message'])
        ? $data['error']['message']
        : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
    $taskFinished = true;
    completeTask($taskId, 'error');
    http_response_code(502);
    echo json_encode(['error' => $msg]);
    exit;
}

// Extract token usage and complete the task.
$data             = json_decode($body, true);
$promptTokens     = isset($data['usage']['prompt_tokens'])     ? (int) $data['usage']['prompt_tokens']     : null;
$completionTokens = isset($data['usage']['completion_tokens']) ? (int) $data['usage']['completion_tokens'] : null;
$totalTokens      = isset($data['usage']['total_tokens'])      ? (int) $data['usage']['total_tokens']      : null;

$taskFinished = true;
completeTask($taskId, 'done', $promptTokens, $completionTokens, $totalTokens);

// Forward the raw LM Studio response.
echo $body;
