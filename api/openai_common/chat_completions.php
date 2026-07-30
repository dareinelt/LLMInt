<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../lib/openai_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    openaiSendError(405, 'Method not allowed.');
}

$apiUser = openaiAuthenticateApiRequest();

$rawInput = (string) file_get_contents('php://input');
$decoded = json_decode($rawInput, true);
if (!is_array($decoded)) {
    openaiSendError(400, 'Invalid JSON body.');
}

$payload = openaiNormalizeChatPayload($decoded);

$_SESSION['admin_id'] = (int) $apiUser['user_id'];
$_SESSION['admin_user'] = (string) $apiUser['username'];

$GLOBALS['LLMINT_OPENAI_STRICT_MODE'] = true;
$GLOBALS['LLMINT_OPENAI_TOOL_MODE'] = ((string) ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] ?? 'disabled')) === 'enabled'
    ? 'enabled'
    : 'disabled';
$GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

require __DIR__ . '/../chat.php';
