<?php

/**
 * api/vision.php
 *
 * Shared helper for analysing an image with the configured vision model.
 * Used for image uploads as well as for the rasterised pages of a PDF
 * (see api/pdf_render.php).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/balancer.php';

if (!defined('VISION_DEFAULT_PROMPT')) {
    define(
        'VISION_DEFAULT_PROMPT',
        'Extrahiere alle in diesem Dokument enthaltenen Informationen vollständig und strukturiert. '
        . 'Gib den vollständigen Textinhalt wieder und liste alle relevanten Daten, Fakten und Details auf. '
        . 'Verwende Deutsch.'
    );
}

/** Name of the vision model configured in the admin area ('' when unset). */
function visionModelName(): string
{
    return trim(getSetting('vision_model', ''));
}

/** Whether a vision model is configured. */
function visionModelConfigured(): bool
{
    return visionModelName() !== '';
}

/**
 * Send a single image to the vision model and return the extracted text.
 *
 * Endpoint selection, task accounting and error handling are identical for all
 * callers, so they live here instead of being duplicated per upload kind.
 *
 * @param string $imagePath Absolute path of the image file.
 * @param string $mimeType  MIME type of that file (e.g. image/jpeg).
 * @param string $prompt    Instruction for the model.
 *
 * @return array{ok:bool,text?:string,error?:string,usage?:array{prompt:int,completion:int,total:int}}
 */
function analyzeImageWithVision(string $imagePath, string $mimeType, string $prompt = VISION_DEFAULT_PROMPT): array
{
    $visionModel = visionModelName();
    if ($visionModel === '') {
        return [
            'ok'    => false,
            'error' => 'Kein Vision-Modell konfiguriert. Bitte im Adminbereich unter Anfragenhandling ein Vision-Modell auswählen.',
        ];
    }

    if (!is_file($imagePath)) {
        return ['ok' => false, 'error' => 'Bilddatei nicht gefunden.'];
    }

    try {
        $slot = pickEndpointForModel($visionModel);
    } catch (Throwable $e) {
        $slot = null;
    }

    if ($slot === null) {
        return ['ok' => false, 'error' => 'Kein aktiver Endpunkt für das Vision-Modell verfügbar.'];
    }

    $endpoint  = $slot['endpoint'];
    $taskId    = $slot['task_id'];
    $baseUrl   = rtrim($endpoint['base_url'], '/');
    $epTimeout = max(60, (int) $endpoint['timeout']);

    $imageData = @file_get_contents($imagePath);
    if ($imageData === false) {
        try { completeTask($taskId, 'error'); } catch (Throwable $_e) {}

        return ['ok' => false, 'error' => 'Bilddatei konnte nicht gelesen werden.'];
    }

    $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

    $payload = [
        'model'    => $endpoint['default_model'] !== '' ? $endpoint['default_model'] : $visionModel,
        'stream'   => false,
        'messages' => [
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type'      => 'image_url',
                        'image_url' => ['url' => $dataUrl],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ],
        ],
        'temperature' => 0.1,
        'max_tokens'  => -1,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $epTimeout,
    ]);

    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        try { completeTask($taskId, 'error'); } catch (Throwable $_e) {}

        return ['ok' => false, 'error' => 'Vision-Modell nicht erreichbar: ' . $curlErr];
    }

    $data = json_decode((string) $body, true);

    if ($httpCode !== 200 || !is_array($data)) {
        $errMsg = isset($data['error']['message'])
            ? (string) $data['error']['message']
            : 'Vision-Modell Fehler (HTTP ' . $httpCode . ')';
        try { completeTask($taskId, 'error'); } catch (Throwable $_e) {}

        return ['ok' => false, 'error' => $errMsg];
    }

    // The content may be a plain string or an array of typed parts.
    $content    = '';
    $msgContent = $data['choices'][0]['message']['content'] ?? '';
    if (is_string($msgContent)) {
        $content = $msgContent;
    } elseif (is_array($msgContent)) {
        foreach ($msgContent as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                $content .= $part['text'];
            }
        }
    }

    $usage = [
        'prompt'     => (int) ($data['usage']['prompt_tokens']     ?? 0),
        'completion' => (int) ($data['usage']['completion_tokens'] ?? 0),
        'total'      => (int) ($data['usage']['total_tokens']      ?? 0),
    ];

    try {
        completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total']);
    } catch (Throwable $_e) {}

    return ['ok' => true, 'text' => $content, 'usage' => $usage];
}
