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

function buildSearxngSearchUrl(string $baseUrl, string $query): string
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Ungültige SearXNG-URL.');
    }

    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if ($path === '') {
        $path = '/search';
    } elseif (!preg_match('#/search$#', $path)) {
        $path .= '/search';
    }

    $url = $parts['scheme'] . '://';
    if (isset($parts['user'])) {
        $url .= $parts['user'];
        if (isset($parts['pass'])) {
            $url .= ':' . $parts['pass'];
        }
        $url .= '@';
    }
    $url .= $parts['host'];
    if (isset($parts['port'])) {
        $url .= ':' . $parts['port'];
    }
    $url .= $path;

    return $url . '?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'language' => 'de',
        'safesearch' => '0',
    ]);
}

function runSearxngSearch(string $baseUrl, string $query, int $timeout = 15): array
{
    $url = buildSearxngSearchUrl($baseUrl, $query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new RuntimeException('SearXNG nicht erreichbar: ' . $curlErr);
    }

    $data = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($data)) {
        throw new RuntimeException('Unerwartete Antwort von SearXNG (HTTP ' . $httpCode . ').');
    }

    $results = [];
    foreach (array_slice($data['results'] ?? [], 0, 5) as $result) {
        if (!is_array($result)) {
            continue;
        }
        $results[] = [
            'title' => (string) ($result['title'] ?? ''),
            'url' => (string) ($result['url'] ?? ''),
            'snippet' => (string) ($result['content'] ?? ''),
            'source' => (string) ($result['engine'] ?? ''),
        ];
    }

    return [
        'query' => $query,
        'result_count' => count($results),
        'results' => $results,
    ];
}

function createSearchToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'search_web',
            'description' => 'Suche aktuelle Informationen im Web über SearXNG.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Suchanfrage für aktuelle Informationen im Web.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ]];
}

function extractUsage(array $data): array
{
    return [
        'prompt' => max(0, (int) ($data['usage']['prompt_tokens'] ?? 0)),
        'completion' => max(0, (int) ($data['usage']['completion_tokens'] ?? 0)),
        'total' => max(0, (int) ($data['usage']['total_tokens'] ?? 0)),
    ];
}

function normalizeAssistantContent(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $item) {
        if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text']) && is_string($item['text'])) {
            $parts[] = $item['text'];
        }
    }

    return implode("\n", $parts);
}

function emitSyntheticStream(array $data): void
{
    $content = normalizeAssistantContent($data['choices'][0]['message']['content'] ?? '');
    $id = (string) ($data['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(8))));
    $created = (int) ($data['created'] ?? time());
    $model = (string) ($data['model'] ?? '');

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    if ($content !== '') {
        echo 'data: ' . json_encode([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => ['content' => $content],
                'finish_reason' => null,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    echo 'data: ' . json_encode([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'delta' => new stdClass(),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'stop',
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "data: [DONE]\n\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

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
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));

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

$clientRequestedStream = isset($payload['stream']) && $payload['stream'] === true;
$useSearchTool = $searxngBaseUrl !== '';
$stream = $clientRequestedStream && !$useSearchTool;

// Forward only the fields LM Studio expects.
$forwardPayload = [
    'model'       => $payload['model'],
    'messages'    => $payload['messages'],
    'stream'      => $stream,
    'temperature' => $payload['temperature'] ?? 0.7,
    'max_tokens'  => $payload['max_tokens']  ?? -1,
];

$url = $baseUrl . '/chat/completions';

if ($useSearchTool) {
    $messages = $payload['messages'];
    $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
    $finalData = null;

    for ($iteration = 0; $iteration < 4; $iteration++) {
        $searchPayload = $forwardPayload;
        $searchPayload['messages'] = $messages;
        $searchPayload['stream'] = false;
        $searchPayload['tools'] = createSearchToolDefinition();
        $searchPayload['tool_choice'] = 'auto';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($searchPayload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        header('Content-Type: application/json; charset=utf-8');

        if ($curlErr !== '') {
            $taskFinished = true;
            completeTask($taskId, 'error');
            http_response_code(502);
            echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
            exit;
        }

        $data = json_decode($body, true);
        if ($httpCode !== 200 || !is_array($data)) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
            $taskFinished = true;
            completeTask($taskId, 'error');
            http_response_code(502);
            echo json_encode(['error' => $msg]);
            exit;
        }

        $stepUsage = extractUsage($data);
        $usage['prompt'] += $stepUsage['prompt'];
        $usage['completion'] += $stepUsage['completion'];
        $usage['total'] += $stepUsage['total'];

        $message = $data['choices'][0]['message'] ?? null;
        $toolCalls = is_array($message) ? ($message['tool_calls'] ?? []) : [];
        if (!is_array($toolCalls) || $toolCalls === []) {
            $finalData = $data;
            break;
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $toolCalls,
        ];

        foreach ($toolCalls as $toolCall) {
            $toolResult = ['error' => 'Unbekannter Tool-Aufruf.'];

            if (($toolCall['function']['name'] ?? '') === 'search_web') {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $query = trim((string) ($args['query'] ?? ''));

                if ($query === '') {
                    $toolResult = ['error' => 'Leere Suchanfrage.'];
                } else {
                    try {
                        $toolResult = runSearxngSearch($searxngBaseUrl, mb_substr($query, 0, 400), min($timeout, 15));
                    } catch (Throwable $e) {
                        $toolResult = ['error' => $e->getMessage()];
                    }
                }
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    if ($finalData === null) {
        $taskFinished = true;
        completeTask($taskId, 'error');
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Der Suchdialog mit dem Modell konnte nicht abgeschlossen werden.']);
        exit;
    }

    $finalData['usage'] = [
        'prompt_tokens' => $usage['prompt'],
        'completion_tokens' => $usage['completion'],
        'total_tokens' => $usage['total'],
    ];

    $taskFinished = true;
    completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total']);

    if ($clientRequestedStream) {
        emitSyntheticStream($finalData);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

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
