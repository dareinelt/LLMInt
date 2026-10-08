<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function openaiIsStrictMode(): bool
{
    return !empty($GLOBALS['LLMINT_OPENAI_STRICT_MODE']);
}

function openaiErrorBody(string $message, string $type = 'invalid_request_error', mixed $code = null): array
{
    return [
        'error' => [
            'message' => $message,
            'type' => $type,
            'code' => $code,
        ],
    ];
}

function openaiSendError(int $statusCode, string $message, string $type = 'invalid_request_error', mixed $code = null): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(openaiErrorBody($message, $type, $code), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function openaiApiKeyHash(string $plainKey): string
{
    return hash('sha256', $plainKey);
}

function openaiGenerateApiKeyMaterial(): array
{
    $plain = 'sk-' . bin2hex(random_bytes(24));
    return [
        'plain' => $plain,
        'hash' => openaiApiKeyHash($plain),
        'prefix' => substr($plain, 0, 14),
    ];
}

function openaiReadBearerToken(): string
{
    $header = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    $header = trim((string) $value);
                    break;
                }
            }
        }
    }

    if ($header === '' || stripos($header, 'Bearer ') !== 0) {
        return '';
    }

    return trim(substr($header, 7));
}

/**
 * Validate the optional API key of an OpenAI-compatible request.
 *
 * Without an Authorization header – or with an unknown, inactive or expired
 * key – the request is accepted anonymously. A valid key is only used for
 * identification in the log and for the optional model it pins – API clients
 * never act as the key owner.
 *
 * @return array{key_id:int,name:string,model:string}|null Key info, or null without key.
 */
function openaiAuthenticateApiRequest(): ?array
{
    $token = openaiReadBearerToken();
    if ($token === '') {
        return null;
    }

    $hash = openaiApiKeyHash($token);

    try {
        $stmt = getDb()->prepare(
            'SELECT ak.id, ak.name, ak.model
               FROM api_keys ak
              WHERE ak.api_key_hash = ?
                AND ak.is_active = 1
                AND (ak.expires_at IS NULL OR ak.expires_at > NOW())
              LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        openaiSendError(500, 'API key validation failed.', 'server_error');
    }

    if (!$row) {
        // Anonymous access is allowed anyway, and many OpenAI clients insist
        // on sending some placeholder key – treat unknown keys as anonymous.
        return null;
    }

    try {
        getDb()->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    } catch (Throwable $e) {
        // best-effort
    }

    return [
        'key_id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'model' => trim((string) ($row['model'] ?? '')),
    ];
}

/**
 * Treat the current request as an anonymous visitor and tag all log entries.
 *
 * The caller's PHP session (if a cookie was sent at all) is never loaded, so an
 * API request can neither inherit nor modify a browser login.
 */
function openaiBeginAnonymousApiRequest(?array $apiKey): void
{
    $_SESSION = [];

    $GLOBALS['LLMINT_API_LOG_TAG'] = $apiKey !== null
        ? '[API · Key „' . $apiKey['name'] . '“]'
        : '[API]';
}

/**
 * Public base URL of the OpenAI-compatible API ("…/api/openai/v1" or
 * "…/api/openai-tools/v1"), derived from the current request. Honours
 * X-Forwarded-Proto/-Host/-Prefix when LLMInt runs behind a reverse proxy.
 */
function openaiPublicBaseUrl(bool $withTools = false): string
{
    return appPublicBaseUrl(true) . '/api/' . ($withTools ? 'openai-tools' : 'openai') . '/v1';
}

/**
 * Effective model of an OpenAI-compatible API request.
 *
 * A key can pin a model; it is used as long as an active endpoint still serves
 * it. Without a pinned (or no longer available) model – and for anonymous
 * requests – the guest default model applies.
 */
function openaiResolveApiKeyModel(?array $apiKey): string
{
    $model = trim((string) ($apiKey['model'] ?? ''));
    if ($model !== '' && in_array($model, listActiveEndpointModels(), true)) {
        return $model;
    }

    return getGuestDefaultModel();
}

/**
 * Models offered via the API: the model pinned to the API key, otherwise the
 * guest default model. Routing and load balancing take it from there.
 */
function openaiAvailableModels(?array $apiKey = null): array
{
    $model = openaiResolveApiKeyModel($apiKey);
    return $model !== '' ? [$model] : [];
}

function openaiNormalizeMessages(array $messages): array
{
    $normalized = [];

    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            openaiSendError(400, 'messages must be an array of objects.');
        }

        $role = isset($msg['role']) ? (string) $msg['role'] : '';
        if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) {
            openaiSendError(400, 'Invalid message role: ' . $role);
        }

        $entry = ['role' => $role];

        if (array_key_exists('content', $msg)) {
            $entry['content'] = $msg['content'];
        } elseif ($role === 'assistant' && isset($msg['tool_calls'])) {
            $entry['content'] = null;
        } else {
            openaiSendError(400, 'Each message must include content.');
        }

        if (isset($msg['name']) && is_string($msg['name'])) {
            $entry['name'] = $msg['name'];
        }
        if (isset($msg['tool_call_id']) && is_string($msg['tool_call_id'])) {
            $entry['tool_call_id'] = $msg['tool_call_id'];
        }
        if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
            $entry['tool_calls'] = $msg['tool_calls'];
        }

        $normalized[] = $entry;
    }

    return $normalized;
}

/**
 * @param string $model Model to use; empty string falls back to the guest
 *                      default model (anonymous access or key without model).
 */
function openaiNormalizeChatPayload(array $input, string $model = ''): array
{
    // Like an anonymous web visitor, the API uses the guest default model –
    // unless the request carries an API key with a pinned model. The "model"
    // field of the request is always ignored.
    if ($model === '') {
        $model = getGuestDefaultModel();
    }
    if ($model === '') {
        openaiSendError(503, 'No default model configured.', 'server_error');
    }

    if (!isset($input['messages']) || !is_array($input['messages'])) {
        openaiSendError(400, 'Field "messages" is required and must be an array.');
    }

    $payload = [
        'model' => $model,
        'messages' => openaiNormalizeMessages($input['messages']),
        'stream' => !empty($input['stream']),
    ];

    if (array_key_exists('temperature', $input) && is_numeric($input['temperature'])) {
        $payload['temperature'] = (float) $input['temperature'];
    }

    $maxTokens = $input['max_tokens'] ?? $input['max_completion_tokens'] ?? null;
    if ($maxTokens !== null && is_numeric($maxTokens)) {
        $payload['max_tokens'] = (int) $maxTokens;
    }

    if (array_key_exists('top_p', $input) && is_numeric($input['top_p'])) {
        $payload['top_p'] = (float) $input['top_p'];
    }

    if (array_key_exists('stop', $input) && (is_string($input['stop']) || is_array($input['stop']))) {
        $payload['stop'] = $input['stop'];
    }

    if (isset($input['reasoning_effort']) && is_string($input['reasoning_effort'])) {
        $payload['reasoning_effort'] = $input['reasoning_effort'];
    }

    return $payload;
}
