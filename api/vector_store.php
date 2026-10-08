<?php

/**
 * api/vector_store.php
 *
 * Vector-database layer of the RAG stack.
 *
 * LLMInt no longer maintains a shared ("global") document knowledge base of
 * its own. Shared knowledge comes from a Milvus vector database that is fed by
 * docvecwizard (https://github.com/dareinelt/docvecwizard). Two modes exist:
 *
 *   remote – LLMInt talks to the docvecwizard REST API (POST /api/search) and
 *            thereby queries the Milvus instance of that installation.
 *   local  – A docvecwizard export archive (.tar.gz) is imported into a Milvus
 *            instance that runs inside the LLMInt docker-compose stack. Chunk
 *            texts live in MySQL (vector_chunks), vectors in Milvus. Query
 *            vectors are produced by the configured embedding endpoint, which
 *            must serve the same model the export was created with.
 *
 * Every chat request retrieves context from the active vector store
 * (see api/chat.php) and the result also backs the query_documents tool.
 *
 * Settings (getSetting()):
 *   vector_store_mode        – 'off' | 'remote' | 'local'
 *   vector_top_k             – hits injected into the chat context (default 5)
 *   vector_min_score         – minimum cosine similarity for a hit (default 0.0)
 *   docvec_api_url           – base URL of docvecwizard (https://host:8443)
 *   docvec_api_username / docvec_api_password
 *   docvec_api_timeout       – seconds (default 20)
 *   docvec_api_verify_tls    – '0' | '1' (self-signed certificates are common)
 *   milvus_url               – REST/gRPC proxy URL (default http://milvus:19530)
 *   milvus_metrics_url       – /healthz URL (default http://milvus:9091)
 *   milvus_token             – optional "user:password" bearer token
 *   milvus_timeout           – seconds (default 30)
 *   milvus_collection        – collection to search (set by the import)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/embedding.php';

// ── Configuration ─────────────────────────────────────────────────────────────

/**
 * Settings accessor with an in-request override layer. The admin "test"
 * endpoint uses it to probe unsaved form values without touching the DB.
 *
 * @param array<string,string>|null $overrides
 */
function vectorSettingOverrides(?array $overrides = null): array
{
    static $current = [];
    if ($overrides !== null) {
        $current = $overrides;
    }
    return $current;
}

function vectorSetting(string $key, string $default = ''): string
{
    $overrides = vectorSettingOverrides();
    if (array_key_exists($key, $overrides)) {
        return (string) $overrides[$key];
    }
    return getSetting($key, $default);
}

function vectorStoreMode(): string
{
    $mode = trim(vectorSetting('vector_store_mode', 'off'));
    return in_array($mode, ['remote', 'local'], true) ? $mode : 'off';
}

function vectorStoreEnabled(): bool
{
    return vectorStoreMode() !== 'off';
}

function vectorStoreTopK(): int
{
    return max(1, min(50, (int) vectorSetting('vector_top_k', '5')));
}

function vectorStoreLabel(string $mode = ''): string
{
    $mode = $mode !== '' ? $mode : vectorStoreMode();
    return match ($mode) {
        'remote' => 'docvecwizard API',
        'local'  => 'Milvus (lokal)',
        default  => 'Vektordatenbank',
    };
}

/** Base URL shown in the dashboard tile for the active mode. */
function vectorStoreBaseUrl(string $mode = ''): string
{
    $mode = $mode !== '' ? $mode : vectorStoreMode();
    return match ($mode) {
        'remote' => rtrim(trim(vectorSetting('docvec_api_url', '')), '/'),
        'local'  => milvusBaseUrl(),
        default  => '',
    };
}

function milvusBaseUrl(): string
{
    $url = trim(vectorSetting('milvus_url', ''));
    if ($url === '') {
        $url = trim((string) (getenv('MILVUS_URL') ?: 'http://milvus:19530'));
    }
    return rtrim($url, '/');
}

function milvusMetricsUrl(): string
{
    $url = trim(vectorSetting('milvus_metrics_url', ''));
    if ($url === '') {
        $url = trim((string) (getenv('MILVUS_METRICS_URL') ?: ''));
    }
    if ($url === '') {
        // Default: same host as the proxy URL, metrics port 9091.
        $parts = parse_url(milvusBaseUrl());
        $url   = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'milvus') . ':9091';
    }
    return rtrim($url, '/');
}

// ── Generic HTTP helper ───────────────────────────────────────────────────────

/**
 * Perform a JSON HTTP request.
 *
 * @return array{status:int,body:string,json:array|null,error:string}
 */
function vectorHttpRequest(string $method, string $url, ?array $payload, array $headers, int $timeout, array $curlExtra = []): array
{
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(1, $timeout),
        CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    foreach ($curlExtra as $k => $v) {
        $opts[$k] = $v;
    }
    curl_setopt_array($ch, $opts);

    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($body === false) {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => $error !== '' ? $error : 'Keine Antwort'];
    }
    $json = json_decode((string) $body, true);
    return ['status' => $code, 'body' => (string) $body, 'json' => is_array($json) ? $json : null, 'error' => $error];
}

// ── Remote mode: docvecwizard REST API ────────────────────────────────────────

function docvecConfig(): array
{
    return [
        'url'        => rtrim(trim(vectorSetting('docvec_api_url', '')), '/'),
        'username'   => trim(vectorSetting('docvec_api_username', '')),
        'password'   => (string) vectorSetting('docvec_api_password', ''),
        'timeout'    => max(3, min(120, (int) vectorSetting('docvec_api_timeout', '20'))),
        'verify_tls' => vectorSetting('docvec_api_verify_tls', '0') === '1',
    ];
}

/** Path of the cookie jar that keeps the docvecwizard session between requests. */
function docvecCookieJar(array $cfg): string
{
    $dir = sys_get_temp_dir() . '/llmint_docvec';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . hash('sha256', $cfg['url'] . '|' . $cfg['username']) . '.cookies';
}

function docvecCurlExtra(array $cfg): array
{
    $jar = docvecCookieJar($cfg);
    return [
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_SSL_VERIFYPEER => $cfg['verify_tls'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_tls'] ? 2 : 0,
    ];
}

/**
 * Low-level docvecwizard request. $csrf is sent as X-CSRF-Token when given.
 */
function docvecRequest(array $cfg, string $method, string $path, ?array $payload = null, string $csrf = ''): array
{
    $headers = [];
    if ($csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    return vectorHttpRequest($method, $cfg['url'] . $path, $payload, $headers, $cfg['timeout'], docvecCurlExtra($cfg));
}

/**
 * Make sure the cookie jar holds an authenticated docvecwizard session and
 * return the current CSRF token. Logs in when the session is anonymous.
 *
 * @return array{ok:bool,csrf:string,message:string}
 */
function docvecEnsureSession(array $cfg, bool $forceLogin = false): array
{
    if ($cfg['url'] === '') {
        return ['ok' => false, 'csrf' => '', 'message' => 'Keine docvecwizard-URL konfiguriert.'];
    }

    $me = docvecRequest($cfg, 'GET', '/api/auth/me');
    if ($me['status'] === 0) {
        return ['ok' => false, 'csrf' => '', 'message' => 'docvecwizard nicht erreichbar: ' . $me['error']];
    }
    if ($me['status'] !== 200 || $me['json'] === null) {
        return ['ok' => false, 'csrf' => '', 'message' => 'Unerwartete Antwort von docvecwizard (HTTP ' . $me['status'] . ').'];
    }

    $csrf = (string) ($me['json']['csrf_token'] ?? '');
    if (!$forceLogin && !empty($me['json']['authenticated'])) {
        return ['ok' => true, 'csrf' => $csrf, 'message' => 'Sitzung aktiv.'];
    }

    if ($cfg['username'] === '' || $cfg['password'] === '') {
        return ['ok' => false, 'csrf' => '', 'message' => 'Keine Zugangsdaten für docvecwizard hinterlegt.'];
    }

    $login = docvecRequest($cfg, 'POST', '/api/auth/login', [
        'username' => $cfg['username'],
        'password' => $cfg['password'],
    ], $csrf);

    if ($login['status'] === 401) {
        return ['ok' => false, 'csrf' => '', 'message' => 'Anmeldung bei docvecwizard fehlgeschlagen (Benutzername/Passwort).'];
    }
    if ($login['status'] === 429) {
        return ['ok' => false, 'csrf' => '', 'message' => 'docvecwizard: zu viele Anmeldeversuche, bitte später erneut versuchen.'];
    }
    if ($login['status'] !== 200 || $login['json'] === null || empty($login['json']['authenticated'])) {
        return ['ok' => false, 'csrf' => '', 'message' => 'Anmeldung bei docvecwizard fehlgeschlagen (HTTP ' . $login['status'] . ').'];
    }

    return ['ok' => true, 'csrf' => (string) ($login['json']['csrf_token'] ?? ''), 'message' => 'Angemeldet.'];
}

/**
 * Semantic search through the docvecwizard API.
 *
 * @return array{ok:bool,hits:array,message:string}
 */
function docvecSearch(string $query, int $limit): array
{
    $cfg     = docvecConfig();
    $session = docvecEnsureSession($cfg);
    if (!$session['ok']) {
        return ['ok' => false, 'hits' => [], 'message' => $session['message']];
    }

    $payload = ['query' => mb_substr($query, 0, 2000), 'limit' => max(1, min(50, $limit))];
    $res = docvecRequest($cfg, 'POST', '/api/search', $payload, $session['csrf']);

    // Expired session or rotated CSRF token: log in once more and retry.
    if (in_array($res['status'], [401, 403], true)) {
        $session = docvecEnsureSession($cfg, true);
        if (!$session['ok']) {
            return ['ok' => false, 'hits' => [], 'message' => $session['message']];
        }
        $res = docvecRequest($cfg, 'POST', '/api/search', $payload, $session['csrf']);
    }

    if ($res['status'] === 0) {
        return ['ok' => false, 'hits' => [], 'message' => 'docvecwizard nicht erreichbar: ' . $res['error']];
    }
    if ($res['status'] === 409) {
        return ['ok' => false, 'hits' => [], 'message' => (string) ($res['json']['error'] ?? 'Embedding-Modell in docvecwizard nicht aktiv.')];
    }
    if ($res['status'] !== 200 || $res['json'] === null) {
        return ['ok' => false, 'hits' => [], 'message' => 'Suche fehlgeschlagen (HTTP ' . $res['status'] . '): ' . (string) ($res['json']['error'] ?? '')];
    }

    // docvecwizard answers either with a bare list or {results: [...]}.
    $rows = isset($res['json']['results']) && is_array($res['json']['results'])
        ? $res['json']['results']
        : (array_is_list($res['json']) ? $res['json'] : []);

    $hits = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $text = trim((string) ($row['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $hits[] = [
            'text'        => $text,
            'filename'    => (string) ($row['filename'] ?? 'Dokument'),
            'source_path' => (string) ($row['source_path'] ?? ''),
            'document_id' => (string) ($row['document_id'] ?? ''),
            'chunk_index' => (int) ($row['chunk_index'] ?? 0),
            'page_start'  => (int) ($row['page_start'] ?? 0),
            'page_end'    => (int) ($row['page_end'] ?? 0),
            'score'       => (float) ($row['distance'] ?? 0.0),
            'source'      => 'remote',
        ];
    }

    return ['ok' => true, 'hits' => $hits, 'message' => count($hits) . ' Treffer.'];
}

/**
 * Remote health: reachable, authenticated and – if available – aggregate
 * statistics (document and vector counts).
 */
function docvecStatus(): array
{
    $cfg    = docvecConfig();
    $status = [
        'online'     => false,
        'detail'     => '',
        'documents'  => null,
        'vectors'    => null,
        'collections'=> [],
    ];
    if ($cfg['url'] === '') {
        $status['detail'] = 'Keine URL konfiguriert';
        return $status;
    }

    $probeCfg = $cfg;
    $probeCfg['timeout'] = min(8, $cfg['timeout']);

    $health = docvecRequest($probeCfg, 'GET', '/healthz');
    if ($health['status'] !== 200) {
        $status['detail'] = $health['status'] === 0 ? ('Nicht erreichbar: ' . $health['error']) : ('HTTP ' . $health['status']);
        return $status;
    }

    $session = docvecEnsureSession($probeCfg);
    if (!$session['ok']) {
        $status['detail'] = $session['message'];
        return $status;
    }
    $status['online'] = true;
    $status['detail'] = 'Verbunden';

    $stats = docvecRequest($probeCfg, 'GET', '/api/statistics', null, $session['csrf']);
    if ($stats['status'] === 200 && $stats['json'] !== null) {
        $j = $stats['json'];
        $status['documents'] = isset($j['documents']) ? (int) (is_array($j['documents']) ? ($j['documents']['total'] ?? 0) : $j['documents']) : null;
        $status['vectors']   = isset($j['vectors'])   ? (int) (is_array($j['vectors'])   ? ($j['vectors']['total']   ?? 0) : $j['vectors'])   : null;
    }
    $cols = docvecRequest($probeCfg, 'GET', '/api/collections', null, $session['csrf']);
    if ($cols['status'] === 200 && $cols['json'] !== null) {
        $list = isset($cols['json']['collections']) && is_array($cols['json']['collections']) ? $cols['json']['collections'] : $cols['json'];
        foreach ((array) $list as $c) {
            $name = is_array($c) ? (string) ($c['name'] ?? $c['collection'] ?? '') : (string) $c;
            if ($name !== '') {
                $status['collections'][] = $name;
            }
        }
    }

    return $status;
}

// ── Local mode: Milvus RESTful v2 API ─────────────────────────────────────────

function milvusConfig(): array
{
    return [
        'url'         => milvusBaseUrl(),
        'metrics_url' => milvusMetricsUrl(),
        'token'       => trim(vectorSetting('milvus_token', '')),
        'timeout'     => max(3, min(600, (int) vectorSetting('milvus_timeout', '30'))),
    ];
}

/**
 * POST to the Milvus REST API. Milvus returns HTTP 200 with {code, message}
 * even for logical errors, so non-zero codes are converted into an error.
 *
 * @return array{ok:bool,data:mixed,message:string,code:int}
 */
function milvusPost(string $path, array|object $payload, ?int $timeout = null): array
{
    $cfg     = milvusConfig();
    $headers = [];
    if ($cfg['token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $cfg['token'];
    }
    // Empty payloads must be sent as "{}" – Milvus rejects "[]".
    $body = is_object($payload) || $payload !== [] ? $payload : new stdClass();

    $ch = curl_init($cfg['url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout ?? $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($raw === false) {
        return ['ok' => false, 'data' => null, 'message' => 'Milvus nicht erreichbar: ' . $err, 'code' => -1];
    }
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'data' => null, 'message' => 'Ungültige Antwort von Milvus (HTTP ' . $code . ')', 'code' => -1];
    }
    $mCode = (int) ($json['code'] ?? 0);
    if ($mCode !== 0) {
        return ['ok' => false, 'data' => $json['data'] ?? null, 'message' => 'Milvus-Fehler ' . $mCode . ': ' . (string) ($json['message'] ?? 'unbekannt'), 'code' => $mCode];
    }
    return ['ok' => true, 'data' => $json['data'] ?? null, 'message' => '', 'code' => 0];
}

/** POST with retries for transient Milvus errors (rate limiting = 1807). */
function milvusPostRetry(string $path, array|object $payload, int $maxAttempts = 6): array
{
    $attempt = 0;
    while (true) {
        $res = milvusPost($path, $payload);
        $attempt++;
        if ($res['ok'] || $attempt >= $maxAttempts || !in_array($res['code'], [1807, -1], true)) {
            return $res;
        }
        usleep(min(500_000 * (2 ** ($attempt - 1)), 8_000_000));
    }
}

function milvusHealth(): bool
{
    $cfg = milvusConfig();
    $res = vectorHttpRequest('GET', $cfg['metrics_url'] . '/healthz', null, [], 4);
    if ($res['status'] === 200) {
        return true;
    }
    // Fallback: the REST API itself answers when the metrics port is not exposed.
    return milvusPost('/v2/vectordb/collections/list', new stdClass(), 4)['ok'];
}

/** @return string[] */
function milvusListCollections(): array
{
    $res = milvusPost('/v2/vectordb/collections/list', new stdClass());
    if (!$res['ok'] || !is_array($res['data'])) {
        return [];
    }
    return array_values(array_filter($res['data'], 'is_string'));
}

function milvusHasCollection(string $collection): bool
{
    $res = milvusPost('/v2/vectordb/collections/has', ['collectionName' => $collection]);
    return $res['ok'] && !empty($res['data']['has']);
}

function milvusCollectionRowCount(string $collection): ?int
{
    $res = milvusPost('/v2/vectordb/collections/get_stats', ['collectionName' => $collection]);
    if (!$res['ok'] || !is_array($res['data'])) {
        return null;
    }
    return (int) ($res['data']['rowCount'] ?? 0);
}

/** Collection name used by docvecwizard for a given embedding model. */
function milvusCollectionForModel(string $modelName): string
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($modelName));
    $slug = trim((string) $slug, '_');
    return 'docvec_' . ($slug === '' ? 'default' : $slug);
}

/**
 * Create a collection with the docvecwizard schema so exported vectors can be
 * inserted unchanged.
 */
function milvusCreateCollection(string $collection, int $dimension): array
{
    $vc = static fn(string $name, int $len): array => [
        'fieldName' => $name, 'dataType' => 'VarChar', 'elementTypeParams' => ['max_length' => $len],
    ];
    return milvusPost('/v2/vectordb/collections/create', [
        'collectionName' => $collection,
        'dbName'         => 'default',
        'schema'         => [
            'autoID' => false,
            'fields' => [
                ['fieldName' => 'id', 'dataType' => 'VarChar', 'isPrimary' => true, 'elementTypeParams' => ['max_length' => 36]],
                $vc('document_id', 36),
                $vc('document_version_id', 36),
                $vc('chunk_id', 36),
                $vc('job_id', 36),
                $vc('source_path', 1024),
                $vc('filename', 512),
                $vc('document_hash', 64),
                $vc('embedding_model', 191),
                ['fieldName' => 'embedding_dimension', 'dataType' => 'Int64'],
                ['fieldName' => 'chunk_index', 'dataType' => 'Int64'],
                ['fieldName' => 'page_start', 'dataType' => 'Int64'],
                ['fieldName' => 'page_end', 'dataType' => 'Int64'],
                ['fieldName' => 'vector', 'dataType' => 'FloatVector', 'elementTypeParams' => ['dim' => $dimension]],
            ],
        ],
        'indexParams' => [[
            'fieldName'  => 'vector',
            'indexName'  => 'vector_idx',
            'metricType' => 'COSINE',
            'indexType'  => 'AUTOINDEX',
            'params'     => new stdClass(),
        ]],
    ]);
}

function milvusInsert(string $collection, array $rows): array
{
    return milvusPostRetry('/v2/vectordb/entities/insert', ['collectionName' => $collection, 'data' => $rows]);
}

function milvusFlush(string $collection): array
{
    return milvusPostRetry('/v2/vectordb/collections/flush', ['collectionName' => $collection]);
}

function milvusLoadCollection(string $collection): array
{
    return milvusPost('/v2/vectordb/collections/load', ['collectionName' => $collection]);
}

function milvusDeleteByFilter(string $collection, string $filter): array
{
    return milvusPost('/v2/vectordb/entities/delete', ['collectionName' => $collection, 'filter' => $filter]);
}

function milvusDropCollection(string $collection): array
{
    return milvusPost('/v2/vectordb/collections/drop', ['collectionName' => $collection]);
}

/**
 * Cosine search in a collection.
 *
 * @param float[] $vector
 * @return array{ok:bool,rows:array,message:string}
 */
function milvusSearch(string $collection, array $vector, int $limit): array
{
    $res = milvusPost('/v2/vectordb/entities/search', [
        'collectionName' => $collection,
        'data'           => [$vector],
        'annsField'      => 'vector',
        'limit'          => max(1, min(200, $limit)),
        'outputFields'   => ['id', 'document_id', 'document_version_id', 'chunk_id', 'filename', 'source_path', 'chunk_index', 'page_start', 'page_end'],
        'searchParams'   => ['metricType' => 'COSINE'],
    ]);
    if (!$res['ok']) {
        // Collections that were just created or restarted may need loading.
        if (str_contains($res['message'], 'not loaded') || str_contains($res['message'], 'collection not loaded')) {
            milvusLoadCollection($collection);
            $res = milvusPost('/v2/vectordb/entities/search', [
                'collectionName' => $collection,
                'data'           => [$vector],
                'annsField'      => 'vector',
                'limit'          => max(1, min(200, $limit)),
                'outputFields'   => ['id', 'document_id', 'document_version_id', 'chunk_id', 'filename', 'source_path', 'chunk_index', 'page_start', 'page_end'],
                'searchParams'   => ['metricType' => 'COSINE'],
            ]);
        }
    }
    if (!$res['ok']) {
        return ['ok' => false, 'rows' => [], 'message' => $res['message']];
    }
    return ['ok' => true, 'rows' => is_array($res['data']) ? array_values(array_filter($res['data'], 'is_array')) : [], 'message' => ''];
}

/** The collection the local mode searches in (configured or auto-detected). */
function localVectorCollection(): string
{
    $configured = trim(vectorSetting('milvus_collection', ''));
    if ($configured !== '') {
        return $configured;
    }
    try {
        $row = getDb()->query(
            'SELECT collection_name FROM vector_documents WHERE collection_name <> "" GROUP BY collection_name ORDER BY COUNT(*) DESC LIMIT 1'
        )->fetchColumn();
        return is_string($row) ? $row : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Local search: embed the query with the configured embedding endpoint, search
 * Milvus and hydrate the chunk texts from MySQL.
 *
 * @return array{ok:bool,hits:array,message:string}
 */
function localVectorSearch(string $query, int $limit): array
{
    $collection = localVectorCollection();
    if ($collection === '') {
        return ['ok' => false, 'hits' => [], 'message' => 'Noch keine Collection importiert.'];
    }
    if (!hasActiveEmbeddingEndpoint()) {
        return ['ok' => false, 'hits' => [], 'message' => 'Kein aktiver Embedding-Endpunkt für die Anfrage-Vektorisierung konfiguriert.'];
    }

    $embModel   = trim(getSetting('embedding_model', ''));
    $queryEmbed = $embModel !== '' ? getCachedQueryEmbedding($query, $embModel) : null;
    if ($queryEmbed === null) {
        $queryEmbed = generateEmbeddingAuto($query, 'query');
        if ($queryEmbed !== null && $embModel !== '') {
            setCachedQueryEmbedding($query, $embModel, $queryEmbed);
        }
    }
    if ($queryEmbed === null) {
        return ['ok' => false, 'hits' => [], 'message' => 'Anfrage konnte nicht vektorisiert werden (Embedding-Endpunkt).'];
    }

    // Dimension guard: a mismatching embedding model is the most common misconfiguration.
    try {
        $stmt = getDb()->prepare('SELECT MAX(embedding_dimension) FROM vector_documents WHERE collection_name = ?');
        $stmt->execute([$collection]);
        $expectedDim = (int) $stmt->fetchColumn();
        if ($expectedDim > 0 && $expectedDim !== count($queryEmbed)) {
            return ['ok' => false, 'hits' => [], 'message' => sprintf(
                'Dimension des Embedding-Endpunkts (%d) passt nicht zur Collection "%s" (%d). Bitte dasselbe Embedding-Modell wie in docvecwizard verwenden.',
                count($queryEmbed), $collection, $expectedDim
            )];
        }
    } catch (Throwable $e) {
        // Table missing – skip the guard.
    }

    // Over-fetch so hits without a text row (e.g. partially imported) can be dropped.
    $search = milvusSearch($collection, $queryEmbed, $limit * 3);
    if (!$search['ok']) {
        return ['ok' => false, 'hits' => [], 'message' => $search['message']];
    }

    $chunkIds = [];
    foreach ($search['rows'] as $row) {
        $cid = (string) ($row['chunk_id'] ?? '');
        if ($cid !== '') {
            $chunkIds[] = $cid;
        }
    }
    $texts = [];
    if ($chunkIds !== []) {
        try {
            $ph   = implode(',', array_fill(0, count($chunkIds), '?'));
            $stmt = getDb()->prepare("SELECT chunk_id, text FROM vector_chunks WHERE chunk_id IN ($ph)");
            $stmt->execute($chunkIds);
            foreach ($stmt->fetchAll() as $r) {
                $texts[(string) $r['chunk_id']] = (string) $r['text'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'hits' => [], 'message' => 'Chunk-Texte konnten nicht geladen werden: ' . $e->getMessage()];
        }
    }

    $hits = [];
    foreach ($search['rows'] as $row) {
        $cid  = (string) ($row['chunk_id'] ?? '');
        $text = trim($texts[$cid] ?? '');
        if ($text === '') {
            continue;
        }
        $hits[] = [
            'text'        => $text,
            'filename'    => (string) ($row['filename'] ?? 'Dokument'),
            'source_path' => (string) ($row['source_path'] ?? ''),
            'document_id' => (string) ($row['document_id'] ?? ''),
            'chunk_index' => (int) ($row['chunk_index'] ?? 0),
            'page_start'  => (int) ($row['page_start'] ?? 0),
            'page_end'    => (int) ($row['page_end'] ?? 0),
            'score'       => (float) ($row['distance'] ?? 0.0),
            'source'      => 'local',
        ];
        if (count($hits) >= $limit) {
            break;
        }
    }

    return ['ok' => true, 'hits' => $hits, 'message' => count($hits) . ' Treffer.'];
}

function localVectorStatus(): array
{
    $status = [
        'online'      => false,
        'detail'      => '',
        'documents'   => null,
        'vectors'     => null,
        'collections' => [],
        'collection'  => localVectorCollection(),
    ];

    try {
        $status['documents'] = (int) getDb()->query('SELECT COUNT(*) FROM vector_documents')->fetchColumn();
    } catch (Throwable $e) {
        $status['documents'] = 0;
    }

    if (!milvusHealth()) {
        $status['detail'] = 'Milvus nicht erreichbar';
        return $status;
    }
    $status['online']      = true;
    $status['detail']      = 'Verbunden';
    $status['collections'] = milvusListCollections();
    if ($status['collection'] !== '' && in_array($status['collection'], $status['collections'], true)) {
        $status['vectors'] = milvusCollectionRowCount($status['collection']);
    } elseif ($status['collection'] !== '') {
        $status['detail'] = 'Collection "' . $status['collection'] . '" fehlt in Milvus';
    } elseif ($status['collections'] === []) {
        $status['detail'] = 'Noch kein Export importiert';
    }

    return $status;
}

// ── Mode-independent entry points ─────────────────────────────────────────────

/**
 * Search the active vector store.
 *
 * @return array{ok:bool,hits:array,message:string,mode:string}
 */
function vectorStoreSearch(string $query, ?int $limit = null): array
{
    $mode  = vectorStoreMode();
    $query = trim($query);
    $limit = $limit ?? vectorStoreTopK();

    if ($mode === 'off') {
        return ['ok' => false, 'hits' => [], 'message' => 'Vektordatenbank deaktiviert.', 'mode' => $mode];
    }
    if ($query === '') {
        return ['ok' => false, 'hits' => [], 'message' => 'Leere Suchanfrage.', 'mode' => $mode];
    }

    $startMs = (int) round(microtime(true) * 1000);
    $result  = $mode === 'remote' ? docvecSearch($query, $limit) : localVectorSearch($query, $limit);
    $durMs   = (int) round(microtime(true) * 1000) - $startMs;

    $minScore = (float) str_replace(',', '.', vectorSetting('vector_min_score', '0'));
    if ($result['ok'] && $minScore > 0.0) {
        $result['hits'] = array_values(array_filter(
            $result['hits'],
            static fn(array $h): bool => (float) $h['score'] >= $minScore
        ));
    }

    logVectorQuery($mode, $durMs, count($result['hits']), $result['ok'] ? 'ok' : 'error');
    if (!$result['ok']) {
        writeLog('warning', 'Vektorsuche (' . vectorStoreLabel($mode) . ') fehlgeschlagen: ' . $result['message']);
    }

    $result['mode'] = $mode;
    return $result;
}

/**
 * Health/status snapshot for the dashboard. Probing the backend costs a few
 * network round trips, so the result is cached briefly in the settings table
 * (the dashboard polls every 15 s).
 */
function vectorStoreStatus(bool $useCache = true): array
{
    $mode = vectorStoreMode();
    $base = [
        'enabled'   => $mode !== 'off',
        'mode'      => $mode,
        'label'     => vectorStoreLabel($mode),
        'base_url'  => vectorStoreBaseUrl($mode),
        'online'    => false,
        'detail'    => 'Deaktiviert',
        'documents' => null,
        'vectors'   => null,
        'collections' => [],
        'collection'  => '',
        'checked_at'  => time(),
    ];
    if ($mode === 'off') {
        return $base;
    }

    $cacheKey = 'vector_store_status_cache';
    if ($useCache) {
        $cached = json_decode((string) getSetting($cacheKey, ''), true);
        if (is_array($cached) && ($cached['mode'] ?? '') === $mode
            && (time() - (int) ($cached['checked_at'] ?? 0)) < 20
            && ($cached['base_url'] ?? '') === $base['base_url']) {
            return $cached;
        }
    }

    $probe  = $mode === 'remote' ? docvecStatus() : localVectorStatus();
    $status = array_merge($base, $probe, ['checked_at' => time()]);

    try {
        setSetting($cacheKey, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        // Cache is best effort.
    }
    return $status;
}

/** Persist one query for the dashboard statistics. */
function logVectorQuery(string $mode, int $durationMs, int $hits, string $status): void
{
    try {
        getDb()->prepare(
            'INSERT INTO vector_query_logs (mode, duration_ms, hits, status, created_at) VALUES (?, ?, ?, ?, NOW(3))'
        )->execute([$mode === 'remote' ? 'remote' : 'local', max(0, $durationMs), max(0, $hits), $status === 'ok' ? 'ok' : 'error']);
    } catch (Throwable $e) {
        // Statistics must never break a chat request.
    }
}

/** Aggregated query statistics for the dashboard tile. */
function vectorQueryStats(): array
{
    $stats = ['today_queries' => 0, 'today_errors' => 0, 'avg_duration_ms' => null, 'total_queries' => 0];
    try {
        $row = getDb()->query("
            SELECT
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_queries,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'error' THEN 1 ELSE 0 END), 0) AS today_errors,
                AVG(CASE WHEN DATE(created_at) = CURDATE() AND status = 'ok' THEN duration_ms END) AS avg_duration_ms,
                COUNT(*) AS total_queries
            FROM vector_query_logs
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $stats = [
                'today_queries'   => (int) $row['today_queries'],
                'today_errors'    => (int) $row['today_errors'],
                'avg_duration_ms' => $row['avg_duration_ms'] !== null ? (int) round((float) $row['avg_duration_ms']) : null,
                'total_queries'   => (int) $row['total_queries'],
            ];
        }
    } catch (Throwable $e) {
        // Table may not exist yet.
    }
    return $stats;
}

/**
 * Format vector hits as a system message for the LLM.
 *
 * @param array $hits  Normalised hits from vectorStoreSearch()
 */
function buildVectorContextSystemPrompt(array $hits, int $budget = 12000): string
{
    if ($hits === []) {
        return '';
    }
    $parts = [
        'Wissensdatenbank-Kontext: Die folgenden Auszüge stammen aus der zentralen Vektordatenbank '
        . 'und wurden zur aktuellen Nutzeranfrage gefunden. Nutze sie bevorzugt zur Beantwortung, '
        . 'wenn sie relevant sind, und nenne die Quelle (Dateiname, ggf. Seite). '
        . 'Erfinde keine Inhalte, die nicht in den Auszügen oder im Gespräch stehen.',
    ];
    $perHit = (int) max(400, $budget / max(1, count($hits)));
    foreach ($hits as $i => $hit) {
        $text = trim((string) $hit['text']);
        if (mb_strlen($text) > $perHit) {
            $text = mb_substr($text, 0, $perHit) . ' […]';
        }
        $src = '"' . (string) $hit['filename'] . '"';
        if (!empty($hit['page_start'])) {
            $src .= ', Seite ' . (int) $hit['page_start'];
            if (!empty($hit['page_end']) && (int) $hit['page_end'] !== (int) $hit['page_start']) {
                $src .= '–' . (int) $hit['page_end'];
            }
        }
        $parts[] = sprintf("[Quelle %d: %s, Abschnitt %d, Relevanz %.2f]\n%s", $i + 1, $src, (int) $hit['chunk_index'] + 1, (float) $hit['score'], $text);
    }
    return implode("\n\n", $parts);
}

/** Convert hits into the shape returned by the query_documents tool. */
function vectorHitsToToolResults(array $hits): array
{
    return array_map(static function (array $hit): array {
        $page = (int) ($hit['page_start'] ?? 0);
        return [
            'document'  => (string) $hit['filename'],
            'source'    => 'Vektordatenbank',
            'page'      => $page > 0 ? $page : null,
            'chunk'     => (int) $hit['chunk_index'] + 1,
            'relevance' => round((float) $hit['score'], 4),
            'content'   => mb_substr((string) $hit['text'], 0, 1500),
        ];
    }, $hits);
}
