<?php

/**
 * lib/speech_dictation.php
 *
 * Shared helpers for the speech-recognition / dictation feature.
 *
 * Pipeline: browser microphone → audio segment → SpeechInt
 * (POST /v1/audio/transcriptions) → recognised text → word buffer → SpeechInt
 * (POST /v1/dictate/process) → formatted text → LLMInt input field.
 *
 * The compute-heavy parts – whisper.cpp with the "small" model and the dictation
 * model served by llama.cpp – live in the separate project SpeechInt
 * (https://github.com/dareinelt/SpeechInt) and are reached over HTTP exactly
 * like the document converter (api/doc_convert.php): the base URL comes from the
 * SPEECHINT_URL environment variable (docker-compose) and falls back to the
 * active row of the `speech_endpoints` table, so an administrator can point
 * LLMInt at one or several SpeechInt instances at runtime. PHP never shells out –
 * no browser-supplied value can reach a shell.
 *
 * SpeechInt is stateless: prompt, command table and context travel with every
 * request, so everything stays configurable in LLMInt's admin area.
 *
 * Loading contract: while SpeechInt is still downloading or loading its models
 * it answers 503 with the error code `service_loading` (plus a Retry-After
 * header). That is not a failure – the caller keeps the recognised fragment and
 * retries (see the `loading`/`retry_after` keys of the return values). Only an
 * unreachable, erroring or unconfigured service (or a 502/504) hands over to the
 * deterministic rule fallback speechDictationApplyCommands(), so no recognised
 * text is ever lost.
 *
 * The recognised text never leaves the server: it is forwarded to SpeechInt and
 * logged as a length summary only (speechDictationLogText()).
 */

require_once __DIR__ . '/../db.php';

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
 *        model – SpeechInt cuts them out of the fragment before the model sees it.
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

// ── SpeechInt endpoints ───────────────────────────────────────────────────────

/**
 * All configured SpeechInt endpoints, ordered like the admin card shows them.
 *
 * @return array<int,array<string,mixed>>
 */
function speechDictationEndpoints(): array
{
    try {
        $rows = getDb()->query(
            'SELECT * FROM speech_endpoints ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }
    return is_array($rows) ? $rows : [];
}

/** One SpeechInt endpoint by id; null when it does not exist. */
function speechDictationEndpoint(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = getDb()->prepare('SELECT * FROM speech_endpoints WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable $_e) {
        return null;
    }
    return is_array($row) ? $row : null;
}

/** Endpoint explicitly selected in the admin area (0 = "first active one"). */
function speechDictationEndpointId(): int
{
    $id = (int) getSetting('speech_dictation_endpoint_id', '0');
    return $id > 0 ? $id : 0;
}

/**
 * The endpoint used for the next request.
 *
 * The endpoint selected in the admin area wins; without a selection the first
 * active endpoint in sort order is used, so a fresh installation works as soon
 * as a single endpoint has been added.
 */
function speechDictationActiveEndpoint(): ?array
{
    $endpoints = speechDictationEndpoints();
    if ($endpoints === []) {
        return null;
    }

    $wanted = speechDictationEndpointId();
    if ($wanted > 0) {
        foreach ($endpoints as $endpoint) {
            if ((int) $endpoint['id'] === $wanted && (int) $endpoint['is_active'] === 1) {
                return $endpoint;
            }
        }
    }

    foreach ($endpoints as $endpoint) {
        if ((int) $endpoint['is_active'] === 1) {
            return $endpoint;
        }
    }
    return null;
}

/**
 * Base URL of the SpeechInt service that serves the dictation.
 *
 * Environment first (docker-compose), then the active `speech_endpoints` row –
 * identical to docConvertBaseUrl(). An empty value means "not deployed".
 */
function speechDictationUrl(): string
{
    $url = trim((string) (getenv('SPEECHINT_URL') ?: ''));
    if ($url === '') {
        $endpoint = speechDictationActiveEndpoint();
        $url = $endpoint === null ? '' : trim((string) $endpoint['base_url']);
    }
    return rtrim($url, '/');
}

/** Optional shared secret of the SpeechInt service (X-Auth-Token header). */
function speechDictationToken(): string
{
    $token = trim((string) (getenv('SPEECHINT_TOKEN') ?: ''));
    if ($token === '') {
        $endpoint = speechDictationActiveEndpoint();
        $token = $endpoint === null ? '' : trim((string) ($endpoint['token'] ?? ''));
    }
    return $token;
}

/** Request timeout for one SpeechInt call, in seconds. */
function speechDictationTimeout(): int
{
    $timeout = (int) (getenv('SPEECHINT_TIMEOUT') ?: 0);
    if ($timeout <= 0) {
        $endpoint = speechDictationActiveEndpoint();
        $timeout = $endpoint === null ? 0 : (int) $endpoint['timeout'];
    }
    if ($timeout <= 0) {
        $timeout = 120;
    }
    return max(10, min(600, $timeout));
}

/**
 * Where the effective SpeechInt URL comes from.
 *
 * The admin card needs this because environment variables (docker-compose) take
 * precedence over the endpoint table – without showing the origin an
 * administrator would edit a row that has no effect.
 *
 * @return string 'env', 'endpoint' or 'none'.
 */
function speechDictationUrlSource(): string
{
    if (trim((string) (getenv('SPEECHINT_URL') ?: '')) !== '') {
        return 'env';
    }
    return speechDictationActiveEndpoint() === null ? 'none' : 'endpoint';
}

/** Human-readable origin label for the admin card. */
function speechDictationSourceLabel(string $source): string
{
    switch ($source) {
        case 'env':
            return 'Umgebungsvariable (docker-compose)';
        case 'endpoint':
            return 'Speech-Endpunkt in der Datenbank';
        default:
            return 'nicht konfiguriert';
    }
}

/** Display name of one `speech_endpoints` row (alias, else host and port). */
function speechDictationEndpointLabel(array $endpoint): string
{
    $alias = trim((string) ($endpoint['alias'] ?? ''));
    if ($alias !== '') {
        return $alias;
    }

    $url  = trim((string) ($endpoint['base_url'] ?? ''));
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return $url === '' ? 'Endpunkt #' . (int) ($endpoint['id'] ?? 0) : $url;
    }

    $port = parse_url($url, PHP_URL_PORT);
    return $host . (is_int($port) ? ':' . $port : '');
}

/**
 * Mask a stored SpeechInt token for display.
 *
 * The secret itself never reaches the browser; only its length and the last
 * four characters are shown so an administrator can recognise the right token.
 */
function speechDictationMaskToken(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '– kein Token –';
    }

    $length = strlen($token);
    if ($length <= 4) {
        return str_repeat('•', $length);
    }
    return '••••••••' . substr($token, -4);
}

/** Spoken language handed to SpeechInt ('auto' lets the model detect it). */
function speechDictationLanguage(): string
{
    $language = trim(getSetting('speech_dictation_language', 'de'));
    return $language === '' ? 'de' : $language;
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
 * without waiting for the speech service.
 */
function speechDictationConfigured(): bool
{
    return speechDictationEnabled() && speechDictationUrl() !== '';
}

// ── SpeechInt HTTP client ─────────────────────────────────────────────────────

/**
 * Run one HTTP request against a SpeechInt endpoint.
 *
 * Every SpeechInt call goes through here so transport details – auth header,
 * timeout, Retry-After parsing and the loading contract – exist exactly once.
 *
 * @param array{method?:string,json?:array<string,mixed>,multipart?:array<string,mixed>,
 *              timeout?:int,connect_timeout?:int,token?:string} $options
 *
 * @return array{ok:bool,http:int,error:string,message:string,loading:bool,
 *               retry_after:int,latency_ms:int,data:?array<string,mixed>}
 */
function speechDictationHttpCall(string $url, array $options = []): array
{
    $method  = strtoupper((string) ($options['method'] ?? 'GET'));
    $timeout = max(5, min(600, (int) ($options['timeout'] ?? 120)));
    $connect = max(1, min(60, (int) ($options['connect_timeout'] ?? 10)));
    $token   = (string) ($options['token'] ?? '');

    $result = [
        'ok'          => false,
        'http'        => 0,
        'error'       => '',
        'message'     => '',
        'loading'     => false,
        'retry_after' => 0,
        'latency_ms'  => 0,
        'data'        => null,
    ];

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Auth-Token: ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connect,
        // The Retry-After header of a `service_loading` answer drives the retry
        // loop, so response headers are needed as well.
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (isset($options['multipart'])) {
            // A Content-Type header must not be set by hand here: curl adds the
            // multipart boundary itself when POSTFIELDS is an array.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['multipart']);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
                $options['json'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $startedAt = microtime(true);
    $raw       = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlErr   = curl_error($ch);
    // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; the handle is
    // released together with the scope.

    $result['http']       = $httpCode;
    $result['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

    if ($curlErr !== '') {
        $result['error'] = 'SpeechInt nicht erreichbar: ' . $curlErr;
        return $result;
    }

    $headerText = is_string($raw) ? substr($raw, 0, $headerLen) : '';
    $bodyText   = is_string($raw) ? substr($raw, $headerLen) : '';

    $data = json_decode($bodyText, true);
    if (is_array($data)) {
        $result['data'] = $data;
    }

    $retryAfter = 0;
    if (preg_match('/^Retry-After:\s*(\d+)/mi', $headerText, $match) === 1) {
        $retryAfter = (int) $match[1];
    } elseif (is_array($data) && isset($data['retry_after'])) {
        $retryAfter = (int) $data['retry_after'];
    }
    $result['retry_after'] = max(0, min(300, $retryAfter));

    $code    = is_array($data) ? (string) ($data['error'] ?? '') : '';
    $message = is_array($data) ? trim((string) ($data['message'] ?? '')) : '';
    $status  = is_array($data) ? (string) ($data['status'] ?? '') : '';

    if ($httpCode >= 200 && $httpCode < 300) {
        $result['ok']      = true;
        $result['message'] = $message;
        return $result;
    }

    // Still downloading or loading its models: not an error but a "try again in
    // a moment" signal, so the caller keeps the fragment instead of falling back.
    if ($code === 'service_loading' || $status === 'loading' || $status === 'starting') {
        $result['loading']     = true;
        $result['message']     = $message !== '' ? $message : 'Die SpeechInt-Modelle werden noch geladen.';
        $result['retry_after'] = $result['retry_after'] > 0 ? $result['retry_after'] : 15;
        return $result;
    }

    $result['error']   = $code !== '' ? $code : 'http_' . $httpCode;
    $result['message'] = $message !== '' ? $message : ('SpeechInt meldet HTTP ' . $httpCode . '.');
    return $result;
}

/**
 * Reachability and readiness of a SpeechInt service.
 *
 * `GET /v1/ready` needs no token, `GET /v1/health` does; both answer with the
 * same body (docs/api.md of the SpeechInt project), so the unauthenticated
 * variant is used whenever no shared secret is configured.
 *
 * @return array{ok:bool,reachable:bool,loading:bool,ready:bool,status:string,
 *               message:string,retry_after:int,http:int,latency_ms:int,
 *               components:array<string,array<string,mixed>>,error:string,url:string}
 * @param array{timeout?:int,connect_timeout?:int} $options
 */
function speechDictationHealth(?string $url = null, ?string $token = null, array $options = []): array
{
    $base   = $url === null ? speechDictationUrl() : rtrim(trim($url), '/');
    $secret = $token === null ? speechDictationToken() : trim($token);

    $health = [
        'ok'          => false,
        'reachable'   => false,
        'loading'     => false,
        'ready'       => false,
        'status'      => '',
        'message'     => '',
        'retry_after' => 0,
        'http'        => 0,
        'latency_ms'  => 0,
        'components'  => [],
        'error'       => '',
        'url'         => $base,
    ];

    if ($base === '') {
        $health['error']   = 'not_configured';
        $health['message'] = 'Es ist kein Speech-Endpunkt konfiguriert.';
        return $health;
    }

    $response = speechDictationHttpCall(
        $base . ($secret !== '' ? '/v1/health' : '/v1/ready'),
        [
            'timeout'         => max(5, min(600, (int) ($options['timeout'] ?? 15))),
            'connect_timeout' => max(1, min(60, (int) ($options['connect_timeout'] ?? 10))),
            'token'           => $secret,
        ]
    );

    $data = is_array($response['data']) ? $response['data'] : [];

    // An empty `error` means the service answered with a health body; anything
    // else (curl failure or the error envelope) is a real problem.
    $reachable = $response['http'] > 0 && $response['error'] === '';
    $status    = (string) ($data['status'] ?? '');

    $health['reachable']   = $reachable;
    $health['loading']     = $response['loading'] || $status === 'loading' || $status === 'starting';
    $health['status']      = $status;
    $health['http']        = $response['http'];
    $health['latency_ms']  = $response['latency_ms'];
    $health['retry_after'] = $response['retry_after'];
    $health['error']       = $response['error'];

    foreach ((array) ($data['components'] ?? []) as $name => $component) {
        if (!is_array($component)) {
            continue;
        }
        $health['components'][(string) $name] = [
            'state'       => (string) ($component['state'] ?? ''),
            'state_label' => (string) ($component['state_label'] ?? ''),
            'ok'          => (bool) ($component['ok'] ?? false),
            'http'        => (int) ($component['http'] ?? 0),
            'message'     => (string) ($component['message'] ?? ''),
            'url'         => (string) ($component['url'] ?? ''),
            'model'       => (string) ($component['model'] ?? ''),
        ];
    }

    $health['ready'] = $reachable
        && !$health['loading']
        && $response['http'] === 200
        && (bool) ($data['ready'] ?? true);
    $health['ok'] = $health['ready'];

    $message = trim((string) ($data['message'] ?? ''));
    if ($message === '') {
        $message = $response['message'];
    }
    if ($message === '') {
        $message = $health['ready']
            ? 'SpeechInt ist bereit.'
            : ($reachable ? 'SpeechInt ist noch nicht bereit.' : 'SpeechInt nicht erreichbar.');
    }
    $health['message'] = $message;

    return $health;
}

/**
 * Readiness of the active SpeechInt endpoint for the admin dashboard graphic.
 *
 * The dashboard polls every 15 s, so the live probe is cached for 20 s in the
 * settings table – the same pattern vectorStoreStatus() uses. The probe itself
 * is deliberately cheap (short connect timeout) so a stopped speech host never
 * stalls the dashboard poll.
 *
 * @return array{configured:bool,url:string,source:string,ready:bool,loading:bool,
 *               reachable:bool,status:string,message:string,retry_after:int,
 *               checked_at:int,components:array<string,array<string,mixed>>}
 */
function speechDictationDashboardStatus(bool $allowProbe = true): array
{
    $url = speechDictationUrl();

    $status = [
        'configured'  => $url !== '',
        'url'         => $url,
        'source'      => speechDictationUrlSource(),
        'ready'       => false,
        'loading'     => false,
        'reachable'   => false,
        'status'      => '',
        'message'     => '',
        'retry_after' => 0,
        'checked_at'  => time(),
        'probed'      => false,
        'components'  => [],
    ];

    if ($url === '') {
        $status['message'] = 'Kein Speech-Endpunkt konfiguriert.';
        return $status;
    }

    $cacheKey = 'speech_dictation_status_cache';
    $cached   = json_decode((string) getSetting($cacheKey, ''), true);
    if (is_array($cached) && ($cached['url'] ?? '') === $url
        && (time() - (int) ($cached['checked_at'] ?? 0)) < 20) {
        return $cached + $status;
    }

    // A page that must not wait for a remote host (the admin area) asks for the
    // cached value only and lets the dashboard poll fill it in a moment later.
    if (!$allowProbe) {
        $status['message']   = 'Noch nicht geprüft.';
        $status['reachable'] = is_array($cached) && ($cached['url'] ?? '') === $url
            ? (bool) ($cached['reachable'] ?? false)
            : false;
        return $status;
    }

    $health = speechDictationHealth($url, null, ['timeout' => 4, 'connect_timeout' => 2]);
    $status = [
        'configured'  => true,
        'url'         => $url,
        'source'      => speechDictationUrlSource(),
        'ready'       => $health['ready'],
        'loading'     => $health['loading'],
        'reachable'   => $health['reachable'],
        'status'      => $health['status'],
        'message'     => $health['message'],
        'retry_after' => $health['retry_after'],
        'checked_at'  => time(),
        'probed'      => true,
        'components'  => $health['components'],
    ];

    try {
        setSetting($cacheKey, json_encode($status, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $_e) {
        // A missing cache is harmless – the next poll simply probes again.
    }

    return $status;
}

/**
 * Capabilities, defaults and limits of a SpeechInt service (GET /v1/config).
 *
 * @return array{ok:bool,http:int,error:string,message:string,loading:bool,
 *               latency_ms:int,config:array<string,mixed>}
 */
function speechDictationRemoteConfig(?string $url = null, ?string $token = null): array
{
    $base   = $url === null ? speechDictationUrl() : rtrim(trim($url), '/');
    $secret = $token === null ? speechDictationToken() : trim($token);

    $result = [
        'ok'         => false,
        'http'       => 0,
        'error'      => '',
        'message'    => '',
        'loading'    => false,
        'latency_ms' => 0,
        'config'     => [],
    ];

    if ($base === '') {
        $result['error']   = 'not_configured';
        $result['message'] = 'Es ist kein Speech-Endpunkt konfiguriert.';
        return $result;
    }

    $response = speechDictationHttpCall($base . '/v1/config', [
        'timeout' => 15,
        'token'   => $secret,
    ]);

    $result['http']       = $response['http'];
    $result['error']      = $response['error'];
    $result['message']    = $response['message'];
    $result['loading']    = $response['loading'];
    $result['latency_ms'] = $response['latency_ms'];
    $result['ok']         = $response['ok'];
    $result['config']     = is_array($response['data']) ? $response['data'] : [];

    if (!$response['ok'] && $result['message'] === '') {
        $result['message'] = 'Die SpeechInt-Konfiguration konnte nicht gelesen werden.';
    }

    return $result;
}

/**
 * Send one audio segment to SpeechInt and return the transcript.
 *
 * @param string $path     Absolute path of the uploaded audio file.
 * @param string $filename Original file name (determines the format hint).
 * @param string $mime     MIME type of the file.
 *
 * @return array{ok:bool,text:string,error:string,http:int,duration_ms:int,
 *               loading:bool,retry_after:int,message:string}
 */
function speechDictationTranscribeFile(string $path, string $filename, string $mime): array
{
    $url = speechDictationUrl();
    if ($url === '') {
        return speechDictationTranscribeFailure('SpeechInt ist nicht konfiguriert.');
    }
    if (!is_file($path)) {
        return speechDictationTranscribeFailure('Audiodatei nicht gefunden.');
    }

    $multipart = [
        'file'            => new CURLFile($path, $mime !== '' ? $mime : 'audio/wav', $filename),
        'response_format' => 'json',
    ];
    $language = speechDictationLanguage();
    if ($language !== '' && strtolower($language) !== 'auto') {
        $multipart['language'] = $language;
    }

    $response = speechDictationHttpCall($url . '/v1/audio/transcriptions', [
        'method'    => 'POST',
        'multipart' => $multipart,
        'timeout'   => speechDictationTimeout(),
        'token'     => speechDictationToken(),
    ]);

    if ($response['loading']) {
        return [
            'ok'          => false,
            'text'        => '',
            'error'       => '',
            'http'        => $response['http'],
            'duration_ms' => $response['latency_ms'],
            'loading'     => true,
            'retry_after' => $response['retry_after'],
            'message'     => $response['message'],
        ];
    }

    if (!$response['ok']) {
        return speechDictationTranscribeFailure($response['message'], $response['http']);
    }

    $data = is_array($response['data']) ? $response['data'] : [];

    return [
        'ok'          => true,
        'text'        => trim((string) ($data['text'] ?? '')),
        'error'       => '',
        'http'        => $response['http'],
        'duration_ms' => (int) ($data['duration_ms'] ?? $response['latency_ms']),
        'loading'     => false,
        'retry_after' => 0,
        'message'     => '',
    ];
}

/** Failure shape of speechDictationTranscribeFile(). */
function speechDictationTranscribeFailure(string $error, int $http = 0): array
{
    return [
        'ok'          => false,
        'text'        => '',
        'error'       => $error,
        'http'        => $http,
        'duration_ms' => 0,
        'loading'     => false,
        'retry_after' => 0,
        'message'     => $error,
    ];
}

// ── Dictation post-processing via SpeechInt ───────────────────────────────────

/**
 * Process one dictated fragment through SpeechInt's dictation model.
 *
 * Prompt, command table and context travel with the request, so SpeechInt stays
 * stateless and everything remains configurable in LLMInt's admin area.
 *
 * Never loses recognised text: while SpeechInt is still loading its models the
 * caller retries (`loading` = true, `retry_after` = seconds), and when the
 * service is unreachable, erroring or unconfigured the deterministic command
 * processor takes over (`fallback` = true).
 *
 * @return array{ok:bool,text:string,fallback:bool,model:string,warning:string,
 *               loading:bool,retry_after:int,message:string}
 */
function speechDictationProcessFragment(string $fragment, string $context = ''): array
{
    $fragment = trim($fragment);
    if ($fragment === '') {
        return speechDictationProcessResult('');
    }

    $url = speechDictationUrl();
    if ($url === '') {
        return speechDictationProcessFallback($fragment, 'Es ist kein Speech-Endpunkt konfiguriert.');
    }

    $response = speechDictationHttpCall($url . '/v1/dictate/process', [
        'method'  => 'POST',
        'json'    => [
            'fragment'    => $fragment,
            'context'     => speechDictationLimitContext($context),
            'prompt'      => speechDictationPrompt(),
            'commands'    => speechDictationCommands(),
            'temperature' => 0.1,
            'max_tokens'  => max(128, (int) (mb_strlen($fragment, 'UTF-8') * 2) + 128),
        ],
        'timeout' => speechDictationTimeout(),
        'token'   => speechDictationToken(),
    ]);

    if ($response['loading']) {
        // Models are still being loaded: keep the fragment and try again later.
        return [
            'ok'          => false,
            'text'        => '',
            'fallback'    => false,
            'model'       => '',
            'warning'     => '',
            'loading'     => true,
            'retry_after' => $response['retry_after'],
            'message'     => $response['message'],
        ];
    }

    if (!$response['ok']) {
        return speechDictationProcessFallback($fragment, $response['message']);
    }

    $data    = is_array($response['data']) ? $response['data'] : [];
    $text    = speechDictationCleanModelOutput((string) ($data['text'] ?? ''));
    $warning = trim((string) ($data['warning'] ?? ''));

    if ($text === '') {
        return speechDictationProcessFallback(
            $fragment,
            $warning !== '' ? $warning : 'Das Diktat-Modell hat keinen Text geliefert.'
        );
    }

    return [
        'ok'          => true,
        'text'        => $text,
        'fallback'    => (bool) ($data['fallback'] ?? false),
        'model'       => (string) ($data['model'] ?? ''),
        'warning'     => $warning,
        'loading'     => false,
        'retry_after' => 0,
        'message'     => '',
    ];
}

/**
 * Deterministic rule fallback: the recognised text is applied command by
 * command so a missing speech service never swallows a dictation.
 */
function speechDictationProcessFallback(string $fragment, string $reason): array
{
    return [
        'ok'          => true,
        'text'        => speechDictationApplyCommands($fragment),
        'fallback'    => true,
        'model'       => '',
        'warning'     => 'SpeechInt nicht verfügbar – Rohtext übernommen (' . $reason . ')',
        'loading'     => false,
        'retry_after' => 0,
        'message'     => '',
    ];
}

/** Successful result for an empty fragment (nothing to process). */
function speechDictationProcessResult(string $text): array
{
    return [
        'ok'          => true,
        'text'        => $text,
        'fallback'    => false,
        'model'       => '',
        'warning'     => '',
        'loading'     => false,
        'retry_after' => 0,
        'message'     => '',
    ];
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
