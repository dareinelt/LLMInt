<?php

/**
 * sso.php
 *
 * Windows SSO through a reverse proxy (lanpa auth container). The proxy
 * requires Kerberos/NTLM only for this URL and passes the recognised user in
 * the header configured by PROXY_SSO_HEADER (lib/reverse_proxy.php exposes
 * it as REMOTE_USER). Browsers without domain login get the proxy's
 * ErrorDocument sso_fallback.php instead.
 *
 * The return target is taken from the session (set by index.php or
 * admin/login.php) and limited to known pages.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ldap_auth.php';

$targets = ['index.php' => 'index.php', 'admin' => 'admin/index.php'];
$target  = $targets[$_SESSION['sso_return'] ?? 'index.php'] ?? 'index.php';
unset($_SESSION['sso_return']);
$_SESSION['sso_attempted'] = true;

if (!isset($_SESSION['admin_user']) && ldapProxySsoEnabled()) {
    $result = ldapSsoLogin();
    if ($result === false) {
        $_SESSION['sso_error'] = 'SSO-Anmeldung fehlgeschlagen: Benutzername wird bereits als lokales Konto verwendet.';
        $target = $target === 'admin/index.php' ? 'admin/login.php' : 'login.php';
    } elseif ($result === true) {
        writeLog('info', 'Windows-SSO über Reverse-Proxy: ' . $_SESSION['admin_user'] . ' (' . getClientIp() . ') angemeldet.');
    }
}

header('Cache-Control: no-store');
header('Location: ' . $target);
exit;
