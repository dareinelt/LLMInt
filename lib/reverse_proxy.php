<?php

/**
 * lib/reverse_proxy.php
 *
 * Support for running LLMInt behind a reverse proxy, e.g. the `auth`
 * container of lanpa (https://github.com/dareinelt/lanpa), which terminates
 * HTTPS with a centrally managed certificate and publishes LLMInt under a
 * sub path such as https://intranet.example/ki/.
 *
 * Configuration (environment variables):
 *   TRUSTED_PROXIES   Comma-separated IP addresses, CIDR ranges or host names
 *                     of the reverse proxies. Only requests whose TCP peer
 *                     matches are allowed to set X-Forwarded-For/-Proto/-Host/
 *                     -Prefix (and the SSO header). Empty = legacy behaviour
 *                     (forwarded headers are not normalised).
 *   PROXY_SSO_HEADER  Name of the header carrying the user name that the
 *                     proxy authenticated via Windows SSO (lanpa:
 *                     "X-Remote-User"). Empty = disabled. The value is exposed
 *                     as REMOTE_USER, so the existing LDAP-SSO logic applies.
 *
 * The file is loaded via auto_prepend_file (docker/php.ini) so the request is
 * normalised before any session is started, and additionally from db.php for
 * installations without that php.ini setting. It is idempotent.
 */

if (defined('LLMINT_REVERSE_PROXY_LOADED')) {
    return;
}
define('LLMINT_REVERSE_PROXY_LOADED', true);

/**
 * Configured trusted proxy entries (IP, CIDR or host name).
 *
 * @return string[]
 */
function reverseProxyTrustedEntries(): array
{
    $raw = (string) (getenv('TRUSTED_PROXIES') ?: '');
    $entries = array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []);
    return array_values(array_filter($entries, static fn ($e) => $e !== ''));
}

/**
 * Whether $ip lies within $cidr ("10.0.0.0/8", "fd00::/8" or a single IP).
 */
function reverseProxyIpInRange(string $ip, string $cidr): bool
{
    $bits = null;
    if (str_contains($cidr, '/')) {
        [$cidr, $bitsStr] = explode('/', $cidr, 2);
        if (!ctype_digit($bitsStr)) {
            return false;
        }
        $bits = (int) $bitsStr;
    }

    $ipBin    = @inet_pton($ip);
    $rangeBin = @inet_pton($cidr);
    if ($ipBin === false || $rangeBin === false || strlen($ipBin) !== strlen($rangeBin)) {
        return false;
    }

    $maxBits = strlen($ipBin) * 8;
    $bits    = $bits === null ? $maxBits : $bits;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $fullBytes) !== substr($rangeBin, 0, $fullBytes)) {
        return false;
    }
    $restBits = $bits % 8;
    if ($restBits === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $restBits)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($rangeBin[$fullBytes]) & $mask);
}

/**
 * Whether $ip belongs to one of the configured trusted proxies. Host names
 * (e.g. a Docker service name) are resolved at most once per request.
 */
function reverseProxyIsTrusted(string $ip): bool
{
    static $resolved = [];

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    foreach (reverseProxyTrustedEntries() as $entry) {
        if (filter_var(explode('/', $entry, 2)[0], FILTER_VALIDATE_IP) !== false) {
            if (reverseProxyIpInRange($ip, $entry)) {
                return true;
            }
            continue;
        }
        if (!array_key_exists($entry, $resolved)) {
            $resolved[$entry] = @gethostbynamel($entry) ?: [];
        }
        if (in_array($ip, $resolved[$entry], true)) {
            return true;
        }
    }
    return false;
}

/**
 * First value of a comma-separated forwarded header.
 */
function reverseProxyFirstHeaderValue(string $serverKey): string
{
    return trim(explode(',', (string) ($_SERVER[$serverKey] ?? ''))[0]);
}

/**
 * Normalise $_SERVER for requests that arrive through a trusted proxy:
 * REMOTE_ADDR becomes the real client, HTTPS reflects the public scheme and
 * the SSO header is exposed as REMOTE_USER. Forwarded headers from untrusted
 * peers are discarded so they cannot be spoofed.
 */
function reverseProxyApply(): void
{
    if (PHP_SAPI === 'cli' || reverseProxyTrustedEntries() === []) {
        return;
    }

    $forwardedKeys = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_FORWARDED_PREFIX',
        'HTTP_X_REMOTE_SOURCE',
    ];
    $ssoKey = reverseProxySsoServerKey();
    if ($ssoKey !== '') {
        $forwardedKeys[] = $ssoKey;
    }

    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!reverseProxyIsTrusted($peer)) {
        foreach ($forwardedKeys as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER['LLMINT_BEHIND_PROXY'] = '0';
        return;
    }

    $_SERVER['LLMINT_BEHIND_PROXY'] = '1';
    $_SERVER['LLMINT_PROXY_ADDR']   = $peer;

    // Client address: walk X-Forwarded-For from right to left and take the
    // first hop that is not a trusted proxy itself.
    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $hops   = array_reverse(array_map('trim', explode(',', $xff)));
        $client = null;
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $client = $hop;
            if (!reverseProxyIsTrusted($hop)) {
                break;
            }
        }
        if ($client !== null) {
            $_SERVER['REMOTE_ADDR'] = $client;
        }
        // getClientIp()/heartbeat must not re-read the (client-controlled) head.
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    $proto = strtolower(reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_PROTO'));
    if ($proto === 'https') {
        $_SERVER['HTTPS']          = 'on';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['SERVER_PORT']    = '443';
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_secure', '1');
        }
    } elseif ($proto === 'http') {
        unset($_SERVER['HTTPS']);
        $_SERVER['REQUEST_SCHEME'] = 'http';
    }

    // Windows SSO performed by the proxy. Users of further identity sources
    // (lanpa: X-Remote-Source of additional domains) are not accepted, since
    // LLMInt only knows one directory and names could collide.
    if ($ssoKey !== '') {
        $user   = trim((string) ($_SERVER[$ssoKey] ?? ''));
        $source = trim((string) ($_SERVER['HTTP_X_REMOTE_SOURCE'] ?? ''));
        if ($user !== '' && $source === '' && preg_match('/^[^\x00-\x1F\x7F]{1,256}$/u', $user)) {
            $_SERVER['REMOTE_USER'] = $user;
        }
    }
}

/**
 * $_SERVER key of the configured SSO header ("X-Remote-User" →
 * "HTTP_X_REMOTE_USER"), or '' when proxy SSO is disabled.
 */
function reverseProxySsoServerKey(): string
{
    $name = trim((string) (getenv('PROXY_SSO_HEADER') ?: ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9-]+$/', $name)) {
        return '';
    }
    return 'HTTP_' . strtoupper(str_replace('-', '_', $name));
}

/**
 * Whether Windows SSO is delegated to the reverse proxy (sso.php).
 */
function reverseProxySsoEnabled(): bool
{
    return reverseProxySsoServerKey() !== '' && reverseProxyTrustedEntries() !== [];
}

/**
 * Whether the current request arrived through a trusted proxy.
 */
function reverseProxyActive(): bool
{
    return ($_SERVER['LLMINT_BEHIND_PROXY'] ?? '0') === '1';
}

/**
 * Public path prefix under which the proxy publishes LLMInt ("/ki"), or ''.
 */
function reverseProxyPublicPrefix(): string
{
    if (!reverseProxyActive()) {
        return '';
    }
    $prefix = reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_PREFIX');
    if ($prefix === '' || !preg_match('#^/[A-Za-z0-9._~/-]*$#', $prefix) || str_contains($prefix, '..')) {
        return '';
    }
    return rtrim($prefix, '/');
}

/**
 * URL path of the application root as seen by the web server (without proxy
 * prefix), e.g. "" or "/llmint". Works from any script below the root.
 */
function appRootScriptPath(): string
{
    $dir  = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $root = realpath(dirname(__DIR__));
    $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    if ($root !== false && $file !== false) {
        $root = str_replace('\\', '/', $root);
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, $root . '/')) {
            $relDir = trim(dirname(substr($file, strlen($root))), '/.');
            $depth  = $relDir === '' ? 0 : substr_count($relDir, '/') + 1;
            for ($i = 0; $i < $depth; $i++) {
                $dir = dirname($dir);
            }
        }
    }

    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    return $dir === '.' ? '' : $dir;
}

/**
 * Public base URL of the application root without trailing slash, e.g.
 * "https://intranet.example/ki". Forwarded headers are honoured when the
 * request came through a trusted proxy; without TRUSTED_PROXIES only when
 * $honourUntrustedForwarded is set (legacy behaviour of the API page).
 */
function appPublicBaseUrl(bool $honourUntrustedForwarded = false): string
{
    $useForwarded = reverseProxyActive()
        || ($honourUntrustedForwarded && reverseProxyTrustedEntries() === []);

    $proto = null;
    if ($useForwarded) {
        $forwardedProto = strtolower(reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_PROTO'));
        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $proto = $forwardedProto;
        }
    }
    if ($proto === null) {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $proto = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    $host = $useForwarded ? reverseProxyFirstHeaderValue('HTTP_X_FORWARDED_HOST') : '';
    if ($host === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    return $proto . '://' . $host . reverseProxyPublicPrefix() . appRootScriptPath();
}

reverseProxyApply();
