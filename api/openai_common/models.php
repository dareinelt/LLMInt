<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/openai_api.php';

$apiKey = openaiAuthenticateApiRequest();

$models = openaiAvailableModels($apiKey);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'object' => 'list',
    'data' => array_map(static fn(string $model): array => [
        'id' => $model,
        'object' => 'model',
        'created' => 0,
        'owned_by' => 'llmint',
    ], $models),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
