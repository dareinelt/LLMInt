<?php

/**
 * lib/speech_dictation.php
 *
 * Shared helpers for the speech-recognition / dictation feature.
 *
 * Pipeline: browser microphone → 16 kHz mono WAV → whisper.cpp server →
 * recognised text → word buffer → dictation model (Qwen3.5-2B Q4) →
 * formatted text → LLMInt input field.
 *
 * Whisper is reached over HTTP exactly like the document converter
 * (api/doc_convert.php): the base URL comes from the WHISPER_URL environment
 * variable (docker-compose) and falls back to the `speech_dictation_whisper_url`
 * setting so an administrator can override it at runtime. PHP never shells out
 * to whisper.cpp – no browser-supplied value can reach a shell.
 *
 * The dictation model (Qwen3.5-2B Q4) is served by a dedicated llama.cpp
 * container (`qwen` service in docker-compose.yml) reached through QWEN_URL.
 * When no dedicated server is configured, the model is resolved through the
 * existing endpoint pool / balancer (pickEndpointForModel / completeTask) so
 * endpoint selection, concurrency limits, circuit breaking and task accounting
 * stay in one place.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/balancer.php';

/** Maximum length of the context tail handed to the dictation model. */
const SPEECH_DICTATION_CONTEXT_CHARS = 400;

/**
 * Built-in dictation command table.
 *
 * `phrase`  – the words as they are spoken (matched case-insensitively,
 *             word-boundary aware, longest phrase wins).
 * `type`    – insert | newline | paragraph | delete_word | delete_sentence
 * `value`   – literal text for `insert` (leading/trailing spaces are honoured)
 *
 * The table is stored as JSON in the `speech_dictation_commands` setting and
 * can therefore be extended by the administrator without a code change.
 *
 * @return array<int,array{phrase:string,type:string,value:string}>
 */
function speechDictationDefaultCommands(): array
{
    return [
        ['phrase' => 'punkt',                 'type' => 'insert',          'value' => '.'],
        ['phrase' => 'komma',                 'type' => 'insert',          'value' => ','],
        ['phrase' => 'fragezeichen',          'type' => 'insert',          'value' => '?'],
        ['phrase' => 'ausrufezeichen',        'type' => 'insert',          'value' => '!'],
        ['phrase' => 'doppelpunkt',           'type' => 'insert',          'value' => ':'],
        ['phrase' => 'semikolon',             'type' => 'insert',          'value' => ';'],
        ['phrase' => 'gedankenstrich',        'type' => 'insert',          'value' => ' – '],
        ['phrase' => 'bindestrich',           'type' => 'insert',          'value' => '-'],
        ['phrase' => 'prozent',               'type' => 'insert',          'value' => '%'],
        ['phrase' => 'klammer auf',           'type' => 'insert',          'value' => ' ('],
        ['phrase' => 'klammer zu',            'type' => 'insert',          'value' => ')'],
        ['phrase' => 'anführungszeichen auf', 'type' => 'insert',          'value' => ' „'],
        ['phrase' => 'anführungszeichen zu',  'type' => 'insert',          'value' => '"'],
        ['phrase' => 'neue zeile',            'type' => 'newline',         'value' => ''],
        ['phrase' => 'neuer absatz',          'type' => 'paragraph',       'value' => ''],
        ['phrase' => 'lösche letztes wort',   'type' => 'delete_word',     'value' => ''],
        ['phrase' => 'lösche letzten satz',   'type' => 'delete_sentence', 'value' => ''],
    ];
}

/**
 * Built-in quick-command pills shown after a dictation was stopped.
 *
 * These are executed by the regular LLMInt default model via api/chat.php,
 * not by the dictation model.
 *
 * @return array<int,array{id:string,label:string,emoji:string,instruction:string}>
 */
function speechDictationDefaultPills(): array
{
    return [
        [
            'id'          => 'serioeser',
            'label'       => 'Seriöser',
            'emoji'       => '👔',
            'instruction' => 'Formuliere den folgenden Text seriöser und geschäftstauglicher. '
                . 'Behalte Inhalt, Aussage und Sprache bei. Gib nur den überarbeiteten Text aus.',
        ],
        [
            'id'          => 'kuerzer',
            'label'       => 'Kürzer',
            'emoji'       => '✂️',
            'instruction' => 'Kürze den folgenden Text auf das Wesentliche, ohne wichtige Informationen zu verlieren. '
                . 'Gib nur den gekürzten Text aus.',
        ],
        [
            'id'          => 'professioneller',
            'label'       => 'Professioneller',
            'emoji'       => '🎩',
            'instruction' => 'Formuliere den folgenden Text professioneller und präziser. '
                . 'Gib nur den überarbeiteten Text aus.',
        ],
        [
            'id'          => 'freundlicher',
            'label'       => 'Freundlicher',
            'emoji'       => '🙂',
            'instruction' => 'Formuliere den folgenden Text freundlicher und wertschätzender, '
                . 'ohne die Aussage zu verändern. Gib nur den überarbeiteten Text aus.',
        ],
        [
            'id'          => 'zusammenfassen',
            'label'       => 'Zusammenfassen',
            'emoji'       => '📝',
            'instruction' => 'Fasse den folgenden Text in wenigen Sätzen zusammen. '
                . 'Gib nur die Zusammenfassung aus.',
        ],
        [
            'id'          => 'englisch',
            'label'       => 'Übersetzen auf Englisch',
            'emoji'       => '🌐',
            'instruction' => 'Übersetze den folgenden Text ins Englische. '
                . 'Gib nur die Übersetzung aus.',
        ],
    ];
}

/**
 * Default system prompt of the dictation model.
 *
 * The command list is injected so the prompt always matches the configured
 * command table.
 */
function speechDictationDefaultPrompt(): string
{
    return "Du bist ein Diktatprozessor für Deutsch. Du bekommst ein Fragment aus einer "
        . "Spracherkennung und gibst nur die korrigierte Fassung zurück.\n\n"
        . "Regeln:\n"
        . "- Du bist kein Chat-Assistent. Antworte nie auf den Inhalt.\n"
        . "- Erfinde, kürze und ergänze nichts.\n"
        . "- Korrigiere Rechtschreibung, Grammatik und Zeichensetzung. Schreibe Satzanfänge groß.\n"
        . "- Entferne Füllwörter wie \"ähm\", \"äh\", \"halt\", \"also\".\n"
        . "- Wandle Diktatbefehle in Zeichen/Formatierung um und lösche die Befehlswörter. "
        . speechDictationPromptCommandList(false) . "\n"
        . "- Befehls- und Füllwörter dürfen im Ergebnis nicht mehr als Wörter vorkommen.\n"
        . "- Füllwörter am Satzanfang (\"also\", \"halt\", \"ähm\") werden ebenfalls gestrichen.\n"
        . "- Ergänze am Ende kein Satzendezeichen, wenn keines diktiert wurde.\n"
        . "- Schreibe alles in eine Zeile und füge selbst keine Zeilenumbrüche ein.\n"
        . "- Gib nur den Text aus, ohne Erklärung.\n\n"
        // Qwen3.5-2B follows the rules noticeably more reliably when it also
        // sees them applied, so the default prompt ships with worked examples.
        // Line-break commands are absent on purpose: the caller cuts them out
        // of the fragment and re-inserts the breaks itself, because the model
        // consumes the command word but then emits a space instead of a break.
        . "Beispiele:\n"
        . "Eingabe: hallo ähm wie geht es dir fragezeichen\n"
        . "Ausgabe: Hallo, wie geht es dir?\n\n"
        . "Eingabe: also halt ich wollte sagen dass das projekt fertig ist punkt\n"
        . "Ausgabe: Ich wollte sagen, dass das Projekt fertig ist.\n\n"
        . "Eingabe: ähm also ich wollte nur kurz sagen dass alles geklappt hat punkt\n"
        . "Ausgabe: Ich wollte nur kurz sagen, dass alles geklappt hat.\n\n"
        . "Eingabe: wir treffen uns morgen komma wenn das wetter passt punkt\n"
        . "Ausgabe: Wir treffen uns morgen, wenn das Wetter passt.\n\n"
        . "Eingabe: das ist ein test der dikat funktion\n"
        . "Ausgabe: Das ist ein Test der Diktatfunktion\n\n"
        . "Eingabe: das war ein langer tag ausrufezeichen ich bin müde punkt\n"
        . "Ausgabe: Das war ein langer Tag! Ich bin müde.";
}

/**
 * The configured commands as one compact, grouped line for the system prompt.
 *
 * Rendering a bullet per command made the prompt long enough that the dictation
 * model started dropping individual rules, so the commands are grouped by kind
 * and joined with commas instead. Commands that fit no known kind end up in the
 * generic "Zeichen" group.
 *
 * @param bool $includeBreaks Whether to list the line-break commands. The
 *        default prompt passes false because those commands never reach the
 *        model – see speechDictationSplitAtBreaks().
 */
function speechDictationPromptCommandList(bool $includeBreaks = true): string
{
    $groups = [
        'Satzzeichen' => [],
        'Klammern'    => [],
        'Anführung'   => [],
        'Umbruch'     => [],
        'Löschen'     => [],
        'Zeichen'     => [],
    ];

    foreach (speechDictationCommands() as $cmd) {
        $phrase  = trim((string) ($cmd['phrase'] ?? ''));
        $preview = speechDictationCommandPreview($cmd);
        if ($phrase === '' || $preview === '') {
            continue;
        }

        $entry = '"' . $phrase . '"→' . $preview;
        $type  = (string) ($cmd['type'] ?? 'insert');
        $value = (string) ($cmd['value'] ?? '');

        if ($type === 'newline' || $type === 'paragraph') {
            if ($includeBreaks) {
                $groups['Umbruch'][] = $entry;
            }
            continue;
        }
        if ($type === 'delete_word' || $type === 'delete_sentence') {
            $groups['Löschen'][] = $entry;
        } elseif (preg_match('/^[.,!?;:–\-%]+$/u', trim($value)) === 1) {
            $groups['Satzzeichen'][] = $entry;
        } elseif (strpbrk($value, '()') !== false) {
            $groups['Klammern'][] = $entry;
        } elseif (preg_match('/[„“”«»"]/u', $value) === 1) {
            $groups['Anführung'][] = $entry;
        } else {
            $groups['Zeichen'][] = $entry;
        }
    }

    $parts = [];
    foreach ($groups as $label => $entries) {
        if ($entries !== []) {
            $parts[] = $label . ': ' . implode(', ', $entries) . '.';
        }
    }

    return implode(' ', $parts);
}

/**
 * Human-readable description of what a command does (used in the prompt and in
 * the admin UI).
 *
 * @param array{phrase?:string,type?:string,value?:string} $cmd
 */
function speechDictationCommandPreview(array $cmd): string
{
    switch ((string) ($cmd['type'] ?? 'insert')) {
        case 'newline':
            return 'Zeilenumbruch';
        case 'paragraph':
            return 'Absatzumbruch (Leerzeile)';
        case 'delete_word':
            return 'lösche das zuletzt gesprochene Wort';
        case 'delete_sentence':
            return 'lösche den zuletzt gesprochenen Satz';
        case 'insert':
        default:
            $value = (string) ($cmd['value'] ?? '');
            return $value === '' ? '' : '"' . $value . '"';
    }
}

/**
 * The configured dictation command table (JSON in the settings table).
 *
 * @return array<int,array{phrase:string,type:string,value:string}>
 */
function speechDictationCommands(): array
{
    $raw = trim(getSetting('speech_dictation_commands', ''));
    if ($raw === '') {
        return speechDictationDefaultCommands();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return speechDictationDefaultCommands();
    }

    $allowed = ['insert', 'newline', 'paragraph', 'delete_word', 'delete_sentence'];
    $commands = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $phrase = trim((string) ($entry['phrase'] ?? ''));
        if ($phrase === '') {
            continue;
        }
        $type = (string) ($entry['type'] ?? 'insert');
        if (!in_array($type, $allowed, true)) {
            $type = 'insert';
        }
        $commands[] = [
            'phrase' => $phrase,
            'type'   => $type,
            'value'  => (string) ($entry['value'] ?? ''),
        ];
    }

    return $commands === [] ? speechDictationDefaultCommands() : $commands;
}

/**
 * The configured quick-command pills (JSON in the settings table).
 *
 * @return array<int,array{id:string,label:string,emoji:string,instruction:string}>
 */
function speechDictationPills(): array
{
    $raw = trim(getSetting('speech_dictation_pills', ''));
    if ($raw === '') {
        return speechDictationDefaultPills();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return speechDictationDefaultPills();
    }

    $pills = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $label = trim((string) ($entry['label'] ?? ''));
        $instruction = trim((string) ($entry['instruction'] ?? ''));
        if ($label === '' || $instruction === '') {
            continue;
        }
        $id = trim((string) ($entry['id'] ?? ''));
        if ($id === '') {
            $id = 'pill' . count($pills);
        }
        $pills[] = [
            'id'          => $id,
            'label'       => $label,
            'emoji'       => (string) ($entry['emoji'] ?? ''),
            'instruction' => $instruction,
        ];
    }

    return $pills === [] ? speechDictationDefaultPills() : $pills;
}

/** Look up a single pill by its id; returns null when unknown. */
function speechDictationPill(string $id): ?array
{
    foreach (speechDictationPills() as $pill) {
        if ($pill['id'] === $id) {
            return $pill;
        }
    }
    return null;
}

// ── Settings accessors ────────────────────────────────────────────────────────

/** Clamp a settings value to an integer range. */
function speechDictationIntSetting(string $key, string $default, int $min, int $max): int
{
    $value = (int) getSetting($key, $default);
    if ($value < $min) {
        $value = (int) $default;
    }
    return max($min, min($max, $value));
}

/** Whether the dictation feature is switched on in the admin area. */
function speechDictationEnabled(): bool
{
    return getSetting('speech_dictation_enabled', '1') === '1';
}

/**
 * Base URL of the whisper.cpp server.
 *
 * Environment first (docker-compose), then the settings table – identical to
 * docConvertBaseUrl(). An empty value means "not deployed".
 */
function speechDictationWhisperUrl(): string
{
    $url = trim((string) (getenv('WHISPER_URL') ?: ''));
    if ($url === '') {
        $url = trim(getSetting('speech_dictation_whisper_url', ''));
    }
    return rtrim($url, '/');
}

/** Whisper model label the deployment is expected to serve (e.g. "small"). */
function speechDictationWhisperModel(): string
{
    $model = trim(getSetting('speech_dictation_whisper_model', 'small'));
    return $model === '' ? 'small' : $model;
}

/** Request timeout for a single transcription, in seconds. */
function speechDictationWhisperTimeout(): int
{
    $timeout = (int) (getenv('WHISPER_TIMEOUT') ?: 0);
    if ($timeout <= 0) {
        $timeout = speechDictationIntSetting('speech_dictation_whisper_timeout', '120', 10, 600);
    }
    return max(10, min(600, $timeout));
}

/** Optional shared secret for the whisper server (X-Auth-Token header). */
function speechDictationWhisperToken(): string
{
    $token = trim((string) (getenv('WHISPER_TOKEN') ?: ''));
    if ($token === '') {
        $token = trim(getSetting('speech_dictation_whisper_token', ''));
    }
    return $token;
}

/** Spoken language handed to whisper ('auto' lets the model detect it). */
function speechDictationLanguage(): string
{
    $language = trim(getSetting('speech_dictation_language', 'de'));
    return $language === '' ? 'de' : $language;
}

/** Model label used for the dictation-command processing step. */
function speechDictationQwenModel(): string
{
    $model = trim(getSetting('speech_dictation_qwen_model', 'Qwen3.5-2B Q4'));
    return $model === '' ? 'Qwen3.5-2B Q4' : $model;
}

/**
 * Base URL of the dedicated Qwen3.5-2B llama.cpp server.
 *
 * Environment first (docker-compose), then the settings table – identical to
 * speechDictationWhisperUrl(). When empty, the dictation model is resolved
 * through the regular endpoint pool / balancer instead.
 */
function speechDictationQwenUrl(): string
{
    $url = trim((string) (getenv('QWEN_URL') ?: ''));
    if ($url === '') {
        $url = trim(getSetting('speech_dictation_qwen_url', ''));
    }
    return rtrim($url, '/');
}

/** Request timeout for one dictation-processing call, in seconds. */
function speechDictationQwenTimeout(): int
{
    $timeout = (int) (getenv('QWEN_TIMEOUT') ?: 0);
    if ($timeout <= 0) {
        $timeout = speechDictationIntSetting('speech_dictation_qwen_timeout', '60', 10, 300);
    }
    return max(10, min(300, $timeout));
}

/** Optional shared secret for the dedicated Qwen server (X-Auth-Token). */
function speechDictationQwenToken(): string
{
    $token = trim((string) (getenv('QWEN_TOKEN') ?: ''));
    if ($token === '') {
        $token = trim(getSetting('speech_dictation_qwen_token', ''));
    }
    return $token;
}

/**
 * Where the effective value of a speech setting comes from.
 *
 * The admin card needs this because environment variables (docker-compose)
 * take precedence over the settings table – without showing the origin, an
 * administrator would edit a field that has no effect.
 *
 * @return string 'env', 'setting' or 'none'.
 */
function speechDictationSettingSource(string $envName, string $settingKey): string
{
    if (trim((string) (getenv($envName) ?: '')) !== '') {
        return 'env';
    }
    return trim(getSetting($settingKey, '')) !== '' ? 'setting' : 'none';
}

/** Origin of the effective whisper server URL (see speechDictationSettingSource). */
function speechDictationWhisperUrlSource(): string
{
    return speechDictationSettingSource('WHISPER_URL', 'speech_dictation_whisper_url');
}

/** Origin of the effective dictation model URL (see speechDictationSettingSource). */
function speechDictationQwenUrlSource(): string
{
    return speechDictationSettingSource('QWEN_URL', 'speech_dictation_qwen_url');
}

/** Human-readable origin label for the admin card. */
function speechDictationSourceLabel(string $source): string
{
    switch ($source) {
        case 'env':
            return 'Umgebungsvariable (docker-compose)';
        case 'setting':
            return 'Einstellung in der Datenbank';
        default:
            return 'nicht konfiguriert';
    }
}

/** Number of trailing words held back until more context is available. */
function speechDictationBufferWords(): int
{
    return speechDictationIntSetting('speech_dictation_buffer_words', '4', 1, 50);
}

/** Seconds without new processed content before "Erkennung beenden" appears. */
function speechDictationStopTimeoutSeconds(): int
{
    return speechDictationIntSetting('speech_dictation_stop_timeout_seconds', '3', 1, 60);
}

/** Upper bound for a single audio segment sent to whisper, in seconds. */
function speechDictationMaxSegmentSeconds(): int
{
    return speechDictationIntSetting('speech_dictation_max_segment_seconds', '15', 3, 120);
}

/** Maximum accepted audio upload size, in bytes. */
function speechDictationMaxAudioBytes(): int
{
    $mb = speechDictationIntSetting('speech_dictation_max_audio_mb', '10', 1, 100);
    return $mb * 1024 * 1024;
}

/** System prompt of the dictation model. */
function speechDictationPrompt(): string
{
    $prompt = getSetting('speech_dictation_prompt', '');
    return trim($prompt) === '' ? speechDictationDefaultPrompt() : $prompt;
}

/**
 * Whether the microphone UI should be offered at all.
 *
 * Deliberately configuration-only (no network call) so the chat page can render
 * without waiting for the whisper container.
 */
function speechDictationConfigured(): bool
{
    return speechDictationEnabled() && speechDictationWhisperUrl() !== '';
}

// ── whisper.cpp server client ─────────────────────────────────────────────────

/**
 * Live health check of the whisper server (GET /health).
 *
 * @return array{ok:bool,message:string,http:int}
 */
function speechDictationWhisperHealth(): array
{
    $url = speechDictationWhisperUrl();
    if ($url === '') {
        return ['ok' => false, 'message' => 'Keine Whisper-URL konfiguriert.', 'http' => 0];
    }

    $ch = curl_init($url . '/health');
    $headers = ['Accept: application/json'];
    $token = speechDictationWhisperToken();
    if ($token !== '') {
        $headers[] = 'X-Auth-Token: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return ['ok' => false, 'message' => 'Whisper nicht erreichbar: ' . $curlErr, 'http' => 0];
    }
    if ($httpCode === 200) {
        return ['ok' => true, 'message' => 'Whisper erreichbar (HTTP 200).', 'http' => 200];
    }
    // Older builds do not expose /health; a 404 still proves the server is up.
    if ($httpCode === 404) {
        return [
            'ok'      => true,
            'message' => 'Whisper erreichbar, aber ohne /health-Endpunkt (ältere Version).',
            'http'    => 404,
        ];
    }

    return [
        'ok'      => false,
        'message' => 'Whisper meldet HTTP ' . $httpCode . '.',
        'http'    => $httpCode,
    ];
}

/**
 * Send one audio segment to the whisper server and return the transcript.
 *
 * @param string $path     Absolute path of the uploaded audio file.
 * @param string $filename Original file name (determines the format hint).
 * @param string $mime     MIME type of the file.
 *
 * @return array{ok:bool,text:string,error:string,http:int,duration_ms:int}
 */
function speechDictationTranscribeFile(string $path, string $filename, string $mime): array
{
    $url = speechDictationWhisperUrl();
    if ($url === '') {
        return [
            'ok'          => false,
            'text'        => '',
            'error'       => 'Whisper ist nicht konfiguriert.',
            'http'        => 0,
            'duration_ms' => 0,
        ];
    }
    if (!is_file($path)) {
        return [
            'ok'          => false,
            'text'        => '',
            'error'       => 'Audiodatei nicht gefunden.',
            'http'        => 0,
            'duration_ms' => 0,
        ];
    }

    $post = [
        'file'            => new CURLFile($path, $mime !== '' ? $mime : 'audio/wav', $filename),
        'response_format' => 'json',
        'temperature'     => '0.0',
    ];
    $language = speechDictationLanguage();
    if ($language !== '') {
        $post['language'] = $language;
    }

    $headers = ['Accept: application/json'];
    $token = speechDictationWhisperToken();
    if ($token !== '') {
        $headers[] = 'X-Auth-Token: ' . $token;
    }

    $startedAt = microtime(true);
    $ch = curl_init($url . '/inference');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => speechDictationWhisperTimeout(),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    if ($curlErr !== '') {
        return [
            'ok'          => false,
            'text'        => '',
            'error'       => 'Whisper nicht erreichbar: ' . $curlErr,
            'http'        => 0,
            'duration_ms' => $durationMs,
        ];
    }

    $bodyText = (string) $body;
    if ($httpCode !== 200) {
        $decoded = json_decode($bodyText, true);
        $message = is_array($decoded)
            ? (string) ($decoded['error'] ?? $decoded['message'] ?? '')
            : '';
        if ($message === '') {
            $message = 'Whisper-Fehler (HTTP ' . $httpCode . ')';
        }
        return [
            'ok'          => false,
            'text'        => '',
            'error'       => $message,
            'http'        => $httpCode,
            'duration_ms' => $durationMs,
        ];
    }

    $decoded = json_decode($bodyText, true);
    if (is_array($decoded) && isset($decoded['text'])) {
        $text = (string) $decoded['text'];
    } elseif (is_string($decoded)) {
        $text = $decoded;
    } else {
        // Plain-text responses of older builds.
        $text = $bodyText;
    }

    return [
        'ok'          => true,
        'text'        => trim($text),
        'error'       => '',
        'http'        => $httpCode,
        'duration_ms' => $durationMs,
    ];
}

// ── Dedicated Qwen3.5-2B (llama.cpp) server ───────────────────────────────────

/**
 * Live health check of the dedicated llama.cpp server (GET /health).
 *
 * @return array{ok:bool,message:string,http:int}
 */
function speechDictationQwenHealth(): array
{
    $url = speechDictationQwenUrl();
    if ($url === '') {
        return ['ok' => true, 'message' => 'Kein eigener Qwen-Server konfiguriert – es wird der Endpunkt-Pool verwendet.', 'http' => 0];
    }

    $headers = ['Accept: application/json'];
    $token = speechDictationQwenToken();
    if ($token !== '') {
        $headers[] = 'X-Auth-Token: ' . $token;
    }

    $ch = curl_init($url . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return ['ok' => false, 'message' => 'Qwen-Server nicht erreichbar: ' . $curlErr, 'http' => 0];
    }
    if ($httpCode === 200) {
        $decoded = json_decode((string) $body, true);
        $status  = is_array($decoded) ? (string) ($decoded['status'] ?? 'ok') : 'ok';
        return ['ok' => true, 'message' => 'Qwen-Server erreichbar (' . $status . ').', 'http' => 200];
    }

    return ['ok' => false, 'message' => 'Qwen-Server meldet HTTP ' . $httpCode . '.', 'http' => $httpCode];
}

// ── Dictation-command post-processing ─────────────────────────────────────────
/**
 * Strip reasoning blocks, code fences and other decoration a model may add.
 */
function speechDictationCleanModelOutput(string $text): string
{
    // Hybrid-reasoning models (Qwen3 & friends) may emit a thinking block even
    // when thinking is switched off – never let it reach the input field.
    $text = (string) preg_replace(
        '/<(think|thinking|reasoning)>.*?<\/\1>/is',
        '',
        $text
    );
    $text = (string) preg_replace('/<(think|thinking|reasoning)>.*$/is', '', $text);
    $text = trim($text);

    // Unwrap a single Markdown code fence.
    if (preg_match('/^```[a-zA-Z0-9_-]*\s*\n(.*?)\n?```$/s', $text, $m)) {
        $text = trim($m[1]);
    }

    // Drop a leading "Ausgabe:"-style label some models like to add.
    $text = (string) preg_replace(
        '/^(ausgabe|output|ergebnis|result|antwort|text|transkript)\s*:\s*/iu',
        '',
        $text
    );

    // Remove one wrapping pair of straight double quotes (only when the text
    // itself contains no quote, so genuine quotations survive).
    if (preg_match('/^"(.*)"$/s', $text, $m) && strpos($m[1], '"') === false) {
        $text = trim($m[1]);
    }

    // Blanks before a line break are a model artefact, not content; the same
    // goes for runs of empty lines the model adds around line breaks.
    $text = (string) preg_replace('/[ \t]+(?=\n)/', '', $text);
    $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

/**
 * Deterministic dictation-command processing.
 *
 * Used when the dictation model is unavailable or fails, so the recognised text
 * is never lost and the most important commands still work.
 */
function speechDictationApplyCommands(string $text): string
{
    $commands = speechDictationCommands();
    if ($commands === []) {
        return trim($text);
    }

    // Index by lowercase phrase; remember the word count for n-gram matching.
    $byPhrase = [];
    $maxWords = 1;
    foreach ($commands as $cmd) {
        $phrase = trim(mb_strtolower($cmd['phrase'], 'UTF-8'));
        if ($phrase === '') {
            continue;
        }
        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            continue;
        }
        $maxWords = max($maxWords, count($words));
        $byPhrase[$phrase] = ['cmd' => $cmd, 'words' => count($words)];
    }

    $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = '';
    $count = count($tokens);
    $i = 0;

    while ($i < $count) {
        $matched = null;
        $matchedWords = 0;

        // Longest phrase wins ("lösche letztes wort" before "wort").
        for ($len = min($maxWords, $count - $i); $len >= 1; $len--) {
            $candidate = mb_strtolower(implode(' ', array_slice($tokens, $i, $len)), 'UTF-8');
            if (isset($byPhrase[$candidate])) {
                $matched = $byPhrase[$candidate]['cmd'];
                $matchedWords = $len;
                break;
            }
        }

        if ($matched !== null) {
            $out = speechDictationApplyCommand($out, $matched);
            $i += $matchedWords;
            continue;
        }

        $out = speechDictationAppendWord($out, $tokens[$i]);
        $i++;
    }

    return rtrim($out, " \t");
}

/** Apply one command to the assembled output buffer. */
function speechDictationApplyCommand(string $out, array $cmd): string
{
    switch ((string) ($cmd['type'] ?? 'insert')) {
        case 'newline':
            return rtrim($out, " \t") . "\n";

        case 'paragraph':
            return rtrim($out, " \t") . "\n\n";

        case 'delete_word':
            $out = rtrim($out, " \t");
            $out = (string) preg_replace('/[\p{L}\p{N}]+[\.,!?;:]*$/u', '', $out);
            return rtrim($out, " \t");

        case 'delete_sentence':
            $out = rtrim($out, " \t");
            for ($i = mb_strlen($out, 'UTF-8') - 1; $i >= 0; $i--) {
                $char = mb_substr($out, $i, 1, 'UTF-8');
                if ($char === '.' || $char === '!' || $char === '?' || $char === "\n") {
                    return rtrim(mb_substr($out, 0, $i, 'UTF-8'), " \t");
                }
            }
            return '';

        case 'insert':
        default:
            $value = (string) ($cmd['value'] ?? '');
            if ($value === '') {
                return $out;
            }
            // A value that starts with a space is only meaningful when it does
            // not follow a line break or the very beginning of the text.
            if ($value[0] === ' ' && ($out === '' || preg_match('/(\s)$/u', $out) === 1)) {
                $value = ltrim($value, ' ');
            }
            return $out . $value;
    }
}

/** Append a plain word, inserting a separating space where needed. */
function speechDictationAppendWord(string $out, string $word): string
{
    if ($out === '' || preg_match('/\s$/u', $out) === 1) {
        return $out . $word;
    }
    return $out . ' ' . $word;
}

// ── Dictation model client ────────────────────────────────────────────────────

/**
 * Build the system message for one dictation fragment.
 *
 * The already written text belongs in the system message, not the user turn:
 * in the user turn the model treats it as something to answer and echoes it
 * back into the result, which is exactly what must not happen. Inside the
 * system message it is read as background information and stays out of the
 * output.
 */
function speechDictationBuildSystemMessage(string $context): string
{
    $prompt = speechDictationPrompt();
    if ($context === '') {
        return $prompt;
    }

    return $prompt
        . "\n\nBereits geschriebener Text (nur Kontext – nicht wiederholen, nicht verändern):\n"
        . "<<<\n" . $context . "\n>>>";
}

/**
 * Build the user message for one dictation fragment.
 *
 * Only the new fragment goes in here; the already written text travels in the
 * system message – see speechDictationBuildSystemMessage().
 */
function speechDictationBuildUserMessage(string $fragment): string
{
    return "Neues Fragment aus der Spracherkennung:\n<<<\n" . $fragment . "\n>>>\n\n"
        . "Gib nur die überarbeitete Fassung dieses neuen Fragments aus.";
}

/**
 * Resolve the backend for one dictation completion.
 *
 * A dedicated llama.cpp server (QWEN_URL / speech_dictation_qwen_url, see the
 * `qwen` service in docker-compose.yml) is preferred because it is reserved for
 * the dictation pipeline and always serves exactly the Qwen3.5-2B Q4 model.
 * Without one, the request goes through the regular endpoint pool / balancer,
 * so an administrator who already hosts the model on an endpoint needs no extra
 * container.
 *
 * @return array{ok:bool,url?:string,model?:string,timeout?:int,task_id?:int|null,token?:string,error?:string}
 */
function speechDictationResolveCompletionTarget(string $model): array
{
    if ($model === '') {
        return ['ok' => false, 'error' => 'Kein Modell angegeben.'];
    }

    $directUrl = speechDictationQwenUrl();
    if ($directUrl !== '') {
        return [
            'ok'      => true,
            'url'     => $directUrl . '/v1/chat/completions',
            'model'   => $model,
            'timeout' => speechDictationQwenTimeout(),
            'task_id' => null,
            'token'   => speechDictationQwenToken(),
        ];
    }

    try {
        $slot = pickEndpointForModel($model);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Endpunkt-Auswahl fehlgeschlagen: ' . $e->getMessage()];
    }

    if ($slot === null) {
        return ['ok' => false, 'error' => 'Kein aktiver Endpunkt für das Modell "' . $model . '" verfügbar.'];
    }

    $endpoint = $slot['endpoint'];

    return [
        'ok'      => true,
        'url'     => rtrim((string) $endpoint['base_url'], '/') . '/chat/completions',
        'model'   => $endpoint['default_model'] !== '' ? (string) $endpoint['default_model'] : $model,
        'timeout' => max(20, min(120, (int) $endpoint['timeout'])),
        'task_id' => (int) $slot['task_id'],
        'token'   => '',
    ];
}

/**
 * Run one non-streaming chat completion for the dictation pipeline.
 *
 * Thinking is switched off (`chat_template_kwargs.enable_thinking = false` plus
 * `reasoning_budget = 0`), mirroring the convention in api/chat.php for requests
 * without reasoning.
 * Balancer-picked endpoints additionally get their task finished through
 * completeTask() so counters and latency statistics stay correct.
 *
 * @param array<int,array{role:string,content:string}> $messages
 * @param array{temperature?:float,max_tokens?:int}    $options
 *
 * @return array{ok:bool,text?:string,error?:string,model?:string,latency_ms?:int}
 */
function speechDictationRunChatCompletion(string $model, array $messages, array $options = []): array
{
    $target = speechDictationResolveCompletionTarget($model);
    if (!$target['ok']) {
        return ['ok' => false, 'error' => (string) $target['error'], 'model' => $model];
    }

    $taskId = $target['task_id'];

    $payload = [
        'model'    => $target['model'],
        'stream'   => false,
        'messages' => $messages,
        'temperature' => (float) ($options['temperature'] ?? 0.1),
        // Dictation must never think: hybrid-reasoning chat templates are told
        // to skip the thinking block, exactly like api/chat.php does.
        'chat_template_kwargs' => ['enable_thinking' => false],
        // The template switch alone is not enough on every llama.cpp build: the
        // model still emits a thinking block that eats the whole token budget,
        // so the completion comes back empty and the raw transcript is used.
        // A reasoning budget of 0 pins the answer to the token limit.
        'reasoning_budget' => 0,
    ];
    if (isset($options['max_tokens'])) {
        $payload['max_tokens'] = max(32, min(4096, (int) $options['max_tokens']));
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($target['token'] !== '') {
        $headers[] = 'X-Auth-Token: ' . $target['token'];
    }

    $startedAt = microtime(true);
    $ch = curl_init($target['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $target['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    $latencyMs = (float) round((microtime(true) - $startedAt) * 1000, 1);

    if ($curlErr !== '') {
        if ($taskId !== null) {
            try { completeTask($taskId, 'error', null, null, null, $latencyMs); } catch (Throwable $_e) {}
        }
        return ['ok' => false, 'error' => 'Modell nicht erreichbar: ' . $curlErr, 'model' => $model];
    }

    $data = json_decode((string) $body, true);
    if ($httpCode !== 200 || !is_array($data)) {
        $errMsg = isset($data['error']['message'])
            ? (string) $data['error']['message']
            : 'Modell-Fehler (HTTP ' . $httpCode . ')';
        if ($taskId !== null) {
            try { completeTask($taskId, 'error', null, null, null, $latencyMs); } catch (Throwable $_e) {}
        }
        return ['ok' => false, 'error' => $errMsg, 'model' => $model];
    }

    // The content may be a plain string or an array of typed parts.
    $content = '';
    $msgContent = $data['choices'][0]['message']['content'] ?? '';
    if (is_string($msgContent)) {
        $content = $msgContent;
    } elseif (is_array($msgContent)) {
        foreach ($msgContent as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                $content .= (string) $part['text'];
            }
        }
    }

    $usage = [
        'prompt'     => (int) ($data['usage']['prompt_tokens']     ?? 0),
        'completion' => (int) ($data['usage']['completion_tokens'] ?? 0),
        'total'      => (int) ($data['usage']['total_tokens']      ?? 0),
    ];

    if ($taskId !== null) {
        try {
            completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total'], $latencyMs);
        } catch (Throwable $_e) {}
    }

    return [
        'ok'         => true,
        'text'       => speechDictationCleanModelOutput($content),
        'model'      => (string) $payload['model'],
        'latency_ms' => (int) round($latencyMs),
    ];
}

/**
 * Cut a fragment at the line-break commands and mark where the breaks go.
 *
 * Qwen3.5-2B reliably *consumes* "neue zeile"/"neuer absatz" but then writes a
 * space where the break belongs, so asking it for whitespace is not dependable.
 * The break commands are therefore taken out of the fragment before the model
 * sees it and re-inserted afterwards: the model stays responsible for language
 * (filler removal, punctuation, capitalisation) and the caller guarantees the
 * structure. The configured phrases are used, so admin-edited break commands
 * work too.
 *
 * @return array<int,array{text:string,break:?string}> Parts in reading order;
 *         `break` is "\n" or "\n\n" for a break command, null for text. The
 *         first part is always a text part and text parts are never adjacent.
 */
function speechDictationSplitAtBreaks(string $fragment): array
{
    $breaks = [];
    foreach (speechDictationCommands() as $command) {
        $type   = (string) ($command['type'] ?? '');
        $phrase = trim((string) ($command['phrase'] ?? ''));
        if ($phrase === '' || ($type !== 'newline' && $type !== 'paragraph')) {
            continue;
        }
        $breaks[mb_strtolower($phrase, 'UTF-8')] = $type === 'paragraph' ? "\n\n" : "\n";
    }

    if ($breaks === []) {
        return [['text' => $fragment, 'break' => null]];
    }

    // Longest phrase first, so "neuer absatz" cannot be clipped by a shorter one.
    $phrases = array_keys($breaks);
    usort($phrases, static fn(string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
    $pattern = '/\b(?:' . implode('|', array_map(
        static fn(string $p): string => preg_quote($p, '/'),
        $phrases
    )) . ')\b/iu';

    $matches = [];
    preg_match_all($pattern, $fragment, $matches, PREG_OFFSET_CAPTURE);

    $parts  = [];
    $cursor = 0;
    foreach ($matches[0] as [$match, $offset]) {
        $parts[]  = ['text' => substr($fragment, $cursor, $offset - $cursor), 'break' => null];
        $parts[]  = ['text' => '', 'break' => $breaks[mb_strtolower($match, 'UTF-8')] ?? "\n"];
        $cursor   = $offset + strlen($match);
    }
    $parts[] = ['text' => substr($fragment, $cursor), 'break' => null];

    return $parts;
}

/**
 * Keep a context string within the size the dictation model is given.
 */
function speechDictationLimitContext(string $context): string
{
    $context = trim($context);
    if (mb_strlen($context, 'UTF-8') > SPEECH_DICTATION_CONTEXT_CHARS) {
        $context = mb_substr($context, -SPEECH_DICTATION_CONTEXT_CHARS, null, 'UTF-8');
    }
    return $context;
}

/**
 * Keep the capitalisation the speaker used at the start of a segment.
 *
 * Every segment after a line break is its own completion, so the model tends to
 * capitalise it as if it opened a new sentence. The dictation spec keeps the
 * spoken casing ("Hallo Peter Punkt Neue Zeile ich wollte dich etwas fragen"
 * stays lowercase after the break), so a segment that was dictated in lower
 * case is put back into lower case. A segment the speaker capitalised is left
 * alone.
 */
function speechDictationMatchLeadingCase(string $source, string $produced): string
{
    $produced = ltrim($produced);
    if ($produced === '') {
        return $produced;
    }

    if (preg_match('/^\p{Ll}/u', ltrim($source)) === 1
        && preg_match('/^\p{Lu}/u', $produced) === 1) {
        return mb_strtolower(mb_substr($produced, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($produced, 1, null, 'UTF-8');
    }

    return $produced;
}

/**
 * Drop a closing sentence mark the model added on its own.
 *
 * A dictation fragment is often only part of a sentence – the user pauses and
 * keeps speaking. When no sentence mark was dictated, adding one would end the
 * sentence early and the next fragment would start a new one, so a mark the
 * model invented at the very end is removed again. §18 of the spec shows the
 * same behaviour ("… ich wollte dich etwas fragen" keeps no closing period).
 */
function speechDictationDropInventedSentenceEnd(string $fragment, string $text): string
{
    $tail = trim($fragment);
    if ($tail === '' || $text === '') {
        return $text;
    }

    if (preg_match('/[.!?…]$/u', $text) !== 1 || preg_match('/[.!?…]$/u', $tail) === 1) {
        return $text;
    }

    // A mark the speaker dictated is legitimate, so look for the command.
    $phrases = [];
    foreach (speechDictationCommands() as $command) {
        $phrase = trim((string) ($command['phrase'] ?? ''));
        $value  = (string) ($command['value'] ?? '');
        if ($phrase !== '' && preg_match('/^[.!?…]+$/u', trim($value)) === 1) {
            $phrases[] = preg_quote(mb_strtolower($phrase, 'UTF-8'), '/');
        }
    }

    if ($phrases !== []
        && preg_match('/\b(?:' . implode('|', $phrases) . ')$/u', mb_strtolower($tail, 'UTF-8')) === 1) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, -1, 'UTF-8'));
}

/**
 * Process one dictated fragment with the dictation model.
 *
 * Never fails hard for non-empty input: when the model is unavailable the
 * deterministic command processor takes over, so no recognised text is lost.
 *
 * @return array{ok:bool,text:string,fallback:bool,model:string,warning:string}
 */
function speechDictationProcessFragment(string $fragment, string $context = ''): array
{
    $fragment = trim($fragment);
    if ($fragment === '') {
        return ['ok' => true, 'text' => '', 'fallback' => false, 'model' => '', 'warning' => ''];
    }

    $context = speechDictationLimitContext($context);

    $model = speechDictationQwenModel();
    $pieces = [];
    $afterBreak = false;

    foreach (speechDictationSplitAtBreaks($fragment) as $part) {
        if ($part['break'] !== null) {
            $pieces[]   = $part['break'];
            $afterBreak = true;
            continue;
        }

        $text = trim($part['text']);
        if ($text === '') {
            continue;
        }

        $result = speechDictationRunChatCompletion($model, [
            ['role' => 'system', 'content' => speechDictationBuildSystemMessage($context)],
            ['role' => 'user',   'content' => speechDictationBuildUserMessage($text)],
        ], [
            'temperature' => 0.1,
            'max_tokens'  => max(128, (int) (mb_strlen($text, 'UTF-8') * 2) + 128),
        ]);

        if (!$result['ok'] || trim((string) $result['text']) === '') {
            // Model unavailable or returned nothing usable → keep the raw text alive.
            $reason = $result['ok']
                ? 'Das Diktat-Modell hat keinen Text geliefert.'
                : (string) ($result['error'] ?? 'Unbekannter Fehler.');

            return [
                'ok'       => true,
                'text'     => speechDictationApplyCommands($fragment),
                'fallback' => true,
                'model'    => $model,
                'warning'  => 'Diktat-Modell nicht verfügbar – Rohtext übernommen (' . $reason . ')',
            ];
        }

        $segment = (string) $result['text'];
        if ($afterBreak) {
            $segment = speechDictationMatchLeadingCase($text, $segment);
        }
        $pieces[] = $segment;

        // Each following segment continues the same dictation, so it sees what
        // has been produced so far – that keeps mid-sentence breaks lowercase.
        $context = speechDictationLimitContext($context . ' ' . $segment);
    }

    $text = speechDictationCleanModelOutput(implode('', $pieces));
    $text = speechDictationDropInventedSentenceEnd($fragment, $text);
    if ($text === '') {
        return [
            'ok'       => true,
            'text'     => speechDictationApplyCommands($fragment),
            'fallback' => true,
            'model'    => $model,
            'warning'  => 'Diktat-Modell nicht verfügbar – Rohtext übernommen '
                . '(Das Diktat-Modell hat keinen Text geliefert.)',
        ];
    }

    return [
        'ok'       => true,
        'text'     => $text,
        'fallback' => false,
        'model'    => $model,
        'warning'  => '',
    ];
}

/**
 * Strip anything from a log line that could carry private speech content.
 *
 * Logs must not contain the dictated text itself; only counts and short
 * metadata are written.
 */
function speechDictationLogText(string $text): string
{
    $length = mb_strlen($text, 'UTF-8');
    $words  = $text === '' ? 0 : count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    return $length . ' Zeichen / ' . $words . ' Wörter';
}
