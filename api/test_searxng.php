<?php
/**
 * api/test_searxng.php
 *
 * Admin-only endpoint that tests connectivity to a SearXNG instance.
 *
 * Request:
 *   GET ?url=<searxng_base_url>
 *
 * Response shape (success):
 *   { "ok": true, "message": "SearXNG erreichbar. 5 Ergebnisse für Testsuche." }
 *
 * Response shape (error):
 *   { "ok": false, "message": "Fehlermeldung" }
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
requireAdminOrJson403();

$rawUrl = trim($_GET['url'] ?? '');

if ($rawUrl === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Keine URL angegeben.']);
    exit;
}

if (filter_var($rawUrl, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Ungültige URL.']);
    exit;
}

// Build the search URL the same way chat.php does.
function buildTestUrl(string $baseUrl): string|false
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }

    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if ($path === '') {
        $path = '/search';
    } elseif (!preg_match('#/search$#', $path)) {
        $path .= '/search';
    }

    $url = $parts['scheme'] . '://';
    if (isset($parts['user'])) {
        $url .= $parts['user'];
        if (isset($parts['pass'])) {
            $url .= ':' . $parts['pass'];
        }
        $url .= '@';
    }
    $url .= $parts['host'];
    if (isset($parts['port'])) {
        $url .= ':' . $parts['port'];
    }
    $url .= $path;

    return $url . '?' . http_build_query([
        'q'          => 'test',
        'format'     => 'json',
        'language'   => 'de',
        'safesearch' => '0',
    ]);
}

$testUrl = buildTestUrl($rawUrl);
if ($testUrl === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'URL konnte nicht verarbeitet werden.']);
    exit;
}

$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    echo json_encode(['ok' => false, 'message' => 'SearXNG nicht erreichbar: ' . $curlErr]);
    exit;
}

$data = json_decode($body, true);

if ($httpCode !== 200 || !is_array($data)) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Unerwartete Antwort von SearXNG (HTTP ' . $httpCode . ').',
    ]);
    exit;
}

$count = count($data['results'] ?? []);
echo json_encode([
    'ok'      => true,
    'message' => 'SearXNG erreichbar. ' . $count . ' Ergebnis' . ($count !== 1 ? 'se' : '') . ' für Testsuche.',
]);
