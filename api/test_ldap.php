<?php

/**
 * api/test_ldap.php
 *
 * Admin-only AJAX endpoint that tests an LDAP / Active Directory connection.
 *
 * Accepts a JSON body:
 *   {
 *     "csrf_token": "…",
 *     "ldap_host":      "ad.example.com",
 *     "ldap_port":      389,
 *     "ldap_use_ssl":   false,
 *     "ldap_bind_dn":   "CN=svc,DC=example,DC=com",   // optional
 *     "ldap_bind_pass": "secret"                       // optional
 *   }
 *
 * Returns JSON { ok: bool, message: string }.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
requireAdminOrJson403();

require_once __DIR__ . '/../lib/ldap_auth.php';

$body = (string) file_get_contents('php://input');
$data = json_decode($body, true) ?: [];
$csrf = $data['csrf_token'] ?? '';

if (empty($_SESSION['csrf_token']) || $csrf !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Ungültiger CSRF-Token.']);
    exit;
}

$host     = trim($data['ldap_host']      ?? '');
$port     = max(1, min(65535, (int) ($data['ldap_port'] ?? 389)));
$useSsl   = !empty($data['ldap_use_ssl']);
$bindDn   = trim($data['ldap_bind_dn']   ?? '');
$bindPass = $data['ldap_bind_pass']      ?? '';

$result = ldapTestConnection($host, $port, $useSsl, $bindDn, $bindPass);

echo json_encode($result);
