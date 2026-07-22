<?php
/**
 * api/chat.php
 *
 * Proxies a POST /v1/chat/completions request to LM Studio.
 *
 * Expected JSON body:
 *   {
 *     "model":    "model-id",
 *     "messages": [ { "role": "user"|"assistant"|"system", "content": "..." }, ... ],
 *     "stream":   false   (optional, default false)
 *   }
 *
 * Streaming (stream: true) is supported – the response is forwarded
 * as Server-Sent Events (text/event-stream).
 *
 * Non-streaming response shape (success):
 *   Full OpenAI-style chat completion object from LM Studio.
 *
 * Response shape (error):
 *   { "error": "Human-readable message" }
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

$raw = file_get_contents('php://input');
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

// Endpoint is managed exclusively via the admin panel and loaded from the database.
$baseUrl = LMSTUDIO_BASE_URL;

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
    CURLOPT_RETURNTRANSFER => !$stream,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
    CURLOPT_TIMEOUT        => LMSTUDIO_TIMEOUT,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

if ($stream) {
    // Stream the Server-Sent Events from LM Studio directly to the client.
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data): int {
        echo $data;
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        return strlen($data);
    });

    curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        echo "data: " . json_encode(['error' => $curlErr]) . "\n\n";
        flush();
    }
    exit;
}

// Non-streaming path.
$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200) {
    $data = json_decode($body, true);
    $msg  = isset($data['error']['message'])
        ? $data['error']['message']
        : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
    http_response_code(502);
    echo json_encode(['error' => $msg]);
    exit;
}

// Forward the raw LM Studio response.
echo $body;
