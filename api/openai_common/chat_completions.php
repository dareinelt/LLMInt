<?php

declare(strict_types=1);

// API clients act as anonymous visitors: no PHP session is started, so a
// browser login (session cookie) can never be inherited or modified.

require_once __DIR__ . '/../../lib/openai_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    openaiSendError(405, 'Method not allowed.');
}

$apiKey = openaiAuthenticateApiRequest();

$rawInput = (string) file_get_contents('php://input');
$decoded = json_decode($rawInput, true);
if (!is_array($decoded)) {
    openaiSendError(400, 'Invalid JSON body.');
}

$payload = openaiNormalizeChatPayload($decoded, openaiResolveApiKeyModel($apiKey));

$GLOBALS['LLMINT_OPENAI_STRICT_MODE'] = true;
$GLOBALS['LLMINT_OPENAI_TOOL_MODE'] = ((string) ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] ?? 'disabled')) === 'enabled'
    ? 'enabled'
    : 'disabled';

// The tool-enabled endpoint reaches the web and the document store. Unless an
// administrator explicitly allows it, it therefore requires a valid API key.
if ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] === 'enabled'
    && $apiKey === null
    && !openaiAnonymousToolAccessAllowed()) {
    openaiSendError(
        401,
        'The tool-enabled API requires a valid API key.',
        'invalid_request_error',
        'invalid_api_key'
    );
}

$GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

openaiBeginAnonymousApiRequest($apiKey);

// Count the call for the API cloud of the admin topology: per API key, or in
// the shared "without key" entry when no usable key was presented. Model
// listing requests are not counted – only calls that actually run inference.
recordApiCallUsage($apiKey !== null ? (int) $apiKey['key_id'] : null, (string) $payload['model']);

$requestedModel = trim((string) ($decoded['model'] ?? ''));
writeLog('info', 'Zugriff über OpenAI-kompatible API von ' . getClientIp()
    . ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] === 'enabled' ? ' (mit Tools)' : ' (ohne Tools)')
    . '; angefordertes Modell: ' . ($requestedModel !== '' ? mb_substr($requestedModel, 0, 100) : '–')
    . ', verwendet: ' . $payload['model']
    . ($apiKey !== null && ($apiKey['model'] ?? '') !== '' ? ' (API-Key-Modell)' : ' (Standardmodell)')
    . '.');

require __DIR__ . '/../chat.php';
