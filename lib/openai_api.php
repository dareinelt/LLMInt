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

function openaiAuthenticateApiRequest(): array
{
    $token = openaiReadBearerToken();
    if ($token === '') {
        openaiSendError(401, 'Missing Authorization bearer token.', 'authentication_error');
    }

    $hash = openaiApiKeyHash($token);

    try {
        $stmt = getDb()->prepare(
            'SELECT ak.id, ak.user_id, u.username
               FROM api_keys ak
               JOIN users u ON u.id = ak.user_id
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
        openaiSendError(401, 'Invalid API key.', 'authentication_error');
    }

    try {
        getDb()->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    } catch (Throwable $e) {
        // best-effort
    }

    return [
        'key_id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'username' => (string) $row['username'],
    ];
}

function openaiAvailableModels(): array
{
    $rows = getDb()->query(
        "SELECT DISTINCT default_model
           FROM endpoints
          WHERE is_active = 1
            AND default_model <> ''
          ORDER BY default_model ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $models = [];
    foreach ($rows as $row) {
        $model = trim((string) ($row['default_model'] ?? ''));
        if ($model !== '') {
            $models[] = $model;
        }
    }

    return $models;
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

function openaiNormalizeChatPayload(array $input): array
{
    $model = trim((string) ($input['model'] ?? ''));
    if ($model === '') {
        openaiSendError(400, 'Field "model" is required.');
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

    return $payload;
}
