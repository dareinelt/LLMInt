<?php

/**
 * lib/ldap_auth.php
 *
 * Optional Active Directory / LDAP authentication helper.
 *
 * All public functions read their configuration from the settings table via
 * getSetting(), which is provided by db.php.  This file must therefore be
 * included *after* db.php has been required.
 *
 * Requires the PHP LDAP extension (php-ldap).
 */

// ── Feature & SSO probes ───────────────────────────────────────────────────────

/**
 * Returns true when LDAP authentication is enabled and a host is configured.
 */
function ldapEnabled(): bool
{
    if (getSetting('ldap_enabled', '0') !== '1') {
        return false;
    }
    return getSetting('ldap_host', '') !== '';
}

/**
 * Returns true when Windows-SSO via REMOTE_USER is also enabled.
 */
function ldapSsoEnabled(): bool
{
    return ldapEnabled() && getSetting('ldap_sspi_enabled', '0') === '1';
}

/**
 * Returns the stripped username from REMOTE_USER (e.g. "DOMAIN\user" → "user"),
 * or an empty string if the header is absent.
 */
function ldapSsoUsername(): string
{
    $raw = $_SERVER['REMOTE_USER'] ?? '';
    if ($raw === '') {
        return '';
    }
    // Strip DOMAIN\ prefix if present
    if (str_contains($raw, '\\')) {
        $raw = substr($raw, strrpos($raw, '\\') + 1);
    }
    // Strip @domain suffix (UPN form)
    if (str_contains($raw, '@')) {
        $raw = substr($raw, 0, strpos($raw, '@'));
    }
    return $raw;
}

// ── Core authentication ────────────────────────────────────────────────────────

/**
 * Attempt to authenticate $username/$password against Active Directory.
 *
 * Strategy:
 *   1.  Bind to AD with the supplied credentials (UPN or DOMAIN\user).
 *   2.  If a search base-DN is configured, search for the user object and read
 *       additional attributes (e-mail, display name, DN).
 *
 * Returns an associative array on success:
 *   [
 *     'username'     => string,  // sAMAccountName value
 *     'dn'           => string,  // distinguished name
 *     'email'        => string,  // mail attribute (may be empty)
 *     'display_name' => string,  // displayName attribute (may be empty)
 *   ]
 *
 * Returns null on authentication failure.
 * Throws RuntimeException when the PHP LDAP extension is missing or when a
 * configuration error prevents the connection from being opened.
 *
 * @throws RuntimeException
 */
function ldapAuthenticate(string $username, string $password): ?array
{
    if (!function_exists('ldap_connect')) {
        throw new RuntimeException('Die PHP-LDAP-Erweiterung ist nicht installiert (php-ldap).');
    }

    $host    = getSetting('ldap_host', '');
    $port    = (int) getSetting('ldap_port', '389');
    $useSsl  = getSetting('ldap_use_ssl', '0') === '1';
    $domain  = getSetting('ldap_domain', '');
    $baseDn  = getSetting('ldap_base_dn', '');

    if ($host === '') {
        throw new RuntimeException('LDAP-Host ist nicht konfiguriert.');
    }
    if ($username === '' || $password === '') {
        return null;
    }

    // Build connection URI
    $uri  = ($useSsl ? 'ldaps' : 'ldap') . '://' . $host . ':' . $port;
    $conn = @ldap_connect($uri);
    if ($conn === false) {
        throw new RuntimeException("Konnte keine LDAP-Verbindung zu {$uri} herstellen.");
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

    // Build bind DN – prefer UPN (user@domain), fall back to DOMAIN\user
    $bindUsername = $username;
    if ($domain !== '') {
        $bindUsername = $username . '@' . $domain;
    }

    // Attempt bind
    $bound = @ldap_bind($conn, $bindUsername, $password);
    if (!$bound) {
        @ldap_unbind($conn);
        return null; // Wrong credentials
    }

    // Fetch additional attributes when a base-DN is given
    $info = ldapFetchUserInfo($conn, $username, $baseDn);

    @ldap_unbind($conn);

    return [
        'username'     => $username,
        'dn'           => $info['dn']           ?? '',
        'email'        => $info['email']         ?? '',
        'display_name' => $info['display_name']  ?? '',
    ];
}

/**
 * Search for a user entry in the directory and return selected attributes.
 *
 * Uses a service account (ldap_bind_dn / ldap_bind_password) if configured,
 * or falls back to an anonymous search (which typically does not work on AD).
 *
 * Returns a partial array (missing keys will simply be empty strings).
 *
 * @internal Called by ldapAuthenticate() and ldapTestConnection().
 */
function ldapFetchUserInfo($conn, string $username, string $baseDn): array
{
    $result = [];

    if ($baseDn === '') {
        return $result;
    }

    $userAttr        = getSetting('ldap_user_attr',         'sAMAccountName');
    $emailAttr       = getSetting('ldap_email_attr',        'mail');
    $displayNameAttr = getSetting('ldap_display_name_attr', 'displayName');
    $bindDn          = getSetting('ldap_bind_dn',           '');
    $bindPass        = getSetting('ldap_bind_password',     '');

    // Re-bind as service account for the search (if configured)
    if ($bindDn !== '') {
        @ldap_bind($conn, $bindDn, $bindPass);
    }

    $filter  = '(' . ldap_escape($userAttr, '', LDAP_ESCAPE_FILTER) . '=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
    $attrs   = array_unique([$userAttr, $emailAttr, $displayNameAttr, 'distinguishedName', 'dn']);
    $search  = @ldap_search($conn, $baseDn, $filter, $attrs, 0, 1);

    if ($search === false) {
        return $result;
    }

    $entries = @ldap_get_entries($conn, $search);
    if (!$entries || $entries['count'] === 0) {
        return $result;
    }

    $entry = $entries[0];

    // DN
    $result['dn'] = $entry['dn'] ?? '';

    // E-Mail
    $emailKey = strtolower($emailAttr);
    $result['email'] = $entry[$emailKey][0] ?? '';

    // Display name
    $dnKey = strtolower($displayNameAttr);
    $result['display_name'] = $entry[$dnKey][0] ?? '';

    return $result;
}

// ── Just-in-Time provisioning ─────────────────────────────────────────────────

/**
 * Find or create a local user record for an AD-authenticated user.
 *
 * If a user with the same username already exists:
 *   - and its auth_source is 'ldap'  → update last_login, return id.
 *   - and its auth_source is 'local' → return null (conflict, do not take over).
 *
 * If no such user exists, a new row is inserted with auth_source='ldap',
 * email_verified=1 (the AD is authoritative), and no password hash.
 *
 * Returns the user's id on success, or null on conflict / DB error.
 */
function ldapProvisionUser(array $info): ?int
{
    $username = $info['username'] ?? '';
    if ($username === '') {
        return null;
    }

    try {
        $db = getDb();

        $stmt = $db->prepare(
            'SELECT id, auth_source FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Conflict: local account with the same name
            if (($existing['auth_source'] ?? 'local') !== 'ldap') {
                return null;
            }
            // Update login timestamp and refresh LDAP DN / e-mail
            $db->prepare(
                'UPDATE users
                    SET last_login = NOW(),
                        email   = ?,
                        ldap_dn = ?
                  WHERE id = ?'
            )->execute([
                $info['email'] ?: null,
                $info['dn']    ?: null,
                (int) $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        // New user – create with a placeholder password hash that can never match
        $defaultModel = getSetting('new_user_default_model', '');
        $db->prepare(
            'INSERT INTO users
                (username, password_hash, email, email_verified, default_model,
                 auth_source, ldap_dn, last_login)
             VALUES (?, ?, ?, 1, ?, ?, ?, NOW())'
        )->execute([
            $username,
            '*ldap*',           // Placeholder – ldap_verify() will never be called
            $info['email'] ?: null,
            $defaultModel,
            'ldap',
            $info['dn'] ?: null,
        ]);

        return (int) $db->lastInsertId();

    } catch (Throwable $e) {
        return null;
    }
}

// ── Connection test ────────────────────────────────────────────────────────────

/**
 * Test an LDAP connection with the supplied parameters.
 *
 * Used by the admin panel "Test connection" button via api/test_ldap.php.
 *
 * Returns an array:
 *   [ 'ok' => bool, 'message' => string ]
 */
function ldapTestConnection(
    string $host,
    int    $port,
    bool   $useSsl,
    string $bindDn,
    string $bindPass
): array {
    if (!function_exists('ldap_connect')) {
        return ['ok' => false, 'message' => 'Die PHP-LDAP-Erweiterung ist nicht installiert (php-ldap).'];
    }

    if ($host === '') {
        return ['ok' => false, 'message' => 'Kein LDAP-Host angegeben.'];
    }

    $uri  = ($useSsl ? 'ldaps' : 'ldap') . '://' . $host . ':' . $port;
    $conn = @ldap_connect($uri);
    if ($conn === false) {
        return ['ok' => false, 'message' => "Verbindung zu {$uri} konnte nicht hergestellt werden."];
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

    if ($bindDn !== '') {
        $bound = @ldap_bind($conn, $bindDn, $bindPass);
        @ldap_unbind($conn);
        if (!$bound) {
            $errMsg = ldap_error($conn);
            return ['ok' => false, 'message' => "Bind als Dienstkonto fehlgeschlagen: {$errMsg}"];
        }
        return ['ok' => true, 'message' => "Verbindung zu {$uri} erfolgreich. Dienstkonto-Bind OK."];
    }

    // Anonymous bind (just tests TCP reachability + LDAP handshake)
    $bound = @ldap_bind($conn);
    @ldap_unbind($conn);
    if (!$bound) {
        return ['ok' => true, 'message' => "Verbindung zu {$uri} erfolgreich (anonymer Bind nicht erlaubt – normal für AD)."];
    }
    return ['ok' => true, 'message' => "Verbindung zu {$uri} erfolgreich (anonymer Bind OK)."];
}
