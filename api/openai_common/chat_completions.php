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

$payload = openaiNormalizeChatPayload($decoded);

$GLOBALS['LLMINT_OPENAI_STRICT_MODE'] = true;
$GLOBALS['LLMINT_OPENAI_TOOL_MODE'] = ((string) ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] ?? 'disabled')) === 'enabled'
    ? 'enabled'
    : 'disabled';
$GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

openaiBeginAnonymousApiRequest($apiKey);

$requestedModel = trim((string) ($decoded['model'] ?? ''));
writeLog('info', 'Zugriff über OpenAI-kompatible API von ' . getClientIp()
    . ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] === 'enabled' ? ' (mit Tools)' : ' (ohne Tools)')
    . '; angefordertes Modell: ' . ($requestedModel !== '' ? mb_substr($requestedModel, 0, 100) : '–')
    . ', verwendet: Gast-Standardmodell ' . $payload['model'] . '.');

require __DIR__ . '/../chat.php';
