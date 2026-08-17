<?php
/**
 * api/chat.php
 *
 * Proxies a POST /v1/chat/completions request to the best available
 * LM Studio endpoint, selected by the load balancer.
 *
 * The load balancer groups endpoints by their configured default_model.
 * Within a group it picks the endpoint with the fewest running tasks
 * (max 4 per endpoint), favouring the one that was used least recently.
 *
 * Every dispatched request is registered as a task in the DB.
 * Token counts from the LM Studio response are persisted on completion
 * so that statistics can be derived later.
 *
 * Streaming (stream: true) is supported – the response is forwarded
 * as Server-Sent Events (text/event-stream).
 */

// Start the PHP session so that $_SESSION['admin_id'] is available for
// linking conversation sessions to registered users.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/prompt_security.php';
require_once __DIR__ . '/balancer.php';
require_once __DIR__ . '/sd_balancer.php';
require_once __DIR__ . '/comfy_balancer.php';
require_once __DIR__ . '/embedding.php';

function buildSearxngSearchUrl(string $baseUrl, string $query): string
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Ungültige SearXNG-URL.');
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
        'q' => $query,
        'format' => 'json',
        'language' => 'de',
        'safesearch' => '0',
    ]);
}

function runSearxngSearch(string $baseUrl, string $query, int $timeout = 15): array
{
    $url = buildSearxngSearchUrl($baseUrl, $query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new RuntimeException('SearXNG nicht erreichbar: ' . $curlErr);
    }

    $data = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($data)) {
        throw new RuntimeException('Unerwartete Antwort von SearXNG (HTTP ' . $httpCode . ').');
    }

    $results = [];
    foreach (array_slice($data['results'] ?? [], 0, 5) as $result) {
        if (!is_array($result)) {
            continue;
        }
        $results[] = [
            'title' => (string) ($result['title'] ?? ''),
            'url' => (string) ($result['url'] ?? ''),
            'snippet' => (string) ($result['content'] ?? ''),
            'source' => (string) ($result['engine'] ?? ''),
        ];
    }

    return [
        'query' => $query,
        'result_count' => count($results),
        'results' => $results,
    ];
}

/**
 * Validates a URL the model wants to fetch and protects against SSRF:
 * only plain http/https URLs pointing at public IP addresses are allowed,
 * everything else (file://, localhost, private/link-local/reserved ranges)
 * is rejected before any request leaves the server.
 */
function assertFetchableWebUrl(string $url): array
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Ungültige URL.');
    }

    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new RuntimeException('Nur http- und https-URLs können abgerufen werden.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('URLs mit Zugangsdaten werden nicht abgerufen.');
    }

    $host = strtolower(trim((string) $parts['host'], '[]'));
    if ($host === '') {
        throw new RuntimeException('Ungültige URL.');
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = (string) $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }
        if ($ips === []) {
            $resolved = gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }
    }

    if ($ips === []) {
        throw new RuntimeException('Hostname konnte nicht aufgelöst werden.');
    }
    foreach ($ips as $ip) {
        if (!filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new RuntimeException('Interne oder reservierte Adressen werden nicht abgerufen.');
        }
    }

    return ['url' => $url, 'host' => $host, 'ips' => $ips];
}

/**
 * Converts an HTML document into compact plain text the LLM can read:
 * script/style/nav noise is dropped, tags are stripped and whitespace is
 * collapsed.
 */
function extractReadableText(string $html): string
{
    $text = preg_replace(
        '#<(script|style|noscript|template|svg|head)\b[^>]*>.*?</\1>#is',
        ' ',
        $html
    ) ?? $html;
    $text = preg_replace('#<!--.*?-->#s', ' ', $text) ?? $text;
    $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $text) ?? $text;

    return trim($text);
}

/**
 * Extracts the <title> of an HTML document, if present.
 */
function extractHtmlTitle(string $html): string
{
    if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m) !== 1) {
        return '';
    }
    return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Downloads a single web page (typically one of the URLs search_web returned)
 * and returns its readable text content so the model can work with the actual
 * page instead of the short search snippet.
 */
function fetchWebPage(string $url, int $timeout = 15, int $maxChars = 6000): array
{
    $target = assertFetchableWebUrl($url);
    $maxBytes = 2 * 1024 * 1024;

    $ch = curl_init($target['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        // Redirects are followed manually so every hop can be re-validated
        // against the SSRF rules above.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
            'Accept-Language: de,en;q=0.8',
        ],
        CURLOPT_USERAGENT      => 'LLMInt/1.0 (+web_fetch)',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_BUFFERSIZE     => 16384,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) use ($maxBytes): int {
            return ($downloaded > $maxBytes || $downloadSize > $maxBytes) ? 1 : 0;
        },
    ]);

    $body = '';
    $finalUrl = $target['url'];
    $contentType = '';
    $httpCode = 0;

    for ($hop = 0; $hop < 4; $hop++) {
        curl_setopt($ch, CURLOPT_URL, $finalUrl);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));

        if ($curlErr !== '') {
            curl_close($ch);
            throw new RuntimeException('Seite nicht erreichbar: ' . $curlErr);
        }

        if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            $location = trim((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL));
            if ($location === '') {
                break;
            }
            try {
                $finalUrl = assertFetchableWebUrl($location)['url'];
            } catch (RuntimeException $e) {
                curl_close($ch);
                throw new RuntimeException('Weiterleitung blockiert: ' . $e->getMessage());
            }
            continue;
        }
        break;
    }
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Seite lieferte HTTP ' . $httpCode . '.');
    }
    if ($contentType !== '' && !preg_match('#(text/|application/(xhtml\+xml|json|xml))#', $contentType)) {
        throw new RuntimeException('Nicht unterstützter Inhaltstyp: ' . $contentType . '.');
    }

    $body = (string) $body;
    $title = extractHtmlTitle($body);
    $content = extractReadableText($body);
    if ($content === '') {
        throw new RuntimeException('Die Seite enthielt keinen lesbaren Text.');
    }

    $truncated = false;
    if (mb_strlen($content, 'UTF-8') > $maxChars) {
        $content = mb_substr($content, 0, $maxChars, 'UTF-8');
        $truncated = true;
    }

    return [
        'url' => $finalUrl,
        'title' => $title,
        'content' => $content,
        'truncated' => $truncated,
        'char_count' => mb_strlen($content, 'UTF-8'),
    ];
}

function startSearchLog(string $query): int
{
    try {
        $db = getDb();
        $db->prepare(
            "INSERT INTO search_logs (query, status, started_at) VALUES (?, 'running', NOW(3))"
        )->execute([$query]);
        return (int) $db->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function completeSearchLog(int $id, string $status, ?array $results = null): void
{
    if ($id <= 0) {
        return;
    }
    try {
        $resultsJson = $results !== null
            ? json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        getDb()->prepare(
            'UPDATE search_logs SET status = ?, results = ?, finished_at = NOW(3) WHERE id = ?'
        )->execute([$status, $resultsJson, $id]);
    } catch (Throwable $e) {
        // Best-effort
    }
}

/**
 * Extracts the compact {title, url} source list of a search_web tool result
 * for use as "used sources" pills in the UI and for logging. Duplicate URLs
 * (e.g. the same page returned to multiple queries in one response) are
 * removed while keeping first-seen order.
 */
function extractSearchSources(array $searchResult): array
{
    $sources = [];
    foreach ($searchResult['results'] ?? [] as $result) {
        if (!is_array($result)) {
            continue;
        }
        $url = trim((string) ($result['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $title = trim((string) ($result['title'] ?? ''));
        $sources[$url] = ['title' => $title !== '' ? $title : $url, 'url' => $url];
    }
    return array_values($sources);
}

function createSearchToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'search_web',
            'description' => 'Sucht aktuelle Informationen im Web über SearXNG. Nur aufrufen, wenn die Anfrage aktuelle, sich häufig ändernde oder nach dem Wissensstand des Modells liegende Informationen erfordert (z. B. aktuelle Ereignisse, Preise, Versionsnummern) und diese nicht bereits zuverlässig aus eigenem Wissen beantwortet werden können. Für allgemeines, zeitloses Wissen NICHT aufrufen. Die Treffer enthalten nur kurze Snippets – reichen diese nicht aus, anschließend web_fetch mit den gelieferten URLs aufrufen, um den vollständigen Seiteninhalt zu lesen.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Suchanfrage für aktuelle Informationen im Web.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ]];
}

function createWebFetchToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'web_fetch',
            'description' => 'Ruft den Textinhalt einer Webseite ab. Direkt nach search_web für die interessantesten Treffer-URLs aufrufen, wenn die Suchsnippets für eine belastbare Antwort nicht ausreichen (z. B. Preise, Details, Zahlen). Nur öffentliche http/https-URLs, bevorzugt URLs aus vorherigen search_web-Ergebnissen.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'Vollständige http(s)-URL der abzurufenden Seite, in der Regel aus einem search_web-Ergebnis.',
                    ],
                    'max_chars' => [
                        'type' => 'integer',
                        'description' => 'Optionale Obergrenze der gelieferten Zeichen (500–20000, Standard: 6000).',
                    ],
                ],
                'required' => ['url'],
            ],
        ],
    ]];
}

function createImageGenerationToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'generate_image',
            'description' => 'Generiert ein Bild mit Stable Diffusion (AUTOMATIC1111) anhand eines Text-Prompts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Englischer Text-Prompt, der das zu generierende Bild beschreibt.',
                    ],
                    'negative_prompt' => [
                        'type' => 'string',
                        'description' => 'Optionaler negativer Prompt – Elemente, die im Bild vermieden werden sollen.',
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Breite des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Höhe des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                ],
                'required' => ['prompt'],
            ],
        ],
    ]];
}

/**
 * Returns true when at least one active SD endpoint is configured.
 */
function hasSdEndpoints(): bool
{
    try {
        $count = (int) getDb()->query(
            "SELECT COUNT(*) FROM sd_endpoints WHERE is_active = 1"
        )->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Calls api/sd_generate.php internally by performing a loopback HTTP request.
 * Returns an associative array with either 'image_url' (success) or 'error'.
 */
function callSdGenerate(array $params, int $timeout = 120): array
{
    $outputDir = __DIR__ . '/../sd_output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    // Resolve a slot directly instead of doing an HTTP round-trip.
    $mode = in_array($params['mode'] ?? '', ['img2img'], true) ? 'img2img' : 'txt2img';

    try {
        $slot = pickSdEndpoint($mode);
    } catch (Throwable $e) {
        return ['error' => 'Interner Fehler beim SD-Endpunkt-Routing.'];
    }

    if ($slot === null) {
        return ['error' => 'Kein SD-Endpunkt verfügbar.'];
    }

    $endpoint = $slot['endpoint'];
    $taskId   = $slot['task_id'];
    $baseUrl  = rtrim($endpoint['base_url'], '/');
    $epTimeout = max(1, (int) $endpoint['timeout']);

    $prompt          = trim((string) ($params['prompt'] ?? ''));
    $negativePrompt  = (string) ($params['negative_prompt'] ?? '');
    $width           = max(64, min(2048, (int) ($params['width']  ?? 512)));
    $height          = max(64, min(2048, (int) ($params['height'] ?? 512)));
    $steps           = max(1,  min(150,  (int) ($params['steps']  ?? 20)));
    $cfgScale        = max(1.0, min(30.0, (float) ($params['cfg_scale'] ?? 7.0)));

    $sdPayload = [
        'prompt'          => $prompt,
        'negative_prompt' => $negativePrompt,
        'width'           => $width,
        'height'          => $height,
        'steps'           => $steps,
        'cfg_scale'       => $cfgScale,
        'save_images'     => false,
        'send_images'     => true,
    ];

    $apiPath = $mode === 'img2img' ? '/sdapi/v1/img2img' : '/sdapi/v1/txt2img';
    $url     = $baseUrl . $apiPath;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($sdPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $epTimeout,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        completeSdTask($taskId, 'error');
        return ['error' => 'AUTOMATIC1111 nicht erreichbar: ' . $curlErr];
    }

    $data = json_decode($body, true);

    if ($httpCode !== 200 || !is_array($data)) {
        completeSdTask($taskId, 'error');
        $msg = isset($data['detail']) ? (string) $data['detail'] : 'AUTOMATIC1111 Fehler (HTTP ' . $httpCode . ')';
        return ['error' => $msg];
    }

    $images = $data['images'] ?? [];
    if (!is_array($images) || count($images) === 0) {
        completeSdTask($taskId, 'error');
        return ['error' => 'AUTOMATIC1111 hat kein Bild zurückgegeben.'];
    }

    $imageData = base64_decode($images[0], true);
    if ($imageData === false) {
        completeSdTask($taskId, 'error');
        return ['error' => 'Ungültige Bilddaten von AUTOMATIC1111.'];
    }

    $filename = 'sd_' . bin2hex(random_bytes(12)) . '.png';
    $filePath = $outputDir . '/' . $filename;

    if (file_put_contents($filePath, $imageData) === false) {
        completeSdTask($taskId, 'error');
        return ['error' => 'Bild konnte nicht gespeichert werden.'];
    }

    completeSdTask($taskId, 'done');

    $seed = null;
    if (isset($data['info'])) {
        $info = json_decode((string) $data['info'], true);
        if (is_array($info) && isset($info['seed'])) {
            $seed = (int) $info['seed'];
        }
    }

    return [
        'image_url' => 'sd_output/' . $filename,
        'width'     => $width,
        'height'    => $height,
        'prompt'    => $prompt,
        'seed'      => $seed,
    ];
}

function tokenizeQueryTerms(string $query): array
{
    $query = mb_strtolower(trim($query));
    if ($query === '') {
        return [];
    }
    $parts = preg_split('/[^\p{L}\p{N}_-]+/u', $query) ?: [];
    $terms = [];
    foreach ($parts as $term) {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            continue;
        }
        $terms[$term] = true;
    }
    return array_keys($terms);
}

function buildRagSnippet(string $text, array $terms, int $maxLen = 1200): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    $lower = mb_strtolower($text);
    $pos = null;
    foreach ($terms as $term) {
        $idx = mb_strpos($lower, $term);
        if ($idx !== false && ($pos === null || $idx < $pos)) {
            $pos = $idx;
        }
    }

    if ($pos === null) {
        return mb_substr($text, 0, $maxLen) . ' … [gekürzt]';
    }

    $start = max(0, $pos - (int) floor($maxLen / 3));
    $snippet = mb_substr($text, $start, $maxLen);
    if ($start > 0) {
        $snippet = '… ' . $snippet;
    }
    if (($start + $maxLen) < mb_strlen($text)) {
        $snippet .= ' …';
    }
    return $snippet;
}

function scoreRagChunk(string $chunkText, string $query, array $terms): float
{
    $lowerChunk = mb_strtolower($chunkText);
    $score = 0.0;
    $matchedTerms = 0;

    $queryLower = mb_strtolower($query);
    if ($queryLower !== '' && mb_strpos($lowerChunk, $queryLower) !== false) {
        $score += 8.0;
    }

    foreach ($terms as $term) {
        $hits = substr_count($lowerChunk, $term);
        if ($hits > 0) {
            $matchedTerms++;
            $score += min(4.0, 1.2 * $hits);
        }
    }

    if ($matchedTerms > 0) {
        $coverage = $matchedTerms / max(1, count($terms));
        $score += $coverage * 4.0;
    }

    $len = max(1, mb_strlen($chunkText));
    $score += min(1.5, 700 / $len);

    return $score;
}

/**
 * Returns true when at least one analysed document upload is available
 * for the current user (own uploads or globally shared uploads).
 */
function hasDocumentUploads(?int $userId): bool
{
    if ($userId === null || $userId <= 0) {
        return false;
    }
    try {
        $stmt = getDb()->prepare(
            "SELECT COUNT(*)
               FROM document_uploads
              WHERE status = 'done'
                AND chunk_count > 0"
                . " AND (user_id = ? OR is_global_rag = 1)"
        );
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function createDocumentQueryToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'query_documents',
            'description' => 'Durchsucht analysierte Dokumente (eigene Uploads sowie global freigegebene Uploads anderer Nutzer) per chunk-basierter RAG-Suche. Gib in deiner Antwort an, aus welchem Dokument die Informationen stammen.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Suchanfrage, nach der in den Dokumenten gesucht werden soll.',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ]];
}

/**
 * Search through extracted document texts for relevant content.
 *
 * When hybrid search is enabled (hybrid_search_enabled = 1) and an
 * embedding endpoint is configured, results are obtained through:
 *   1. BM25-style keyword scoring (existing logic).
 *   2. Cosine-similarity scoring against stored chunk embeddings.
 *   3. Reciprocal Rank Fusion (RRF) of both result lists.
 *   4. Optional reranking via an OpenAI-compatible reranker API.
 *
 * Falls back to plain BM25 when embeddings or the reranker are unavailable.
 *
 * Returns an array with matching document excerpts.
 */
function queryDocuments(string $query, ?int $userId): array
{
    $query = trim($query);
    if ($query === '') {
        return ['error' => 'Leere Suchanfrage.'];
    }
    if ($userId === null || $userId <= 0) {
        return ['error' => 'Dokumentsuche ist nur für angemeldete Nutzer verfügbar.'];
    }

    // Read configuration.
    $hybridEnabled    = getSetting('hybrid_search_enabled', '0') === '1';
    $embeddingEnabled = getSetting('embedding_enabled', '0') === '1';
    $rerankerEnabled  = getSetting('reranker_enabled', '0') === '1';
    $bm25Weight       = max(0.0, (float) getSetting('bm25_weight', '0.5'));
    $embeddingWeight  = max(0.0, (float) getSetting('embedding_weight', '0.5'));
    $rerankerTopK     = max(1, (int) getSetting('reranker_top_k', '5'));

    // Number of candidates to load for each step.
    $chunkLimit       = 200; // fetch limit from DB
    $fusionCandidates = 50;  // max candidates after RRF fusion, before reranking

    writeLog('info', 'Dokumentensuche im Wissensspeicher gestartet' . ($hybridEnabled ? ' (Hybrid-Modus)' : '') . '.');

    try {
        $db    = getDb();
        $terms = array_slice(tokenizeQueryTerms($query), 0, 12);

        // ── Step 1: Load chunk candidates ────────────────────────────────────
        // When hybrid search is on and embeddings exist, also fetch the stored
        // embedding so we can compute cosine similarity in PHP.
        $selectEmbedding = ($hybridEnabled && $embeddingEnabled) ? ', dc.embedding' : '';
        $stmt = $db->prepare(
            "SELECT du.id AS document_id, du.original_name,
                    dc.id AS chunk_id, dc.chunk_index, dc.chunk_text{$selectEmbedding}
               FROM document_chunks dc
               JOIN document_uploads du ON du.id = dc.document_upload_id
              WHERE du.status = 'done'
                AND (du.user_id = ? OR du.is_global_rag = 1)
              ORDER BY du.uploaded_at DESC, dc.chunk_index ASC
              LIMIT {$chunkLimit}"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            writeLog('info', 'Dokumentensuche lieferte 0 relevante Dokumente.');
            return [
                'found'   => false,
                'message' => 'Es sind noch keine analysierten Dokument-Chunks (eigene oder global freigegebene) verfügbar.',
            ];
        }

        // ── Step 2: BM25-style scoring ────────────────────────────────────────
        $bm25Results = [];
        foreach ($rows as $row) {
            $chunkText = trim((string) ($row['chunk_text'] ?? ''));
            if ($chunkText === '') {
                continue;
            }
            $score = scoreRagChunk($chunkText, $query, $terms);
            if ($score <= 0.0) {
                continue;
            }
            $bm25Results[] = [
                'document_id'  => (int) ($row['document_id'] ?? 0),
                'document'     => (string) ($row['original_name'] ?? 'Unbekannt'),
                'chunk_id'     => (int) ($row['chunk_id'] ?? 0),
                'chunk_index'  => (int) ($row['chunk_index'] ?? 0),
                'chunk_text'   => $chunkText,
                'content'      => buildRagSnippet($chunkText, $terms),
                'bm25_score'   => $score,
                'embedding'    => $row['embedding'] ?? null,
            ];
        }

        // Sort BM25 results by score descending.
        usort($bm25Results, static fn($a, $b) => $b['bm25_score'] <=> $a['bm25_score']);

        // ── Step 3: Embedding search (optional) ───────────────────────────────
        $embeddingResults = [];
        $useEmbedding = $hybridEnabled && $embeddingEnabled && hasActiveEmbeddingEndpoint();

        if ($useEmbedding) {
            // Retrieve or compute the query embedding.
            $embModel   = trim(getSetting('embedding_model', ''));
            $queryEmbed = null;

            if ($embModel !== '') {
                $queryEmbed = getCachedQueryEmbedding($query, $embModel);
            }

            if ($queryEmbed === null) {
                $startMs    = (int) round(microtime(true) * 1000);
                $queryEmbed = generateEmbeddingAuto($query, 'query');
                $durationMs = (int) round(microtime(true) * 1000) - $startMs;

                if ($queryEmbed !== null && $embModel !== '') {
                    setCachedQueryEmbedding($query, $embModel, $queryEmbed);
                }
            }

            if ($queryEmbed !== null) {
                // Score every chunk that has a stored embedding.
                $maxSim = null;
                foreach ($rows as $row) {
                    $chunkText = trim((string) ($row['chunk_text'] ?? ''));
                    if ($chunkText === '') {
                        continue;
                    }
                    $chunkEmbed = embeddingFromJson($row['embedding'] ?? null);
                    if ($chunkEmbed === null) {
                        continue;
                    }
                    $sim = cosineSimilarity($queryEmbed, $chunkEmbed);
                    if ($sim <= 0.0) {
                        continue;
                    }
                    if ($maxSim === null || $sim > $maxSim) {
                        $maxSim = $sim;
                    }
                    $embeddingResults[] = [
                        'document_id' => (int) ($row['document_id'] ?? 0),
                        'document'    => (string) ($row['original_name'] ?? 'Unbekannt'),
                        'chunk_id'    => (int) ($row['chunk_id'] ?? 0),
                        'chunk_index' => (int) ($row['chunk_index'] ?? 0),
                        'chunk_text'  => $chunkText,
                        'content'     => buildRagSnippet($chunkText, $terms),
                        'sim_score'   => $sim,
                    ];
                }
                // Sort embedding results by similarity descending.
                usort($embeddingResults, static fn($a, $b) => $b['sim_score'] <=> $a['sim_score']);

                if ($maxSim !== null) {
                    logEmbeddingRequest('query', $embModel, 0, $maxSim, false, 'ok');
                }
            }
        }

        // ── Step 4: Reciprocal Rank Fusion (RRF) ─────────────────────────────
        // k = 60 is the standard constant that prevents very high-rank dominance.
        $rrfK       = 60;
        $rrfScores  = []; // keyed by chunk_id
        $rrfMeta    = []; // keyed by chunk_id → metadata

        // Helper to assign RRF contribution from one ranked list.
        $applyRrf = static function (array $list, float $weight) use ($rrfK, &$rrfScores, &$rrfMeta): void {
            foreach ($list as $rank => $item) {
                $cid = (int) ($item['chunk_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $contribution = $weight * (1.0 / ($rrfK + $rank + 1));
                $rrfScores[$cid] = ($rrfScores[$cid] ?? 0.0) + $contribution;
                if (!isset($rrfMeta[$cid])) {
                    $rrfMeta[$cid] = $item;
                }
            }
        };

        if ($useEmbedding && !empty($embeddingResults)) {
            // Both lists contribute with their configured weights.
            $applyRrf($bm25Results, $bm25Weight);
            $applyRrf($embeddingResults, $embeddingWeight);
        } else {
            // Only BM25 available – use it directly (weight = 1.0 for simplicity).
            $applyRrf($bm25Results, 1.0);
        }

        // Sort fused results by RRF score descending.
        arsort($rrfScores);

        // Build fused candidate list (up to $fusionCandidates entries).
        $fused = [];
        foreach (array_slice($rrfScores, 0, $fusionCandidates, true) as $cid => $rrfScore) {
            if (!isset($rrfMeta[$cid])) {
                continue;
            }
            $item = $rrfMeta[$cid];
            $item['rrf_score'] = $rrfScore;
            $fused[] = $item;
        }

        if (empty($fused)) {
            // No BM25 hits either – nothing found.
            writeLog('info', 'Dokumentensuche lieferte 0 relevante Dokumente.');
            return [
                'found'   => false,
                'message' => 'Keine passenden Informationen in den analysierten Dokumenten gefunden.',
            ];
        }

        // ── Step 5: Optional reranking ────────────────────────────────────────
        if ($rerankerEnabled && getSetting('reranker_endpoint', '') !== '') {
            $fused = rerankDocuments($query, $fused, $rerankerTopK);
        } else {
            // No reranker – just take top N from fusion (default 5, or rerankerTopK).
            $fused = array_slice($fused, 0, $rerankerTopK);
        }

        // ── Step 6: Format output ─────────────────────────────────────────────
        $results = array_map(static function (array $row): array {
            return [
                'document'  => $row['document'],
                'chunk'     => $row['chunk_index'] + 1,
                'relevance' => round((float) ($row['rrf_score'] ?? $row['bm25_score'] ?? 0.0), 4),
                'content'   => $row['content'],
            ];
        }, $fused);

        $relevantDocumentCount = count(array_unique(array_map(
            static fn(array $row): int => (int) ($row['document_id'] ?? 0),
            $fused
        )));
        writeLog('info', 'Dokumentensuche lieferte ' . $relevantDocumentCount . ' relevante Dokumente.');

        return [
            'found'   => true,
            'results' => $results,
        ];
    } catch (Throwable $e) {
        writeLog('error', 'Dokumentensuche Fehler: ' . $e->getMessage());
        return ['error' => 'Datenbankfehler: ' . $e->getMessage()];
    }
}


/**
 * Returns true when at least one active ComfyUI endpoint is configured.
 */
function hasComfyEndpoints(): bool
{
    try {
        $count = (int) getDb()->query(
            "SELECT COUNT(*) FROM comfy_endpoints WHERE is_active = 1"
        )->fetchColumn();
        return $count > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function createComfyToolDefinition(): array
{
    return [[
        'type' => 'function',
        'function' => [
            'name' => 'generate_image_comfy',
            'description' => 'Generiert ein Bild mit ComfyUI anhand eines Text-Prompts.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Englischer Text-Prompt, der das zu generierende Bild beschreibt.',
                    ],
                    'negative_prompt' => [
                        'type' => 'string',
                        'description' => 'Optionaler negativer Prompt – Elemente, die im Bild vermieden werden sollen.',
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Breite des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Höhe des Bildes in Pixeln (64–2048, Standard: 512).',
                    ],
                ],
                'required' => ['prompt'],
            ],
        ],
    ]];
}

/**
 * Generate an image via ComfyUI, analogous to callSdGenerate().
 * Returns an associative array with either 'image_url' (success) or 'error'.
 */
function callComfyGenerate(array $params, int $timeout = 120): array
{
    $outputDir = __DIR__ . '/../sd_output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    try {
        $slot = pickComfyEndpoint();
    } catch (Throwable $e) {
        return ['error' => 'Interner Fehler beim ComfyUI-Endpunkt-Routing.'];
    }

    if ($slot === null) {
        return ['error' => 'Kein ComfyUI-Endpunkt verfügbar.'];
    }

    $endpoint   = $slot['endpoint'];
    $taskId     = $slot['task_id'];
    $baseUrl    = rtrim($endpoint['base_url'], '/');
    $epTimeout  = max(1, (int) $endpoint['timeout']);
    $checkpoint = (string) ($endpoint['default_checkpoint'] ?: '');

    $prompt         = trim((string) ($params['prompt'] ?? ''));
    $negativePrompt = (string) ($params['negative_prompt'] ?? '');
    $width          = max(64, min(2048, (int) ($params['width']  ?? 512)));
    $height         = max(64, min(2048, (int) ($params['height'] ?? 512)));
    $steps          = max(1,  min(150,  (int) ($params['steps']  ?? 20)));
    $cfgScale       = max(1.0, min(30.0, (float) ($params['cfg_scale'] ?? 7.0)));
    $seed           = isset($params['seed']) ? (int) $params['seed'] : random_int(0, PHP_INT_MAX);
    $clientId       = bin2hex(random_bytes(8));

    // If no checkpoint configured, query the first available one.
    if ($checkpoint === '') {
        $infoUrl = $baseUrl . '/object_info/CheckpointLoaderSimple';
        $infoCh  = curl_init($infoUrl);
        curl_setopt_array($infoCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $infoBody = curl_exec($infoCh);
        $infoData = json_decode((string) $infoBody, true);
        curl_close($infoCh);

        $ckptList = $infoData['CheckpointLoaderSimple']['input']['required']['ckpt_name'][0] ?? [];
        if (is_array($ckptList) && !empty($ckptList)) {
            $checkpoint = (string) reset($ckptList);
        } else {
            completeComfyTask($taskId, 'error');
            return ['error' => 'Kein Checkpoint konfiguriert und kein Checkpoint auf dem ComfyUI-Server gefunden.'];
        }
    }

    $workflow = [
        '4' => [
            'class_type' => 'CheckpointLoaderSimple',
            'inputs'     => ['ckpt_name' => $checkpoint],
        ],
        '5' => [
            'class_type' => 'EmptyLatentImage',
            'inputs'     => ['batch_size' => 1, 'height' => $height, 'width' => $width],
        ],
        '6' => [
            'class_type' => 'CLIPTextEncode',
            'inputs'     => ['clip' => ['4', 1], 'text' => $prompt],
        ],
        '7' => [
            'class_type' => 'CLIPTextEncode',
            'inputs'     => ['clip' => ['4', 1], 'text' => $negativePrompt],
        ],
        '3' => [
            'class_type' => 'KSampler',
            'inputs'     => [
                'seed'         => $seed,
                'steps'        => $steps,
                'cfg'          => $cfgScale,
                'sampler_name' => 'euler',
                'scheduler'    => 'normal',
                'denoise'      => 1.0,
                'model'        => ['4', 0],
                'positive'     => ['6', 0],
                'negative'     => ['7', 0],
                'latent_image' => ['5', 0],
            ],
        ],
        '8' => [
            'class_type' => 'VAEDecode',
            'inputs'     => ['samples' => ['3', 0], 'vae' => ['4', 2]],
        ],
        '9' => [
            'class_type' => 'SaveImage',
            'inputs'     => ['filename_prefix' => 'ComfyUI', 'images' => ['8', 0]],
        ],
    ];

    $ch = curl_init($baseUrl . '/prompt');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['client_id' => $clientId, 'prompt' => $workflow]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        completeComfyTask($taskId, 'error');
        return ['error' => 'ComfyUI nicht erreichbar: ' . $curlErr];
    }

    $queueData = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($queueData) || empty($queueData['prompt_id'])) {
        completeComfyTask($taskId, 'error');
        $errMsg = isset($queueData['error']) ? (string) $queueData['error'] : 'ComfyUI Fehler beim Einreihen (HTTP ' . $httpCode . ')';
        return ['error' => $errMsg];
    }

    $promptId = (string) $queueData['prompt_id'];
    $deadline = time() + $epTimeout;
    $historyData = null;

    while (time() < $deadline) {
        sleep(1);
        $hCh = curl_init($baseUrl . '/history/' . rawurlencode($promptId));
        curl_setopt_array($hCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $hBody = curl_exec($hCh);
        $hCode = curl_getinfo($hCh, CURLINFO_HTTP_CODE);
        curl_close($hCh);

        if ($hCode !== 200) {
            continue;
        }
        $hData = json_decode($hBody, true);
        if (!is_array($hData) || empty($hData[$promptId])) {
            continue;
        }
        $entry = $hData[$promptId];

        if (!empty($entry['status']['status_str']) && $entry['status']['status_str'] === 'error') {
            completeComfyTask($taskId, 'error');
            return ['error' => 'ComfyUI Generierungsfehler.'];
        }
        if (!empty($entry['outputs'])) {
            $historyData = $entry;
            break;
        }
    }

    if ($historyData === null) {
        completeComfyTask($taskId, 'error');
        return ['error' => 'ComfyUI Timeout: Bild wurde nicht rechtzeitig fertiggestellt.'];
    }

    $imageInfo = null;
    foreach ($historyData['outputs'] as $nodeOutput) {
        $images = is_array($nodeOutput) ? ($nodeOutput['images'] ?? []) : [];
        if (is_array($images) && !empty($images)) {
            $imageInfo = $images[0];
            break;
        }
    }

    if (!is_array($imageInfo) || empty($imageInfo['filename'])) {
        completeComfyTask($taskId, 'error');
        return ['error' => 'ComfyUI hat kein Bild zurückgegeben.'];
    }

    $viewUrl = $baseUrl . '/view?' . http_build_query([
        'filename'  => $imageInfo['filename'],
        'subfolder' => $imageInfo['subfolder'] ?? '',
        'type'      => $imageInfo['type'] ?? 'output',
    ]);

    $imgCh = curl_init($viewUrl);
    curl_setopt_array($imgCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $imageData = curl_exec($imgCh);
    $imgCode   = curl_getinfo($imgCh, CURLINFO_HTTP_CODE);
    $imgErr    = curl_error($imgCh);
    curl_close($imgCh);

    if ($imgErr !== '' || $imgCode !== 200 || $imageData === false || $imageData === '') {
        completeComfyTask($taskId, 'error');
        return ['error' => 'Bild konnte nicht von ComfyUI heruntergeladen werden.'];
    }

    $filename = 'comfy_' . bin2hex(random_bytes(12)) . '.png';
    $filePath = $outputDir . '/' . $filename;

    if (file_put_contents($filePath, $imageData) === false) {
        completeComfyTask($taskId, 'error');
        return ['error' => 'Bild konnte nicht gespeichert werden.'];
    }

    completeComfyTask($taskId, 'done');

    return [
        'image_url' => 'sd_output/' . $filename,
        'width'     => $width,
        'height'    => $height,
        'prompt'    => $prompt,
        'seed'      => $seed,
    ];
}
function extractUsage(array $data): array
{
    return [
        'prompt' => max(0, (int) ($data['usage']['prompt_tokens'] ?? 0)),
        'completion' => max(0, (int) ($data['usage']['completion_tokens'] ?? 0)),
        'total' => max(0, (int) ($data['usage']['total_tokens'] ?? 0)),
    ];
}

function normalizeAssistantContent(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $item) {
        if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text']) && is_string($item['text'])) {
            $parts[] = $item['text'];
        }
    }

    return implode("\n", $parts);
}

/**
 * Collapses every "system" message of a conversation into a single system
 * message at the very beginning.
 *
 * Chat templates rendered by llama.cpp in --jinja mode (Mistral, Magistral,
 * Devstral, …) abort with
 *   "Jinja Exception: System message must be at the beginning."
 * as soon as they encounter more than one system message or a system message
 * that is not the first entry. LLMInt prepends both the date/time notice and
 * the configurable global system prompt, and clients may add their own system
 * message on top, so the outgoing conversation regularly contained several of
 * them. Merging them keeps the instructions semantically identical for every
 * backend while remaining compatible with those strict templates – it is
 * therefore applied to all endpoints, not just those flagged as direct
 * llama.cpp instances.
 */
function mergeSystemMessages(array $messages): array
{
    $systemParts = [];
    $rest        = [];

    foreach ($messages as $msg) {
        if (is_array($msg) && ($msg['role'] ?? '') === 'system') {
            $text = trim(normalizeAssistantContent($msg['content'] ?? ''));
            if ($text !== '') {
                $systemParts[] = $text;
            }
            continue;
        }
        $rest[] = $msg;
    }

    if ($systemParts === []) {
        return $rest;
    }

    array_unshift($rest, [
        'role'    => 'system',
        'content' => implode("\n\n", $systemParts),
    ]);

    return $rest;
}

/**
 * Returns true when a single message's "content" value contains at least one
 * OpenAI-style "image_url" content part (i.e. an attached image).
 */
function messageContentHasImage(mixed $content): bool
{
    if (!is_array($content)) {
        return false;
    }
    foreach ($content as $part) {
        if (is_array($part) && ($part['type'] ?? '') === 'image_url') {
            return true;
        }
    }
    return false;
}

/**
 * Returns true when any message in the request contains an attached image.
 * Used to route the request to a vision-capable endpoint only when needed.
 */
function payloadMessagesHaveImage(array $messages): bool
{
    foreach ($messages as $message) {
        if (is_array($message) && messageContentHasImage($message['content'] ?? null)) {
            return true;
        }
    }
    return false;
}

function ensureSseHeaders(): void
{
    static $headersSent = false;

    if ($headersSent) {
        return;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    $headersSent = true;
}

function emitSseData(array|string $payload): void
{
    ensureSseHeaders();

    if (is_array($payload)) {
        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    echo 'data: ' . $payload . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function isOpenAiStrictMode(): bool
{
    return !empty($GLOBALS['LLMINT_OPENAI_STRICT_MODE']);
}

function formatIntelligenceLabel(float $value): string
{
    if (abs($value - round($value)) < 0.00001) {
        return ((string) ((int) round($value))) . 'b';
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . 'b';
}

function buildIntelligenceUpgradePayload(array $suggestion): array
{
    $defaultMessage = 'Es stehen Ressourcen bereit um die Aufgabe erneut mit größerer Intelligenz zu bearbeiten. Dies kann länger dauern als zuvor, kann jedoch genauere Antworten liefern. Fortfahren?';
    $configuredMessage = trim(getSetting('intelligence_upgrade_message', ''));
    return [
        'available' => true,
        'current_model' => (string) ($suggestion['requested_model'] ?? ''),
        'current_intelligence' => formatIntelligenceLabel((float) ($suggestion['requested_intelligence'] ?? 0)),
        'suggested_model' => (string) ($suggestion['model'] ?? ''),
        'suggested_intelligence' => formatIntelligenceLabel((float) ($suggestion['suggested_intelligence'] ?? 0)),
        'message' => $configuredMessage !== '' ? $configuredMessage : $defaultMessage,
    ];
}

function emitIntelligenceUpgradeSse(?array $suggestion): void
{
    if ($suggestion === null) {
        return;
    }
    emitSseData([
        'type' => 'intelligence_upgrade',
        'upgrade' => buildIntelligenceUpgradePayload($suggestion),
    ]);
}

function buildResponseDetails(array $endpoint): array
{
    $alias = trim((string) ($endpoint['alias'] ?? ''));
    $baseUrl = trim((string) ($endpoint['base_url'] ?? ''));
    return [
        'processed_by' => $alias !== '' ? $alias : $baseUrl,
    ];
}

/**
 * Rough token estimate for a single OpenAI-style "image_url" content part,
 * used by estimateTokenCount() below.
 *
 * Vision-capable models tokenize an attached image based on its resolution
 * and the requested "detail" level (tiling), NOT on the length of the raw
 * base64 encoding that carries the pixel data over the wire. A modest
 * 1536px image can easily base64-encode to several hundred KB / a few
 * million characters, so counting it as literal text (chars / 4) would
 * inflate the estimate by two to three orders of magnitude – which is
 * exactly what produced absurd "Kontextlimit erreicht" reports for small
 * images. We therefore use small fixed budgets modeled after the
 * low/high-detail token costs used by common OpenAI-compatible vision
 * APIs, completely independent of the base64 payload size.
 */
function estimateImageTokenCount(mixed $imageUrlPart): int
{
    $detail = 'auto';
    if (is_array($imageUrlPart) && is_string($imageUrlPart['detail'] ?? null)) {
        $detail = $imageUrlPart['detail'];
    }
    // "low" detail images are always tokenized as a small fixed-size tile.
    if ($detail === 'low') {
        return 85;
    }
    // "auto"/"high" may be tiled up to ~1536px; use a conservative fixed
    // upper-bound estimate rather than scanning/decoding the image.
    return 1105;
}

/**
 * Rough token estimate for a chat messages array, used to pre-flight-check
 * an outgoing request against the configured context limits before it is
 * actually dispatched to the model (real usage is only known afterwards).
 * Uses the common ~4 characters-per-token rule of thumb for plain text,
 * plus a small per-message overhead for role/format tokens.
 *
 * Attached images (content-part type "image_url") are deliberately
 * excluded from the character count and instead budgeted via
 * estimateImageTokenCount() — see that function's docblock for why the
 * base64 image data must never be treated as literal text here.
 */
function estimateTokenCount(array $messages): int
{
    $chars = 0;
    $imageTokens = 0;
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            $chars += strlen($content);
        } elseif (is_array($content)) {
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'image_url') {
                    $imageTokens += estimateImageTokenCount($part['image_url'] ?? null);
                    continue;
                }
                if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                    $chars += strlen($part['text']);
                    continue;
                }
                // Unknown/other structured part: fall back to counting it as
                // text, but only its own (typically small) encoding, never a
                // base64 image payload (already handled above).
                $chars += strlen(json_encode($part));
            }
        }
        $chars += strlen((string) ($message['role'] ?? ''));
    }
    return (int) ceil($chars / 4) + $imageTokens + count($messages) * 4;
}

/**
 * Resolves the effective context limit(s) for an endpoint: the endpoint's
 * total context window and the (optionally smaller) per-user-slot cap. Both
 * are 0 when not configured (= kein Limit hinterlegt).
 *
 * @return array{endpoint_max:int,slot_limit:int,effective:int}
 */
function resolveContextLimits(array $endpoint): array
{
    $endpointMax = max(0, (int) ($endpoint['max_context'] ?? 0));
    $slotLimit   = max(0, (int) ($endpoint['context_limit_per_slot'] ?? 0));

    if ($slotLimit > 0 && $endpointMax > 0) {
        $effective = min($slotLimit, $endpointMax);
    } elseif ($slotLimit > 0) {
        $effective = $slotLimit;
    } else {
        $effective = $endpointMax;
    }

    return ['endpoint_max' => $endpointMax, 'slot_limit' => $slotLimit, 'effective' => $effective];
}

/**
 * Adds the context-usage figures (for the shrinking-circle indicators in the
 * chat UI) and, if the model was cut off because the context window ran out
 * (finish_reason "length"), a flag the frontend uses to show a clean notice
 * instead of silently truncating the answer.
 */
function addContextUsageToResponseDetails(array &$responseDetails, array $endpoint, int $totalTokens, ?string $finishReason): void
{
    $limits = resolveContextLimits($endpoint);

    if ($limits['endpoint_max'] > 0) {
        $responseDetails['endpoint_context_used'] = $totalTokens;
        $responseDetails['endpoint_context_max']  = $limits['endpoint_max'];
    }
    if ($limits['slot_limit'] > 0) {
        $responseDetails['session_context_used']  = $totalTokens;
        $responseDetails['session_context_limit'] = $limits['slot_limit'];
    }
    if ($finishReason === 'length' && $limits['effective'] > 0 && $totalTokens >= $limits['effective']) {
        $responseDetails['context_limit_reached'] = true;
    }
}

function emitResponseDetailsSse(?array $responseDetails): void
{
    if ($responseDetails === null) {
        return;
    }
    emitSseData([
        'type' => 'response_details',
        'details' => $responseDetails,
    ]);
}

function emitSyntheticStream(array $data, ?array $upgradeSuggestion = null, ?array $responseDetails = null, bool $contentAlreadySent = false): void
{
    $content = normalizeAssistantContent($data['choices'][0]['message']['content'] ?? '');
    $id = (string) ($data['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(8))));
    $created = (int) ($data['created'] ?? time());
    $model = (string) ($data['model'] ?? '');

    ensureSseHeaders();
    if ($contentAlreadySent) {
        // The answer was already forwarded token by token, only the closing
        // events are still missing.
        $content = '';
    } else {
        writeLog('info', 'Streaming der Antwort an User gestartet.');
    }

    if ($content !== '') {
        emitSseData([
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => ['content' => $content],
                'finish_reason' => null,
            ]],
        ]);
    }

    emitSseData([
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [[
            'index' => 0,
            'delta' => new stdClass(),
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'stop',
        ]],
    ]);
    if (!isOpenAiStrictMode()) {
        emitIntelligenceUpgradeSse($upgradeSuggestion);
        emitResponseDetailsSse($responseDetails);
    }
    emitSseData('[DONE]');
    if (!connection_aborted()) {
        writeLog('info', 'Antwort vollständig an User übertragen.');
    } else {
        writeLog('warning', 'Anfrage durch User abgebrochen.');
    }
}

/**
 * Merge streamed tool_call deltas into the accumulated tool call list.
 * Name and arguments arrive in fragments and are concatenated per index.
 */
function mergeToolCallDeltas(array &$toolCalls, array $deltas): void
{
    foreach ($deltas as $position => $part) {
        if (!is_array($part)) {
            continue;
        }
        $index = isset($part['index']) ? (int) $part['index'] : (int) $position;
        if (!isset($toolCalls[$index])) {
            $toolCalls[$index] = [
                'id'       => '',
                'type'     => 'function',
                'function' => ['name' => '', 'arguments' => ''],
            ];
        }
        if (!empty($part['id'])) {
            $toolCalls[$index]['id'] = (string) $part['id'];
        }
        if (!empty($part['type'])) {
            $toolCalls[$index]['type'] = (string) $part['type'];
        }
        if (isset($part['function']['name'])) {
            $toolCalls[$index]['function']['name'] .= (string) $part['function']['name'];
        }
        if (isset($part['function']['arguments'])) {
            $toolCalls[$index]['function']['arguments'] .= (string) $part['function']['arguments'];
        }
    }
}

/**
 * Run a single chat completion with upstream streaming enabled and assemble the
 * result into the same shape a non-streaming response would have.
 *
 * Content and reasoning deltas are forwarded to the client as SSE chunks while
 * they arrive, so the answer builds up live even during the tool-calling phase.
 * Tool call deltas are collected instead of forwarded; a small hold-back buffer
 * makes sure nothing is forwarded for a turn that turns out to be a tool call.
 *
 * @return array{error:string,http_code:int,data:?array,forwarded:bool,body:string}
 */
function streamChatCompletionRequest(
    string $url,
    array $payload,
    int $timeout,
    bool $forwardToClient,
    float $requestStart,
    bool &$firstTokenLogged,
    bool &$streamStartedLogged,
    bool &$clientAborted,
    ?float &$firstTokenElapsedMs = null
): array {
    $payload['stream'] = true;
    $payload['stream_options'] = ['include_usage' => true];

    $buffer           = '';
    $rawHead          = '';
    $content          = '';
    $reasoning        = '';
    $pendingContent   = '';
    $pendingReasoning = '';
    $toolCalls        = [];
    $usage            = null;
    $finishReason     = null;
    $id               = '';
    $model            = (string) ($payload['model'] ?? '');
    $created          = time();
    $forwarded        = false;
    $sawToolCall      = false;

    $flushPending = function () use (
        &$pendingContent, &$pendingReasoning, &$forwarded, &$streamStartedLogged,
        &$id, &$created, &$model, $forwardToClient
    ): void {
        if (!$forwardToClient) {
            $pendingContent = '';
            $pendingReasoning = '';
            return;
        }
        if ($pendingContent === '' && $pendingReasoning === '') {
            return;
        }
        $delta = [];
        if ($pendingReasoning !== '') {
            $delta['reasoning_content'] = $pendingReasoning;
        }
        if ($pendingContent !== '') {
            $delta['content'] = $pendingContent;
        }
        $pendingContent = '';
        $pendingReasoning = '';

        ensureSseHeaders();
        if (!$streamStartedLogged) {
            writeLog('info', 'Streaming der Antwort an User gestartet.');
            $streamStartedLogged = true;
        }
        emitSseData([
            'id'      => $id !== '' ? $id : 'chatcmpl-live',
            'object'  => 'chat.completion.chunk',
            'created' => $created,
            'model'   => $model,
            'choices' => [[
                'index'         => 0,
                'delta'         => $delta,
                'finish_reason' => null,
            ]],
        ]);
        $forwarded = true;
    };

    $processLine = function (string $line) use (
        &$content, &$reasoning, &$pendingContent, &$pendingReasoning, &$toolCalls,
        &$usage, &$finishReason, &$id, &$created, &$model, &$sawToolCall,
        &$firstTokenLogged, &$firstTokenElapsedMs, $requestStart
    ): void {
        if (!preg_match('/^data:\s?(.*)$/', $line, $m)) {
            return;
        }
        $raw = trim($m[1]);
        if ($raw === '' || $raw === '[DONE]') {
            return;
        }
        $obj = json_decode($raw, true);
        if (!is_array($obj)) {
            return;
        }

        if (!empty($obj['id'])) {
            $id = (string) $obj['id'];
        }
        if (!empty($obj['created'])) {
            $created = (int) $obj['created'];
        }
        if (!empty($obj['model'])) {
            $model = (string) $obj['model'];
        }
        if (isset($obj['usage']['total_tokens'])) {
            $usage = [
                'prompt_tokens'     => (int) ($obj['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($obj['usage']['completion_tokens'] ?? 0),
                'total_tokens'      => (int) $obj['usage']['total_tokens'],
            ];
        }

        $choice = $obj['choices'][0] ?? null;
        if (!is_array($choice)) {
            return;
        }
        if (!empty($choice['finish_reason'])) {
            $finishReason = (string) $choice['finish_reason'];
        }

        $delta = $choice['delta'] ?? [];
        if (!is_array($delta)) {
            return;
        }

        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $sawToolCall = true;
            mergeToolCallDeltas($toolCalls, $delta['tool_calls']);
        }
        if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
            // Reasoning ("thinking") tokens are the first tokens a reasoning model
            // decodes and often make up the bulk of the generation time. They must
            // start the first-token timer too, otherwise the tokens/sec metric is
            // computed over only the final answer segment and comes out wildly
            // inflated.
            if (!$firstTokenLogged) {
                $firstTokenElapsedMs = (microtime(true) - $requestStart) * 1000;
                writeLog('info', 'Erste Antworttokens nach ' . elapsedMilliseconds($requestStart) . ' ms erzeugt.');
                $firstTokenLogged = true;
            }
            $reasoning .= $delta['reasoning_content'];
            $pendingReasoning .= $delta['reasoning_content'];
        }
        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            if (!$firstTokenLogged) {
                $firstTokenElapsedMs = (microtime(true) - $requestStart) * 1000;
                writeLog('info', 'Erste Antworttokens nach ' . elapsedMilliseconds($requestStart) . ' ms erzeugt.');
                $firstTokenLogged = true;
            }
            $content .= $delta['content'];
            $pendingContent .= $delta['content'];
        }
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => 0,
    ]);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $chunk) use (
        &$buffer, &$rawHead, &$sawToolCall, &$clientAborted, $processLine, $flushPending
    ): int {
        if (strlen($rawHead) < 4096) {
            $rawHead .= substr($chunk, 0, 4096 - strlen($rawHead));
        }

        $buffer .= $chunk;
        $lines = explode("\n", $buffer);
        $buffer = array_pop($lines);
        foreach ($lines as $line) {
            $processLine(rtrim($line, "\r"));
        }

        // Hold back the very first characters so a turn that turns out to be a
        // tool call never leaks partial text to the client.
        if (!$sawToolCall) {
            $flushPending();
        }

        if (connection_aborted()) {
            if (!$clientAborted) {
                writeLog('warning', 'Anfrage durch User abgebrochen.');
                $clientAborted = true;
            }
            return 0;
        }
        return strlen($chunk);
    });

    curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($buffer !== '') {
        $processLine(rtrim($buffer, "\r"));
        $buffer = '';
    }
    if (!$sawToolCall && $httpCode === 200 && $curlErr === '') {
        $flushPending();
    } else {
        $pendingContent = '';
        $pendingReasoning = '';
    }

    if ($curlErr !== '' || ($httpCode !== 0 && $httpCode !== 200)) {
        return [
            'error'     => $curlErr,
            'http_code' => $httpCode,
            'data'      => null,
            'forwarded' => $forwarded,
            'body'      => $rawHead,
        ];
    }

    $message = ['role' => 'assistant', 'content' => $content];
    if ($reasoning !== '') {
        $message['reasoning_content'] = $reasoning;
    }
    if ($toolCalls !== []) {
        ksort($toolCalls);
        $message['tool_calls'] = array_values($toolCalls);
    }

    $data = [
        'id'      => $id !== '' ? $id : ('chatcmpl-' . bin2hex(random_bytes(8))),
        'object'  => 'chat.completion',
        'created' => $created,
        'model'   => $model,
        'choices' => [[
            'index'         => 0,
            'message'       => $message,
            'finish_reason' => $finishReason ?? 'stop',
        ]],
    ];
    if ($usage !== null) {
        $data['usage'] = $usage;
    }

    return [
        'error'     => '',
        'http_code' => $httpCode === 0 ? 200 : $httpCode,
        'data'      => $data,
        'forwarded' => $forwarded,
        'body'      => $rawHead,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Nur POST-Anfragen erlaubt.']);
    exit;
}

/**
 * Return the best-guess client IP address.
 * Checks X-Forwarded-For when a trusted proxy injects it, falls back to REMOTE_ADDR.
 */
function getClientIp(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = explode(',', $xff);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '–';
}

function getEndpointLogLabel(array $endpoint): string
{
    $alias = trim((string) ($endpoint['alias'] ?? ''));
    if ($alias !== '') {
        return $alias;
    }
    $baseUrl = trim((string) ($endpoint['base_url'] ?? ''));
    return $baseUrl !== '' ? $baseUrl : 'Unbekannter Endpunkt';
}

function elapsedMilliseconds(float $startedAt): int
{
    return max(0, (int) round((microtime(true) - $startedAt) * 1000));
}

function isTimeoutMessage(string $message): bool
{
    return preg_match('/timed out|timeout|zeitlimit/i', $message) === 1;
}

function logToolInvoked(string $toolName): void
{
    if ($toolName === '') {
        $toolName = 'Unbekanntes Tool';
    }
    writeLog('info', 'Tool ' . $toolName . ' durch Agent aufgerufen.');
}

function logToolResult(string $toolName, mixed $toolResult): void
{
    if ($toolName === '') {
        $toolName = 'Unbekanntes Tool';
    }
    $errorMessage = '';
    if (is_array($toolResult) && isset($toolResult['error']) && is_string($toolResult['error'])) {
        $errorMessage = trim($toolResult['error']);
    }

    if ($errorMessage === '') {
        writeLog('info', 'Tool ' . $toolName . ' erfolgreich beendet.');
        return;
    }

    if (isTimeoutMessage($errorMessage)) {
        writeLog('warning', 'Tool ' . $toolName . ' überschritt das konfigurierte Zeitlimit und wurde abgebrochen.');
    }
}

function logResponseFinished(
    float $requestStart,
    ?int $promptTokens,
    ?int $completionTokens,
    ?float $tokensPerSecond = null
): void {
    writeLog('info', 'Antwortgenerierung abgeschlossen (Gesamtdauer: ' . elapsedMilliseconds($requestStart) . ' ms).');
    if ($promptTokens !== null && $completionTokens !== null) {
        writeLog('info', 'Promptgröße: ' . $promptTokens . ' Token, Antwortgröße: ' . $completionTokens . ' Token.');
    }
    if ($tokensPerSecond !== null) {
        writeLog('info', 'Generierungsgeschwindigkeit: ' . number_format($tokensPerSecond, 1) . ' Token/s.');
    }
}

/**
 * Computes the token generation speed (tokens/sec) from the time of the
 * first streamed token to the end of the response, based on the number of
 * completion tokens produced. Returns null when the inputs don't allow a
 * meaningful measurement (no completion tokens, no first-token timestamp,
 * or a non-positive generation duration).
 */
function computeTokensPerSecond(
    float $requestStart,
    ?float $firstTokenElapsedMs,
    ?int $completionTokens
): ?float {
    if ($firstTokenElapsedMs === null || $completionTokens === null || $completionTokens <= 0) {
        return null;
    }
    $totalElapsedMs = (microtime(true) - $requestStart) * 1000;
    $generationMs   = $totalElapsedMs - $firstTokenElapsedMs;
    if ($generationMs <= 0) {
        return null;
    }
    return $completionTokens / ($generationMs / 1000);
}

$raw     = isset($GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE']) && is_string($GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE'])
    ? $GLOBALS['LLMINT_REQUEST_BODY_OVERRIDE']
    : file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ungültiger JSON-Body.']);
    exit;
}

if (($payload['action'] ?? '') === 'decline_intelligence_upgrade') {
    writeLog('info', 'Intelligence Upgrade durch User (' . getClientIp() . ') abgelehnt.');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

if (empty($payload['model']) || empty($payload['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '"model" und "messages" sind Pflichtfelder.']);
    exit;
}

// Validate messages array.
foreach ($payload['messages'] as $msg) {
    if (!isset($msg['role'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Jede Nachricht muss "role" enthalten.']);
        exit;
    }
    $allowed = ['system', 'user', 'assistant', 'tool'];
    if (!in_array($msg['role'], $allowed, true)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Ungültige Rolle: ' . htmlspecialchars((string) $msg['role'])]);
        exit;
    }
    if (!array_key_exists('content', $msg) && !($msg['role'] === 'assistant' && isset($msg['tool_calls']))) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Jede Nachricht muss "content" enthalten (oder assistant.tool_calls).']);
        exit;
    }
}

// ── Select endpoint via load balancer ─────────────────────────────────────────

$model = $payload['model'];

// Whether this request attaches at least one image – only vision-capable
// endpoints may be selected for it (see admin endpoint config "Vision-fähig").
$requiresVision = payloadMessagesHaveImage($payload['messages']);

// Extract and validate the optional session ID for conversation persistence.
$sessionId = '';
if (isset($payload['session_id']) && is_string($payload['session_id'])) {
    $rawSessionId = $payload['session_id'];
    if (preg_match('/^[a-f0-9]{8,128}$/', $rawSessionId)) {
        $sessionId = $rawSessionId;
    }
}

// Resolve the currently logged-in user for session ownership.
$sessionUserId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;

// ── Intelligence group prefix (e.g. "@@35b …") ───────────────────────────────
// Logged-in users can address an intelligence group directly. The chosen group
// overrides user defaults, the standard model and rule-based routing and stays
// active for the rest of the chat session.
$intelligenceGroup = null;

if ($sessionUserId !== null && isIntelligenceGroupFeatureEnabled()) {
    $requestedGroup = null;
    if (isset($payload['intelligence_group']) && is_string($payload['intelligence_group'])) {
        $requestedGroup = trim($payload['intelligence_group']);
    }

    // A prefix inside the message text takes precedence over the UI selection.
    foreach ($payload['messages'] as $idx => $msg) {
        if (($msg['role'] ?? '') !== 'user' || !is_string($msg['content'] ?? null)) {
            continue;
        }
        [$token, $stripped] = extractIntelligenceGroupPrefix($msg['content']);
        if ($token === '') {
            continue;
        }
        $payload['messages'][$idx]['content'] = $stripped;
        $requestedGroup = $token;
    }

    if ($requestedGroup !== null && $requestedGroup !== '') {
        $groupModel = resolveIntelligenceGroupModel($requestedGroup);
        if ($groupModel === null) {
            $available = implode(', ', array_keys(listIntelligenceGroups()));
            $errorText = 'Für die Intelligenzgruppe "' . $requestedGroup . '" ist kein Modell verfügbar.'
                . ($available !== '' ? ' Verfügbar: ' . $available . '.' : '');
            writeLog('warning', 'Unbekannte Intelligenzgruppe von Nutzer (' . getClientIp() . ') angefragt: ' . $requestedGroup . '.');
            if (isset($payload['stream']) && $payload['stream'] === true) {
                emitSseData(['error' => $errorText]);
            } else {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $errorText]);
            }
            exit;
        }
        $intelligenceGroup = [
            'label' => normalizeIntelligenceGroupLabel($requestedGroup),
            'model' => $groupModel,
        ];
        setSessionIntelligenceGroup($sessionId, $intelligenceGroup['label'], $groupModel, $sessionUserId);
        writeLog('info', 'Intelligenzgruppe ' . $intelligenceGroup['label'] . ' von Nutzer (' . getClientIp() . ') gewählt (Modell: ' . $groupModel . ').');
    } elseif ($requestedGroup === '') {
        // Explicitly removed in the UI.
        clearSessionIntelligenceGroup($sessionId);
    } else {
        $intelligenceGroup = getSessionIntelligenceGroup($sessionId);
    }

    if ($intelligenceGroup !== null) {
        $model = $intelligenceGroup['model'];
        $payload['model'] = $model;
    }
}

// ── Prompt Security check ─────────────────────────────────────────────────────
// Every chat request is evaluated before reaching the LLM pipeline.
{
    $psLastUserText = '';
    foreach (array_reverse($payload['messages']) as $psMsg) {
        if (($psMsg['role'] ?? '') === 'user') {
            $psLastUserText = is_string($psMsg['content']) ? $psMsg['content'] : normalizeAssistantContent($psMsg['content'] ?? '');
            break;
        }
    }

    if ($psLastUserText !== '') {
        $psUserId    = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $psSessionId = isset($payload['session_id']) && is_string($payload['session_id'])
            ? substr($payload['session_id'], 0, 128) : '';
        $psIp        = getClientIp();

        $psResult = psEvaluate($psLastUserText, $psUserId, $psSessionId, $psIp);

        // Opportunistically purge old security logs (~1 % of requests).
        if (mt_rand(1, 100) === 1) {
            psPurgeLogs();
        }

        if ($psResult['decision'] === 'block') {
            writeLog('warning', 'Prompt-Security blockiert Anfrage von ' . $psIp
                . ' (Score: ' . $psResult['score'] . ').');
            if (isset($payload['stream']) && $payload['stream'] === true) {
                emitSseData(['error' => $psResult['message']]);
            } else {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => $psResult['message']]);
            }
            exit;
        }
    }
}

// ── Rule-based model routing ──────────────────────────────────────────────────
// If a routing decision model is configured, classify the user's prompt and
// replace $model with the category-specific target model before dispatching.

$routingDecisionModel = trim(getSetting('routing_decision_model', ''));
$detectedCategory = '';
if ($routingDecisionModel !== '' && $intelligenceGroup === null) {
    // Extract the last user message text for classification.
    $lastUserText = '';
    foreach (array_reverse($payload['messages']) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $lastUserText = is_string($msg['content']) ? $msg['content'] : normalizeAssistantContent($msg['content'] ?? '');
            break;
        }
    }

    if ($lastUserText !== '') {
        $systemPrompt = buildRoutingPrompt();

        if ($systemPrompt !== '') {
            // Pick a slot for the decision model (non-blocking: skip if unavailable).
            try {
                $routingSlot = pickEndpointForModel($routingDecisionModel);
            } catch (Throwable $e) {
                $routingSlot = null;
            }

            if ($routingSlot !== null) {
                $routingEndpoint = $routingSlot['endpoint'];
                $routingTaskId   = $routingSlot['task_id'];
                $routingBaseUrl  = rtrim($routingEndpoint['base_url'], '/');
                $routingTimeout  = max(10, min(60, (int) $routingEndpoint['timeout']));

                $routingPayload = [
                    'model'       => $routingDecisionModel,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $lastUserText],
                    ],
                    'stream'      => false,
                    'temperature' => 0.0,
                    'max_tokens'  => 20,
                ];

                $rch = curl_init($routingBaseUrl . '/chat/completions');
                curl_setopt_array($rch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($routingPayload),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $routingTimeout,
                ]);
                $routingBody = curl_exec($rch);
                $routingHttpCode = (int) curl_getinfo($rch, CURLINFO_HTTP_CODE);
                $routingCurlErr  = curl_error($rch);
                curl_close($rch);

                completeTask($routingTaskId, ($routingCurlErr !== '' || $routingHttpCode !== 200) ? 'error' : 'done');

                if ($routingCurlErr === '' && $routingHttpCode === 200) {
                    $routingData = json_decode($routingBody, true);
                    $detectedCategory = trim(
                        (string) ($routingData['choices'][0]['message']['content'] ?? '')
                    );
                    // Strip surrounding whitespace, newlines and any markdown artefacts.
                    $detectedCategory = trim($detectedCategory, " \t\n\r\0\x0B`\"'");

                    // Look up the model assigned to this category.
                    $routingRules = loadRoutingRules();
                    if ($detectedCategory !== '' && isset($routingRules[$detectedCategory]) && $routingRules[$detectedCategory] !== '') {
                        $model = $routingRules[$detectedCategory];
                        $payload['model'] = $model;
                    }
                    if ($detectedCategory !== '') {
                        writeLog('info', 'Entscheidungsmodell hat Prompt von Nutzer (' . getClientIp() . ') der Kategorie ' . $detectedCategory . ' zugeordnet.');
                    }
                }
            }
        }
    }
}

$intelligenceUpgrade = null;
if ($intelligenceGroup === null) {
    try {
        $intelligenceUpgrade = getUpgradeModelSuggestionForRequestedModel($model, $detectedCategory);
    } catch (Throwable $e) {
        $intelligenceUpgrade = null;
    }
}

// ── Session-level intelligence upgrade persistence ────────────────────────────
// When the user accepted an intelligence upgrade, remember the upgraded model
// for 20 minutes so that all subsequent requests in the same session are also
// routed to the higher-intelligence endpoint.
$upgradeAccepted = isset($payload['intelligence_upgrade_accepted']) && $payload['intelligence_upgrade_accepted'] === true;

if ($intelligenceGroup !== null) {
    // An explicitly addressed intelligence group wins over any stored upgrade.
    $upgradeAccepted = false;
} elseif ($upgradeAccepted && $sessionId !== '' && $model !== '') {
    // Persist the accepted upgrade model so future requests in this session
    // (within 20 minutes) are automatically routed to it.
    setSessionUpgradeModel($sessionId, $model);
    writeLog('info', 'Intelligence-Upgrade durch Nutzer (' . getClientIp() . ') akzeptiert (Modell: ' . $model . ').');
} elseif (!$upgradeAccepted && $sessionId !== '') {
    // Apply a previously accepted upgrade if it is still within the 20-minute window.
    $activeUpgradeModel = getActiveSessionUpgradeModel($sessionId);
    if ($activeUpgradeModel !== null && $activeUpgradeModel !== $model) {
        $model = $activeUpgradeModel;
        $payload['model'] = $model;
        $intelligenceUpgrade = null; // already on the upgraded model, no further suggestion needed
    }
}

// Release the session write lock immediately. chat.php can run for many seconds
// (waiting for the LLM response) and holding the lock blocks every other same-session
// request – most critically the admin/load_stats.php polling endpoint.
session_write_close();

// Occasionally purge expired conversation sessions (5 % probability).
if (mt_rand(1, 20) === 1) {
    purgeExpiredConversationSessions();
}

// When the user-selected model is also the routing decision model, reserve one
// slot per endpoint exclusively for routing decisions so they are never delayed.
$balancerMaxConcurrentForChat = getBalancerMaxConcurrent();
$userSlotMax = ($routingDecisionModel !== '' && $model === $routingDecisionModel)
    ? max(1, $balancerMaxConcurrentForChat - 1)
    : $balancerMaxConcurrentForChat;

try {
    $slot = pickEndpointForModel($model, $userSlotMax, false, $requiresVision);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
    exit;
}

$noVisionEndpointMessage = 'Kein Vision-fähiger Endpunkt für dieses Modell verfügbar. '
    . 'Bitte ein Vision-fähiges Modell wählen oder im Admin-Bereich einen Endpunkt als "Vision-fähig" markieren.';

if ($slot === null) {
    try {
        $hasMatchingEndpoint = hasActiveEndpointForModel($model, $requiresVision);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
        exit;
    }

    if (!$hasMatchingEndpoint) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $requiresVision ? $noVisionEndpointMessage : 'Kein passender Endpunkt verfügbar.']);
        exit;
    }

    ignore_user_abort(true);
    @set_time_limit(0);

    if (isset($payload['stream']) && $payload['stream'] === true && !isOpenAiStrictMode()) {
        emitSseData([
            'status' => 'queued',
            'message' => 'Alle LLM-Ressourcen sind derzeit belegt. Die Bearbeitung beginnt automatisch, sobald ein Slot frei wird.',
        ]);
    }

    while ($slot === null) {
        if (connection_aborted()) {
            writeLog('warning', 'Anfrage durch User abgebrochen.');
            exit;
        }

        usleep(500000);

        try {
            if (!hasActiveEndpointForModel($model, $requiresVision)) {
                $noEndpointMessage = $requiresVision ? $noVisionEndpointMessage : 'Kein passender Endpunkt mehr verfügbar.';
                if (isset($payload['stream']) && $payload['stream'] === true) {
                    emitSseData(['error' => $noEndpointMessage]);
                } else {
                    http_response_code(503);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => $noEndpointMessage]);
                }
                writeLog('warning', 'Für Modell ' . $model . ' ist kein aktiver Modellendpunkt mehr verfügbar.');
                exit;
            }

            $slot = pickEndpointForModel($model, $userSlotMax, false, $requiresVision);
        } catch (Throwable $e) {
            if (isset($payload['stream']) && $payload['stream'] === true) {
                emitSseData(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
            } else {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Interner Fehler beim Endpunkt-Routing.']);
            }
            exit;
        }
    }
}

$endpoint = $slot['endpoint'];
$taskId   = $slot['task_id'];
$baseUrl  = rtrim($endpoint['base_url'], '/');
$timeout  = max(1, (int) $endpoint['timeout']);
$responseDetails = buildResponseDetails($endpoint);
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));
$requestStart = microtime(true);
writeLog('info', 'Modell ' . $model . ' für die Bearbeitung des Prompts ausgewählt.');
writeLog('info', 'Antwortgenerierung gestartet.');

// ── Endpoint overload warning ─────────────────────────────────────────────────
// Log a warning when every active endpoint for this model has more than two
// running sessions, hinting that additional endpoints should be provided.
try {
    $overloadModelPool = equivalentActiveModelNames($model);
    $overloadPlaceholders = implode(',', array_fill(0, count($overloadModelPool), '?'));
    $overloadRows = getDb()->prepare("
        SELECT e.id, e.alias, e.default_model,
               COALESCE(r.running_count, 0) AS running_count
          FROM endpoints e
          LEFT JOIN (
              SELECT endpoint_id, COUNT(*) AS running_count
                FROM tasks
               WHERE status = 'running'
               GROUP BY endpoint_id
          ) r ON r.endpoint_id = e.id
         WHERE e.is_active = 1
           AND e.default_model IN ({$overloadPlaceholders})
    ");
    $overloadRows->execute($overloadModelPool);
    $epRows = $overloadRows->fetchAll();
    if (!empty($epRows)) {
        $allBusy = true;
        foreach ($epRows as $epRow) {
            if ((int) $epRow['running_count'] <= 2) {
                $allBusy = false;
                break;
            }
        }
        if ($allBusy) {
            $intelligenceLabel = $model;
            $intelligenceScore = modelIntelligenceScore($model);
            if ($intelligenceScore !== null) {
                $intelligenceLabel = (abs($intelligenceScore - round($intelligenceScore)) < 0.00001)
                    ? ((string) ((int) round($intelligenceScore))) . 'B'
                    : rtrim(rtrim(number_format($intelligenceScore, 2, '.', ''), '0'), '.') . 'B';
            }
            writeLog('warning', 'Die Endpunkte mit der Intelligenz ' . $intelligenceLabel . ' sind stark ausgelastet (mehr als zwei laufende Sessions je Endpunkt). Bitte erwägen Sie, mehr Endpunkte zur Verfügung zu stellen, um Verzögerungen zu vermeiden.');
        }
    }
} catch (Throwable $_owEx) { /* best-effort */ }

// Ensure the task is always marked finished, even on unexpected PHP termination.
$taskFinished = false;
register_shutdown_function(static function () use (&$taskId, &$taskFinished): void {
    if (!$taskFinished) {
        try {
            completeTask($taskId, 'error');
        } catch (Throwable $e) {
            // Best-effort – nothing more we can do in a shutdown handler.
        }
    }
});

// Maximum number of additional endpoints to try when the primary one fails.
$endpointRetries = 2;
$upgradeFailoverTried = false;
$forwardPayload = [];

/**
 * Marks the current task as failed, picks a new endpoint for $model,
 * and updates the shared $endpoint / $taskId / $baseUrl / $timeout / $url
 * variables in the outer scope. Returns false when no further endpoint
 * is available or the retry budget is exhausted.
 */
$switchEndpoint = function (bool $allowUpgradeFallback = false) use (
    &$model, $userSlotMax, $requiresVision,
    &$endpoint, &$taskId, &$baseUrl, &$timeout, &$url, &$endpointRetries, &$responseDetails,
    &$upgradeFailoverTried, &$intelligenceUpgrade, &$forwardPayload, &$payload, $detectedCategory
): bool {
    if ($endpointRetries <= 0) {
        return false;
    }
    $attemptNumber = 3 - $endpointRetries; // 1-based retry attempt count
    $endpointRetries--;
    try {
        completeTask($taskId, 'error');
    } catch (Throwable $e) {}

    // Exponential backoff with jitter before hitting another endpoint, so a
    // transient blip (e.g. all endpoints briefly overloaded) doesn't cause an
    // immediate retry storm.
    try {
        backoffSleep($attemptNumber);
    } catch (Throwable $e) {}

    try {
        $newSlot = pickEndpointForModel($model, $userSlotMax, false, $requiresVision);
    } catch (Throwable $e) {
        return false;
    }

    // Try the configured fallback chain for the current model before falling
    // back to the generic "bigger model" upgrade heuristic.
    if ($newSlot === null) {
        try {
            foreach (getFallbackChain($model) as $fallbackModel) {
                $newSlot = pickEndpointForModel($fallbackModel, $userSlotMax, false, $requiresVision);
                if ($newSlot !== null) {
                    $model = $fallbackModel;
                    $payload['model'] = $fallbackModel;
                    $forwardPayload['model'] = $fallbackModel;
                    $intelligenceUpgrade = null;
                    writeLog('info', 'Fallback-Kette: Modell ' . $model . ' für die Bearbeitung des Prompts ausgewählt.');
                    break;
                }
            }
        } catch (Throwable $e) {
            $newSlot = null;
        }
    }

    if ($newSlot === null && $allowUpgradeFallback && !$upgradeFailoverTried) {
        $upgradeFailoverTried = true;
        try {
            $upgrade = getUpgradeModelSuggestionForRequestedModel($model, $detectedCategory);
            if (is_array($upgrade) && !empty($upgrade['model'])) {
                $upgradeModel = (string) $upgrade['model'];
                $newSlot = pickEndpointForModel($upgradeModel, null, false, $requiresVision);
                if ($newSlot !== null) {
                    $model = $upgradeModel;
                    $payload['model'] = $upgradeModel;
                    $forwardPayload['model'] = $upgradeModel;
                    $intelligenceUpgrade = null;
                    writeLog('info', 'Modell ' . $model . ' für die Bearbeitung des Prompts ausgewählt.');
                }
            }
        } catch (Throwable $e) {
            $newSlot = null;
        }
    }

    if ($newSlot === null) {
        return false;
    }
    $endpoint = $newSlot['endpoint'];
    $taskId   = $newSlot['task_id'];
    $baseUrl  = rtrim($endpoint['base_url'], '/');
    $timeout  = max(1, (int) $endpoint['timeout']);
    $responseDetails = buildResponseDetails($endpoint);
    $url      = $baseUrl . '/chat/completions';
    // The endpoint just picked may be an equivalence-pool match for $model
    // rather than an exact `default_model` match (see
    // equivalentActiveModelNames() in db.php) – always forward the label the
    // endpoint itself expects, not the originally requested $model.
    $forwardPayload['model'] = $endpoint['default_model'] !== '' ? $endpoint['default_model'] : $model;
    // Keep the reasoning effort and the llama.cpp payload adjustments in sync
    // with the new endpoint.
    $newReasoningEffort = normalizeReasoningEffort($endpoint['reasoning_effort'] ?? null);
    if ($newReasoningEffort !== null) {
        $forwardPayload['reasoning_effort'] = $newReasoningEffort;
    } else {
        unset($forwardPayload['reasoning_effort']);
    }
    if (!empty($endpoint['is_llamacpp'])) {
        if (array_key_exists('stop', $forwardPayload) && $forwardPayload['stop'] === null) {
            unset($forwardPayload['stop']);
        }
        if (isset($forwardPayload['max_tokens']) && (int) $forwardPayload['max_tokens'] === -1) {
            unset($forwardPayload['max_tokens']);
        }
    }
    return true;
};

$clientRequestedStream = isset($payload['stream']) && $payload['stream'] === true;
$openAiToolMode = (string) ($GLOBALS['LLMINT_OPENAI_TOOL_MODE'] ?? 'auto');
$endpointSupportsToolCalling = (bool) ($endpoint['supports_tool_calling'] ?? true);
if ($openAiToolMode === 'enabled') {
    $endpointSupportsToolCalling = true;
}
// Bare-metal llama.cpp servers (started without --jinja) reject any request
// containing "tools"/"tool_choice" with "tools param requires --jinja flag",
// and their static chat templates (e.g. gemma) cannot render tool-role
// messages – the corrupted prompt then leaks template fragments into the
// answer. Direct llama.cpp endpoints therefore never take part in tool calling.
if (!empty($endpoint['is_llamacpp'])) {
    $endpointSupportsToolCalling = false;
}
$useSearchTool   = $searxngBaseUrl !== '' && $endpointSupportsToolCalling;
$useSdTool       = hasSdEndpoints() && $endpointSupportsToolCalling;
$useComfyTool    = hasComfyEndpoints() && $endpointSupportsToolCalling;
$useDocQueryTool = hasDocumentUploads($sessionUserId) && $endpointSupportsToolCalling;
$useTools        = $useSearchTool || $useSdTool || $useComfyTool || $useDocQueryTool;
if ($openAiToolMode === 'disabled') {
    $useSearchTool = false;
    $useSdTool = false;
    $useComfyTool = false;
    $useDocQueryTool = false;
    $useTools = false;
}
$stream = $clientRequestedStream && !$useTools;

// Prepend the application-wide system prompt (configured in the admin area)
// to a dedicated copy of the conversation used only for the upstream LLM
// call. $payload['messages'] itself stays untouched so it can still be
// persisted via saveConversationSession() and returned to the client without
// ever exposing this prompt to the user.
$llmMessages = $payload['messages'];
$globalSystemPrompt = getGlobalSystemPrompt();
if ($globalSystemPrompt !== '') {
    array_unshift($llmMessages, ['role' => 'system', 'content' => $globalSystemPrompt]);
}
// Always tell the upstream model the current date and time. This notice is
// hard-coded and therefore stays in place regardless of how the configurable
// global system prompt above is edited; it is prepended so it appears at the
// very beginning of the system instructions.
array_unshift($llmMessages, ['role' => 'system', 'content' => buildCurrentDateTimeSystemPrompt()]);
// Strict chat templates (llama.cpp with --jinja) only accept one system
// message and only as the very first entry, so all system instructions –
// including any the client sent – are merged into a single leading message.
$llmMessages = mergeSystemMessages($llmMessages);

// Forward only the fields LM Studio expects.
//
// The "model" field sent upstream is the endpoint's own default_model, not
// the originally requested $model: the balancer pools endpoints that serve
// the same model under different labels (see equivalentActiveModelNames()
// in db.php), so the endpoint actually picked may differ in path/extension/
// quantisation from $model even though it is functionally equivalent.
// OpenAI-compatible backends that validate the "model" field (vLLM, Ollama,
// LM Studio) require the label they themselves expose.
$forwardPayload = [
    'model'       => $endpoint['default_model'] !== '' ? $endpoint['default_model'] : $model,
    'messages'    => $llmMessages,
    'stream'      => $stream,
    'temperature' => $payload['temperature'] ?? 0.7,
    'max_tokens'  => $payload['max_tokens']  ?? -1,
    'top_p'       => $payload['top_p'] ?? 1.0,
    'stop'        => $payload['stop'] ?? null,
];

// Ask the upstream to include token usage in the final SSE chunk when
// streaming, so prompt/completion/total token counts are available for the
// context-usage indicators even on the raw passthrough streaming path.
if ($stream) {
    $forwardPayload['stream_options'] = ['include_usage' => true];
}

// The reasoning effort configured for the endpoint (low/medium/high) is sent
// to every endpoint type – not just direct llama.cpp instances – so that
// OpenAI-compatible backends (LM Studio, vLLM, Ollama, …) can honour it as
// well. Endpoints configured with "none" get no "reasoning_effort" field at
// all, which is the right choice for backends that reject unknown fields.
$endpointReasoningEffort = normalizeReasoningEffort($endpoint['reasoning_effort'] ?? null);
if ($endpointReasoningEffort !== null) {
    $forwardPayload['reasoning_effort'] = $endpointReasoningEffort;
}

// Direct llama.cpp instances additionally need a payload without
// LM-Studio-specific placeholder values: llama.cpp expects "stop" to be absent
// instead of null and "max_tokens" to be absent instead of the LM Studio
// convention -1.
if (!empty($endpoint['is_llamacpp'])) {
    if ($forwardPayload['stop'] === null) {
        unset($forwardPayload['stop']);
    }
    if ((int) $forwardPayload['max_tokens'] === -1) {
        unset($forwardPayload['max_tokens']);
    }
}

$url = $baseUrl . '/chat/completions';

// ── Kontextlimit-Vorabprüfung ──────────────────────────────────────────────
// Bevor die Anfrage überhaupt an das Modell geschickt wird: eine grobe
// Token-Schätzung des ausgehenden Prompts gegen die konfigurierten Limits
// (Endpunkt-Kontext bzw. Kontextlimit je Userslot) prüfen. So bricht die
// Antwort im Chat nicht einfach kommentarlos ab, sondern der User bekommt
// sofort eine saubere Fehlermeldung statt eines (evtl. abgeschnittenen)
// Modellaufrufs, der ohnehin scheitern würde.
$contextLimits = resolveContextLimits($endpoint);
if ($contextLimits['effective'] > 0) {
    $estimatedPromptTokens = estimateTokenCount($llmMessages);
    if ($estimatedPromptTokens >= $contextLimits['effective']) {
        $limitLabel = ($contextLimits['slot_limit'] > 0 && $contextLimits['slot_limit'] <= $contextLimits['endpoint_max'])
            || $contextLimits['endpoint_max'] === 0
            ? 'Session-Kontext' : 'Endpunkt-Kontext';
        $contextErrorMsg = 'Kontextlimit erreicht (' . $limitLabel . ': ca. ' . $estimatedPromptTokens .
            ' von ' . $contextLimits['effective'] . ' Token). Bitte kürzen Sie die Unterhaltung oder starten Sie einen neuen Chat.';
        writeLog('warning', 'Anfrage abgelehnt: ' . $contextErrorMsg);
        $taskFinished = true;
        completeTask($taskId, 'error');
        if ($clientRequestedStream) {
            emitSseData(['error' => $contextErrorMsg]);
        } else {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $contextErrorMsg]);
        }
        exit;
    }
}

if ($useTools) {
    $messages = $llmMessages;
    $usage = ['prompt' => 0, 'completion' => 0, 'total' => 0];
    $finalData = null;
    $searchQueryUsed = '';
    // {url => ['title' => ..., 'url' => ...]} of every source seen so far in
    // this response, across all search_web calls, deduplicated by URL and
    // kept for the "used sources" pills as well as the persisted history.
    $searchSources = [];

    // When an intelligence upgrade re-runs a query that already used search_web,
    // the caller may supply the original search query so we can pre-fetch fresh
    // results and inject them into the context before the LLM loop starts.
    $forceSearchQuery = '';
    if ($useSearchTool && isset($payload['force_search_query']) && is_string($payload['force_search_query'])) {
        $forceSearchQuery = trim($payload['force_search_query']);
    }
    if ($forceSearchQuery !== '') {
        $searchLogId = startSearchLog(substr($forceSearchQuery, 0, 400));
        $searchStartedAt = microtime(true);
        try {
            $searchResult = runSearxngSearch($searxngBaseUrl, substr($forceSearchQuery, 0, 400), min($timeout, 15));
            foreach (extractSearchSources($searchResult) as $source) {
                $searchSources[$source['url']] = $source;
            }
            completeSearchLog($searchLogId, 'done', $searchResult['results'] ?? []);
            $searchQueryUsed = $forceSearchQuery;
            $searchElapsedMs = elapsedMilliseconds($searchStartedAt);
            writeLog('info', 'Websuche erfolgreich abgeschlossen.');
            writeLog('info', 'Websuche erhöhte Bearbeitungszeit um ' . $searchElapsedMs . ' ms.');
        } catch (Throwable $e) {
            completeSearchLog($searchLogId, 'error');
            $searchResult = ['error' => $e->getMessage()];
        }
        // Inject the pre-fetched search results as plain text into the last user
        // message instead of a synthetic assistant tool_call + tool-role message
        // pair. Endpoints whose chat template has no tool roles (e.g. pure
        // llama.cpp with Gemma) cannot render such messages and would build a
        // corrupted prompt, causing the raw template text to leak into the answer.
        $searchContext = "\n\n[Aktuelle Websuchergebnisse zur Anfrage \"" . $forceSearchQuery . "\":\n"
            . json_encode($searchResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\nNutze diese Ergebnisse zur Beantwortung, ohne sie wörtlich wiederzugeben.]";
        for ($mi = count($messages) - 1; $mi >= 0; $mi--) {
            if (($messages[$mi]['role'] ?? '') === 'user' && is_string($messages[$mi]['content'] ?? null)) {
                $messages[$mi]['content'] .= $searchContext;
                break;
            }
        }
    }

    // Build the tool list from active integrations.
    $tools = [];
    if ($useSearchTool) {
        $tools = array_merge($tools, createSearchToolDefinition());
        $tools = array_merge($tools, createWebFetchToolDefinition());
    }
    if ($useSdTool) {
        $tools = array_merge($tools, createImageGenerationToolDefinition());
    }
    if ($useComfyTool) {
        $tools = array_merge($tools, createComfyToolDefinition());
    }
    if ($useDocQueryTool) {
        $tools = array_merge($tools, createDocumentQueryToolDefinition());
    }

    // Tool calling used to force a non-streaming upstream request, which meant the
    // client only ever saw a blinking cursor until the complete answer had been
    // generated. The upstream request is now streamed as well: content is pushed
    // to the client while it is produced, tool call deltas are collected instead.
    $toolStreamLive        = $clientRequestedStream;
    $liveStreamed          = false;
    $toolFirstTokenLogged  = false;
    $toolFirstTokenElapsedMs = null;
    $toolStreamStartLogged = false;
    $toolClientAborted     = false;
    if ($toolStreamLive) {
        ignore_user_abort(true);
        @set_time_limit(0);
    }

    // Budget for the tool dialog: enough iterations so a search_web call can be
    // followed by several web_fetch calls before the final answer is composed.
    for ($iteration = 0; $iteration < 8; $iteration++) {
        $toolPayload = $forwardPayload;
        $toolPayload['messages'] = $messages;
        $toolPayload['stream'] = false;
        // search_web is never forced: the model decides on its own, per iteration,
        // whether it lacks current information and needs to call the tool. This
        // avoids an unconditional web search on every prompt.
        // A failover can move the request onto a direct llama.cpp endpoint, which
        // rejects "tools"/"tool_choice" without --jinja – never send them there.
        if (empty($endpoint['is_llamacpp'])) {
            $toolPayload['tools'] = $tools;
            $toolPayload['tool_choice'] = 'auto';
        }
        writeLog('info', 'Prompt an Modell ' . $model . ' weitergeleitet.');

        if ($toolStreamLive) {
            $streamResult = streamChatCompletionRequest(
                $url,
                $toolPayload,
                $timeout,
                true,
                $requestStart,
                $toolFirstTokenLogged,
                $toolStreamStartLogged,
                $toolClientAborted,
                $toolFirstTokenElapsedMs
            );
            if ($streamResult['forwarded']) {
                $liveStreamed = true;
            }
            $body     = $streamResult['body'];
            $httpCode = $streamResult['http_code'];
            $curlErr  = $streamResult['error'];
            $data     = $streamResult['data'];
            if ($toolClientAborted) {
                $taskFinished = true;
                completeTask($taskId, 'error');
                exit;
            }
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($toolPayload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            $data = null;
        }

        if (!$clientRequestedStream && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if ($curlErr !== '') {
            writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
            if (!$liveStreamed && $switchEndpoint(true)) {
                $url = $baseUrl . '/chat/completions';
                $iteration--;  // redo this iteration with the new endpoint
                continue;
            }
            $taskFinished = true;
            completeTask($taskId, 'error');
            if ($clientRequestedStream && headers_sent()) {
                emitSseData(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
            } else {
                http_response_code(502);
                echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
            }
            exit;
        }

        if ($data === null) {
            $data = json_decode((string) $body, true);
        }
        if ($httpCode !== 200 || !is_array($data)) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
            // Only 5xx / server-side failures warrant an endpoint failover: retrying an
            // identical payload against another endpoint after a 4xx (client/payload)
            // rejection just repeats the same malformed request and wastes the retry budget.
            if ($httpCode >= 500 && !$liveStreamed) {
                writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
                if ($switchEndpoint()) {
                    $url = $baseUrl . '/chat/completions';
                    $iteration--;  // redo this iteration with the new endpoint
                    continue;
                }
            }
            $taskFinished = true;
            completeTask($taskId, 'error');
            if ($clientRequestedStream && headers_sent()) {
                emitSseData(['error' => $msg]);
            } else {
                http_response_code(502);
                echo json_encode(['error' => $msg]);
            }
            exit;
        }

        $stepUsage = extractUsage($data);
        $usage['prompt'] += $stepUsage['prompt'];
        $usage['completion'] += $stepUsage['completion'];
        $usage['total'] += $stepUsage['total'];

        $message = $data['choices'][0]['message'] ?? null;
        $toolCalls = is_array($message) ? ($message['tool_calls'] ?? []) : [];
        if (!is_array($toolCalls) || $toolCalls === []) {
            $finalData = $data;
            break;
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $toolCalls,
        ];

        foreach ($toolCalls as $toolCall) {
            $toolResult = ['error' => 'Unbekannter Tool-Aufruf.'];
            $toolName   = $toolCall['function']['name'] ?? '';
            logToolInvoked($toolName);

            if ($toolName === 'search_web' && $useSearchTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $query = trim((string) ($args['query'] ?? ''));

                if ($query === '') {
                    $toolResult = ['error' => 'Leere Suchanfrage.'];
                } else {
                    writeLog('info', 'Websuche durch Modell ' . $model . ' auf Prompt von Nutzer (' . getClientIp() . ') gestartet (Anfrage: ' . mb_substr($query, 0, 200, 'UTF-8') . ').');
                    $searchLogId = startSearchLog(substr($query, 0, 400));
                    $searchStartedAt = microtime(true);
                    try {
                        $toolResult = runSearxngSearch($searxngBaseUrl, substr($query, 0, 400), min($timeout, 15));
                        foreach (extractSearchSources($toolResult) as $source) {
                            $searchSources[$source['url']] = $source;
                        }
                        completeSearchLog($searchLogId, 'done', $toolResult['results'] ?? []);
                        $searchQueryUsed = $query;
                        $searchElapsedMs = elapsedMilliseconds($searchStartedAt);
                        writeLog('info', 'Websuche erfolgreich abgeschlossen.');
                        writeLog('info', 'Websuche erhöhte Bearbeitungszeit um ' . $searchElapsedMs . ' ms.');
                    } catch (Throwable $e) {
                        completeSearchLog($searchLogId, 'error');
                        $toolResult = ['error' => $e->getMessage()];
                    }
                }
            } elseif ($toolName === 'web_fetch' && $useSearchTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                $fetchUrl = trim((string) ($args['url'] ?? ''));
                $maxChars = isset($args['max_chars']) ? (int) $args['max_chars'] : 6000;
                $maxChars = max(500, min(20000, $maxChars));

                if ($fetchUrl === '') {
                    $toolResult = ['error' => 'Leere URL.'];
                } else {
                    writeLog('info', 'Seitenabruf durch Modell ' . $model . ' gestartet (URL: ' . mb_substr($fetchUrl, 0, 200, 'UTF-8') . ').');
                    $fetchStartedAt = microtime(true);
                    try {
                        $toolResult = fetchWebPage($fetchUrl, min($timeout, 15), $maxChars);
                        $sourceUrl = (string) $toolResult['url'];
                        $sourceTitle = trim((string) $toolResult['title']);
                        $searchSources[$sourceUrl] = [
                            'title' => $sourceTitle !== '' ? $sourceTitle : $sourceUrl,
                            'url' => $sourceUrl,
                        ];
                        writeLog('info', 'Seitenabruf erfolgreich abgeschlossen.');
                        writeLog('info', 'Seitenabruf erhöhte Bearbeitungszeit um ' . elapsedMilliseconds($fetchStartedAt) . ' ms.');
                    } catch (Throwable $e) {
                        $toolResult = ['error' => $e->getMessage()];
                    }
                }
            } elseif ($toolName === 'generate_image' && $useSdTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                $toolResult = callSdGenerate($args, $timeout);
                // Include a markdown image in the result so the LLM can reference it.
                if (isset($toolResult['image_url'])) {
                    $toolResult['markdown'] = '![Generiertes Bild](' . $toolResult['image_url'] . ')';
                }
            } elseif ($toolName === 'generate_image_comfy' && $useComfyTool) {
                $args = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                $toolResult = callComfyGenerate($args, $timeout);
                if (isset($toolResult['image_url'])) {
                    $toolResult['markdown'] = '![Generiertes Bild (ComfyUI)](' . $toolResult['image_url'] . ')';
                }
            } elseif ($toolName === 'query_documents' && $useDocQueryTool) {
                $args  = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
                $query = trim((string) ($args['query'] ?? ''));
                $toolResult = queryDocuments($query, $sessionUserId);
            }

            logToolResult($toolName, $toolResult);

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    if ($finalData === null) {
        $taskFinished = true;
        completeTask($taskId, 'error');
        if ($clientRequestedStream && headers_sent()) {
            emitSseData(['error' => 'Der Tool-Dialog mit dem Modell konnte nicht abgeschlossen werden.']);
        } else {
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Der Tool-Dialog mit dem Modell konnte nicht abgeschlossen werden.']);
        }
        exit;
    }

    $finalData['usage'] = [
        'prompt_tokens' => $usage['prompt'],
        'completion_tokens' => $usage['completion'],
        'total_tokens' => $usage['total'],
    ];
    if (!isOpenAiStrictMode() && $intelligenceUpgrade !== null) {
        $finalData['intelligence_upgrade'] = buildIntelligenceUpgradePayload($intelligenceUpgrade);
    }
    if (!isOpenAiStrictMode()) {
        $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
        if ($searchQueryUsed !== '') {
            $responseDetails['search_query'] = $searchQueryUsed;
        }
        if ($searchSources !== []) {
            // Cap to a reasonable number of pills; SearXNG already limits each
            // individual query to 5 results, this only matters when the model
            // called search_web more than once.
            $responseDetails['search_sources'] = array_slice(array_values($searchSources), 0, 8);
        }
        addContextUsageToResponseDetails($responseDetails, $endpoint, $usage['total'], $finalData['choices'][0]['finish_reason'] ?? null);
        $finalData['response_details'] = $responseDetails;
    }

    $taskFinished = true;
    $toolTokensPerSecond = computeTokensPerSecond($requestStart, $toolFirstTokenElapsedMs, $usage['completion']);
    completeTask($taskId, 'done', $usage['prompt'], $usage['completion'], $usage['total'], (microtime(true) - $requestStart) * 1000, $toolTokensPerSecond);
    logResponseFinished($requestStart, $usage['prompt'], $usage['completion'], $toolTokensPerSecond);

    // Persist the conversation so it survives future endpoint failures.
    if ($sessionId !== '') {
        $assistantContent = normalizeAssistantContent(
            $finalData['choices'][0]['message']['content'] ?? ''
        );
        $assistantMessage = ['role' => 'assistant', 'content' => $assistantContent];
        // Persist the used sources on the message itself so they reappear as
        // pills the next time the user opens this chat, without needing a
        // separate lookup at load time.
        if (isset($responseDetails['search_sources']) && $responseDetails['search_sources'] !== []) {
            $assistantMessage['sources'] = $responseDetails['search_sources'];
        }
        $sessionMessages = array_merge(
            $payload['messages'],
            [$assistantMessage]
        );
        saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
    }

    if ($clientRequestedStream) {
        emitSyntheticStream($finalData, $intelligenceUpgrade, $responseDetails, $liveStreamed);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
]);

// ── Streaming path ────────────────────────────────────────────────────────────

if ($stream) {
    ignore_user_abort(true);
    @set_time_limit(0);

    // Attempt the stream; retry with a different endpoint if it fails before
    // any data has been written to the client.
    $dataWritten      = false;
    $tailBuffer       = '';
    $lineBuffer       = '';  // holds incomplete SSE line across chunk boundaries
    $accumulatedText  = '';  // for session persistence
    $streamCurlErr    = '';
    $streamHttpCode   = 0;
    $firstTokenLogged = false;
    $firstTokenElapsedMs = null;
    $streamStartedLogged = false;
    $clientAborted = false;

    do {
        $tailBuffer      = '';
        $lineBuffer      = '';
        $accumulatedText = '';
        $dataWritten     = false;
        $firstTokenLogged = false;
        $firstTokenElapsedMs = null;
        $streamStartedLogged = false;
        $clientAborted = false;

        $chStream = curl_init($url);
        curl_setopt_array($chStream, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => 0,
        ]);
        writeLog('info', 'Prompt an Modell ' . $model . ' weitergeleitet.');

        curl_setopt($chStream, CURLOPT_WRITEFUNCTION,
            static function ($ch, $data) use (&$tailBuffer, &$lineBuffer, &$accumulatedText, &$dataWritten, &$firstTokenLogged, &$firstTokenElapsedMs, &$streamStartedLogged, &$clientAborted, $requestStart): int {
                if (!$dataWritten) {
                    // Emit SSE headers on first data chunk (deferred so we can
                    // still switch the endpoint if the connection was refused).
                    ensureSseHeaders();
                    $dataWritten = true;
                    if (!$streamStartedLogged) {
                        writeLog('info', 'Streaming der Antwort an User gestartet.');
                        $streamStartedLogged = true;
                    }
                }
                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                if (connection_aborted()) {
                    if (!$clientAborted) {
                        writeLog('warning', 'Anfrage durch User abgebrochen.');
                        $clientAborted = true;
                    }
                    return 0;
                }
                $tailBuffer .= $data;
                if (strlen($tailBuffer) > 8192) {
                    $tailBuffer = substr($tailBuffer, -8192);
                }
                // Accumulate assistant content from delta events. Network chunks can
                // split an SSE "data: {...}" line in the middle, so line boundaries
                // must be tracked across calls rather than regex-matching each raw
                // chunk in isolation (which silently drops split events).
                $lineBuffer .= $data;
                $lines = explode("\n", $lineBuffer);
                $lineBuffer = array_pop($lines);
                foreach ($lines as $line) {
                    $line = rtrim($line, "\r");
                    if (!preg_match('/^data:\s?(.*)$/', $line, $dm)) {
                        continue;
                    }
                    $djson = trim($dm[1]);
                    if ($djson === '' || $djson === '[DONE]') {
                        continue;
                    }
                    $dobj  = json_decode($djson, true);
                    $delta = is_array($dobj) ? ($dobj['choices'][0]['delta'] ?? null) : null;
                    if (!is_array($delta)) {
                        continue;
                    }
                    // Reasoning ("thinking") tokens are decoded before the visible
                    // answer content and often make up the bulk of the generation
                    // time. The first-token timer must start on whichever kind of
                    // delta arrives first, otherwise the tokens/sec metric is
                    // computed over only the final answer segment and comes out
                    // wildly inflated.
                    $reasoningDelta = isset($delta['reasoning_content']) ? (string) $delta['reasoning_content'] : '';
                    $deltaContent   = isset($delta['content']) ? (string) $delta['content'] : '';
                    if (!$firstTokenLogged && ($reasoningDelta !== '' || $deltaContent !== '')) {
                        $firstTokenElapsedMs = (microtime(true) - $requestStart) * 1000;
                        writeLog('info', 'Erste Antworttokens nach ' . elapsedMilliseconds($requestStart) . ' ms erzeugt.');
                        $firstTokenLogged = true;
                    }
                    if ($deltaContent !== '') {
                        $accumulatedText .= $deltaContent;
                    }
                }
                return strlen($data);
            }
        );

        curl_exec($chStream);
        $streamCurlErr  = curl_error($chStream);
        $streamHttpCode = (int) curl_getinfo($chStream, CURLINFO_HTTP_CODE);
        curl_close($chStream);

        // Process any trailing line left in the buffer (stream ended without a
        // final newline after the last "data: ..." event).
        if ($lineBuffer !== '') {
            $line = rtrim($lineBuffer, "\r");
            if (preg_match('/^data:\s?(.*)$/', $line, $dm)) {
                $djson = trim($dm[1]);
                if ($djson !== '' && $djson !== '[DONE]') {
                    $dobj = json_decode($djson, true);
                    if (is_array($dobj) && isset($dobj['choices'][0]['delta']['content'])) {
                        $accumulatedText .= (string) $dobj['choices'][0]['delta']['content'];
                    }
                }
            }
            $lineBuffer = '';
        }

        // Retry with a different endpoint only if the failure occurred before we sent
        // anything to the client (headers not yet committed) AND it looks like a
        // transient/server-side failure (connection error or 5xx). A 4xx means the
        // request itself was rejected, so retrying the identical payload elsewhere
        // would just repeat the same error and waste the retry budget.
        if (($streamCurlErr !== '' || ($streamHttpCode !== 0 && $streamHttpCode !== 200)) && !$dataWritten) {
            if ($streamCurlErr !== '' || $streamHttpCode >= 500) {
                writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
                if ($switchEndpoint(true)) {
                    $forwardPayload['stream'] = true;
                    continue;
                }
            }
        }
        break;
    } while (true);

    // Extract token usage from the tail of the SSE stream.
    $promptTokens     = null;
    $completionTokens = null;
    $totalTokens      = null;
    $streamFinishReason = null;
    if (preg_match_all('/^data:\s*(\{.+\})$/m', $tailBuffer, $matches)) {
        foreach (array_reverse($matches[1]) as $rawEvt) {
            $obj = json_decode($rawEvt, true);
            if (!is_array($obj)) {
                continue;
            }
            if ($streamFinishReason === null && isset($obj['choices'][0]['finish_reason']) && $obj['choices'][0]['finish_reason'] !== null) {
                $streamFinishReason = (string) $obj['choices'][0]['finish_reason'];
            }
            if ($totalTokens === null && isset($obj['usage']['total_tokens'])) {
                $promptTokens     = isset($obj['usage']['prompt_tokens'])     ? (int) $obj['usage']['prompt_tokens']     : null;
                $completionTokens = isset($obj['usage']['completion_tokens']) ? (int) $obj['usage']['completion_tokens'] : null;
                $totalTokens      = (int) $obj['usage']['total_tokens'];
            }
            if ($totalTokens !== null && $streamFinishReason !== null) {
                break;
            }
        }
    }

    $taskFinished = true;
    $streamStatus = ($streamCurlErr === '' && ($streamHttpCode === 0 || $streamHttpCode === 200))
        ? 'done' : 'error';
    $streamTokensPerSecond = $streamStatus === 'done'
        ? computeTokensPerSecond($requestStart, $firstTokenElapsedMs, $completionTokens)
        : null;
    completeTask($taskId, $streamStatus, $promptTokens, $completionTokens, $totalTokens, (microtime(true) - $requestStart) * 1000, $streamTokensPerSecond);
    if ($streamStatus === 'done') {
        logResponseFinished($requestStart, $promptTokens, $completionTokens, $streamTokensPerSecond);
    }

    // Persist conversation on success.
    if ($streamStatus === 'done' && $sessionId !== '' && $accumulatedText !== '') {
        $sessionMessages = array_merge(
            $payload['messages'],
            [['role' => 'assistant', 'content' => $accumulatedText]]
        );
        saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
    }
    if ($streamStatus === 'done' && ($dataWritten || headers_sent())) {
        if (!isOpenAiStrictMode()) {
            $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
            if ($totalTokens !== null) {
                addContextUsageToResponseDetails($responseDetails, $endpoint, $totalTokens, $streamFinishReason);
            }
            emitIntelligenceUpgradeSse($intelligenceUpgrade);
            emitResponseDetailsSse($responseDetails);
        }
        if (!$clientAborted) {
            writeLog('info', 'Antwort vollständig an User übertragen.');
        }
    }

    if ($streamCurlErr !== '') {
        if ($dataWritten || headers_sent()) {
            emitSseData(['error' => $streamCurlErr]);
        } else {
            // Nothing sent yet – return a plain JSON error.
            http_response_code(502);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $streamCurlErr]);
        }
    }
    exit;
}

// ── Non-streaming path ────────────────────────────────────────────────────────

do {
    writeLog('info', 'Prompt an Modell ' . $model . ' weitergeleitet.');
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    header('Content-Type: application/json; charset=utf-8');

    if ($curlErr !== '') {
        writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
        if ($switchEndpoint(true)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            continue;
        }
        $taskFinished = true;
        completeTask($taskId, 'error');
        http_response_code(502);
        echo json_encode(['error' => 'LM Studio nicht erreichbar: ' . $curlErr]);
        exit;
    }

    if ($httpCode !== 200) {
        $data = json_decode($body, true);
        $msg  = isset($data['error']['message'])
            ? $data['error']['message']
            : 'LM Studio Fehler (HTTP ' . $httpCode . ')';
        // Only failover to another endpoint on server-side (5xx) failures. A 4xx means
        // the request payload itself was rejected, so resending it unchanged elsewhere
        // would just reproduce the same error and burn through the retry budget.
        if ($httpCode >= 500) {
            writeLog('warning', 'Modellendpunkt ' . getEndpointLogLabel($endpoint) . ' nicht mehr verfügbar.');
            if ($switchEndpoint()) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($forwardPayload),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                ]);
                continue;
            }
        }
        $taskFinished = true;
        completeTask($taskId, 'error');
        http_response_code(502);
        echo json_encode(['error' => $msg]);
        exit;
    }
    break;
} while (true);

// Extract token usage and complete the task.
$data             = json_decode($body, true);
$promptTokens     = isset($data['usage']['prompt_tokens'])     ? (int) $data['usage']['prompt_tokens']     : null;
$completionTokens = isset($data['usage']['completion_tokens']) ? (int) $data['usage']['completion_tokens'] : null;
$totalTokens      = isset($data['usage']['total_tokens'])      ? (int) $data['usage']['total_tokens']      : null;

$taskFinished = true;
completeTask($taskId, 'done', $promptTokens, $completionTokens, $totalTokens, (microtime(true) - $requestStart) * 1000);
logResponseFinished($requestStart, $promptTokens, $completionTokens);

// Persist conversation on success.
if ($sessionId !== '' && is_array($data)) {
    $assistantContent = normalizeAssistantContent($data['choices'][0]['message']['content'] ?? '');
    $sessionMessages  = array_merge(
        $payload['messages'],
        [['role' => 'assistant', 'content' => $assistantContent]]
    );
    saveConversationSession($sessionId, $model, $sessionMessages, $sessionUserId);
}

// Forward the raw LM Studio response.
if (is_array($data) && !isOpenAiStrictMode() && $intelligenceUpgrade !== null) {
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    if ($totalTokens !== null) {
        addContextUsageToResponseDetails($responseDetails, $endpoint, $totalTokens, $data['choices'][0]['finish_reason'] ?? null);
    }
    $data['intelligence_upgrade'] = buildIntelligenceUpgradePayload($intelligenceUpgrade);
    $data['response_details'] = $responseDetails;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (is_array($data) && !isOpenAiStrictMode()) {
    $responseDetails['elapsed_seconds'] = max(1, (int) round(microtime(true) - $requestStart));
    if ($totalTokens !== null) {
        addContextUsageToResponseDetails($responseDetails, $endpoint, $totalTokens, $data['choices'][0]['finish_reason'] ?? null);
    }
    $data['response_details'] = $responseDetails;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif (is_array($data)) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    echo $body;
}
