<?php
declare(strict_types=1);

/**
 * tests/php/test_openai_api.php
 *
 * Unit tests for the pure request/response helpers of lib/openai_api.php:
 * error bodies, API-key material, bearer-token extraction and the
 * normalisation of incoming OpenAI-compatible chat requests.
 *
 * Only valid inputs are exercised: the normalisers answer invalid requests with
 * openaiSendError(), which calls exit() and would end the test run. The
 * validation branches are therefore covered by the HTTP-level behaviour, not
 * here. No database, no HTTP, no network.
 *
 * Run from the repository root:  php tests/php/test_openai_api.php
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/openai_api.php';

/**
 * Run $fn with $_SERVER['HTTP_AUTHORIZATION'] set (or unset) and restore it.
 *
 * @param mixed $fn
 * @return mixed
 */
function withAuthorization(?string $header, $fn)
{
    $backup = $_SERVER;
    if ($header === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
    }
    try {
        return $fn();
    } finally {
        $_SERVER = $backup;
    }
}

// ── openaiErrorBody() ─────────────────────────────────────────────────────────

test_case('openaiErrorBody() erzeugt die OpenAI-Fehlerstruktur', function (): void {
    $body = openaiErrorBody('bad request');
    assert_keys(['error'], $body);
    assert_same('bad request', $body['error']['message']);
    assert_same('invalid_request_error', $body['error']['type']);
    assert_same(null, $body['error']['code']);
});

test_case('openaiErrorBody() übernimmt Typ und Code', function (): void {
    $body = openaiErrorBody('kaputt', 'server_error', 'code1');
    assert_same('server_error', $body['error']['type']);
    assert_same('code1', $body['error']['code']);
});

test_case('openaiErrorBody() bleibt JSON-serialisierbar', function (): void {
    $json = json_encode(openaiErrorBody('kaputt', 'server_error', 'code1'), JSON_UNESCAPED_UNICODE);
    assert_true(is_string($json));
    assert_contains('"error"', (string) $json);
});

// ── openaiApiKeyHash() / openaiGenerateApiKeyMaterial() ───────────────────────

test_case('openaiApiKeyHash() ist ein stabiler SHA-256-Hash', function (): void {
    assert_same(hash('sha256', 'sk-test'), openaiApiKeyHash('sk-test'));
    assert_same(64, strlen(openaiApiKeyHash('sk-test')));
    assert_same(openaiApiKeyHash('sk-test'), openaiApiKeyHash('sk-test'));
    assert_not_same(openaiApiKeyHash('sk-test'), openaiApiKeyHash('sk-test2'));
});

test_case('openaiGenerateApiKeyMaterial() liefert Schlüssel, Hash und Präfix', function (): void {
    $material = openaiGenerateApiKeyMaterial();
    assert_keys(['plain', 'hash', 'prefix'], $material);

    assert_true(str_starts_with($material['plain'], 'sk-'), 'Der Schlüssel beginnt mit "sk-"');
    assert_same(51, strlen($material['plain']), 'sk- plus 48 Hex-Zeichen');
    assert_true(ctype_xdigit(substr($material['plain'], 3)), 'Der Zufallsteil ist hexadezimal');
    assert_same($material['plain'], substr($material['plain'], 0, 51));
    assert_same(openaiApiKeyHash($material['plain']), $material['hash'], 'Der Hash gehört zum Schlüssel');
    assert_same(substr($material['plain'], 0, 14), $material['prefix']);
});

test_case('openaiGenerateApiKeyMaterial() erzeugt bei jedem Aufruf neue Schlüssel', function (): void {
    $first  = openaiGenerateApiKeyMaterial();
    $second = openaiGenerateApiKeyMaterial();

    assert_not_same($first['plain'], $second['plain']);
    assert_not_same($first['hash'], $second['hash']);
    assert_same(51, strlen($second['plain']));
});

// ── openaiReadBearerToken() ───────────────────────────────────────────────────

test_case('openaiReadBearerToken() liest das Bearer-Token aus dem Authorization-Header', function (): void {
    withAuthorization('Bearer sk-abc', function (): void {
        assert_same('sk-abc', openaiReadBearerToken());
    });
});

test_case('openaiReadBearerToken() akzeptiert beliebige Schreibweisen von "Bearer"', function (): void {
    withAuthorization('bearer sk-abc', function (): void {
        assert_same('sk-abc', openaiReadBearerToken());
    });
    withAuthorization('BEARER   sk-abc  ', function (): void {
        assert_same('sk-abc', openaiReadBearerToken());
    });
});

test_case('openaiReadBearerToken() liefert einen leeren String ohne Bearer-Token', function (): void {
    withAuthorization(null, function (): void {
        assert_same('', openaiReadBearerToken());
    });
    withAuthorization('', function (): void {
        assert_same('', openaiReadBearerToken());
    });
    withAuthorization('Basic dXNlcjpwYXNz', function (): void {
        assert_same('', openaiReadBearerToken(), 'Basic-Authentifizierung ist kein API-Schlüssel');
    });
    withAuthorization('sk-abc', function (): void {
        assert_same('', openaiReadBearerToken(), 'Ohne "Bearer "-Präfix wird nichts gelesen');
    });
});

// ── openaiNormalizeMessages() ─────────────────────────────────────────────────

test_case('openaiNormalizeMessages() übernimmt die vier gültigen Rollen', function (): void {
    $messages = openaiNormalizeMessages([
        ['role' => 'system', 'content' => 'sys'],
        ['role' => 'user', 'content' => 'hi'],
        ['role' => 'assistant', 'content' => 'hey'],
        ['role' => 'tool', 'content' => 'res'],
    ]);

    assert_count(4, $messages);
    assert_same(['system', 'user', 'assistant', 'tool'], array_column($messages, 'role'));
    assert_same(['sys', 'hi', 'hey', 'res'], array_column($messages, 'content'));
});

test_case('openaiNormalizeMessages() erlaubt leeren Inhalt bei Tool-Aufrufen', function (): void {
    $messages = openaiNormalizeMessages([
        ['role' => 'assistant', 'tool_calls' => [['id' => '1']]],
    ]);

    assert_same('assistant', $messages[0]['role']);
    assert_same(null, $messages[0]['content']);
    assert_same([['id' => '1']], $messages[0]['tool_calls']);
});

test_case('openaiNormalizeMessages() übernimmt nur bekannte Zusatzfelder', function (): void {
    $messages = openaiNormalizeMessages([
        ['role' => 'tool', 'content' => 'res', 'tool_call_id' => '1', 'name' => 'suche', 'extra' => 'weg'],
    ]);

    assert_keys(['role', 'content', 'tool_call_id', 'name'], $messages[0]);
    assert_same('1', $messages[0]['tool_call_id']);
    assert_same('suche', $messages[0]['name']);
    assert_false(array_key_exists('extra', $messages[0]), 'Unbekannte Felder werden verworfen');
});

test_case('openaiNormalizeMessages() ignoriert Zusatzfelder mit falschem Typ', function (): void {
    $messages = openaiNormalizeMessages([
        ['role' => 'user', 'content' => 'x', 'name' => 42, 'tool_calls' => 'kein Array'],
    ]);

    assert_false(array_key_exists('name', $messages[0]));
    assert_false(array_key_exists('tool_calls', $messages[0]));
});

test_case('openaiNormalizeMessages() behält die Reihenfolge der Nachrichten', function (): void {
    $messages = openaiNormalizeMessages([
        ['role' => 'user', 'content' => '1'],
        ['role' => 'assistant', 'content' => '2'],
        ['role' => 'user', 'content' => '3'],
    ]);

    assert_same(['1', '2', '3'], array_column($messages, 'content'));
});

// ── openaiNormalizeChatPayload() ──────────────────────────────────────────────

test_case('openaiNormalizeChatPayload() setzt Modell, Nachrichten und stream', function (): void {
    $payload = openaiNormalizeChatPayload(['messages' => [['role' => 'user', 'content' => 'hi']]], 'm1');

    assert_same('m1', $payload['model']);
    assert_same([['role' => 'user', 'content' => 'hi']], $payload['messages']);
    assert_same(false, $payload['stream'], 'Ohne stream-Feld wird nicht gestreamt');
    assert_false(array_key_exists('temperature', $payload));
    assert_false(array_key_exists('max_tokens', $payload));
});

test_case('openaiNormalizeChatPayload() ignoriert das angeforderte Modell des Clients', function (): void {
    // Das Modell kommt aus dem API-Schlüssel bzw. dem Gast-Standardmodell.
    $payload = openaiNormalizeChatPayload([
        'model'    => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ], 'm1');

    assert_same('m1', $payload['model']);
});

test_case('openaiNormalizeChatPayload() übernimmt Sampling-Parameter', function (): void {
    $payload = openaiNormalizeChatPayload([
        'messages'    => [['role' => 'user', 'content' => 'hi']],
        'stream'      => true,
        'temperature' => 0.7,
        'max_tokens'  => 128,
        'top_p'       => 0.9,
        'stop'        => ['a', 'b'],
        'reasoning_effort' => 'low',
    ], 'm1');

    assert_same(true, $payload['stream']);
    assert_same(0.7, $payload['temperature']);
    assert_same(128, $payload['max_tokens']);
    assert_same(0.9, $payload['top_p']);
    assert_same(['a', 'b'], $payload['stop']);
    assert_same('low', $payload['reasoning_effort']);
});

test_case('openaiNormalizeChatPayload() akzeptiert numerische Strings', function (): void {
    $payload = openaiNormalizeChatPayload([
        'messages'    => [['role' => 'user', 'content' => 'hi']],
        'temperature' => '0.5',
        'max_tokens'  => '64',
        'top_p'       => '0.8',
        'stop'        => 'ENDE',
    ], 'm1');

    assert_same(0.5, $payload['temperature']);
    assert_same(64, $payload['max_tokens']);
    assert_same(0.8, $payload['top_p']);
    assert_same('ENDE', $payload['stop']);
});

test_case('openaiNormalizeChatPayload() verwirft nicht-numerische Parameter', function (): void {
    $payload = openaiNormalizeChatPayload([
        'messages'    => [['role' => 'user', 'content' => 'hi']],
        'temperature' => 'heiß',
        'max_tokens'  => 'viele',
        'top_p'       => null,
        'stop'        => 5,
        'reasoning_effort' => 5,
    ], 'm1');

    assert_false(array_key_exists('temperature', $payload));
    assert_false(array_key_exists('max_tokens', $payload));
    assert_false(array_key_exists('top_p', $payload));
    assert_false(array_key_exists('stop', $payload));
    assert_false(array_key_exists('reasoning_effort', $payload));
});

test_case('openaiNormalizeChatPayload() bevorzugt max_tokens vor max_completion_tokens', function (): void {
    $payload = openaiNormalizeChatPayload([
        'messages'              => [['role' => 'user', 'content' => 'hi']],
        'max_tokens'            => 10,
        'max_completion_tokens' => 999,
    ], 'm1');
    assert_same(10, $payload['max_tokens']);

    $payload = openaiNormalizeChatPayload([
        'messages'              => [['role' => 'user', 'content' => 'hi']],
        'max_completion_tokens' => 42,
    ], 'm1');
    assert_same(42, $payload['max_tokens'], 'max_completion_tokens wird als Ersatz akzeptiert');
});

test_case('openaiNormalizeChatPayload() normalisiert die Nachrichtenliste', function (): void {
    $payload = openaiNormalizeChatPayload([
        'messages' => [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'hi'],
        ],
    ], 'm1');

    assert_same([
        ['role' => 'system', 'content' => 'sys'],
        ['role' => 'user', 'content' => 'hi'],
    ], $payload['messages']);
});

exit(test_summary('test_openai_api'));
