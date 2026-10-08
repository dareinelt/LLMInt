<?php

/**
 * lib/image_generation.php
 *
 * Shared helpers for the ImageInt integration.
 *
 * Pipeline: chat → tool call `generate_image` → ImageInt
 * (POST /v1/images/generations) → job polling (GET {status_url}) → PNG in
 * `image_output/` → markdown image in the chat answer. Long-running jobs can be
 * announced by e-mail: the user's consent is recorded in `image_notifications`
 * and drained by api/image_notify_worker.php (cron/CLI), which survives a
 * closed browser tab.
 *
 * The compute-heavy part – Qwen-Image-2.1 plus its prompt enhancer – lives in
 * the separate project ImageInt (https://github.com/dareinelt/ImageInt) and is
 * reached over HTTP exactly like SpeechInt (lib/speech_dictation.php) and the
 * document converter: the base URL comes from the IMAGEINT_URL environment
 * variable (docker-compose) and falls back to the active row of the
 * `image_endpoints` table, so an administrator can point LLMInt at one or
 * several ImageInt instances at runtime. PHP never shells out.
 *
 * Loading contract: while ImageInt is still loading its models it answers 503
 * with `transient: true` (plus a Retry-After header). That is *not* a failure –
 * imageIntGenerate() retries in a bounded loop and finally reports a waiting
 * message (see the `loading` key of the return values). Only a 503 with
 * `error: "host_unsupported"` is a permanent misconfiguration and is reported
 * as such.
 *
 * The PNG is copied into `image_output/` as soon as it exists. That is what
 * makes the chat message self-contained: ImageInt only keeps a job for
 * IMAGEINT_JOB_RETENTION_SECONDS (24 h by default) and its `image_url` points
 * at its own host, which a browser cannot necessarily reach.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/reverse_proxy.php';
require_once __DIR__ . '/mailer.php';

/** Default ceiling of the whole image operation, in seconds. */
const IMAGE_INT_TIMEOUT_DEFAULT = 1800;

/** Default sync window: how long one PHP request may wait for the PNG. */
const IMAGE_INT_SYNC_TIMEOUT_DEFAULT = 120;

/** Default interval between two job polls, in seconds. */
const IMAGE_INT_POLL_INTERVAL_DEFAULT = 15;

/** Relative directory (from the application root) the PNGs are cached in. */
const IMAGE_INT_OUTPUT_DIR = 'image_output';

/**
 * All configured ImageInt endpoints, ordered like the admin card shows them.
 *
 * @return array<int,array<string,mixed>>
 */
function imageIntEndpoints(): array
{
    try {
        $rows = getDb()->query(
            'SELECT * FROM image_endpoints ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }
    return is_array($rows) ? $rows : [];
}

/** One ImageInt endpoint by id; null when it does not exist. */
function imageIntEndpoint(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = getDb()->prepare('SELECT * FROM image_endpoints WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable $_e) {
        return null;
    }
    return is_array($row) ? $row : null;
}

/** Endpoint explicitly selected in the admin area (0 = "first active one"). */
function imageIntEndpointId(): int
{
    $id = (int) getSetting('image_endpoint_id', '0');
    return $id > 0 ? $id : 0;
}

/**
 * The endpoint used for the next request.
 *
 * The endpoint selected in the admin area wins; without a selection the first
 * active endpoint in sort order is used, so a fresh installation works as soon
 * as a single endpoint has been added.
 */
function imageIntActiveEndpoint(): ?array
{
    $endpoints = imageIntEndpoints();
    if ($endpoints === []) {
        return null;
    }

    $wanted = imageIntEndpointId();
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
 * Base URL of the ImageInt service.
 *
 * Environment first (docker-compose), then the active `image_endpoints` row.
 * An empty value means "not deployed".
 */
function imageIntUrl(): string
{
    $url = trim((string) (getenv('IMAGEINT_URL') ?: ''));
    if ($url === '') {
        $endpoint = imageIntActiveEndpoint();
        $url = $endpoint === null ? '' : trim((string) $endpoint['base_url']);
    }
    return rtrim($url, '/');
}

/** Optional shared secret of the ImageInt service (X-Auth-Token header). */
function imageIntToken(): string
{
    $token = trim((string) (getenv('IMAGEINT_TOKEN') ?: ''));
    if ($token === '') {
        $endpoint = imageIntActiveEndpoint();
        $token = $endpoint === null ? '' : trim((string) ($endpoint['token'] ?? ''));
    }
    return $token;
}

/**
 * Ceiling of the whole image operation including job polling, in seconds.
 *
 * ImageInt renders on a CPU, so this is minutes and not seconds: 1800 s by
 * default. The value is deliberately *not* the timeout of a single HTTP call –
 * imageIntHttpCall() clamps its own per-request timeouts well below that.
 */
function imageIntTimeout(): int
{
    $timeout = (int) (getenv('IMAGEINT_TIMEOUT') ?: 0);
    if ($timeout <= 0) {
        $endpoint = imageIntActiveEndpoint();
        $timeout = $endpoint === null ? 0 : (int) $endpoint['timeout'];
    }
    if ($timeout <= 0) {
        $timeout = IMAGE_INT_TIMEOUT_DEFAULT;
    }
    return max(30, min(7200, $timeout));
}

/**
 * How long one PHP request may wait for the finished PNG before it hands the
 * job back to the browser (which then polls api/image_status.php).
 *
 * ImageInt answers with 202 after IMAGEINT_SYNC_TIMEOUT (120 s by default);
 * waiting any longer would run into every reverse-proxy timeout in front of
 * PHP without producing a result.
 */
function imageIntSyncTimeout(): int
{
    $timeout = (int) (getenv('IMAGEINT_SYNC_TIMEOUT') ?: 0);
    if ($timeout <= 0) {
        $timeout = IMAGE_INT_SYNC_TIMEOUT_DEFAULT;
    }
    return max(5, min(600, $timeout));
}

/**
 * Where the effective ImageInt URL comes from.
 *
 * The admin card needs this because environment variables (docker-compose) take
 * precedence over the endpoint table – without showing the origin an
 * administrator would edit a row that has no effect.
 *
 * @return string 'env', 'endpoint' or 'none'.
 */
function imageIntUrlSource(): string
{
    if (trim((string) (getenv('IMAGEINT_URL') ?: '')) !== '') {
        return 'env';
    }
    return imageIntActiveEndpoint() === null ? 'none' : 'endpoint';
}

/** Human-readable origin label for the admin card. */
function imageIntSourceLabel(string $source): string
{
    switch ($source) {
        case 'env':
            return 'Umgebungsvariable (docker-compose)';
        case 'endpoint':
            return 'Bild-Endpunkt in der Datenbank';
        default:
            return 'nicht konfiguriert';
    }
}

/** Display name of one `image_endpoints` row (alias, else host and port). */
function imageIntEndpointLabel(array $endpoint): string
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
 * Mask a stored ImageInt token for display.
 *
 * The secret itself never reaches the browser; only its length and the last
 * four characters are shown so an administrator can recognise the right token.
 */
function imageIntMaskToken(string $token): string
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

/** Whether the image generation is offered at all (admin switch). */
function imageIntEnabled(): bool
{
    return getSetting('image_generation_enabled', '1') === '1';
}

/**
 * Whether the chat should offer the `generate_image` tool.
 *
 * Deliberately configuration-only (no network call) so the chat page renders
 * without waiting for the image service – the same contract as
 * speechDictationConfigured().
 */
function imageIntConfigured(): bool
{
    return imageIntEnabled() && imageIntUrl() !== '';
}

/** Name of the installation, used for the {sitename} placeholder. */
function imageIntSiteName(): string
{
    $name = trim(getSetting('smtp_from_name', ''));
    return $name !== '' ? $name : 'LLMInt';
}

/**
 * Public base URL used for links inside notification mails.
 *
 * A CLI worker has no $_SERVER, so appPublicBaseUrl() would produce
 * "http://localhost". The APP_BASE_URL environment variable wins, then the
 * `image_notify_base_url` setting, then the request-derived value.
 */
function imageIntPublicBaseUrl(): string
{
    $base = trim((string) (getenv('APP_BASE_URL') ?: ''));
    if ($base === '') {
        $base = trim(getSetting('image_notify_base_url', ''));
    }
    if ($base === '') {
        $base = appPublicBaseUrl();
    }
    return rtrim($base, '/');
}

/** Deep link into the chat that carries session and job. */
function imageIntChatUrl(string $sessionId, string $jobId): string
{
    $url = imageIntPublicBaseUrl() . '/index.php';
    $params = [];
    if ($sessionId !== '') {
        $params['session'] = $sessionId;
    }
    if ($jobId !== '') {
        $params['job'] = $jobId;
    }
    if ($params === []) {
        return $url;
    }
    return $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** Absolute URL of a locally cached PNG (relative path below the app root). */
function imageIntAbsoluteImageUrl(string $relativePath): string
{
    $relativePath = ltrim(trim($relativePath), '/');
    if ($relativePath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $relativePath) === 1) {
        return $relativePath;
    }
    return imageIntPublicBaseUrl() . '/' . $relativePath;
}

// ── ImageInt HTTP client ──────────────────────────────────────────────────────

/**
 * Run one HTTP request against an ImageInt endpoint.
 *
 * Every JSON call goes through here so transport details – auth header,
 * timeout, Retry-After parsing and the loading contract – exist exactly once.
 * Binary payloads (the PNG) use imageIntDownloadImage() instead.
 *
 * @param array{method?:string,json?:array<string,mixed>,timeout?:int,
 *              connect_timeout?:int,token?:string} $options
 *
 * @return array{ok:bool,http:int,error:string,message:string,loading:bool,
 *               transient:bool,retry_after:int,latency_ms:int,data:?array<string,mixed>}
 */
function imageIntHttpCall(string $url, array $options = []): array
{
    $method  = strtoupper((string) ($options['method'] ?? 'GET'));
    $timeout = max(5, min(1800, (int) ($options['timeout'] ?? 60)));
    $connect = max(1, min(60, (int) ($options['connect_timeout'] ?? 10)));
    $token   = (string) ($options['token'] ?? '');

    $result = [
        'ok'          => false,
        'http'        => 0,
        'error'       => '',
        'message'     => '',
        'loading'     => false,
        'transient'   => false,
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
        // The Retry-After header of a `transient` answer drives the retry loop,
        // so response headers are needed as well.
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
            $options['json'] ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $startedAt = microtime(true);
    $raw       = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlErr   = curl_error($ch);

    $result['http']       = $httpCode;
    $result['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

    if ($curlErr !== '') {
        $result['error'] = 'unreachable';
        $result['message'] = 'ImageInt nicht erreichbar: ' . $curlErr;
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

    $code      = is_array($data) ? (string) ($data['error'] ?? '') : '';
    $message   = is_array($data) ? trim((string) ($data['message'] ?? '')) : '';
    $status    = is_array($data) ? (string) ($data['status'] ?? '') : '';
    $transient = is_array($data) && !empty($data['transient']);

    if ($httpCode >= 200 && $httpCode < 300) {
        $result['ok']      = true;
        $result['message'] = $message;
        return $result;
    }

    // Still loading its models: not an error but a "try again in a moment"
    // signal. ImageInt marks those answers with `transient: true`; the
    // service_loading code is accepted as well so an older service version
    // behaves the same.
    if ($transient || $code === 'service_loading' || $status === 'loading' || $status === 'starting') {
        $result['transient']   = true;
        $result['loading']     = true;
        $result['message']     = $message !== '' ? $message : 'Das Bildmodell wird noch geladen.';
        $result['retry_after'] = $result['retry_after'] > 0 ? $result['retry_after'] : 15;
        return $result;
    }

    $result['error']   = $code !== '' ? $code : 'http_' . $httpCode;
    $result['message'] = $message !== '' ? $message : ('ImageInt meldet HTTP ' . $httpCode . '.');
    return $result;
}

/**
 * Download a binary resource (the PNG) from ImageInt.
 *
 * @return array{ok:bool,http:int,error:string,message:string,body:string,
 *               content_type:string,latency_ms:int}
 */
function imageIntDownloadImage(string $url, string $token = '', int $timeout = 60): array
{
    $result = [
        'ok'           => false,
        'http'         => 0,
        'error'        => '',
        'message'      => '',
        'body'         => '',
        'content_type' => '',
        'latency_ms'   => 0,
    ];

    $headers = [];
    if ($token !== '') {
        $headers[] = 'X-Auth-Token: ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(5, min(600, $timeout)),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $startedAt = microtime(true);
    $body      = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime      = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlErr   = curl_error($ch);

    $result['http']         = $httpCode;
    $result['content_type'] = $mime;
    $result['latency_ms']   = (int) round((microtime(true) - $startedAt) * 1000);

    if ($curlErr !== '') {
        $result['error']   = 'unreachable';
        $result['message'] = 'Das Bild konnte nicht abgerufen werden: ' . $curlErr;
        return $result;
    }

    if ($httpCode === 404) {
        $result['error']   = 'not_found';
        $result['message'] = 'Der Bildauftrag ist nicht mehr abrufbar (abgelaufen).';
        return $result;
    }

    if ($httpCode < 200 || $httpCode >= 300 || !is_string($body) || $body === '') {
        $result['error']   = 'http_' . $httpCode;
        $result['message'] = 'Das Bild konnte nicht abgerufen werden (HTTP ' . $httpCode . ').';
        return $result;
    }

    $result['ok']   = true;
    $result['body'] = $body;
    return $result;
}

// ── Readiness / health ────────────────────────────────────────────────────────

/**
 * Reachability and readiness of an ImageInt service (GET /v1/ready).
 *
 * `GET /v1/ready` answers 200 when every component is ready and 503 with
 * `Retry-After` while the models are still loading. Both cases are reported
 * separately: `ready` for the first, `loading` for the second. Only
 * `host_unsupported` is a permanent misconfiguration.
 *
 * @param array{timeout?:int,connect_timeout?:int} $options
 *
 * @return array{ok:bool,reachable:bool,loading:bool,ready:bool,status:string,
 *               message:string,retry_after:int,http:int,latency_ms:int,
 *               error:string,url:string,host:array<string,mixed>}
 */
function imageIntReady(?string $url = null, ?string $token = null, array $options = []): array
{
    $base   = $url === null ? imageIntUrl() : rtrim(trim($url), '/');
    $secret = $token === null ? imageIntToken() : trim($token);

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
        'error'       => '',
        'url'         => $base,
        'host'        => [],
    ];

    if ($base === '') {
        $health['error']   = 'not_configured';
        $health['message'] = 'Es ist kein Bild-Endpunkt konfiguriert.';
        return $health;
    }

    $response = imageIntHttpCall($base . '/v1/ready', [
        'timeout'         => max(5, min(600, (int) ($options['timeout'] ?? 15))),
        'connect_timeout' => max(1, min(60, (int) ($options['connect_timeout'] ?? 10))),
        'token'           => $secret,
    ]);

    $data = is_array($response['data']) ? $response['data'] : [];

    $health['reachable']   = $response['http'] > 0;
    $health['loading']     = $response['loading'];
    $health['status']      = (string) ($data['status'] ?? '');
    $health['http']        = $response['http'];
    $health['latency_ms']  = $response['latency_ms'];
    $health['retry_after'] = $response['retry_after'];
    $health['error']       = $response['error'];

    // `host_unsupported` is not a transient state: the service refuses to run
    // on this machine, and no amount of waiting will change that.
    if ($response['error'] === 'host_unsupported') {
        $health['message'] = $response['message'] !== ''
            ? $response['message']
            : 'ImageInt lehnt diesen Host ab (host_unsupported).';
        return $health;
    }

    if ($response['ok']) {
        $health['ready'] = true;
        $health['ok']    = true;
    }

    $message = trim((string) ($data['message'] ?? ''));
    if ($message === '') {
        $message = $response['message'];
    }
    if ($message === '') {
        $message = $health['ready']
            ? 'ImageInt ist bereit.'
            : ($health['loading']
                ? 'Das Bildmodell wird noch geladen.'
                : ($health['reachable'] ? 'ImageInt ist noch nicht bereit.' : 'ImageInt nicht erreichbar.'));
    }
    $health['message'] = $message;

    return $health;
}

/**
 * Per-component detail of an ImageInt service (GET /v1/health), including the
 * `host` sizing block the admin card shows.
 *
 * @return array{ok:bool,http:int,error:string,message:string,loading:bool,
 *               latency_ms:int,ready:bool,models:array<string,array<string,mixed>>,
 *               limits:array<string,mixed>,quant:string,
 *               host:array<string,mixed>,raw:array<string,mixed>}
 */
function imageIntHealth(?string $url = null, ?string $token = null, array $options = []): array
{
    $base   = $url === null ? imageIntUrl() : rtrim(trim($url), '/');
    $secret = $token === null ? imageIntToken() : trim($token);

    $result = [
        'ok'         => false,
        'http'       => 0,
        'error'      => '',
        'message'    => '',
        'loading'    => false,
        'latency_ms' => 0,
        'ready'      => false,
        'models'     => [],
        'limits'     => [],
        'quant'      => '',
        'host'       => [],
        'raw'        => [],
    ];

    if ($base === '') {
        $result['error']   = 'not_configured';
        $result['message'] = 'Es ist kein Bild-Endpunkt konfiguriert.';
        return $result;
    }

    $response = imageIntHttpCall($base . '/v1/health', [
        'timeout'         => max(5, min(600, (int) ($options['timeout'] ?? 15))),
        'connect_timeout' => max(1, min(60, (int) ($options['connect_timeout'] ?? 10))),
        'token'           => $secret,
    ]);

    $data = is_array($response['data']) ? $response['data'] : [];

    $result['http']       = $response['http'];
    $result['error']      = $response['error'];
    $result['message']    = $response['message'];
    $result['loading']    = $response['loading'];
    $result['latency_ms'] = $response['latency_ms'];
    $result['ok']         = $response['ok'];
    $result['ready']      = $response['ok'] && (bool) ($data['ready'] ?? true);
    $result['raw']        = $data;

    // Both model servers are reported individually under `components`
    // (keys `enhancer` and `image`). Older/newer shapes are tolerated.
    $componentSources = [];
    foreach (['components', 'enhancer', 'renderer', 'models'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $componentSources[] = $data[$key];
        }
    }
    foreach ($componentSources as $group) {
        foreach ($group as $name => $model) {
            if (!is_array($model) || isset($result['models'][(string) $name])) {
                continue;
            }
            $result['models'][(string) $name] = [
                'state'       => (string) ($model['state'] ?? ''),
                'state_label' => (string) ($model['state_label'] ?? ''),
                'ok'          => (bool) ($model['ready'] ?? $model['ok'] ?? false),
                'model'       => (string) ($model['model'] ?? ''),
                'message'     => (string) ($model['message'] ?? ''),
            ];
        }
    }

    $limits = $data['limits'] ?? [];
    $result['limits'] = is_array($limits) ? $limits : [];
    $result['quant']  = (string) ($data['quant'] ?? '');

    $host = $data['host'] ?? [];
    $result['host'] = is_array($host) ? $host : [];

    if (!$response['ok'] && $result['message'] === '') {
        $result['message'] = 'Der Zustand von ImageInt konnte nicht gelesen werden.';
    }

    return $result;
}

/**
 * State of both model servers (GET /v1/models). Answers 200 even while the
 * models are still loading, which makes it the second half of the admin
 * "Verbindung testen" probe.
 *
 * @return array{ok:bool,http:int,error:string,message:string,loading:bool,
 *               latency_ms:int,models:array<string,mixed>}
 */
function imageIntModels(?string $url = null, ?string $token = null): array
{
    $base   = $url === null ? imageIntUrl() : rtrim(trim($url), '/');
    $secret = $token === null ? imageIntToken() : trim($token);

    $result = [
        'ok'         => false,
        'http'       => 0,
        'error'      => '',
        'message'    => '',
        'loading'    => false,
        'latency_ms' => 0,
        'models'     => [],
    ];

    if ($base === '') {
        $result['error']   = 'not_configured';
        $result['message'] = 'Es ist kein Bild-Endpunkt konfiguriert.';
        return $result;
    }

    $response = imageIntHttpCall($base . '/v1/models', [
        'timeout' => 15,
        'token'   => $secret,
    ]);

    $result['http']       = $response['http'];
    $result['error']      = $response['error'];
    $result['message']    = $response['message'];
    $result['loading']    = $response['loading'];
    $result['latency_ms'] = $response['latency_ms'];
    $result['ok']         = $response['ok'];
    $result['models']     = is_array($response['data']) ? $response['data'] : [];

    return $result;
}

/**
 * Readiness of the active ImageInt endpoint for the admin dashboard graphic.
 *
 * The dashboard polls every 15 s, so the live probe is cached for 20 s in the
 * settings table – the same pattern speechDictationDashboardStatus() uses.
 *
 * @return array{configured:bool,url:string,source:string,ready:bool,loading:bool,
 *               reachable:bool,status:string,message:string,retry_after:int,
 *               checked_at:int,probed:bool}
 */
function imageIntDashboardStatus(bool $allowProbe = true): array
{
    $url = imageIntUrl();

    $status = [
        'configured'  => $url !== '',
        'url'         => $url,
        'source'      => imageIntUrlSource(),
        'ready'       => false,
        'loading'     => false,
        'reachable'   => false,
        'status'      => '',
        'message'     => '',
        'retry_after' => 0,
        'checked_at'  => time(),
        'probed'      => false,
    ];

    if ($url === '') {
        $status['message'] = 'Kein Bild-Endpunkt konfiguriert.';
        return $status;
    }

    $cacheKey = 'image_generation_status_cache';
    $cached   = json_decode((string) getSetting($cacheKey, ''), true);
    if (is_array($cached) && ($cached['url'] ?? '') === $url
        && (time() - (int) ($cached['checked_at'] ?? 0)) < 20) {
        return $cached + $status;
    }

    if (!$allowProbe) {
        $status['message']   = 'Noch nicht geprüft.';
        $status['reachable'] = is_array($cached) && ($cached['url'] ?? '') === $url
            ? (bool) ($cached['reachable'] ?? false)
            : false;
        return $status;
    }

    $health = imageIntReady($url, null, ['timeout' => 4, 'connect_timeout' => 2]);
    $status = [
        'configured'  => true,
        'url'         => $url,
        'source'      => imageIntUrlSource(),
        'ready'       => $health['ready'],
        'loading'     => $health['loading'],
        'reachable'   => $health['reachable'],
        'status'      => $health['status'],
        'message'     => $health['message'],
        'retry_after' => $health['retry_after'],
        'checked_at'  => time(),
        'probed'      => true,
    ];

    try {
        setSetting($cacheKey, json_encode($status, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $_e) {
        // A missing cache is harmless – the next poll simply probes again.
    }

    return $status;
}

// ── Job helpers ───────────────────────────────────────────────────────────────

/** Progress message for an ImageInt job stage. */
function imageIntStageMessage(string $stage): string
{
    switch ($stage) {
        case 'enhancing':
            return 'Der Bildwunsch wird ausgearbeitet …';
        case 'rendering':
            return 'Das Bild wird gezeichnet …';
        case 'done':
            return 'Das Bild ist fertig.';
        case 'error':
            return 'Die Bildgenerierung ist fehlgeschlagen.';
        case 'queued':
        default:
            return 'Der Auftrag steht in der Warteschlange …';
    }
}

/** German label of an ImageInt job stage. */
function imageIntStageLabel(string $stage): string
{
    switch ($stage) {
        case 'enhancing':
            return 'Prompt wird ausgearbeitet';
        case 'rendering':
            return 'Bild wird gezeichnet';
        case 'done':
            return 'fertig';
        case 'error':
            return 'Fehler';
        case 'queued':
        default:
            return 'in der Warteschlange';
    }
}

/**
 * Render a duration in milliseconds as "2:41 Minuten" for the {duration}
 * placeholder and the status line.
 */
function imageIntFormatDuration(int $milliseconds): string
{
    if ($milliseconds <= 0) {
        return 'unbekannt';
    }
    $totalSeconds = (int) round($milliseconds / 1000);
    $minutes      = intdiv($totalSeconds, 60);
    $seconds      = $totalSeconds % 60;
    if ($minutes <= 0) {
        return $seconds . ' Sekunden';
    }
    return $minutes . ':' . str_pad((string) $seconds, 2, '0', STR_PAD_LEFT) . ' Minuten';
}

/**
 * Total duration of a job document in milliseconds.
 *
 * ImageInt reports `timings.enhance` / `timings.render` in seconds in its
 * documented 200/202 body and `timings.*_ms` in the job document; both shapes
 * are accepted so a change on the service side cannot silently produce a
 * "0 Sekunden" status line.
 *
 * @param array<string,mixed> $job
 */
function imageIntJobDurationMs(array $job): int
{
    $timings = is_array($job['timings'] ?? null) ? $job['timings'] : [];

    $total = 0;
    foreach (['total_ms', 'total'] as $key) {
        if (isset($timings[$key]) && is_numeric($timings[$key])) {
            $total = (int) round((float) $timings[$key]);
            break;
        }
    }
    if ($total > 0) {
        return $total;
    }

    foreach (['enhance_ms', 'render_ms', 'enhance', 'render'] as $key) {
        if (!isset($timings[$key]) || !is_numeric($timings[$key])) {
            continue;
        }
        $value = (float) $timings[$key];
        // Keys without the _ms suffix are seconds.
        $total += (int) round(str_ends_with($key, '_ms') ? $value : $value * 1000);
    }

    if ($total > 0) {
        return $total;
    }

    // Last resort: the wall clock between start and finish.
    if (isset($job['created_at'], $job['finished_at'])
        && is_numeric($job['created_at']) && is_numeric($job['finished_at'])) {
        return max(0, (int) round(((float) $job['finished_at'] - (float) $job['created_at']) * 1000));
    }

    return 0;
}

/**
 * Reduce a full job document to what the browser may see.
 *
 * The ImageInt URLs point at the service host (e.g. http://imageint:8080),
 * which a browser usually cannot reach, and they are an internal detail.
 * api/image_status.php resolves them server-side from the stored job record,
 * so the client only needs the job id.
 *
 * @param array<string,mixed> $job
 * @return array<string,mixed>
 */
function imageIntClientJob(array $job): array
{
    $client = [
        'job_id'             => (string) ($job['job_id'] ?? ''),
        'status'             => (string) ($job['status'] ?? ''),
        'stage'              => (string) ($job['stage'] ?? ''),
        'stage_label'        => imageIntStageLabel((string) ($job['stage'] ?? '')),
        'poll_after_seconds' => (int) ($job['poll_after_seconds'] ?? IMAGE_INT_POLL_INTERVAL_DEFAULT),
        'message'            => (string) ($job['message'] ?? ''),
        'prompt'             => (string) ($job['prompt'] ?? ''),
        'session_id'         => (string) ($job['session_id'] ?? ''),
    ];
    // Only the locally cached PNG is passed through. While a job is still
    // running `image_url` is the ImageInt URL (service host, token-protected),
    // which a browser cannot reach – the client learns the local path from
    // api/image_status.php once the job is done.
    $imageUrl = (string) ($job['image_url'] ?? '');
    if ($imageUrl !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $imageUrl)) {
        $client['image_url'] = $imageUrl;
    }
    if (isset($job['width'])) {
        $client['width'] = (int) $job['width'];
    }
    if (isset($job['height'])) {
        $client['height'] = (int) $job['height'];
    }
    if (isset($job['duration_ms'])) {
        $client['duration_ms'] = (int) $job['duration_ms'];
    }
    return $client;
}

/** Display name of a user – the users table has no separate display column. */
function imageIntUserName(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    try {
        $stmt = getDb()->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Render the consent question with the placeholders that are already known
 * when the job is handed to the browser. Placeholders that only exist once the
 * render has finished ({chat_url}, {image_url}, {duration}, {width},
 * {height}) are removed instead of being shown as literal braces.
 *
 * @param array<string,string> $vars
 */
function imageIntRenderConsentText(array $vars): string
{
    $text = renderImageNotificationTemplate(getImageNotifyConsentText(), $vars);
    foreach (array_keys(imageGenerationPlaceholderHelp()) as $key) {
        $text = str_replace('{' . $key . '}', '', $text);
    }
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    return trim((string) $text);
}

/**
 * Status of the notification row for a job, or '' when the user was never
 * asked / never asked for one.
 */
function imageIntNotificationStatus(string $jobId, int $userId): string
{
    if ($jobId === '' || $userId <= 0) {
        return '';
    }
    try {
        $stmt = getDb()->prepare(
            'SELECT status FROM image_notifications WHERE job_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$jobId, $userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Everything the chat frontend needs to render the consent question and the
 * "notification requested" confirmation.
 *
 * @return array{enabled:bool,email:string,consent_text:string,requested:bool}
 */
function imageIntNotifyClientPayload(int $userId, string $prompt = '', bool $requested = false): array
{
    $email = '';
    if ($userId > 0) {
        try {
            $stmt = getDb()->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $email = trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $email = '';
        }
    }

    return [
        // Without an address in the user profile there is nothing to ask for.
        'enabled'      => imageNotifyEnabled() && $email !== '',
        'email'        => $email,
        'consent_text' => imageIntRenderConsentText([
            'sitename' => imageIntSiteName(),
            'username' => imageIntUserName($userId),
            'email'    => $email,
            'prompt'   => $prompt,
        ]),
        'requested'    => $requested,
    ];
}

// ── Local PNG cache ───────────────────────────────────────────────────────────
/** Absolute path of the directory the generated PNGs are cached in. */
function imageIntOutputDir(): string
{
    return dirname(__DIR__) . '/' . IMAGE_INT_OUTPUT_DIR;
}

/** File name of the cached PNG for one job id. */
function imageIntJobFileName(string $jobId): string
{
    return 'imageint-' . $jobId . '.png';
}

/**
 * Store a PNG in the local cache and return its path relative to the
 * application root (the value used in the markdown image).
 *
 * @return string Empty string when the payload is not a usable PNG.
 */
function imageIntStoreImage(string $jobId, string $binary): string
{
    if ($jobId === '' || $binary === '' || preg_match('/^[a-f0-9]{4,64}$/i', $jobId) !== 1) {
        return '';
    }

    $dir = imageIntOutputDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return '';
    }

    $target = $dir . '/' . imageIntJobFileName($jobId);
    if (@file_put_contents($target, $binary) === false) {
        return '';
    }
    @chmod($target, 0644);

    return IMAGE_INT_OUTPUT_DIR . '/' . imageIntJobFileName($jobId);
}

/**
 * Make sure the PNG of a job is cached locally and return its relative path.
 *
 * Returns an empty string when the image cannot be obtained (expired job,
 * unreachable service, non-PNG payload) – the caller then reports
 * `image_url_error` instead of linking a broken image.
 */
function imageIntEnsureImage(array $job): string
{
    $jobId = (string) ($job['job_id'] ?? '');
    if ($jobId === '' || preg_match('/^[a-f0-9]{4,64}$/i', $jobId) !== 1) {
        return '';
    }

    $relative = IMAGE_INT_OUTPUT_DIR . '/' . imageIntJobFileName($jobId);
    if (is_file(imageIntOutputDir() . '/' . imageIntJobFileName($jobId))) {
        return $relative;
    }

    // A 200 answer carries the PNG inline; only fall back to a download when it
    // is missing.
    $b64 = (string) ($job['b64_json'] ?? '');
    if ($b64 !== '') {
        $decoded = base64_decode($b64, true);
        if (is_string($decoded) && $decoded !== '') {
            $stored = imageIntStoreImage($jobId, $decoded);
            if ($stored !== '') {
                return $stored;
            }
        }
    }

    $url = (string) ($job['image_url'] ?? '');
    if ($url === '') {
        $base = imageIntUrl();
        $url  = $base === '' ? '' : $base . '/v1/images/' . rawurlencode($jobId);
    }
    if ($url === '') {
        return '';
    }

    $download = imageIntDownloadImage($url, imageIntToken());
    if (!$download['ok']) {
        return '';
    }
    return imageIntStoreImage($jobId, $download['body']);
}

// ── Generation ────────────────────────────────────────────────────────────────

/**
 * Poll a job document until it reaches a terminal state or the deadline passes.
 *
 * @param array<string,mixed> $job The 202 body (carries status_url).
 *
 * @return array{job:array<string,mixed>,timeout:bool,polls:int}
 */
function imageIntPollJob(array $job, int $deadline, string $token, ?callable $onProgress = null): array
{
    $statusUrl = (string) ($job['status_url'] ?? '');
    $interval  = (int) ($job['poll_after_seconds'] ?? 0);
    $interval  = $interval > 0 ? min(60, $interval) : IMAGE_INT_POLL_INTERVAL_DEFAULT;
    $polls     = 0;

    if ($statusUrl === '') {
        return ['job' => $job, 'timeout' => false, 'polls' => 0];
    }

    while (time() < $deadline) {
        $status = (string) ($job['status'] ?? '');
        if ($status === 'done' || $status === 'error') {
            return ['job' => $job, 'timeout' => false, 'polls' => $polls];
        }

        // Never sleep past the deadline.
        $sleep = min($interval, max(0, $deadline - time()));
        if ($sleep <= 0) {
            break;
        }
        sleep($sleep);

        $response = imageIntHttpCall($statusUrl, ['timeout' => 30, 'token' => $token]);
        $polls++;

        if (!$response['ok']) {
            if ($response['loading']) {
                // The service is still loading; the job stays queued.
                continue;
            }
            if ($response['error'] === 'not_found') {
                $job['status']  = 'error';
                $job['stage']   = 'error';
                $job['error']   = 'not_found';
                $job['message'] = $response['message'] !== ''
                    ? $response['message']
                    : 'Der Bildauftrag ist nicht mehr abrufbar (abgelaufen).';
                return ['job' => $job, 'timeout' => false, 'polls' => $polls];
            }
            if ($response['error'] === 'unreachable') {
                // A single dropped connection must not kill a running render.
                continue;
            }
            $job['status']  = 'error';
            $job['stage']   = 'error';
            $job['error']   = $response['error'];
            $job['message'] = $response['message'];
            return ['job' => $job, 'timeout' => false, 'polls' => $polls];
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        // The status document is the same shape as the 200 answer; keep the
        // values we already know in case the service omits them.
        $job = array_merge($job, $data);
        $job['status_url'] = $statusUrl;

        $stage = (string) ($job['stage'] ?? '');
        if ($onProgress !== null) {
            $onProgress($stage, $polls);
        }
    }

    return ['job' => $job, 'timeout' => true, 'polls' => $polls];
}

/**
 * Start an image job on ImageInt and wait for it inside a bounded window.
 *
 * @param array<string,mixed> $args      Tool arguments (prompt, negative_prompt, size).
 * @param int                 $userId    Logged-in user (0 for anonymous).
 * @param string              $sessionId Chat session the job belongs to.
 *
 * @return array<string,mixed> Either a finished image (`status` = `done`,
 *                             `image_url`, `width`, `height`, `duration_ms`) or
 *                             a pending job (`pending` = true plus the keys of
 *                             imageIntClientJob()) or an error (`error`,
 *                             `message`). `loading` = true marks a waiting
 *                             state that is not an error.
 */
function imageIntGenerate(array $args, int $userId = 0, string $sessionId = ''): array
{
    $prompt = trim((string) ($args['prompt'] ?? ''));
    if ($prompt === '') {
        return [
            'error'   => 'bad_request',
            'message' => 'Es wurde keine Bildbeschreibung übergeben.',
        ];
    }

    $base = imageIntUrl();
    if ($base === '') {
        return [
            'error'   => 'not_configured',
            'message' => 'Die Bildgenerierung ist nicht konfiguriert (kein Bild-Endpunkt hinterlegt).',
        ];
    }

    $token    = imageIntToken();
    $deadline = time() + imageIntTimeout();

    writeLog('info', 'Bildgenerierung angefordert (' . mb_strlen($prompt) . ' Zeichen Prompt).');

    // 1. Readiness pre-check. A `transient` 503 means "still loading", which is
    //    retried a few times before the user is told to try again later.
    $ready = imageIntReady($base, $token, ['timeout' => 20]);
    $attempts = 1;
    while (!$ready['ok'] && $ready['loading'] && $attempts < 4) {
        $wait = min(60, max(5, (int) $ready['retry_after']));
        if (time() + $wait >= $deadline) {
            break;
        }
        writeLog('info', 'ImageInt lädt noch – erneuter Versuch in ' . $wait . ' s.');
        sleep($wait);
        $ready = imageIntReady($base, $token, ['timeout' => 20]);
        $attempts++;
    }

    if (!$ready['ok']) {
        if ($ready['loading']) {
            // Not an error: the model is still being loaded. The caller shows a
            // waiting message and the request can simply be repeated.
            return [
                'status'      => 'loading',
                'loading'     => true,
                'retry_after' => max(15, (int) $ready['retry_after']),
                'message'     => 'Das Bildmodell lädt noch, einen Moment bitte. '
                    . 'Bitte versuche es in ein paar Minuten erneut.',
            ];
        }
        return [
            'error'   => $ready['error'] !== '' ? $ready['error'] : 'unreachable',
            'message' => $ready['message'],
        ];
    }

    // 2. Create the job. `wait: false` makes ImageInt answer 202 immediately
    //    instead of holding the PHP request open for the whole render.
    $payload = ['prompt' => $prompt, 'wait' => false];
    $negative = trim((string) ($args['negative_prompt'] ?? ''));
    if ($negative !== '') {
        $payload['negative_prompt'] = $negative;
    }
    $size = trim((string) ($args['size'] ?? ''));
    if ($size !== '') {
        $payload['size'] = $size;
    }

    $startedAt = microtime(true);
    $response  = imageIntHttpCall($base . '/v1/images/generations', [
        'method'  => 'POST',
        'json'    => $payload,
        'timeout' => min(120, max(30, imageIntSyncTimeout())),
        'token'   => $token,
    ]);

    if (!$response['ok']) {
        writeLog('warning', 'Bildgenerierung fehlgeschlagen: '
            . ($response['error'] !== '' ? $response['error'] : 'http_' . $response['http'])
            . ' – ' . $response['message']);
        if ($response['loading']) {
            return [
                'status'      => 'loading',
                'loading'     => true,
                'retry_after' => max(15, (int) $response['retry_after']),
                'message'     => $response['message'] !== ''
                    ? $response['message']
                    : 'Das Bildmodell lädt noch, einen Moment bitte.',
            ];
        }
        return [
            'error'   => $response['error'] !== '' ? $response['error'] : 'http_' . $response['http'],
            'message' => $response['message'],
        ];
    }

    $job = is_array($response['data']) ? $response['data'] : [];
    $job['prompt'] = $prompt;
    if ($sessionId !== '') {
        $job['session_id'] = $sessionId;
    }

    // 3. Poll while the job is queued or running – but only for as long as one
    //    PHP request may reasonably block.
    $syncDeadline = min($deadline, time() + imageIntSyncTimeout());
    $lastStage = '';
    $polled = imageIntPollJob($job, $syncDeadline, $token, static function (string $stage) use (&$lastStage): void {
        if ($stage !== '' && $stage !== $lastStage) {
            $lastStage = $stage;
            writeLog('info', 'Bildauftrag: ' . imageIntStageMessage($stage));
        }
    });
    $job = $polled['job'];

    $status = (string) ($job['status'] ?? '');
    $job['duration_ms'] = imageIntJobDurationMs($job);

    if ($status === 'done') {
        $relative = imageIntEnsureImage($job);
        if ($relative === '') {
            return [
                'error'   => 'image_unavailable',
                'message' => 'Das Bild wurde erzeugt, konnte aber nicht abgerufen werden.',
                'job_id'  => (string) ($job['job_id'] ?? ''),
            ];
        }
        $job['image_url'] = $relative;
        $job['pending']   = false;
        unset($job['b64_json']);
        writeLog('info', 'Bildgenerierung abgeschlossen in '
            . imageIntFormatDuration((int) $job['duration_ms'])
            . ' (' . (int) round(microtime(true) - $startedAt) . ' ms PHP-Zeit).');
        return $job;
    }

    if ($status === 'error') {
        return [
            'error'   => (string) ($job['error'] ?? 'image_error'),
            'message' => (string) ($job['message'] ?? 'Die Bildgenerierung ist fehlgeschlagen.'),
            'job_id'  => (string) ($job['job_id'] ?? ''),
        ];
    }

    // 4. Still running: hand the job back. The browser polls
    //    api/image_status.php, and the notification worker delivers the mail.
    unset($job['b64_json']);
    $job['pending'] = true;
    if (($job['message'] ?? '') === '') {
        $job['message'] = imageIntStageMessage((string) ($job['stage'] ?? 'queued'));
    }
    writeLog('info', 'Bildauftrag ' . (string) ($job['job_id'] ?? '?')
        . ' läuft weiter (Stage: ' . (string) ($job['stage'] ?? '') . ') und wird im Browser nachgeladen.');
    return $job;
}

/**
 * Read one job document from ImageInt (GET {status_url}).
 *
 * @return array{ok:bool,error:string,message:string,http:int,job:array<string,mixed>,expired:bool}
 */
function imageIntFetchJob(string $statusUrl, string $token = ''): array
{
    $result = [
        'ok'      => false,
        'error'   => '',
        'message' => '',
        'http'    => 0,
        'job'     => [],
        'expired' => false,
    ];

    $statusUrl = trim($statusUrl);
    if ($statusUrl === '') {
        $result['error']   = 'not_configured';
        $result['message'] = 'Zu diesem Auftrag ist keine Status-URL bekannt.';
        return $result;
    }

    $response = imageIntHttpCall($statusUrl, ['timeout' => 30, 'token' => $token]);

    $result['http']    = $response['http'];
    $result['error']   = $response['error'];
    $result['message'] = $response['message'];

    if ($response['ok']) {
        $result['ok']  = true;
        $result['job'] = is_array($response['data']) ? $response['data'] : [];
        return $result;
    }

    if ($response['error'] === 'not_found') {
        $result['expired'] = true;
        $result['message'] = 'Der Bildauftrag ist nicht mehr abrufbar: ImageInt bewahrt Aufträge nur '
            . 'begrenzte Zeit auf (Standard 24 Stunden).';
        return $result;
    }

    if ($response['loading']) {
        $result['message'] = $response['message'] !== ''
            ? $response['message']
            : 'Das Bildmodell lädt noch.';
        return $result;
    }

    if ($result['message'] === '') {
        $result['message'] = 'Der Bildauftrag konnte nicht abgefragt werden.';
    }
    return $result;
}

/**
 * Load a job document via the status URL of a stored job record and cache the
 * PNG once the job is done.
 *
 * @param array<string,mixed> $record Job record with `status_url` and `job_id`.
 *
 * @return array{ok:bool,status:string,stage:string,message:string,expired:bool,
 *               error:string,job:array<string,mixed>,image_url:string}
 */
function imageIntResolveJob(array $record): array
{
    $jobId     = (string) ($record['job_id'] ?? '');
    $statusUrl = (string) ($record['status_url'] ?? '');

    $result = [
        'ok'        => false,
        'status'    => (string) ($record['status'] ?? ''),
        'stage'     => (string) ($record['stage'] ?? ''),
        'message'   => '',
        'expired'   => false,
        'error'     => '',
        'job'       => [],
        'image_url' => (string) ($record['image_url'] ?? ''),
    ];

    // A job whose PNG is already cached needs no round-trip: the image stays
    // visible even after ImageInt has dropped the job. The job document is then
    // gone, so width/height come from the cached file itself and the duration
    // from whatever the caller already knows about the job.
    $cached = $jobId !== '' ? imageIntOutputDir() . '/' . imageIntJobFileName($jobId) : '';
    if ($cached !== '' && is_file($cached)) {
        $job = ['job_id' => $jobId];
        $size = @getimagesize($cached);
        if (is_array($size)) {
            $job['width']  = (int) $size[0];
            $job['height'] = (int) $size[1];
        }
        foreach (['prompt', 'width', 'height', 'duration_ms'] as $key) {
            if (!isset($job[$key]) && isset($record[$key]) && $record[$key] !== '') {
                $job[$key] = $record[$key];
            }
        }
        // Callers that need the exact timings (the notification worker, which
        // reports the render duration in the mail) ask for one enrichment
        // round-trip. The browser's polling path stays round-trip free.
        if (!empty($record['enrich']) && (int) ($job['duration_ms'] ?? 0) <= 0) {
            $enriched = imageIntFetchJob($statusUrl, imageIntToken());
            if ($enriched['ok'] && is_array($enriched['job'])) {
                foreach (['prompt', 'width', 'height', 'seed', 'timings'] as $key) {
                    if (isset($enriched['job'][$key]) && $enriched['job'][$key] !== '') {
                        $job[$key] = $enriched['job'][$key];
                    }
                }
                $job['duration_ms'] = imageIntJobDurationMs($enriched['job']);
            }
        }
        $result['ok']        = true;
        $result['status']    = 'done';
        $result['stage']     = 'done';
        $result['job']       = $job;
        $result['message']   = imageIntStageMessage('done');
        $result['image_url'] = IMAGE_INT_OUTPUT_DIR . '/' . imageIntJobFileName($jobId);
        return $result;
    }

    $fetched = imageIntFetchJob($statusUrl, imageIntToken());
    if ($fetched['expired']) {
        $result['expired'] = true;
        $result['status']  = 'expired';
        $result['error']   = 'not_found';
        $result['message'] = $fetched['message'];
        return $result;
    }
    if (!$fetched['ok']) {
        $result['status']  = (string) ($record['status'] ?? 'running');
        $result['error']   = $fetched['error'] !== '' ? $fetched['error'] : 'unavailable';
        $result['message'] = $fetched['message'];
        return $result;
    }

    $job = $fetched['job'];
    if ($jobId === '') {
        $jobId = (string) ($job['job_id'] ?? '');
    }
    $job['job_id']    = $jobId;
    $job['status_url'] = $statusUrl;
    if (!isset($job['prompt']) || (string) $job['prompt'] === '') {
        $job['prompt'] = (string) ($record['prompt'] ?? '');
    }

    $status = (string) ($job['status'] ?? '');
    $result['job']    = $job;
    $result['status'] = $status;
    $result['stage']  = (string) ($job['stage'] ?? '');

    if ($status === 'done') {
        $relative = imageIntEnsureImage($job);
        if ($relative === '') {
            $result['status']  = 'done';
            $result['error']   = 'image_unavailable';
            $result['message'] = 'Das Bild wurde erzeugt, konnte aber nicht abgerufen werden.';
            return $result;
        }
        $result['ok']        = true;
        $result['image_url'] = $relative;
        $result['message']   = imageIntStageMessage('done');
        return $result;
    }

    if ($status === 'error') {
        $result['error']   = (string) ($job['error'] ?? 'image_error');
        $result['message'] = (string) ($job['message'] ?? 'Die Bildgenerierung ist fehlgeschlagen.');
        return $result;
    }

    $result['ok']      = true;
    $result['message'] = imageIntStageMessage($result['stage'] !== '' ? $result['stage'] : $status);
    return $result;
}

// ── Chat history integration ──────────────────────────────────────────────────

/**
 * Markdown block that shows a finished image in the chat.
 */
function imageIntMarkdown(string $relativeImageUrl): string
{
    return '![Generiertes Bild](' . $relativeImageUrl . ')';
}

/**
 * Find the stored job record for one job id inside a session's message history.
 *
 * The record is persisted on the assistant message that requested the image, so
 * the deep link keeps working after ImageInt has dropped the job and no extra
 * table is needed.
 *
 * @param array<int,array<string,mixed>> $messages
 * @return array<string,mixed>|null
 */
function imageIntFindJobInMessages(array $messages, string $jobId): ?array
{
    if ($jobId === '') {
        return null;
    }
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $message = $messages[$i];
        if (!is_array($message)) {
            continue;
        }
        $record = $message['image_job'] ?? null;
        if (is_array($record) && (string) ($record['job_id'] ?? '') === $jobId) {
            return $record;
        }
    }
    return null;
}

/**
 * Update the stored job record of a session in place.
 *
 * Used by api/image_status.php once the render is done: the assistant message
 * then carries the local PNG path, so the image is still there when ImageInt
 * has already forgotten the job.
 *
 * @param array<int,array<string,mixed>> $messages
 * @param array<string,mixed>            $patch
 * @return array<int,array<string,mixed>>
 */
function imageIntPatchJobInMessages(array $messages, string $jobId, array $patch): array
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $record = $messages[$i]['image_job'] ?? null;
        if (!is_array($record) || (string) ($record['job_id'] ?? '') !== $jobId) {
            continue;
        }
        $messages[$i]['image_job'] = array_merge($record, $patch);
        break;
    }
    return $messages;
}

/** Whether a message history already contains the given image path. */
function imageIntMessagesContainImage(array $messages, string $relativeImageUrl): bool
{
    if ($relativeImageUrl === '') {
        return false;
    }
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $content = $message['content'] ?? '';
        if (is_string($content) && str_contains($content, $relativeImageUrl)) {
            return true;
        }
    }
    return false;
}

// ── Notification queue and worker ─────────────────────────────────────────────

/**
 * Record the user's consent and queue the notification mail.
 *
 * The unique key (job_id, user_id) makes the call idempotent: clicking "Ja"
 * twice still produces exactly one mail.
 *
 * @return array{ok:bool,already:bool,error:string,message:string}
 */
function imageIntQueueNotification(string $jobId, int $userId, string $sessionId, string $prompt, string $statusUrl, string $imageUrl): array
{
    if ($jobId === '' || $userId <= 0) {
        return ['ok' => false, 'already' => false, 'error' => 'bad_request', 'message' => 'Unvollständige Benachrichtigungsdaten.'];
    }

    try {
        $db = getDb();
        $existing = $db->prepare(
            'SELECT status FROM image_notifications WHERE job_id = ? AND user_id = ? LIMIT 1'
        );
        $existing->execute([$jobId, $userId]);
        $row = $existing->fetch();

        if ($row !== false) {
            return [
                'ok'      => true,
                'already' => true,
                'error'   => '',
                'message' => $row['status'] === 'sent'
                    ? 'Die Benachrichtigung wurde bereits versendet.'
                    : 'Die Benachrichtigung ist bereits vorgemerkt.',
            ];
        }

        $db->prepare(
            'INSERT INTO image_notifications
                 (job_id, user_id, session_id, prompt, status_url, image_url, status)
             VALUES (?, ?, ?, ?, ?, ?, \'pending\')'
        )->execute([$jobId, $userId, substr($sessionId, 0, 64), $prompt, $statusUrl, $imageUrl]);
    } catch (Throwable $e) {
        return [
            'ok'      => false,
            'already' => false,
            'error'   => 'db_error',
            'message' => 'Die Benachrichtigung konnte nicht vorgemerkt werden.',
        ];
    }

    return ['ok' => true, 'already' => false, 'error' => '', 'message' => ''];
}

/**
 * Open notification rows, oldest first.
 *
 * @return array<int,array<string,mixed>>
 */
function imageIntPendingNotifications(int $limit = 20): array
{
    $limit = max(1, min(200, $limit));
    try {
        $stmt = getDb()->prepare(
            'SELECT n.*, u.username, u.email
               FROM image_notifications n
               LEFT JOIN users u ON u.id = n.user_id
              WHERE n.status = \'pending\'
              ORDER BY n.created_at ASC
              LIMIT ' . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }
    return is_array($rows) ? $rows : [];
}

/**
 * Write a finished render back into the stored job record of its chat session.
 *
 * The notification worker runs from cron and is therefore often the first thing
 * that sees a finished job – typically while no browser is watching. Persisting
 * the result here keeps the chat in step with the mail: image path, dimensions
 * and render duration are then already there when the deep link is opened, and
 * the polling browser finds them even when the PNG was cached by the worker
 * before its own status request.
 *
 * @param array<string,mixed> $job Resolved job document (with `duration_ms`).
 */
function imageIntPersistJobResult(int $userId, string $sessionId, string $jobId, array $job, string $imageUrl): void
{
    if ($userId <= 0 || $jobId === '' || !preg_match('/^[a-f0-9]{8,128}$/', $sessionId)) {
        return;
    }

    $patch = [
        'status'    => 'done',
        'stage'     => 'done',
        'pending'   => false,
        'message'   => imageIntStageMessage('done'),
        'image_url' => $imageUrl,
    ];
    foreach (['width', 'height', 'duration_ms'] as $key) {
        $value = (int) ($job[$key] ?? 0);
        if ($value > 0) {
            $patch[$key] = $value;
        }
    }

    try {
        $session = loadUserConversationSession($sessionId, $userId);
        if ($session === null) {
            return;
        }
        $messages = imageIntPatchJobInMessages($session['messages'], $jobId, $patch);
        saveConversationSession($sessionId, $session['model'], $messages, $userId);
    } catch (Throwable $e) {
        writeLog('warning', 'Bildauftrag ' . $jobId . ': Ergebnis konnte nicht im Chat gespeichert werden – ' . $e->getMessage());
    }
}

/** Update the state of one notification row. */
function imageIntMarkNotification(int $id, string $status, string $error = ''): void
{
    if ($id <= 0) {
        return;
    }
    try {
        $sentAt = $status === 'sent' ? 'NOW(3)' : 'NULL';
        getDb()->prepare(
            'UPDATE image_notifications
                SET status = ?, error = ?, sent_at = ' . $sentAt . '
              WHERE id = ?'
        )->execute([$status, mb_substr($error, 0, 500), $id]);
    } catch (Throwable $_e) {
        // Best effort – the next worker run simply tries again.
    }
}

/**
 * Placeholder values for the consent question and the notification mail.
 *
 * @param array<string,mixed> $job
 * @param array<string,string> $imageUrlOverrides
 * @return array<string,string>
 */
function imageIntTemplateVars(array $job, string $username, string $email, string $sessionId, string $imageUrl = ''): array
{
    $width  = isset($job['width']) ? (int) $job['width'] : 0;
    $height = isset($job['height']) ? (int) $job['height'] : 0;
    $jobId  = (string) ($job['job_id'] ?? '');
    $duration = (string) ($job['duration_label'] ?? '');

    if ($duration === '') {
        $durationMs = (int) ($job['duration_ms'] ?? 0);
        $duration = $durationMs > 0 ? imageIntFormatDuration($durationMs) : 'noch unbekannt';
    }

    return [
        'sitename'  => imageIntSiteName(),
        'username'  => $username !== '' ? $username : 'Nutzer',
        'email'     => $email,
        'prompt'    => (string) ($job['prompt'] ?? ''),
        'chat_url'  => imageIntChatUrl($sessionId, $jobId),
        'image_url' => $imageUrl,
        'duration'  => $duration,
        'width'     => $width > 0 ? (string) $width : '–',
        'height'    => $height > 0 ? (string) $height : '–',
        'job_id'    => $jobId,
    ];
}

/**
 * Send the "your image is ready" mail for one notification row.
 *
 * @param array<string,mixed> $notification Row from image_notifications.
 * @param array<string,mixed> $job          Finished job document.
 * @param string              $imageUrl     Absolute URL of the PNG.
 *
 * @return array{ok:bool,message:string}
 */
function imageIntSendNotificationEmail(array $notification, array $job, string $imageUrl): array
{
    $email = trim((string) ($notification['email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'message' => 'Für den Nutzer ist keine E-Mail-Adresse hinterlegt.'];
    }

    $username = trim((string) ($notification['username'] ?? ''));
    $vars = imageIntTemplateVars(
        $job,
        $username,
        $email,
        (string) ($notification['session_id'] ?? ''),
        $imageUrl
    );

    $subject = renderImageNotificationTemplate(getImageNotifyEmailSubject(), $vars);
    $body    = renderImageNotificationTemplate(getImageNotifyEmailBody(), $vars);

    $textBody = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
    $htmlBody = '<html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">'
        . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
        . '</body></html>';

    try {
        sendMail($email, $username !== '' ? $username : $email, $subject, $textBody, $htmlBody);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Drain the notification queue: poll every open job and send the mail once the
 * render is done.
 *
 * This is the primary delivery path – it runs from cron/CLI and therefore works
 * with the browser tab closed, which a client-side trigger cannot do.
 *
 * @param callable(string):void|null $log
 *
 * @return array{checked:int,sent:int,failed:int,expired:int,waiting:int}
 */
function imageIntProcessNotifications(int $limit = 20, ?callable $log = null): array
{
    $stats = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0, 'waiting' => 0];
    $logger = $log ?? static function (string $message): void {
        writeLog('info', '[image_notify_worker] ' . $message);
    };

    foreach (imageIntPendingNotifications($limit) as $notification) {
        $stats['checked']++;
        $id     = (int) $notification['id'];
        $jobId  = (string) $notification['job_id'];
        $record = [
            'job_id'     => $jobId,
            'status_url' => (string) $notification['status_url'],
            'prompt'     => (string) $notification['prompt'],
            'image_url'  => (string) $notification['image_url'],
            'enrich'     => true,
        ];

        $resolved = imageIntResolveJob($record);

        if ($resolved['expired']) {
            imageIntMarkNotification($id, 'expired', $resolved['message']);
            $stats['expired']++;
            $logger('Auftrag ' . $jobId . ' ist abgelaufen, bevor die Mail rausging.');
            continue;
        }

        if (($resolved['job']['status'] ?? '') === 'error' || $resolved['error'] === 'image_error') {
            $message = $resolved['message'] !== '' ? $resolved['message'] : 'Die Bildgenerierung ist fehlgeschlagen.';
            imageIntMarkNotification($id, 'failed', $message);
            $stats['failed']++;
            $logger('Auftrag ' . $jobId . ' ist fehlgeschlagen: ' . $message);
            continue;
        }

        if (!$resolved['ok'] || $resolved['status'] !== 'done') {
            // Still queued/running or temporarily unreachable – try again next run.
            $stats['waiting']++;
            $logger('Auftrag ' . $jobId . ' läuft noch ('
                . ($resolved['status'] !== '' ? $resolved['status'] : 'unbekannt') . ').');
            continue;
        }

        $job = $resolved['job'];
        if (!isset($job['prompt']) || (string) $job['prompt'] === '') {
            $job['prompt'] = (string) $notification['prompt'];
        }
        $job['duration_ms'] = (int) ($job['duration_ms'] ?? imageIntJobDurationMs($job));
        $job['job_id']      = $jobId;

        // Keep the chat in step with the mail: the worker is often the first to
        // see the finished render, and the browser may never poll it again.
        imageIntPersistJobResult(
            (int) ($notification['user_id'] ?? 0),
            (string) ($notification['session_id'] ?? ''),
            $jobId,
            $job,
            (string) $resolved['image_url']
        );

        $imageUrl = imageIntAbsoluteImageUrl($resolved['image_url']);
        if ($imageUrl === '') {
            // No local copy: fall back to the URL ImageInt reported, so the mail
            // is still useful when LLMInt cannot write to image_output/.
            $imageUrl = (string) $notification['image_url'];
        }

        $sent = imageIntSendNotificationEmail($notification, $job, $imageUrl);
        if ($sent['ok']) {
            imageIntMarkNotification($id, 'sent');
            $stats['sent']++;
            $logger('Benachrichtigung für Auftrag ' . $jobId . ' versendet.');
        } else {
            imageIntMarkNotification($id, 'failed', $sent['message']);
            $stats['failed']++;
            $logger('Benachrichtigung für Auftrag ' . $jobId . ' fehlgeschlagen: ' . $sent['message']);
        }
    }

    return $stats;
}

/**
 * Build the system-prompt block that teaches the text model when to call
 * `generate_image`.
 *
 * Both formulations come from the settings so an administrator can extend the
 * trigger phrases without a deployment.
 */
function buildImageToolSystemPrompt(): string
{
    $trigger    = trim(getImagePromptTriggerText());
    $antiTrigger = trim(getImagePromptAntiTriggerText());
    if ($trigger === '' && $antiTrigger === '') {
        return '';
    }

    $prompt = "Bildgenerierung: Für Bildwünsche steht dir das Tool `generate_image` zur Verfügung.\n";
    if ($trigger !== '') {
        $prompt .= $trigger . "\n";
    }
    if ($antiTrigger !== '') {
        $prompt .= $antiTrigger . "\n";
    }
    return trim($prompt);
}
