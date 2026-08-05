<?php

/**
 * admin/index.php
 *
 * Protected admin dashboard.
 * Manages multiple LM Studio endpoints (CRUD), shows load-balancing
 * statistics, lists user accounts, and allows password changes.
 */

session_start();

require_once __DIR__ . '/../db.php';

requireAdminOrRedirect('login.php');

$db = getDb();
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));
$guestDefaultModel  = trim(getSetting('default_model', ''));
$newUserDefaultModel = trim(getSetting('new_user_default_model', ''));
$visionModel = trim(getSetting('vision_model', ''));
$routingDecisionModel = trim(getSetting('routing_decision_model', ''));
$intelligenceUpgradeMessage = getSetting('intelligence_upgrade_message', '');
$loginBannerEnabled = getSetting('login_banner_enabled', '0') === '1';
$loginBannerText    = getSetting('login_banner_text', '');
$registrationEmailText = getSetting('registration_email_text', '');
$routingCategories = loadRoutingCategories();
$routingCategoriesData = loadRoutingCategoriesFromDb();
$routingRules = loadRoutingRules();

// ── Logging settings ──────────────────────────────────────────────────────────
$logLevel         = getSetting('log_level', 'info');
$logRetentionDays = (int) getSetting('log_retention_days', '30');

// ── SMTP settings ─────────────────────────────────────────────────────────────
$smtpHost       = getSetting('smtp_host', '');
$smtpPort       = getSetting('smtp_port', '587');
$smtpEncryption = getSetting('smtp_encryption', 'tls');
$smtpAuth       = getSetting('smtp_auth', '1') === '1';
$smtpUser       = getSetting('smtp_user', '');
$smtpPass       = getSetting('smtp_pass', '');
$smtpFromEmail  = getSetting('smtp_from_email', '');
$smtpFromName   = getSetting('smtp_from_name', 'LLMInt');

// ── LDAP / Active Directory settings ─────────────────────────────────────────
$ldapEnabled         = getSetting('ldap_enabled',          '0') === '1';
$ldapHost            = getSetting('ldap_host',             '');
$ldapPort            = getSetting('ldap_port',             '389');
$ldapUseSsl          = getSetting('ldap_use_ssl',          '0') === '1';
$ldapDomain          = getSetting('ldap_domain',           '');
$ldapBaseDn          = getSetting('ldap_base_dn',          '');
$ldapBindDn          = getSetting('ldap_bind_dn',          '');
$ldapBindPassword    = getSetting('ldap_bind_password',    '');
$ldapUserAttr        = getSetting('ldap_user_attr',        'sAMAccountName');
$ldapEmailAttr       = getSetting('ldap_email_attr',       'mail');
$ldapDisplayNameAttr = getSetting('ldap_display_name_attr','displayName');
$ldapSspiEnabled     = getSetting('ldap_sspi_enabled',     '0') === '1';

// ── Hybrid-RAG / Embedding settings ──────────────────────────────────────────
require_once __DIR__ . '/../api/embedding.php';
$embeddingEnabled      = getSetting('embedding_enabled',      '0') === '1';
$embeddingModel        = getSetting('embedding_model',        '');
$embeddingDimensions   = (int) getSetting('embedding_dimensions',   '0');
$embeddingTimeout      = (int) getSetting('embedding_timeout',      '60');
$embeddingCacheEnabled = getSetting('embedding_cache_enabled', '0') === '1';
$embeddingCacheTtlDays = (int) getSetting('embedding_cache_ttl_days', '7');
$hybridSearchEnabled   = getSetting('hybrid_search_enabled',  '0') === '1';
$bm25Weight            = getSetting('bm25_weight',            '0.5');
$embeddingWeight       = getSetting('embedding_weight',       '0.5');
$rerankerEnabled       = getSetting('reranker_enabled',       '0') === '1';
$rerankerEndpoint      = getSetting('reranker_endpoint',      '');
$rerankerModel         = getSetting('reranker_model',         '');
$rerankerTimeout       = (int) getSetting('reranker_timeout', '30');
$rerankerTopK          = (int) getSetting('reranker_top_k',   '5');

// ── Generate CSRF token ───────────────────────────────────────────────────────

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Handle POST actions ───────────────────────────────────────────────────────

$flashOk    = '';
$flashError = '';
$editEp     = null; // endpoint being edited (populated after POST redirect)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $flashError = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Add endpoint ──────────────────────────────────────────────────────
        if ($action === 'add_endpoint') {
            $newAlias            = trim($_POST['ep_alias'] ?? '');
            $newUrl              = trim($_POST['ep_base_url'] ?? '');
            $newTimeout          = (int) ($_POST['ep_timeout'] ?? 120);
            $newModel            = trim($_POST['ep_default_model'] ?? '');
            $isActive            = isset($_POST['ep_is_active']) ? 1 : 0;
            $sshHost             = trim($_POST['ep_ssh_host'] ?? '');
            $sshPort             = max(1, min(65535, (int) ($_POST['ep_ssh_port'] ?? 22)));
            $sshUser             = trim($_POST['ep_ssh_user'] ?? '');
            $sshPassword         = $_POST['ep_ssh_password'] ?? '';
            $specializedFor      = trim($_POST['ep_specialized_for_category'] ?? '');
            $supportsToolCalling = isset($_POST['ep_supports_tool_calling']) ? 1 : 0;

            if (strlen($newAlias) > 120) {
                $flashError = 'Alias darf maximal 120 Zeichen lang sein.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO endpoints (alias, base_url, timeout, default_model, specialized_for_category,
                                            supports_tool_calling, is_active, sort_order,
                                            ssh_host, ssh_port, ssh_user, ssh_password)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newModel, $specializedFor,
                            $supportsToolCalling, $isActive, $maxOrder + 1,
                            $sshHost, $sshPort, $sshUser, $sshPassword !== '' ? $sshPassword : null]);
                $endpointLabel = $newAlias !== '' ? $newAlias : rtrim($newUrl, '/');
                writeLog('info', 'Neuer Modellendpunkt erfolgreich registriert (' . $endpointLabel . ', Modell: ' . $newModel . ').');
                $flashOk = 'Endpunkt hinzugefügt.';
            }

        // ── Update endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'update_endpoint') {
            $epId                = (int) ($_POST['ep_id'] ?? 0);
            $newAlias            = trim($_POST['ep_alias'] ?? '');
            $newUrl              = trim($_POST['ep_base_url'] ?? '');
            $newTimeout          = (int) ($_POST['ep_timeout'] ?? 120);
            $newModel            = trim($_POST['ep_default_model'] ?? '');
            $isActive            = isset($_POST['ep_is_active']) ? 1 : 0;
            $sshHost             = trim($_POST['ep_ssh_host'] ?? '');
            $sshPort             = max(1, min(65535, (int) ($_POST['ep_ssh_port'] ?? 22)));
            $sshUser             = trim($_POST['ep_ssh_user'] ?? '');
            $sshPassword         = $_POST['ep_ssh_password'] ?? null;
            $specializedFor      = trim($_POST['ep_specialized_for_category'] ?? '');
            $supportsToolCalling = isset($_POST['ep_supports_tool_calling']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } elseif (strlen($newAlias) > 120) {
                $flashError = 'Alias darf maximal 120 Zeichen lang sein.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $previousEndpoint = $db->prepare('SELECT alias, base_url, is_active FROM endpoints WHERE id = ? LIMIT 1');
                $previousEndpoint->execute([$epId]);
                $previousEndpoint = $previousEndpoint->fetch();

                // If the password field was left blank, keep the existing value.
                if ($sshPassword === '' || $sshPassword === null) {
                    $db->prepare(
                        'UPDATE endpoints
                            SET alias = ?, base_url = ?, timeout = ?, default_model = ?,
                                specialized_for_category = ?, supports_tool_calling = ?, is_active = ?,
                                ssh_host = ?, ssh_port = ?, ssh_user = ?
                          WHERE id = ?'
                    )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newModel, $specializedFor,
                                $supportsToolCalling, $isActive, $sshHost, $sshPort, $sshUser, $epId]);
                } else {
                    $db->prepare(
                        'UPDATE endpoints
                            SET alias = ?, base_url = ?, timeout = ?, default_model = ?,
                                specialized_for_category = ?, supports_tool_calling = ?, is_active = ?,
                                ssh_host = ?, ssh_port = ?, ssh_user = ?, ssh_password = ?
                          WHERE id = ?'
                    )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newModel, $specializedFor,
                                $supportsToolCalling, $isActive, $sshHost, $sshPort, $sshUser, $sshPassword, $epId]);
                }
                if (is_array($previousEndpoint) && (int) ($previousEndpoint['is_active'] ?? 0) === 1 && $isActive !== 1) {
                    $endpointLabel = trim((string) ($previousEndpoint['alias'] ?? ''));
                    if ($endpointLabel === '') {
                        $endpointLabel = trim((string) ($previousEndpoint['base_url'] ?? ''));
                    }
                    writeLog('warning', 'Modellendpunkt ' . $endpointLabel . ' nicht mehr verfügbar.');
                }
                $flashOk = 'Endpunkt gespeichert.';
            }

        // ── Delete endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'delete_endpoint') {
            $epId = (int) ($_POST['ep_id'] ?? 0);
            if ($epId > 0) {
                $previousEndpoint = $db->prepare('SELECT alias, base_url FROM endpoints WHERE id = ? LIMIT 1');
                $previousEndpoint->execute([$epId]);
                $previousEndpoint = $previousEndpoint->fetch();
                $db->prepare('DELETE FROM endpoints WHERE id = ?')->execute([$epId]);
                if (is_array($previousEndpoint)) {
                    $endpointLabel = trim((string) ($previousEndpoint['alias'] ?? ''));
                    if ($endpointLabel === '') {
                        $endpointLabel = trim((string) ($previousEndpoint['base_url'] ?? ''));
                    }
                    writeLog('warning', 'Modellendpunkt ' . $endpointLabel . ' nicht mehr verfügbar.');
                }
                $flashOk = 'Endpunkt gelöscht.';
            }

        // ── Save search settings ──────────────────────────────────────────────
        } elseif ($action === 'save_search_settings') {
            $newSearxngUrl = trim($_POST['searxng_base_url'] ?? '');

            if ($newSearxngUrl !== '' && filter_var($newSearxngUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige SearXNG-URL eingeben oder das Feld leer lassen.';
            } else {
                $searxngBaseUrl = $newSearxngUrl === '' ? '' : rtrim($newSearxngUrl, '/');
                setSetting('searxng_base_url', $searxngBaseUrl);
                $flashOk = $searxngBaseUrl === ''
                    ? 'SearXNG-Suche deaktiviert.'
                    : 'SearXNG-URL gespeichert.';
            }

        // ── Save request-handling settings ───────────────────────────────────
        } elseif ($action === 'save_request_handling') {
            $newGuestDefaultModel = trim($_POST['guest_default_model'] ?? '');

            if ($newGuestDefaultModel !== '') {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM endpoints
                      WHERE is_active = 1
                        AND default_model = ?'
                );
                $stmt->execute([$newGuestDefaultModel]);
                $isKnownModelGroup = (int) $stmt->fetchColumn() > 0;

                if (!$isKnownModelGroup) {
                    $flashError = 'Bitte ein verfügbares Modell bzw. eine verfügbare Modellgruppe aus den aktiven Endpunkten auswählen.';
                }
            }

            if ($flashError === '') {
                $guestDefaultModel = $newGuestDefaultModel;
                setSetting('default_model', $guestDefaultModel);
                $flashOk = $guestDefaultModel === ''
                    ? 'Anfragenhandling gespeichert. Neue Anfragen nutzen wieder automatisch das erste aktive Modell.'
                    : 'Anfragenhandling gespeichert.';
            }

        // ── Save new-user default model ───────────────────────────────────────
        } elseif ($action === 'save_new_user_model') {
            $newModel = trim($_POST['new_user_default_model'] ?? '');
            $newUserDefaultModel = $newModel;
            setSetting('new_user_default_model', $newModel);
            $flashOk = 'Standard-Modell für neue Benutzer gespeichert.';

        // ── Save system messages ──────────────────────────────────────────────
        } elseif ($action === 'save_system_messages') {
            $newIntelligenceUpgradeMessage = trim($_POST['intelligence_upgrade_message'] ?? '');
            $intelligenceUpgradeMessage = $newIntelligenceUpgradeMessage;
            setSetting('intelligence_upgrade_message', $newIntelligenceUpgradeMessage);
            $loginBannerEnabled = isset($_POST['login_banner_enabled']);
            $loginBannerText    = trim($_POST['login_banner_text'] ?? '');
            setSetting('login_banner_enabled', $loginBannerEnabled ? '1' : '0');
            setSetting('login_banner_text', $loginBannerText);
            $newRegistrationEmailText = trim($_POST['registration_email_text'] ?? '');
            $registrationEmailText = $newRegistrationEmailText;
            setSetting('registration_email_text', $newRegistrationEmailText);
            $flashOk = 'Systemmeldungen gespeichert.';

        // ── Save vision model ─────────────────────────────────────────────────
        } elseif ($action === 'save_vision_settings') {
            $newVisionModel = trim($_POST['vision_model'] ?? '');
            $visionModel = $newVisionModel;
            setSetting('vision_model', $newVisionModel);
            $flashOk = $newVisionModel === ''
                ? 'Vision-Modell zurückgesetzt (Dokument-Upload deaktiviert).'
                : 'Vision-Modell gespeichert.';

        // ── Save SMTP settings ────────────────────────────────────────────────
        } elseif ($action === 'save_smtp_settings') {
            $newSmtpHost       = trim($_POST['smtp_host']        ?? '');
            $newSmtpPort       = (int) ($_POST['smtp_port']      ?? 587);
            $newSmtpEncryption = trim($_POST['smtp_encryption']  ?? 'tls');
            $newSmtpAuth       = isset($_POST['smtp_auth']) ? '1' : '0';
            $newSmtpUser       = trim($_POST['smtp_user']        ?? '');
            $newSmtpPass       = $_POST['smtp_pass']             ?? '';
            $newSmtpFromEmail  = trim($_POST['smtp_from_email']  ?? '');
            $newSmtpFromName   = trim($_POST['smtp_from_name']   ?? 'LLMInt');

            if ($newSmtpHost !== '' && !in_array($newSmtpEncryption, ['none', 'tls', 'ssl'], true)) {
                $flashError = 'Ungültige Verschlüsselungsoption.';
            } elseif ($newSmtpHost !== '' && ($newSmtpPort < 1 || $newSmtpPort > 65535)) {
                $flashError = 'Ungültiger SMTP-Port (1–65535).';
            } elseif ($newSmtpFromEmail !== '' && !filter_var($newSmtpFromEmail, FILTER_VALIDATE_EMAIL)) {
                $flashError = 'Ungültige Absender-E-Mail-Adresse.';
            } else {
                setSetting('smtp_host',       $newSmtpHost);
                setSetting('smtp_port',       (string) $newSmtpPort);
                setSetting('smtp_encryption', $newSmtpEncryption);
                setSetting('smtp_auth',       $newSmtpAuth);
                setSetting('smtp_user',       $newSmtpUser);
                // Only overwrite the password if the field was not left blank
                if ($newSmtpPass !== '') {
                    setSetting('smtp_pass', $newSmtpPass);
                }
                setSetting('smtp_from_email', $newSmtpFromEmail);
                setSetting('smtp_from_name',  $newSmtpFromName);

                $smtpHost       = $newSmtpHost;
                $smtpPort       = (string) $newSmtpPort;
                $smtpEncryption = $newSmtpEncryption;
                $smtpAuth       = $newSmtpAuth === '1';
                $smtpUser       = $newSmtpUser;
                if ($newSmtpPass !== '') { $smtpPass = $newSmtpPass; }
                $smtpFromEmail  = $newSmtpFromEmail;
                $smtpFromName   = $newSmtpFromName;

                $flashOk = 'SMTP-Einstellungen gespeichert.';
            }

        // ── Save LDAP / AD settings ───────────────────────────────────────────
        } elseif ($action === 'save_ldap_settings') {
            $newLdapEnabled         = isset($_POST['ldap_enabled'])      ? '1' : '0';
            $newLdapHost            = trim($_POST['ldap_host']           ?? '');
            $newLdapPort            = max(1, min(65535, (int) ($_POST['ldap_port'] ?? 389)));
            $newLdapUseSsl          = isset($_POST['ldap_use_ssl'])      ? '1' : '0';
            $newLdapDomain          = trim($_POST['ldap_domain']         ?? '');
            $newLdapBaseDn          = trim($_POST['ldap_base_dn']        ?? '');
            $newLdapBindDn          = trim($_POST['ldap_bind_dn']        ?? '');
            $newLdapBindPassword    = $_POST['ldap_bind_password']       ?? '';
            $newLdapUserAttr        = trim($_POST['ldap_user_attr']      ?? 'sAMAccountName');
            $newLdapEmailAttr       = trim($_POST['ldap_email_attr']     ?? 'mail');
            $newLdapDisplayNameAttr = trim($_POST['ldap_display_name_attr'] ?? 'displayName');
            $newLdapSspiEnabled     = isset($_POST['ldap_sspi_enabled']) ? '1' : '0';

            if ($newLdapEnabled === '1' && $newLdapHost === '') {
                $flashError = 'LDAP-Host darf nicht leer sein, wenn LDAP aktiviert ist.';
            } else {
                setSetting('ldap_enabled',          $newLdapEnabled);
                setSetting('ldap_host',             $newLdapHost);
                setSetting('ldap_port',             (string) $newLdapPort);
                setSetting('ldap_use_ssl',          $newLdapUseSsl);
                setSetting('ldap_domain',           $newLdapDomain);
                setSetting('ldap_base_dn',          $newLdapBaseDn);
                setSetting('ldap_bind_dn',          $newLdapBindDn);
                setSetting('ldap_user_attr',        $newLdapUserAttr ?: 'sAMAccountName');
                setSetting('ldap_email_attr',       $newLdapEmailAttr ?: 'mail');
                setSetting('ldap_display_name_attr',$newLdapDisplayNameAttr ?: 'displayName');
                setSetting('ldap_sspi_enabled',     $newLdapSspiEnabled);
                // Only overwrite password if the field was not left blank
                if ($newLdapBindPassword !== '') {
                    setSetting('ldap_bind_password', $newLdapBindPassword);
                }

                $ldapEnabled         = $newLdapEnabled === '1';
                $ldapHost            = $newLdapHost;
                $ldapPort            = (string) $newLdapPort;
                $ldapUseSsl          = $newLdapUseSsl === '1';
                $ldapDomain          = $newLdapDomain;
                $ldapBaseDn          = $newLdapBaseDn;
                $ldapBindDn          = $newLdapBindDn;
                $ldapUserAttr        = $newLdapUserAttr ?: 'sAMAccountName';
                $ldapEmailAttr       = $newLdapEmailAttr ?: 'mail';
                $ldapDisplayNameAttr = $newLdapDisplayNameAttr ?: 'displayName';
                $ldapSspiEnabled     = $newLdapSspiEnabled === '1';
                if ($newLdapBindPassword !== '') { $ldapBindPassword = $newLdapBindPassword; }

                $flashOk = $ldapEnabled
                    ? 'Active-Directory-Einstellungen gespeichert.'
                    : 'Active-Directory-Integration deaktiviert.';
            }

        // ── Add SD endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'add_sd_endpoint') {
            $newUrl     = trim($_POST['sd_ep_base_url'] ?? '');
            $newTimeout = (int) ($_POST['sd_ep_timeout'] ?? 120);
            $isActive   = isset($_POST['sd_ep_is_active']) ? 1 : 0;

            if ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM sd_endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO sd_endpoints (base_url, timeout, is_active, sort_order) VALUES (?, ?, ?, ?)'
                )->execute([rtrim($newUrl, '/'), $newTimeout, $isActive, $maxOrder + 1]);
                $flashOk = 'SD-Endpunkt hinzugefügt.';
            }

        // ── Update SD endpoint ────────────────────────────────────────────────
        } elseif ($action === 'update_sd_endpoint') {
            $epId       = (int) ($_POST['sd_ep_id'] ?? 0);
            $newUrl     = trim($_POST['sd_ep_base_url'] ?? '');
            $newTimeout = (int) ($_POST['sd_ep_timeout'] ?? 120);
            $isActive   = isset($_POST['sd_ep_is_active']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige SD-Endpunkt-ID.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $db->prepare(
                    'UPDATE sd_endpoints SET base_url = ?, timeout = ?, is_active = ? WHERE id = ?'
                )->execute([rtrim($newUrl, '/'), $newTimeout, $isActive, $epId]);
                $flashOk = 'SD-Endpunkt gespeichert.';
            }

        // ── Delete SD endpoint ────────────────────────────────────────────────
        } elseif ($action === 'delete_sd_endpoint') {
            $epId = (int) ($_POST['sd_ep_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM sd_endpoints WHERE id = ?')->execute([$epId]);
                $flashOk = 'SD-Endpunkt gelöscht.';
            }

        // ── Add ComfyUI endpoint ──────────────────────────────────────────────
        } elseif ($action === 'add_comfy_endpoint') {
            $newUrl        = trim($_POST['comfy_ep_base_url'] ?? '');
            $newTimeout    = (int) ($_POST['comfy_ep_timeout'] ?? 120);
            $newCheckpoint = trim($_POST['comfy_ep_default_checkpoint'] ?? '');
            $isActive      = isset($_POST['comfy_ep_is_active']) ? 1 : 0;

            if ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM comfy_endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO comfy_endpoints (base_url, timeout, default_checkpoint, is_active, sort_order) VALUES (?, ?, ?, ?, ?)'
                )->execute([rtrim($newUrl, '/'), $newTimeout, $newCheckpoint, $isActive, $maxOrder + 1]);
                $flashOk = 'ComfyUI-Endpunkt hinzugefügt.';
            }

        // ── Update ComfyUI endpoint ───────────────────────────────────────────
        } elseif ($action === 'update_comfy_endpoint') {
            $epId          = (int) ($_POST['comfy_ep_id'] ?? 0);
            $newUrl        = trim($_POST['comfy_ep_base_url'] ?? '');
            $newTimeout    = (int) ($_POST['comfy_ep_timeout'] ?? 120);
            $newCheckpoint = trim($_POST['comfy_ep_default_checkpoint'] ?? '');
            $isActive      = isset($_POST['comfy_ep_is_active']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige ComfyUI-Endpunkt-ID.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } else {
                $db->prepare(
                    'UPDATE comfy_endpoints SET base_url = ?, timeout = ?, default_checkpoint = ?, is_active = ? WHERE id = ?'
                )->execute([rtrim($newUrl, '/'), $newTimeout, $newCheckpoint, $isActive, $epId]);
                $flashOk = 'ComfyUI-Endpunkt gespeichert.';
            }

        // ── Delete ComfyUI endpoint ───────────────────────────────────────────
        } elseif ($action === 'delete_comfy_endpoint') {
            $epId = (int) ($_POST['comfy_ep_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM comfy_endpoints WHERE id = ?')->execute([$epId]);
                $flashOk = 'ComfyUI-Endpunkt gelöscht.';
            }

        // ── Save routing settings ─────────────────────────────────────────────
        } elseif ($action === 'save_routing_settings') {
            $newDecisionModel = trim($_POST['routing_decision_model'] ?? '');
            setSetting('routing_decision_model', $newDecisionModel);

            // Save per-category model assignments.
            $categories = loadRoutingCategories();
            foreach ($categories as $cat) {
                $fieldName = 'routing_model_' . preg_replace('/[^a-zA-Z0-9]/', '_', $cat);
                $catModel  = trim($_POST[$fieldName] ?? '');
                saveRoutingRule($cat, $catModel);
            }
            $flashOk = 'Modellrouting gespeichert.';

        // ── Add routing category ──────────────────────────────────────────────
        } elseif ($action === 'add_routing_category') {
            $catName      = trim($_POST['rc_name']              ?? '');
            $catDef       = trim($_POST['rc_definition']        ?? '');
            $catRule      = trim($_POST['rc_decision_rule']     ?? '');
            $catSort      = (int) ($_POST['rc_sort_order']      ?? 0);
            $catPriority  = (int) ($_POST['rc_decision_priority'] ?? 0);

            if ($catName === '') {
                $flashError = 'Kategoriename darf nicht leer sein.';
            } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $catName)) {
                $flashError = 'Kategoriename darf nur Buchstaben, Ziffern und Unterstriche enthalten.';
            } else {
                try {
                    saveRoutingCategory(0, $catName, $catDef, $catRule, $catSort, $catPriority);
                    $flashOk = 'Kategorie "' . htmlspecialchars($catName) . '" hinzugefügt.';
                } catch (Throwable $e) {
                    $flashError = 'Kategorie konnte nicht gespeichert werden (Name bereits vorhanden?).';
                }
            }

        // ── Update routing category ───────────────────────────────────────────
        } elseif ($action === 'update_routing_category') {
            $catId        = (int) ($_POST['rc_id']              ?? 0);
            $catName      = trim($_POST['rc_name']              ?? '');
            $catDef       = trim($_POST['rc_definition']        ?? '');
            $catRule      = trim($_POST['rc_decision_rule']     ?? '');
            $catSort      = (int) ($_POST['rc_sort_order']      ?? 0);
            $catPriority  = (int) ($_POST['rc_decision_priority'] ?? 0);

            if ($catId <= 0) {
                $flashError = 'Ungültige Kategorie-ID.';
            } elseif ($catName === '') {
                $flashError = 'Kategoriename darf nicht leer sein.';
            } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $catName)) {
                $flashError = 'Kategoriename darf nur Buchstaben, Ziffern und Unterstriche enthalten.';
            } else {
                try {
                    saveRoutingCategory($catId, $catName, $catDef, $catRule, $catSort, $catPriority);
                    $flashOk = 'Kategorie "' . htmlspecialchars($catName) . '" gespeichert.';
                } catch (Throwable $e) {
                    $flashError = 'Kategorie konnte nicht gespeichert werden (Name bereits vorhanden?).';
                }
            }

        // ── Delete routing category ───────────────────────────────────────────
        } elseif ($action === 'delete_routing_category') {
            $catId = (int) ($_POST['rc_id'] ?? 0);
            if ($catId > 0) {
                deleteRoutingCategory($catId);
                $flashOk = 'Kategorie gelöscht.';
            }

        // ── Import routing categories from prompt.txt ─────────────────────────
        } elseif ($action === 'import_prompt_txt') {
            $categoriesJson = $_POST['prompt_categories'] ?? '';
            $categories     = json_decode($categoriesJson, true);

            if (!is_array($categories) || empty($categories)) {
                $flashError = 'Keine gültigen Kategorien in der hochgeladenen Datei gefunden.';
            } else {
                $valid = true;
                foreach ($categories as $cat) {
                    if (!isset($cat['name'], $cat['definition'], $cat['decision_rule'], $cat['sort_order'], $cat['decision_priority'])) {
                        $valid = false;
                        break;
                    }
                    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $cat['name'])) {
                        $valid = false;
                        break;
                    }
                }
                if (!$valid) {
                    $flashError = 'Die hochgeladene Datei entspricht nicht dem erwarteten Schema.';
                } else {
                    $pdo = getDb();
                    $pdo->exec('DELETE FROM routing_rules');
                    $pdo->exec('DELETE FROM routing_categories');
                    foreach ($categories as $cat) {
                        saveRoutingCategory(
                            0,
                            (string) $cat['name'],
                            (string) $cat['definition'],
                            (string) $cat['decision_rule'],
                            (int)    $cat['sort_order'],
                            (int)    $cat['decision_priority']
                        );
                    }
                    $flashOk = count($categories) . ' Kategorien aus prompt.txt importiert. Alle vorherigen Einstellungen wurden überschrieben.';
                }
            }

        // ── Save logging configuration ────────────────────────────────────────
        } elseif ($action === 'save_log_config') {
            $newLogLevel = $_POST['log_level'] ?? 'info';
            if (!in_array($newLogLevel, ['info', 'warning', 'error'], true)) {
                $newLogLevel = 'info';
            }
            $newRetention = max(1, min(3650, (int) ($_POST['log_retention_days'] ?? 30)));
            setSetting('log_level', $newLogLevel);
            setSetting('log_retention_days', (string) $newRetention);
            $logLevel         = $newLogLevel;
            $logRetentionDays = $newRetention;
            $flashOk = 'Protokollierungseinstellungen gespeichert.';

        // ── Add embedding endpoint ────────────────────────────────────────────
        } elseif ($action === 'add_embedding_endpoint') {
            $epAlias   = trim($_POST['emb_alias']   ?? '');
            $epUrl     = trim($_POST['emb_base_url'] ?? '');
            $epModel   = trim($_POST['emb_model']   ?? '');
            $epApiKey  = $_POST['emb_api_key']      ?? '';
            $epTimeout = max(5, min(600, (int) ($_POST['emb_timeout'] ?? 60)));
            $epActive  = isset($_POST['emb_is_active']) ? 1 : 0;

            if ($epUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($epUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($epModel === '') {
                $flashError = 'Modellname darf nicht leer sein.';
            } else {
                $maxOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), -1) FROM embedding_endpoints')->fetchColumn();
                $db->prepare(
                    'INSERT INTO embedding_endpoints (alias, base_url, model, api_key, timeout, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$epAlias, rtrim($epUrl, '/'), $epModel, $epApiKey !== '' ? $epApiKey : null, $epTimeout, $epActive, $maxOrder + 1]);
                $flashOk = 'Embedding-Endpunkt hinzugefügt.';
            }

        // ── Update embedding endpoint ─────────────────────────────────────────
        } elseif ($action === 'update_embedding_endpoint') {
            $epId      = (int) ($_POST['emb_id'] ?? 0);
            $epAlias   = trim($_POST['emb_alias']   ?? '');
            $epUrl     = trim($_POST['emb_base_url'] ?? '');
            $epModel   = trim($_POST['emb_model']   ?? '');
            $epApiKey  = $_POST['emb_api_key']      ?? '';
            $epTimeout = max(5, min(600, (int) ($_POST['emb_timeout'] ?? 60)));
            $epActive  = isset($_POST['emb_is_active']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } elseif ($epUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($epUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($epModel === '') {
                $flashError = 'Modellname darf nicht leer sein.';
            } else {
                if ($epApiKey !== '') {
                    $db->prepare(
                        'UPDATE embedding_endpoints SET alias=?, base_url=?, model=?, api_key=?, timeout=?, is_active=? WHERE id=?'
                    )->execute([$epAlias, rtrim($epUrl, '/'), $epModel, $epApiKey, $epTimeout, $epActive, $epId]);
                } else {
                    $db->prepare(
                        'UPDATE embedding_endpoints SET alias=?, base_url=?, model=?, timeout=?, is_active=? WHERE id=?'
                    )->execute([$epAlias, rtrim($epUrl, '/'), $epModel, $epTimeout, $epActive, $epId]);
                }
                $embeddingModel = $epModel;
                setSetting('embedding_model', $epModel);
                $flashOk = 'Embedding-Endpunkt gespeichert.';
            }

        // ── Delete embedding endpoint ─────────────────────────────────────────
        } elseif ($action === 'delete_embedding_endpoint') {
            $epId = (int) ($_POST['emb_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM embedding_endpoints WHERE id = ?')->execute([$epId]);
                $flashOk = 'Embedding-Endpunkt gelöscht.';
            }

        // ── Save hybrid search settings ───────────────────────────────────────
        } elseif ($action === 'save_hybrid_search_settings') {
            $newHybridEnabled      = isset($_POST['hybrid_search_enabled']) ? '1' : '0';
            $newEmbeddingEnabled   = isset($_POST['embedding_enabled'])     ? '1' : '0';
            $newEmbeddingModel     = trim($_POST['embedding_model']         ?? '');
            $newEmbeddingDims      = max(0, (int) ($_POST['embedding_dimensions']   ?? 0));
            $newEmbeddingTimeout   = max(5, min(600, (int) ($_POST['embedding_timeout'] ?? 60)));
            $newBm25Weight         = max(0.0, min(1.0, (float) str_replace(',', '.', $_POST['bm25_weight'] ?? '0.5')));
            $newEmbeddingWeight    = max(0.0, min(1.0, (float) str_replace(',', '.', $_POST['embedding_weight'] ?? '0.5')));
            $newCacheEnabled       = isset($_POST['embedding_cache_enabled']) ? '1' : '0';
            $newCacheTtl           = max(1, min(365, (int) ($_POST['embedding_cache_ttl_days'] ?? 7)));

            setSetting('hybrid_search_enabled',   $newHybridEnabled);
            setSetting('embedding_enabled',        $newEmbeddingEnabled);
            setSetting('embedding_model',          $newEmbeddingModel);
            setSetting('embedding_dimensions',     (string) $newEmbeddingDims);
            setSetting('embedding_timeout',        (string) $newEmbeddingTimeout);
            setSetting('bm25_weight',              number_format($newBm25Weight, 2, '.', ''));
            setSetting('embedding_weight',         number_format($newEmbeddingWeight, 2, '.', ''));
            setSetting('embedding_cache_enabled',  $newCacheEnabled);
            setSetting('embedding_cache_ttl_days', (string) $newCacheTtl);

            $hybridSearchEnabled   = $newHybridEnabled === '1';
            $embeddingEnabled      = $newEmbeddingEnabled === '1';
            $embeddingModel        = $newEmbeddingModel;
            $embeddingDimensions   = $newEmbeddingDims;
            $embeddingTimeout      = $newEmbeddingTimeout;
            $bm25Weight            = number_format($newBm25Weight, 2, '.', '');
            $embeddingWeight       = number_format($newEmbeddingWeight, 2, '.', '');
            $embeddingCacheEnabled = $newCacheEnabled === '1';
            $embeddingCacheTtlDays = $newCacheTtl;

            $flashOk = 'Hybrid-Search-Einstellungen gespeichert.';

        // ── Save reranker settings ────────────────────────────────────────────
        } elseif ($action === 'save_reranker_settings') {
            $newRerankerEnabled  = isset($_POST['reranker_enabled']) ? '1' : '0';
            $newRerankerEndpoint = trim($_POST['reranker_endpoint'] ?? '');
            $newRerankerModel    = trim($_POST['reranker_model']    ?? '');
            $newRerankerTimeout  = max(5, min(300, (int) ($_POST['reranker_timeout'] ?? 30)));
            $newRerankerTopK     = max(1, min(50,  (int) ($_POST['reranker_top_k']   ?? 5)));

            if ($newRerankerEndpoint !== '' && filter_var($newRerankerEndpoint, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige Reranker-URL eingeben oder das Feld leer lassen.';
            } else {
                setSetting('reranker_enabled',  $newRerankerEnabled);
                setSetting('reranker_endpoint', $newRerankerEndpoint);
                setSetting('reranker_model',    $newRerankerModel);
                setSetting('reranker_timeout',  (string) $newRerankerTimeout);
                setSetting('reranker_top_k',    (string) $newRerankerTopK);

                $rerankerEnabled  = $newRerankerEnabled === '1';
                $rerankerEndpoint = $newRerankerEndpoint;
                $rerankerModel    = $newRerankerModel;
                $rerankerTimeout  = $newRerankerTimeout;
                $rerankerTopK     = $newRerankerTopK;

                $flashOk = 'Reranker-Einstellungen gespeichert.';
            }

        // ── Change password ───────────────────────────────────────────────────
        } elseif ($action === 'change_password') {
            $oldPass  = $_POST['old_password']         ?? '';
            $newPass  = $_POST['new_password']          ?? '';
            $newPass2 = $_POST['new_password_confirm']  ?? '';

            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['admin_id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($oldPass, $row['password_hash'])) {
                $flashError = 'Aktuelles Passwort ist falsch.';
            } elseif ($newPass === '') {
                $flashError = 'Das neue Passwort darf nicht leer sein.';
            } elseif ($newPass !== $newPass2) {
                $flashError = 'Die neuen Passwörter stimmen nicht überein.';
            } elseif (strlen($newPass) < 8) {
                $flashError = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash = ?, requires_password_change = 0 WHERE id = ?')
                   ->execute([$hash, $_SESSION['admin_id']]);
                unset($_SESSION['requires_password_change']);
                $flashOk = 'Passwort erfolgreich geändert.';
            }
        }
    }
}

// ── Log viewer query ──────────────────────────────────────────────────────────

$logFilterDateFrom = trim($_GET['log_from']     ?? '');
$logFilterDateTo   = trim($_GET['log_to']       ?? '');
$logFilterKeyword  = trim($_GET['log_keyword']  ?? '');
$logFilterLevel    = trim($_GET['log_lv']       ?? '');
$logSortDir        = ($_GET['log_sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$logPage           = max(1, (int) ($_GET['log_page'] ?? 1));
$logPerPage        = 50;

$logRows   = [];
$logTotal  = 0;
try {
    $logWhere  = ['1=1'];
    $logParams = [];

    if ($logFilterDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFilterDateFrom)) {
        $logWhere[]  = 'created_at >= ?';
        $logParams[] = $logFilterDateFrom . ' 00:00:00';
    }
    if ($logFilterDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFilterDateTo)) {
        $logWhere[]  = 'created_at <= ?';
        $logParams[] = $logFilterDateTo . ' 23:59:59';
    }
    if ($logFilterKeyword !== '') {
        $logWhere[]  = 'message LIKE ?';
        $logParams[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $logFilterKeyword) . '%';
    }
    if (in_array($logFilterLevel, ['info', 'warning', 'error'], true)) {
        $logWhere[]  = 'level = ?';
        $logParams[] = $logFilterLevel;
    }

    $logWhereClause = implode(' AND ', $logWhere);
    $logOrderDir    = strtoupper($logSortDir);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM app_logs WHERE {$logWhereClause}");
    $countStmt->execute($logParams);
    $logTotal = (int) $countStmt->fetchColumn();

    $logOffset = ($logPage - 1) * $logPerPage;
    $dataStmt  = $db->prepare(
        "SELECT id, level, message, created_at
           FROM app_logs
          WHERE {$logWhereClause}
          ORDER BY created_at {$logOrderDir}, id {$logOrderDir}
          LIMIT {$logPerPage} OFFSET {$logOffset}"
    );
    $dataStmt->execute($logParams);
    $logRows = $dataStmt->fetchAll();
} catch (Throwable $_logEx) {
    // Table may not exist on first request before migration runs.
}

$logTotalPages = $logTotal > 0 ? (int) ceil($logTotal / $logPerPage) : 1;

// ── Load data ─────────────────────────────────────────────────────────────────

$endpoints = $db->query(
    'SELECT * FROM endpoints ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$users = $db->query(
    'SELECT id, username, email, email_verified, default_model, can_upload_documents, role, auth_source, created_at, last_login
       FROM users ORDER BY id'
)->fetchAll();

// ── Load statistics ───────────────────────────────────────────────────────────

try {
    $epStats = $db->query('
        SELECT
            e.id,
            e.alias,
            e.base_url,
            e.default_model,
            e.is_active,
            e.ssh_host,
            e.ssh_user,
            COALESCE(SUM(CASE WHEN t.status = \'running\' THEN 1 ELSE 0 END), 0) AS cnt_running,
            COALESCE(SUM(CASE WHEN t.status = \'done\'    THEN 1 ELSE 0 END), 0) AS cnt_done,
            COALESCE(SUM(CASE WHEN t.status = \'error\'   THEN 1 ELSE 0 END), 0) AS cnt_error,
            COALESCE(SUM(CASE WHEN t.status = \'done\'
                              AND t.started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS cnt_done_24h,
            COALESCE(SUM(t.prompt_tokens), 0)     AS sum_prompt_tokens,
            COALESCE(SUM(t.completion_tokens), 0) AS sum_completion_tokens,
            COALESCE(SUM(t.total_tokens), 0)      AS sum_tokens,
            COALESCE(ROUND(AVG(CASE WHEN t.total_tokens IS NOT NULL
                                    THEN t.total_tokens END)), 0) AS avg_tokens,
            COALESCE(SUM(CASE WHEN t.status = \'done\'
                              AND DATE(t.started_at) = CURDATE()
                         THEN 1 ELSE 0 END), 0) AS today_jobs,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.prompt_tokens, 0) ELSE 0 END), 0)     AS today_prompt_tokens,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.completion_tokens, 0) ELSE 0 END), 0) AS today_completion_tokens,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE()
                         THEN COALESCE(t.total_tokens, 0) ELSE 0 END), 0)      AS today_tokens
        FROM endpoints e
        LEFT JOIN tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.alias, e.base_url, e.default_model, e.is_active, e.ssh_host, e.ssh_user
        ORDER BY e.sort_order ASC, e.id ASC
    ')->fetchAll();

    // Attach cached SSH system stats
    $sysStatsByEpId = [];
    try {
        $sysRows = $db->query("
            SELECT endpoint_id, ram_total, ram_used, cpu_load_1m, cpu_load_5m, cpu_temp, fetch_ok, fetched_at
            FROM   endpoint_sys_stats
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sysRows as $sr) {
            $sysStatsByEpId[(int) $sr['endpoint_id']] = [
                'ok'          => (bool) $sr['fetch_ok'],
                'ram_total'   => $sr['ram_total']   !== null ? (int)   $sr['ram_total']   : null,
                'ram_used'    => $sr['ram_used']    !== null ? (int)   $sr['ram_used']    : null,
                'cpu_load_1m' => $sr['cpu_load_1m'] !== null ? (float) $sr['cpu_load_1m'] : null,
                'cpu_load_5m' => $sr['cpu_load_5m'] !== null ? (float) $sr['cpu_load_5m'] : null,
                'cpu_temp'    => $sr['cpu_temp']    !== null ? (float) $sr['cpu_temp']    : null,
                'fetched_at'  => $sr['fetched_at'],
            ];
        }
    } catch (PDOException $_sse) { /* Table may not exist yet – safe to ignore */ }

    foreach ($epStats as &$epRow) {
        $epRow['ssh_configured'] = trim((string) ($epRow['ssh_host'] ?? '')) !== ''
                                && trim((string) ($epRow['ssh_user'] ?? '')) !== '';
        $epRow['sys_stats'] = $sysStatsByEpId[(int) $epRow['id']] ?? null;
    }
    unset($epRow);

    $totals = $db->query('
        SELECT
            COALESCE(SUM(CASE WHEN status = \'running\' THEN 1 ELSE 0 END), 0) AS total_running,
            COALESCE(SUM(CASE WHEN status = \'done\'    THEN 1 ELSE 0 END), 0) AS total_done,
            COALESCE(SUM(CASE WHEN status = \'error\'   THEN 1 ELSE 0 END), 0) AS total_error,
            COALESCE(SUM(CASE WHEN status = \'done\'
                              AND started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS total_done_24h,
            COALESCE(SUM(prompt_tokens), 0)     AS grand_prompt_tokens,
            COALESCE(SUM(completion_tokens), 0) AS grand_completion_tokens,
            COALESCE(SUM(total_tokens), 0) AS grand_tokens
        FROM tasks
    ')->fetch();
} catch (PDOException $e) {
    $epStats = [];
    $totals  = [
        'total_running' => 0, 'total_done' => 0, 'total_error' => 0,
        'total_done_24h' => 0,
        'grand_prompt_tokens' => 0, 'grand_completion_tokens' => 0, 'grand_tokens' => 0,
    ];
}

// ── SearXNG search statistics ─────────────────────────────────────────────────

$searxngStats = ['running' => 0, 'today_jobs' => 0, 'total_done' => 0, 'avg_duration_seconds' => null];
if ($searxngBaseUrl !== '') {
    try {
        $sRow = $db->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS running,
                COALESCE(SUM(CASE WHEN status = 'done'
                                  AND DATE(started_at) = CURDATE()
                             THEN 1 ELSE 0 END), 0) AS today_jobs,
                COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) AS total_done,
                AVG(CASE WHEN status = 'done'
                              AND DATE(started_at) = CURDATE()
                              AND finished_at IS NOT NULL
                         THEN TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) / 1000000.0
                         ELSE NULL END) AS avg_duration_seconds
            FROM search_logs
        ")->fetch();
        $searxngStats = [
            'running'              => (int) $sRow['running'],
            'today_jobs'           => (int) $sRow['today_jobs'],
            'total_done'           => (int) $sRow['total_done'],
            'avg_duration_seconds' => $sRow['avg_duration_seconds'] !== null
                                        ? round((float) $sRow['avg_duration_seconds'], 1)
                                        : null,
        ];
    } catch (PDOException $e) {
        // search_logs table may not exist on older installations
    }
}

// ── SD endpoints and statistics ───────────────────────────────────────────────

$sdEndpoints = [];
$sdStats     = [];
$sdTotals    = ['total_running' => 0, 'total_done' => 0, 'total_error' => 0, 'total_done_24h' => 0];
$editSdEp    = null;

try {
    $sdEndpoints = $db->query(
        'SELECT * FROM sd_endpoints ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet
}

try {
    $sdStats = $db->query("
        SELECT
            e.id,
            e.base_url,
            e.is_active,
            COALESCE(SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END), 0) AS cnt_running,
            COALESCE(SUM(CASE WHEN t.status = 'done'    THEN 1 ELSE 0 END), 0) AS cnt_done,
            COALESCE(SUM(CASE WHEN t.status = 'error'   THEN 1 ELSE 0 END), 0) AS cnt_error,
            COALESCE(SUM(CASE WHEN t.status = 'done'
                              AND t.started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS cnt_done_24h,
            COALESCE(SUM(CASE WHEN t.status = 'done'
                              AND DATE(t.started_at) = CURDATE()
                         THEN 1 ELSE 0 END), 0) AS today_jobs,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_tasks
        FROM sd_endpoints e
        LEFT JOIN sd_tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.base_url, e.is_active
        ORDER BY e.sort_order ASC, e.id ASC
    ")->fetchAll();

    $sdTotalsRow = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS total_running,
            COALESCE(SUM(CASE WHEN status = 'done'    THEN 1 ELSE 0 END), 0) AS total_done,
            COALESCE(SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END), 0) AS total_error,
            COALESCE(SUM(CASE WHEN status = 'done'
                              AND started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS total_done_24h
        FROM sd_tasks
    ")->fetch();

    if ($sdTotalsRow) {
        $sdTotals = $sdTotalsRow;
    }
} catch (PDOException $e) {
    // Tables may not exist yet
}

// Populate $editSdEp if the URL requests editing a specific SD endpoint.
if (isset($_GET['edit_sd']) && (int) $_GET['edit_sd'] > 0) {
    $editSdId = (int) $_GET['edit_sd'];
    foreach ($sdEndpoints as $sdEp) {
        if ((int) $sdEp['id'] === $editSdId) {
            $editSdEp = $sdEp;
            break;
        }
    }
}

// ── ComfyUI endpoints and statistics ─────────────────────────────────────────

$comfyEndpoints = [];
$comfyStats     = [];
$comfyTotals    = ['total_running' => 0, 'total_done' => 0, 'total_error' => 0, 'total_done_24h' => 0];
$editComfyEp    = null;

try {
    $comfyEndpoints = $db->query(
        'SELECT * FROM comfy_endpoints ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet
}

try {
    $comfyStats = $db->query("
        SELECT
            e.id,
            e.base_url,
            e.is_active,
            COALESCE(SUM(CASE WHEN t.status = 'running' THEN 1 ELSE 0 END), 0) AS cnt_running,
            COALESCE(SUM(CASE WHEN t.status = 'done'    THEN 1 ELSE 0 END), 0) AS cnt_done,
            COALESCE(SUM(CASE WHEN t.status = 'error'   THEN 1 ELSE 0 END), 0) AS cnt_error,
            COALESCE(SUM(CASE WHEN t.status = 'done'
                              AND t.started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS cnt_done_24h,
            COALESCE(SUM(CASE WHEN t.status = 'done'
                              AND DATE(t.started_at) = CURDATE()
                         THEN 1 ELSE 0 END), 0) AS today_jobs,
            COALESCE(SUM(CASE WHEN DATE(t.started_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_tasks
        FROM comfy_endpoints e
        LEFT JOIN comfy_tasks t ON t.endpoint_id = e.id
        GROUP BY e.id, e.base_url, e.is_active
        ORDER BY e.sort_order ASC, e.id ASC
    ")->fetchAll();

    $comfyTotalsRow = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END), 0) AS total_running,
            COALESCE(SUM(CASE WHEN status = 'done'    THEN 1 ELSE 0 END), 0) AS total_done,
            COALESCE(SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END), 0) AS total_error,
            COALESCE(SUM(CASE WHEN status = 'done'
                              AND started_at >= NOW() - INTERVAL 24 HOUR
                         THEN 1 ELSE 0 END), 0) AS total_done_24h
        FROM comfy_tasks
    ")->fetch();

    if ($comfyTotalsRow) {
        $comfyTotals = $comfyTotalsRow;
    }
} catch (PDOException $e) {
    // Tables may not exist yet
}

// Populate $editComfyEp if the URL requests editing a specific ComfyUI endpoint.
if (isset($_GET['edit_comfy']) && (int) $_GET['edit_comfy'] > 0) {
    $editComfyId = (int) $_GET['edit_comfy'];
    foreach ($comfyEndpoints as $comfyEp) {
        if ((int) $comfyEp['id'] === $editComfyId) {
            $editComfyEp = $comfyEp;
            break;
        }
    }
}

// ── Embedding endpoints and statistics ────────────────────────────────────────

$embeddingEndpoints = [];
$editEmbEp          = null;
$embeddingStatsRow  = [
    'total_chunks'       => 0,
    'chunks_with_embed'  => 0,
    'chunks_missing'     => 0,
    'uploads_done'       => 0,
    'uploads_pending'    => 0,
    'uploads_error'      => 0,
    'cache_entries'      => 0,
    'avg_duration_ms'    => null,
    'avg_rerank_ms'      => null,
    'error_count'        => 0,
];

try {
    $embeddingEndpoints = $db->query(
        'SELECT * FROM embedding_endpoints ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    // Table may not exist yet – migrations run on first getDb() call.
}

try {
    $totalChunks = (int) $db->query('SELECT COUNT(*) FROM document_chunks')->fetchColumn();
    $chunksWithEmbed = (int) $db->query("SELECT COUNT(*) FROM document_chunks WHERE embedding IS NOT NULL AND embedding != ''")->fetchColumn();
    $embeddingStatsRow['total_chunks']      = $totalChunks;
    $embeddingStatsRow['chunks_with_embed'] = $chunksWithEmbed;
    $embeddingStatsRow['chunks_missing']    = max(0, $totalChunks - $chunksWithEmbed);
    $embeddingStatsRow['uploads_done']    = (int) $db->query("SELECT COUNT(*) FROM document_uploads WHERE embedding_status = 'done'")->fetchColumn();
    $embeddingStatsRow['uploads_pending'] = (int) $db->query("SELECT COUNT(*) FROM document_uploads WHERE embedding_status IN ('pending','processing')")->fetchColumn();
    $embeddingStatsRow['uploads_error']   = (int) $db->query("SELECT COUNT(*) FROM document_uploads WHERE embedding_status = 'error'")->fetchColumn();
} catch (PDOException $e) { /* table may not exist */ }

try {
    $embeddingStatsRow['cache_entries'] = (int) $db->query('SELECT COUNT(*) FROM embedding_cache')->fetchColumn();
} catch (PDOException $e) { /* table may not exist */ }

try {
    $elRow = $db->query("
        SELECT
            AVG(CASE WHEN type = 'query' AND status = 'ok' THEN duration_ms END) AS avg_query_ms,
            AVG(CASE WHEN type = 'rerank' AND status = 'ok' THEN duration_ms END) AS avg_rerank_ms,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_count
        FROM embedding_logs
        WHERE created_at >= NOW() - INTERVAL 24 HOUR
    ")->fetch();
    if ($elRow) {
        $embeddingStatsRow['avg_duration_ms'] = $elRow['avg_query_ms'] !== null ? round((float) $elRow['avg_query_ms'], 1) : null;
        $embeddingStatsRow['avg_rerank_ms']   = $elRow['avg_rerank_ms'] !== null ? round((float) $elRow['avg_rerank_ms'], 1) : null;
        $embeddingStatsRow['error_count']     = (int) ($elRow['error_count'] ?? 0);
    }
} catch (PDOException $e) { /* table may not exist */ }

// Populate $editEmbEp if the URL requests editing a specific embedding endpoint.
if (isset($_GET['edit_emb']) && (int) $_GET['edit_emb'] > 0) {
    $editEmbId = (int) $_GET['edit_emb'];
    foreach ($embeddingEndpoints as $embEp) {
        if ((int) $embEp['id'] === $editEmbId) {
            $editEmbEp = $embEp;
            break;
        }
    }
}

$clientStats = ['current' => 0, 'today_min' => 0, 'today_max' => 0, 'today_avg' => 0.0];
try {
    $current = (int) $db->query(
        "SELECT COUNT(*) FROM active_clients
          WHERE last_seen > DATE_SUB(NOW(), INTERVAL 90 SECOND)"
    )->fetchColumn();

    $cRow = $db->query(
        "SELECT MIN(cnt) AS min_cnt, MAX(cnt) AS max_cnt, AVG(cnt) AS avg_cnt
           FROM client_count_log
          WHERE DATE(recorded_at) = CURDATE()"
    )->fetch(PDO::FETCH_ASSOC);

    $clientStats = [
        'current'   => $current,
        'today_min' => ($cRow && $cRow['min_cnt'] !== null) ? (int) $cRow['min_cnt'] : $current,
        'today_max' => ($cRow && $cRow['max_cnt'] !== null) ? (int) $cRow['max_cnt'] : $current,
        'today_avg' => ($cRow && $cRow['avg_cnt'] !== null) ? round((float) $cRow['avg_cnt'], 1) : (float) $current,
    ];
} catch (PDOException $e) {
    // Tables may not exist on older installations
}

// Assign a stable colour to each distinct model name.
$palette       = ['#6c63ff', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#f97316', '#84cc16'];
$modelColorMap = [];
$colorIdx      = 0;

/**
 * Extracts the intelligence label (e.g. "7b", "27b") from a model name.
 */
function modelIntelligenceLabel(string $model): string
{
    if ($model === '') {
        return '–';
    }
    if (!preg_match_all('/(\d+(?:[.,]\d+)?)\s*b\b/i', $model, $matches) || empty($matches[1])) {
        return '–';
    }
    $best = null;
    foreach ($matches[1] as $raw) {
        $num = (float) str_replace(',', '.', $raw);
        if ($num <= 0) {
            continue;
        }
        if ($best === null || $num > $best) {
            $best = $num;
        }
    }
    if ($best === null) {
        return '–';
    }
    if (abs($best - round($best)) < 0.00001) {
        return ((string) ((int) round($best))) . 'b';
    }
    return rtrim(rtrim(number_format($best, 2, '.', ''), '0'), '.') . 'b';
}

foreach ($endpoints as $ep) {
    $m = $ep['default_model'];
    if ($m !== '' && !isset($modelColorMap[$m])) {
        $modelColorMap[$m] = $palette[$colorIdx % count($palette)];
        $colorIdx++;
    }
}

$availableGuestModels = [];
foreach ($endpoints as $ep) {
    $model = trim((string) ($ep['default_model'] ?? ''));
    if ((int) ($ep['is_active'] ?? 0) !== 1 || $model === '' || isset($availableGuestModels[$model])) {
        continue;
    }
    $availableGuestModels[$model] = $model;
}

// Populate $editEp if the URL requests editing a specific endpoint.
if (isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
    $epId = (int) $_GET['edit'];
    foreach ($endpoints as $ep) {
        if ($ep['id'] === $epId) {
            $editEp = $ep;
            break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – LM Studio Chat</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #212121;
            --surface:     #2f2f2f;
            --surface-alt: #3a3a3a;
            --border:      rgba(255,255,255,.08);
            --accent:      #6c63ff;
            --accent-dark: #5249cc;
            --text:        #ececf1;
            --text-muted:  #8e8ea0;
            --error:       #ef4444;
            --success:     #22c55e;
            --warning:     #f59e0b;
            --radius:      12px;
            --font:        ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ──────────────────────────────────────────────── */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 24px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }

        header h1 { font-size: 1.1rem; font-weight: 600; }

        .header-right {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: .82rem;
            color: var(--text-muted);
        }

        .header-right a { color: var(--text-muted); text-decoration: none; }
        .header-right a:hover { color: var(--text); }

        /* ── Page body (sidebar + main) ─────────────────────────── */
        .page-body {
            display: flex;
            flex: 1;
            align-items: flex-start;
            min-height: 0;
        }

        /* ── Left navigation sidebar ─────────────────────────────── */
        .sidebar {
            position: sticky;
            top: 0;
            height: 100dvh;
            width: 200px;
            min-width: 200px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 20px 0 20px;
            gap: 2px;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .sidebar-label {
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 14px 16px 6px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 16px;
            font-size: .82rem;
            color: var(--text-muted);
            text-decoration: none;
            border-left: 2px solid transparent;
            transition: color .12s, background .12s, border-color .12s;
            white-space: nowrap;
        }

        .sidebar a:hover {
            color: var(--text);
            background: rgba(255,255,255,.04);
        }

        .sidebar a.active {
            color: var(--accent);
            border-left-color: var(--accent);
            background: rgba(108,99,255,.08);
        }

        /* ── Main layout ─────────────────────────────────────────── */
        main {
            flex: 1;
            padding: 28px 32px;
            max-width: 1440px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 28px;
            min-width: 0;
        }

        /* ── Card ────────────────────────────────────────────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            order: 10;
        }

        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .card h3 {
            font-size: .9rem;
            font-weight: 600;
            margin: 20px 0 12px;
        }

        #dashboard-card { order: 1; }
        #config-smtp-card { order: 3; }
        #config-searxng-card { order: 4; }
        #config-endpoints-card { order: 5; }
        #config-request-handling-card { order: 6; }
        #config-sd-card { order: 7; }
        #config-comfy-card { order: 8; }
        #config-routing-card { order: 9; }
        #config-embedding-card { order: 10; }
        #config-hybrid-search-card { order: 11; }
        #config-reranker-card { order: 12; }
        #embedding-stats-card { order: 13; }
        #config-system-messages-card { order: 14; }
        #log-config-card { order: 15; }
        #log-viewer-card { order: 16; }

        /* ── User row hover ──────────────────────────────────────── */
        .user-row:hover td { background: rgba(108,99,255,.06); }

        .config-panel > summary {
            list-style: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .config-panel > summary::-webkit-details-marker { display: none; }
        .config-panel > summary::before {
            content: '▸';
            font-size: .9rem;
            color: var(--text-muted);
            transform: translateY(1px);
        }
        .config-panel[open] > summary::before { content: '▾'; }

        /* ── Form elements ────────────────────────────────────────── */
        .form-group { margin-bottom: 14px; }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .form-row .form-group { flex: 1 1 180px; }

        label {
            display: block;
            font-size: .82rem;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        label.inline {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
            cursor: pointer;
        }

        input[type="text"],
        input[type="url"],
        input[type="number"],
        input[type="password"],
        select {
            width: 100%;
            padding: 8px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-size: .88rem;
            font-family: var(--font);
        }

        input:focus, select:focus { outline: none; border-color: var(--accent); }

        .hint {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* ── Buttons ─────────────────────────────────────────────── */
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: .88rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
            background: var(--surface-alt);
            color: var(--text);
        }

        .btn:hover { background: #464646; }

        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-dark); }

        .btn-danger { background: rgba(239,68,68,.15); color: var(--error); }
        .btn-danger:hover { background: rgba(239,68,68,.25); }

        .btn-sm {
            padding: 4px 12px;
            font-size: .8rem;
            border-radius: 8px;
        }

        /* ── Flash messages ──────────────────────────────────────── */
        .flash-ok {
            background: rgba(76,175,125,.12);
            border: 1px solid rgba(76,175,125,.4);
            border-radius: var(--radius);
            color: var(--success);
            font-size: .85rem;
            padding: 10px 14px;
            margin-bottom: 18px;
        }

        .flash-error {
            background: rgba(224,92,92,.12);
            border: 1px solid rgba(224,92,92,.4);
            border-radius: var(--radius);
            color: var(--error);
            font-size: .85rem;
            padding: 10px 14px;
            margin-bottom: 18px;
        }

        /* ── Generic table ───────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }

        .data-table th {
            text-align: left;
            padding: 8px 12px;
            color: var(--text-muted);
            font-weight: 500;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--surface-alt);
            vertical-align: middle;
        }

        .data-table tr:last-child td { border-bottom: none; }

        /* ── Model badge ─────────────────────────────────────────── */
        .model-badge {
            display: inline-block;
            font-size: .72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 20px;
            color: #fff;
            white-space: nowrap;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge-empty {
            background: var(--surface-alt);
            color: var(--text-muted);
        }

        /* ── Status dots ─────────────────────────────────────────── */
        .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .dot-on  { background: var(--success); }
        .dot-off { background: var(--text-muted); }

        /* ── Stat summary boxes ──────────────────────────────────── */
        .stat-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-box {
            flex: 1 1 120px;
            background: var(--surface-alt);
            border-radius: var(--radius);
            padding: 14px 16px;
            text-align: center;
        }

        .stat-box .stat-val {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-box .stat-lbl {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .stat-running { color: var(--warning); }
        .stat-done    { color: var(--success); }
        .stat-error   { color: var(--error); }
        .stat-tokens  { color: var(--accent); }

        /* ── Add/edit form ───────────────────────────────────────── */
        .ep-form-section {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .ep-form-section h3 { margin-top: 0; }

        /* ── Action row for forms ────────────────────────────────── */
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ── User table ──────────────────────────────────────────── */
        .badge-you {
            font-size: .7rem;
            background: var(--accent);
            color: #fff;
            padding: 1px 7px;
            border-radius: 20px;
            margin-left: 6px;
            vertical-align: middle;
        }

        /* ── Separator ───────────────────────────────────────────── */
        .sep { color: var(--text-muted); }

        /* ── Load-distribution tree ──────────────────────────────── */
        #load-tree-container {
            position: relative;
            width: 100%;
            height: 480px;
            overflow: hidden;
            cursor: grab;
            border-radius: 10px;
            background: rgba(0,0,0,.15);
            user-select: none;
        }

        #load-tree-container.dragging { cursor: grabbing; }

        #load-tree-svg {
            display: block;
            width: 100%;
            height: 100%;
            touch-action: none;
        }

        .tree-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .tree-header-row h2 { margin: 0; padding: 0; border: none; }

        .tree-header-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .tree-refresh-info {
            font-size: .75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tree-refresh-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--success);
        }

        .tree-reset-btn {
            padding: 3px 10px;
            background: var(--surface-alt);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            font-size: .75rem;
            cursor: pointer;
            line-height: 1.5;
            transition: color .12s, background .12s;
        }
        .tree-reset-btn:hover { background: #464646; color: var(--text); }

        @keyframes tree-pulse {
            0%,100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(1.8); opacity: .35; }
        }

        .pulse-dot {
            animation: tree-pulse 1.4s ease-in-out infinite;
            transform-origin: center;
        }

        @keyframes tree-fade-in {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .tree-refresh-spin {
            display: inline-block;
            animation: spin .8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Animated data-flow connectors ───────────────────────── */
        @keyframes dash-flow {
            to { stroke-dashoffset: -22; }
        }
        @keyframes dash-flow-fast {
            to { stroke-dashoffset: -22; }
        }
        .conn-idle {
            stroke-dasharray: 6 5;
            animation: dash-flow 2.5s linear infinite;
        }
        .conn-active {
            stroke-dasharray: 8 3;
            animation: dash-flow-fast .65s linear infinite;
        }

        /* ── Stat-box flash on live update ───────────────────────── */
        @keyframes stat-flash {
            0%   { background: rgba(108,99,255,.32); }
            100% { background: var(--surface-alt); }
        }
        .stat-box.stat-flash { animation: stat-flash .55s ease-out; }

        /* ── Dashboard detail tables (collapsed by default) ──────── */
        .dash-detail-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            cursor: pointer;
            font-size: .82rem;
            color: var(--text-muted);
            user-select: none;
        }
        .dash-detail-toggle:hover { color: var(--text); }
        .dash-detail-toggle .toggle-arrow { transition: transform .2s; }
        .dash-detail-toggle.open .toggle-arrow { transform: rotate(90deg); }
        .dash-detail-body { display: none; margin-top: 14px; }
        .dash-detail-body.open { display: block; }
    </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<header>
    <h1>⚙️ Admin-Bereich</h1>
    <div class="header-right">
        <span>Angemeldet als <strong><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></span>
        <a href="../index.php">← Zum Chat</a>
        <a href="logout.php">Abmelden</a>
    </div>
</header>

<div class="page-body">

<!-- ── Left sidebar navigation ──────────────────────────────────────────────── -->
<aside class="sidebar">
    <span class="sidebar-label">Übersicht</span>
    <a href="#dashboard-card">🚀 Dashboard</a>

    <span class="sidebar-label">Konfiguration</span>
    <a href="#config-smtp-card">📧 E-Mail (SMTP)</a>
    <a href="#config-ldap-card">🏢 Active Directory</a>
    <a href="#config-searxng-card">🔎 Websuche</a>
    <a href="#config-endpoints-card">🔗 Endpunkte</a>
    <a href="#config-request-handling-card">📨 Anfragenhandling</a>
    <a href="#config-sd-card">🎨 AUTOMATIC1111</a>
    <a href="#config-comfy-card">🖼️ ComfyUI</a>
    <a href="#config-routing-card">🧭 Modellrouting</a>
    <a href="#config-decision-card">🗂️ Entscheidungsfindung</a>

    <span class="sidebar-label">Hybrid-RAG</span>
    <a href="#config-embedding-card">🧬 Embedding-Endpunkte</a>
    <a href="#config-hybrid-search-card">🔀 Hybrid-Suche</a>
    <a href="#config-reranker-card">🏆 Reranker</a>
    <a href="#embedding-stats-card">📊 RAG-Statistik</a>

    <span class="sidebar-label">Systemmeldungen</span>
    <a href="#config-system-messages-card">💬 Systemmeldungen</a>

    <span class="sidebar-label">Protokollierung</span>
    <a href="#log-config-card">⚙️ Konfiguration</a>
    <a href="#log-viewer-card">📋 Log</a>

    <span class="sidebar-label">Verwaltung</span>
    <a href="#users-card">👤 Benutzerkonten</a>
    <a href="api_keys.php">🗝️ API-Keys</a>
    <a href="#password-card">🔑 Passwort ändern</a>
</aside>

<main>

    <?php if (!empty($_SESSION['requires_password_change'])): ?>
        <div class="flash-error" style="border-color:var(--warning);color:var(--warning);background:rgba(245,158,11,.1)">
            ⚠ Du hast dich mit einem temporären Passwort angemeldet.
            Bitte ändere dein Passwort sofort unter <strong>Passwort ändern</strong>.
        </div>
    <?php endif; ?>

    <?php if ($flashOk !== ''): ?>
        <div class="flash-ok">✓ <?= htmlspecialchars($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="flash-error">✗ <?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Dashboard – Statistik & Lastverteilung (live)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="dashboard-card">

        <!-- Header row -->
        <div class="tree-header-row">
            <h2 style="margin:0;padding:0;border:none">🚀 Dashboard</h2>
            <div class="tree-header-controls">
                <button class="tree-reset-btn" id="tree-reset-btn" title="Ansicht zurücksetzen">⊡ Ansicht zurücksetzen</button>
                <div class="tree-refresh-info">
                    <span class="tree-refresh-dot" id="tree-live-dot"></span>
                    <span id="tree-status">Initialisierung …</span>
                </div>
            </div>
        </div>

        <!-- Summary stat boxes -->
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-val stat-running" id="db-llm-running"><?= number_format((int) $totals['total_running']) ?></div>
                <div class="stat-lbl">LLM laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-llm-done24"><?= number_format((int) $totals['total_done_24h']) ?></div>
                <div class="stat-lbl">LLM erledigt (24 h)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-llm-done"><?= number_format((int) $totals['total_done']) ?></div>
                <div class="stat-lbl">LLM erledigt (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-error" id="db-llm-error"><?= number_format((int) $totals['total_error']) ?></div>
                <div class="stat-lbl">LLM Fehler (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-prompt-tok"><?= number_format((int) $totals['grand_prompt_tokens']) ?></div>
                <div class="stat-lbl">Prompt Token (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-comp-tok"><?= number_format((int) $totals['grand_completion_tokens']) ?></div>
                <div class="stat-lbl">Completion Token (gesamt)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-tokens" id="db-total-tok"><?= number_format((int) $totals['grand_tokens']) ?></div>
                <div class="stat-lbl">Total Token (gesamt)</div>
            </div>
            <?php if ($searxngBaseUrl !== ''): ?>
            <div class="stat-box">
                <div class="stat-val stat-running" id="db-srxng-running"><?= number_format($searxngStats['running']) ?></div>
                <div class="stat-lbl">Suchen laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-srxng-today"><?= number_format($searxngStats['today_jobs']) ?></div>
                <div class="stat-lbl">Suchen heute</div>
            </div>
            <?php endif; ?>
            <?php if (!empty($sdEndpoints)): ?>
            <div class="stat-box">
                <div class="stat-val stat-running" id="db-sd-running"><?= number_format((int) $sdTotals['total_running']) ?></div>
                <div class="stat-lbl">SD laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-sd-done24"><?= number_format((int) $sdTotals['total_done_24h']) ?></div>
                <div class="stat-lbl">SD Bilder (24 h)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-sd-done"><?= number_format((int) $sdTotals['total_done']) ?></div>
                <div class="stat-lbl">SD Bilder (gesamt)</div>
            </div>
            <?php endif; ?>
            <?php if (!empty($comfyEndpoints)): ?>
            <div class="stat-box">
                <div class="stat-val stat-running" id="db-comfy-running"><?= number_format((int) $comfyTotals['total_running']) ?></div>
                <div class="stat-lbl">ComfyUI laufend</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-comfy-done24"><?= number_format((int) $comfyTotals['total_done_24h']) ?></div>
                <div class="stat-lbl">ComfyUI (24 h)</div>
            </div>
            <div class="stat-box">
                <div class="stat-val stat-done" id="db-comfy-done"><?= number_format((int) $comfyTotals['total_done']) ?></div>
                <div class="stat-lbl">ComfyUI (gesamt)</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Live load-distribution visualization -->
        <div id="load-tree-container">
            <svg id="load-tree-svg"
                 xmlns="http://www.w3.org/2000/svg"
                 preserveAspectRatio="xMinYMin meet"
                 aria-label="Horizontale Lastverteilung der Endpunkte">
            </svg>
        </div>

        <!-- Collapsible endpoint detail tables -->
        <div class="dash-detail-toggle" id="dash-detail-toggle">
            <span class="toggle-arrow">▶</span>
            <span>Endpunkt-Details</span>
        </div>
        <div class="dash-detail-body" id="dash-detail-body">

        <?php if (empty($epStats)): ?>
            <p style="color:var(--text-muted)">Noch keine LLM-Aufgaben verarbeitet.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Endpunkt</th>
                    <th>Modell-Gruppe</th>
                    <th style="text-align:right">Laufend</th>
                    <th style="text-align:right">Erledigt (24 h)</th>
                    <th style="text-align:right">Erledigt (gesamt)</th>
                    <th style="text-align:right">Fehler</th>
                    <th style="text-align:right">⌀ Token</th>
                    <th style="text-align:right">Prompt Token</th>
                    <th style="text-align:right">Completion Token</th>
                    <th style="text-align:right">Total Token</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($epStats as $s): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($s['base_url']) ?>">
                        <span class="dot <?= $s['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($s['alias'] !== '' ? $s['alias'] : $s['base_url']) ?>
                    </td>
                    <td>
                        <?php if ($s['default_model'] !== ''): ?>
                            <span class="model-badge"
                                  style="background:<?= htmlspecialchars($modelColorMap[$s['default_model']] ?? '#555') ?>">
                                <?= htmlspecialchars($s['default_model']) ?>
                            </span>
                        <?php else: ?>
                            <span class="model-badge badge-empty">–</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;color:var(--warning)"><?= number_format((int) $s['cnt_running']) ?></td>
                    <td style="text-align:right"><?= number_format((int) $s['cnt_done_24h']) ?></td>
                    <td style="text-align:right;color:var(--success)"><?= number_format((int) $s['cnt_done']) ?></td>
                    <td style="text-align:right;color:var(--error)"><?= number_format((int) $s['cnt_error']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['avg_tokens']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['sum_prompt_tokens']) ?></td>
                    <td style="text-align:right;color:var(--text-muted)"><?= number_format((int) $s['sum_completion_tokens']) ?></td>
                    <td style="text-align:right;color:var(--accent)"><?= number_format((int) $s['sum_tokens']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <?php if (!empty($sdStats)): ?>
        <h3 style="margin-top:24px;margin-bottom:12px;font-size:.9rem;font-weight:600;">🎨 Bildgenerierung (AUTOMATIC1111)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>SD-Endpunkt</th>
                    <th style="text-align:right">Laufend</th>
                    <th style="text-align:right">Erledigt (24 h)</th>
                    <th style="text-align:right">Erledigt (gesamt)</th>
                    <th style="text-align:right">Fehler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sdStats as $s): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($s['base_url']) ?>">
                        <span class="dot <?= $s['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($s['base_url']) ?>
                    </td>
                    <td style="text-align:right;color:var(--warning)"><?= number_format((int) $s['cnt_running']) ?></td>
                    <td style="text-align:right"><?= number_format((int) $s['cnt_done_24h']) ?></td>
                    <td style="text-align:right;color:var(--success)"><?= number_format((int) $s['cnt_done']) ?></td>
                    <td style="text-align:right;color:var(--error)"><?= number_format((int) $s['cnt_error']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if (!empty($comfyStats)): ?>
        <h3 style="margin-top:24px;margin-bottom:12px;font-size:.9rem;font-weight:600;">🖼️ Bildgenerierung (ComfyUI)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>ComfyUI-Endpunkt</th>
                    <th style="text-align:right">Laufend</th>
                    <th style="text-align:right">Erledigt (24 h)</th>
                    <th style="text-align:right">Erledigt (gesamt)</th>
                    <th style="text-align:right">Fehler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comfyStats as $s): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.78rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($s['base_url']) ?>">
                        <span class="dot <?= $s['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= htmlspecialchars($s['base_url']) ?>
                    </td>
                    <td style="text-align:right;color:var(--warning)"><?= number_format((int) $s['cnt_running']) ?></td>
                    <td style="text-align:right"><?= number_format((int) $s['cnt_done_24h']) ?></td>
                    <td style="text-align:right;color:var(--success)"><?= number_format((int) $s['cnt_done']) ?></td>
                    <td style="text-align:right;color:var(--error)"><?= number_format((int) $s['cnt_error']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        </div><!-- /.dash-detail-body -->
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         SMTP / Outgoing Mail Server
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-smtp-card">
        <details class="config-panel" id="config-smtp" open>
            <summary>📧 E-Mail (SMTP)</summary>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action"     value="save_smtp_settings">

            <div class="form-row">
                <div class="form-group" style="flex:3">
                    <label for="smtp-host">SMTP-Host</label>
                    <input type="text" id="smtp-host" name="smtp_host"
                           placeholder="smtp.example.com"
                           value="<?= htmlspecialchars($smtpHost) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:120px">
                    <label for="smtp-port">Port</label>
                    <input type="number" id="smtp-port" name="smtp_port"
                           min="1" max="65535"
                           value="<?= htmlspecialchars($smtpPort) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:140px">
                    <label for="smtp-encryption">Verschlüsselung</label>
                    <select id="smtp-encryption" name="smtp_encryption">
                        <option value="tls"  <?= $smtpEncryption === 'tls'  ? 'selected' : '' ?>>STARTTLS (587)</option>
                        <option value="ssl"  <?= $smtpEncryption === 'ssl'  ? 'selected' : '' ?>>SSL/TLS (465)</option>
                        <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>Keine</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="smtp-auth" name="smtp_auth" value="1"
                               <?= $smtpAuth ? 'checked' : '' ?>>
                        Authentifizierung erforderlich
                    </label>
                </div>
            </div>

            <div id="smtp-auth-fields" <?= $smtpAuth ? '' : 'style="display:none"' ?>>
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-user">SMTP-Benutzername</label>
                    <input type="text" id="smtp-user" name="smtp_user"
                           autocomplete="off"
                           placeholder="nutzer@example.com"
                           value="<?= htmlspecialchars($smtpUser) ?>">
                </div>
                <div class="form-group">
                    <label for="smtp-pass">SMTP-Passwort</label>
                    <input type="password" id="smtp-pass" name="smtp_pass"
                           autocomplete="new-password"
                           placeholder="<?= $smtpPass !== '' ? '(gespeichert – leer lassen zum Beibehalten)' : 'Passwort eingeben' ?>">
                    <p class="hint">Leer lassen, um das gespeicherte Passwort beizubehalten.</p>
                </div>
            </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp-from-email">Absender-E-Mail</label>
                    <input type="email" id="smtp-from-email" name="smtp_from_email"
                           placeholder="noreply@example.com"
                           value="<?= htmlspecialchars($smtpFromEmail) ?>">
                </div>
                <div class="form-group">
                    <label for="smtp-from-name">Absender-Name</label>
                    <input type="text" id="smtp-from-name" name="smtp_from_name"
                           placeholder="LLMInt"
                           value="<?= htmlspecialchars($smtpFromName) ?>">
                </div>
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="smtp-test-btn" class="btn"
                        <?= $smtpHost === '' ? 'disabled title="Zuerst SMTP-Einstellungen speichern"' : '' ?>>
                    🔌 Test-E-Mail senden
                </button>
                <input type="email" id="smtp-test-to" name="smtp_test_to"
                       placeholder="empfänger@example.com"
                       style="padding:8px 12px;background:var(--bg);border:1px solid var(--border);
                              border-radius:var(--radius);color:var(--text);font-size:.88rem;
                              font-family:var(--font);flex:1;min-width:200px;max-width:320px">
                <span id="smtp-test-result" style="font-size:.85rem"></span>
            </div>
            </form>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Active Directory / LDAP
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-ldap-card">
        <details class="config-panel" id="config-ldap" open>
            <summary>🏢 Active Directory (LDAP)</summary>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action"     value="save_ldap_settings">

            <!-- Enable toggle -->
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="ldap-enabled" name="ldap_enabled" value="1"
                           <?= $ldapEnabled ? 'checked' : '' ?>>
                    LDAP / Active-Directory-Anmeldung aktivieren
                </label>
                <p class="hint">
                    Wenn aktiv, können sich Benutzer mit ihren AD-Zugangsdaten anmelden.
                    Konten werden beim ersten Login automatisch angelegt (Just-in-Time Provisioning).
                </p>
            </div>

            <div id="ldap-settings-fields" <?= $ldapEnabled ? '' : 'style="display:none"' ?>>

            <!-- Server -->
            <div class="form-row">
                <div class="form-group" style="flex:3">
                    <label for="ldap-host">LDAP-/AD-Server</label>
                    <input type="text" id="ldap-host" name="ldap_host"
                           placeholder="ad.example.com"
                           value="<?= htmlspecialchars($ldapHost) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:110px">
                    <label for="ldap-port">Port</label>
                    <input type="number" id="ldap-port" name="ldap_port"
                           min="1" max="65535"
                           value="<?= htmlspecialchars($ldapPort) ?>">
                </div>
                <div class="form-group" style="flex:1;min-width:120px;justify-content:flex-end">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:22px">
                        <input type="checkbox" id="ldap-use-ssl" name="ldap_use_ssl" value="1"
                               <?= $ldapUseSsl ? 'checked' : '' ?>>
                        LDAPS (SSL)
                    </label>
                </div>
            </div>

            <!-- Domain / Base-DN -->
            <div class="form-row">
                <div class="form-group">
                    <label for="ldap-domain">Domain (für UPN-Bind)</label>
                    <input type="text" id="ldap-domain" name="ldap_domain"
                           placeholder="example.com"
                           value="<?= htmlspecialchars($ldapDomain) ?>">
                    <p class="hint">Wird als <code>benutzername@domain</code> für den Bind verwendet. Leer lassen, wenn kein UPN-Bind gewünscht.</p>
                </div>
                <div class="form-group">
                    <label for="ldap-base-dn">Base-DN (Nutzersuche)</label>
                    <input type="text" id="ldap-base-dn" name="ldap_base_dn"
                           placeholder="DC=example,DC=com"
                           value="<?= htmlspecialchars($ldapBaseDn) ?>">
                    <p class="hint">Basis-Pfad für die LDAP-Suche nach Benutzerattributen (z. B. E-Mail). Optional.</p>
                </div>
            </div>

            <!-- Service account -->
            <p class="hint" style="margin-bottom:12px">
                <strong>Optionales Dienstkonto</strong> – wird für die Nutzerattribut-Suche verwendet.
                Ohne Dienstkonto werden E-Mail und Anzeigename nicht ausgelesen (kein anonymer AD-Zugriff).
            </p>
            <div class="form-row">
                <div class="form-group">
                    <label for="ldap-bind-dn">Dienstkonto-DN</label>
                    <input type="text" id="ldap-bind-dn" name="ldap_bind_dn"
                           autocomplete="off"
                           placeholder="CN=svc-ldap,OU=ServiceAccounts,DC=example,DC=com"
                           value="<?= htmlspecialchars($ldapBindDn) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-bind-password">Dienstkonto-Passwort</label>
                    <input type="password" id="ldap-bind-password" name="ldap_bind_password"
                           autocomplete="new-password"
                           placeholder="<?= $ldapBindPassword !== '' ? '(gespeichert – leer lassen zum Beibehalten)' : 'Passwort eingeben' ?>">
                    <p class="hint">Leer lassen, um das gespeicherte Passwort beizubehalten.</p>
                </div>
            </div>

            <!-- Attribute mapping -->
            <div class="form-row">
                <div class="form-group">
                    <label for="ldap-user-attr">Benutzername-Attribut</label>
                    <input type="text" id="ldap-user-attr" name="ldap_user_attr"
                           placeholder="sAMAccountName"
                           value="<?= htmlspecialchars($ldapUserAttr) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-email-attr">E-Mail-Attribut</label>
                    <input type="text" id="ldap-email-attr" name="ldap_email_attr"
                           placeholder="mail"
                           value="<?= htmlspecialchars($ldapEmailAttr) ?>">
                </div>
                <div class="form-group">
                    <label for="ldap-display-name-attr">Anzeigename-Attribut</label>
                    <input type="text" id="ldap-display-name-attr" name="ldap_display_name_attr"
                           placeholder="displayName"
                           value="<?= htmlspecialchars($ldapDisplayNameAttr) ?>">
                </div>
            </div>

            <!-- SSO -->
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="ldap-sspi" name="ldap_sspi_enabled" value="1"
                           <?= $ldapSspiEnabled ? 'checked' : '' ?>>
                    Windows-SSO via <code>REMOTE_USER</code> aktivieren
                </label>
                <p class="hint">
                    Erfordert Apache <code>mod_auth_kerb</code> / <code>mod_auth_ntlm_winbind</code>
                    oder IIS Windows Authentication. Der Webserver muss <code>REMOTE_USER</code>
                    / <code>AUTH_USER</code> setzen.
                </p>
            </div>

            </div><!-- #ldap-settings-fields -->

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="ldap-test-btn" class="btn"
                        <?= $ldapEnabled ? '' : 'disabled title="Zuerst LDAP aktivieren und Einstellungen speichern"' ?>>
                    🔌 Verbindung testen
                </button>
                <span id="ldap-test-result" style="font-size:.85rem"></span>
            </div>
            </form>
        </details>
    </div>

    <div class="card" id="config-searxng-card">
        <details class="config-panel" id="config-searxng" open>
            <summary>🔎 Websuche</summary>
            <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_search_settings">

            <div class="form-group">
                <label for="searxng-base-url">SearXNG-URL</label>
                <input type="url" id="searxng-base-url" name="searxng_base_url"
                       placeholder="https://search.example.org"
                       value="<?= htmlspecialchars($searxngBaseUrl) ?>">
                <p class="hint">
                    Nur die Basis-URL angeben, <strong>ohne</strong> den Pfad <code>/search</code> – dieser wird automatisch ergänzt.<br>
                    Beispiele: <code>https://search.example.org</code> oder <code>http://192.168.1.10:8080</code><br>
                    Leer lassen, um die Websuche zu deaktivieren.
                </p>
            </div>

            <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
                <button type="button" id="searxng-test-btn" class="btn">🔌 Verbindung testen</button>
                <span id="searxng-test-result" style="font-size:.85rem"></span>
            </div>
            </form>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Endpoint Management
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-endpoints-card">
        <details class="config-panel" id="config-endpoints" open>
        <summary>🔗 Endpunkte</summary>

        <?php if (empty($endpoints)): ?>
            <p style="color:var(--text-muted);margin-bottom:16px;">Noch keine Endpunkte konfiguriert. Füge unten einen hinzu.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Alias</th>
                    <th>URL</th>
                    <th>Timeout</th>
                    <th>Standard-Modell</th>
                    <th>Intelligenz</th>
                    <th>Spezialisierung</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($endpoints as $ep): ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= $ep['id'] ?></td>
                    <td><?= $ep['alias'] !== '' ? htmlspecialchars($ep['alias']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                    <td style="font-family:monospace;font-size:.8rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($ep['base_url']) ?>">
                        <?= htmlspecialchars($ep['base_url']) ?>
                    </td>
                    <td><?= (int) $ep['timeout'] ?>s</td>
                    <td>
                        <?php if ($ep['default_model'] !== ''): ?>
                            <span class="model-badge"
                                  style="background:<?= htmlspecialchars($modelColorMap[$ep['default_model']] ?? '#555') ?>"
                                  title="<?= htmlspecialchars($ep['default_model']) ?>">
                                <?= htmlspecialchars($ep['default_model']) ?>
                            </span>
                        <?php else: ?>
                            <span class="model-badge badge-empty">–</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(modelIntelligenceLabel((string) $ep['default_model'])) ?></td>
                    <td>
                        <?php $sf = (string) ($ep['specialized_for_category'] ?? ''); ?>
                        <?php if ($sf !== ''): ?>
                            <span class="model-badge" style="background:#6d4c8a" title="Nur für Intelligence-Upgrade bei Kategorie &#39;<?= htmlspecialchars($sf) ?>&#39; vorgeschlagen">
                                🏷 <?= htmlspecialchars($sf) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:.85rem">Allgemein</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="dot <?= $ep['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= $ep['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                    </td>
                    <td>
                        <?php
                        // Build safe edit data — never expose ssh_password in inline JS
                        $epEdit = [
                            'id'                       => $ep['id'],
                            'alias'                    => $ep['alias'],
                            'base_url'                 => $ep['base_url'],
                            'timeout'                  => $ep['timeout'],
                            'default_model'            => $ep['default_model'],
                            'specialized_for_category' => $ep['specialized_for_category'] ?? '',
                            'supports_tool_calling'    => (int) ($ep['supports_tool_calling'] ?? 1),
                            'is_active'                => $ep['is_active'],
                            'ssh_host'                 => $ep['ssh_host'] ?? '',
                            'ssh_port'                 => $ep['ssh_port'] ?? 22,
                            'ssh_user'                 => $ep['ssh_user'] ?? '',
                        ];
                        ?>
                        <button type="button" class="btn btn-sm"
                                onclick="startEdit(<?= htmlspecialchars(json_encode($epEdit), ENT_QUOTES) ?>)">
                            ✏ Bearbeiten
                        </button>
                        <span class="sep"> </span>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Endpunkt #<?= $ep['id'] ?> wirklich löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"     value="delete_endpoint">
                            <input type="hidden" name="ep_id"      value="<?= (int) $ep['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <!-- ── Add / Edit form ─────────────────────────────────────────────── -->
        <div class="ep-form-section">
            <h3 id="ep-form-title">➕ Endpunkt hinzufügen</h3>

            <form method="POST" id="ep-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     id="ep-action" value="add_endpoint">
                <input type="hidden" name="ep_id"      id="ep-id"     value="">

                <div class="form-group">
                    <label for="ep-alias">Alias (optional)</label>
                    <input type="text" id="ep-alias" name="ep_alias" maxlength="120"
                           placeholder="z. B. GPU-Server 1"
                           value="<?= $editEp ? htmlspecialchars($editEp['alias']) : '' ?>">
                    <p class="hint">Kurzname für den Endpunkt, wird später in Antwortdetails angezeigt.</p>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:4">
                        <label for="ep-url">API-Endpunkt (Base URL) *</label>
                        <input type="url" id="ep-url" name="ep_base_url"
                               placeholder="http://192.168.1.10:1234/v1" required
                               value="<?= $editEp ? htmlspecialchars($editEp['base_url']) : '' ?>">
                        <p class="hint">Vollständige Base URL der LM Studio REST API.</p>
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px">
                        <label for="ep-timeout">Timeout (Sekunden) *</label>
                        <input type="number" id="ep-timeout" name="ep_timeout"
                               min="1" max="600" required
                               value="<?= $editEp ? (int) $editEp['timeout'] : 120 ?>">
                        <p class="hint">1 – 600 s</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="ep-model-input">Standard-Modell</label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="text" list="ep-model-list" id="ep-model-input"
                               name="ep_default_model"
                               placeholder="Modell-ID eingeben oder Modelle laden …"
                               style="flex:1 1 260px"
                               value="<?= $editEp ? htmlspecialchars($editEp['default_model']) : '' ?>">
                        <datalist id="ep-model-list"></datalist>
                        <button type="button" id="ep-load-btn" class="btn">
                            ⟳ Modelle laden
                        </button>
                    </div>
                    <p class="hint">
                        Endpunkte mit identischem Standard-Modell bilden automatisch eine Load-Balancing-Gruppe.
                    </p>
                </div>

                <div class="form-group">
                    <label for="ep-specialized-for">Spezialisierung (Routing-Kategorie)</label>
                    <select id="ep-specialized-for" name="ep_specialized_for_category">
                        <option value=""<?= (!$editEp || ($editEp['specialized_for_category'] ?? '') === '') ? ' selected' : '' ?>>
                            Allgemein (keine Einschränkung)
                        </option>
                        <?php foreach ($routingCategoriesData as $rc): ?>
                            <option value="<?= htmlspecialchars($rc['name']) ?>"
                                <?= ($editEp && ($editEp['specialized_for_category'] ?? '') === $rc['name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">
                        Ist hier eine Kategorie gewählt, wird dieses Modell beim <strong>Intelligence-Upgrade</strong>
                        nur dann als Vorschlag angezeigt, wenn die aktuelle Anfrage genau dieser Kategorie zugeordnet wurde.
                        So werden spezialisierte Modelle (z.&thinsp;B. Coding) nicht für allgemeine Anfragen empfohlen.
                    </p>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-active" name="ep_is_active"
                               <?= (!$editEp || $editEp['is_active']) ? 'checked' : '' ?>>
                        Endpunkt aktiv (nimmt Anfragen entgegen)
                    </label>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="ep-supports-tool-calling" name="ep_supports_tool_calling"
                               <?= (!$editEp || (int) ($editEp['supports_tool_calling'] ?? 1)) ? 'checked' : '' ?>>
                        Tool-Calling unterstützt (search_web bei Bedarf verfügbar)
                    </label>
                    <p class="hint">
                        Aktivieren, wenn das Modell die OpenAI-kompatible Tool-Calling-API unterstützt.
                        Ist diese Option aktiviert und SearXNG konfiguriert, steht <code>search_web</code>
                        als Tool zur Verfügung. Das Modell entscheidet selbst, ob es aufgerufen wird –
                        nur wenn es keine ausreichend aktuellen Informationen zu einem Thema hat.
                    </p>
                </div>

                <!-- ── SSH-Zugangsdaten (Systemmetriken) ──────────────────────── -->
                <details id="ep-ssh-details"<?= ($editEp && trim((string) ($editEp['ssh_host'] ?? '')) !== '') ? ' open' : '' ?>>
                    <summary style="cursor:pointer;font-weight:600;margin:12px 0 8px;color:var(--text-muted)">
                        🔑 SSH-Zugangsdaten (optional, für Systemmetriken)
                    </summary>
                    <p class="hint" style="margin-bottom:8px">
                        Wenn hinterlegt, werden RAM-Auslastung, CPU-Last und CPU-Temperatur
                        (lm-sensors) live im Dashboard angezeigt. Erfordert <code>php-ssh2</code>
                        auf dem Webserver sowie <code>lm-sensors</code> auf dem Zielrechner.
                    </p>
                    <div class="form-row">
                        <div class="form-group" style="flex:3">
                            <label for="ep-ssh-host">SSH-Host</label>
                            <input type="text" id="ep-ssh-host" name="ep_ssh_host"
                                   placeholder="192.168.1.10"
                                   value="<?= $editEp ? htmlspecialchars($editEp['ssh_host'] ?? '') : '' ?>">
                        </div>
                        <div class="form-group" style="flex:1;min-width:100px">
                            <label for="ep-ssh-port">SSH-Port</label>
                            <input type="number" id="ep-ssh-port" name="ep_ssh_port"
                                   min="1" max="65535"
                                   value="<?= $editEp ? (int) ($editEp['ssh_port'] ?? 22) : 22 ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="ep-ssh-user">SSH-Benutzer</label>
                            <input type="text" id="ep-ssh-user" name="ep_ssh_user"
                                   placeholder="ubuntu"
                                   autocomplete="off"
                                   value="<?= $editEp ? htmlspecialchars($editEp['ssh_user'] ?? '') : '' ?>">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="ep-ssh-password">SSH-Passwort<?= $editEp && trim((string) ($editEp['ssh_host'] ?? '')) !== '' ? ' <span style="font-weight:400;font-size:.8em">(leer lassen = unverändert)</span>' : '' ?></label>
                            <input type="password" id="ep-ssh-password" name="ep_ssh_password"
                                   autocomplete="new-password"
                                   placeholder="<?= $editEp && trim((string) ($editEp['ssh_host'] ?? '')) !== '' ? '••••••••' : 'Passwort' ?>">
                        </div>
                    </div>
                </details>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="resetForm()">✕ Abbrechen</button>
                </div>
            </form>
        </div>
        </details>
    </div>

    <div class="card" id="config-request-handling-card">
       <details class="config-panel" id="config-request-handling" open>
           <summary>📨 Anfragenhandling</summary>

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_request_handling">

               <div class="form-group">
                   <label for="guest-default-model">Standard-Modell / Modellgruppe für neue Anfragen</label>
                   <select id="guest-default-model" name="guest_default_model">
                       <option value="" <?= $guestDefaultModel === '' ? 'selected' : '' ?>>
                           Automatisch: erstes aktives Modell verwenden
                       </option>
                       <?php foreach ($availableGuestModels as $model): ?>
                           <?php $intelligence = modelIntelligenceLabel($model); ?>
                           <option value="<?= htmlspecialchars($model) ?>"
                               <?= $guestDefaultModel === $model ? 'selected' : '' ?>>
                               <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                           </option>
                       <?php endforeach; ?>
                       <?php if ($guestDefaultModel !== '' && !isset($availableGuestModels[$guestDefaultModel])): ?>
                           <option value="<?= htmlspecialchars($guestDefaultModel) ?>" selected>
                               <?= htmlspecialchars($guestDefaultModel) ?> · derzeit nicht über aktive Endpunkte verfügbar
                           </option>
                       <?php endif; ?>
                   </select>
                   <p class="hint">
                       Gilt für neue Anfragen nicht angemeldeter Benutzer im Chat. Zur Auswahl stehen aktive Modellgruppen aus dem Bereich
                       <strong>Endpunkte</strong>.
                   </p>
                   <?php if (empty($availableGuestModels)): ?>
                       <p class="hint" style="color:var(--warning)">
                           Aktuell sind keine aktiven Modellgruppen verfügbar. Bitte zuerst unter <strong>Endpunkte</strong> mindestens ein Standard-Modell konfigurieren.
                       </p>
                   <?php endif; ?>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>

           <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_new_user_model">

               <div class="form-group">
                   <label for="new-user-default-model">Standard-Modell für neu registrierte Benutzer</label>
                   <select id="new-user-default-model" name="new_user_default_model">
                       <option value="" <?= $newUserDefaultModel === '' ? 'selected' : '' ?>>
                           Kein Standard-Modell (systemweites Standard-Modell verwenden)
                       </option>
                       <?php foreach ($availableGuestModels as $model): ?>
                           <?php $intelligence = modelIntelligenceLabel($model); ?>
                           <option value="<?= htmlspecialchars($model) ?>"
                               <?= $newUserDefaultModel === $model ? 'selected' : '' ?>>
                               <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                           </option>
                       <?php endforeach; ?>
                       <?php if ($newUserDefaultModel !== '' && !isset($availableGuestModels[$newUserDefaultModel])): ?>
                           <option value="<?= htmlspecialchars($newUserDefaultModel) ?>" selected>
                               <?= htmlspecialchars($newUserDefaultModel) ?> · derzeit nicht verfügbar
                           </option>
                       <?php endif; ?>
                   </select>
                   <p class="hint">
                       Dieses Modell wird neu registrierten Benutzern automatisch als persönliches Standard-Modell zugewiesen.
                       Leer lassen, um das systemweite Standard-Modell zu verwenden.
                   </p>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>

           <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

           <form method="POST">
               <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
               <input type="hidden" name="action" value="save_vision_settings">

               <div class="form-group">
                   <label for="vision-model">Vision-Modell für Dokument-Upload</label>
                   <select id="vision-model" name="vision_model">
                       <option value="" <?= $visionModel === '' ? 'selected' : '' ?>>
                           Kein Vision-Modell (Dokument-Upload deaktiviert)
                       </option>
                       <?php foreach ($availableGuestModels as $model): ?>
                           <?php $intelligence = modelIntelligenceLabel($model); ?>
                           <option value="<?= htmlspecialchars($model) ?>"
                               <?= $visionModel === $model ? 'selected' : '' ?>>
                               <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                           </option>
                       <?php endforeach; ?>
                       <?php if ($visionModel !== '' && !isset($availableGuestModels[$visionModel])): ?>
                           <option value="<?= htmlspecialchars($visionModel) ?>" selected>
                               <?= htmlspecialchars($visionModel) ?> · derzeit nicht verfügbar
                           </option>
                       <?php endif; ?>
                   </select>
                   <p class="hint">
                       Dieses Vision-fähige Modell analysiert hochgeladene Dokumente (Bilder) und extrahiert deren Inhalt.
                       Wird in jeder Anfrage als Datenquelle per Tool-Aufruf <code>query_documents</code> bereitgestellt.
                       Leer lassen, um den Dokument-Upload zu deaktivieren.
                   </p>
               </div>

               <div class="action-row">
                   <button type="submit" class="btn btn-primary">💾 Speichern</button>
               </div>
           </form>
       </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
        SD / AUTOMATIC1111 Endpoint Management
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-sd-card">
        <details class="config-panel" id="config-sd" open>
        <summary>🎨 Bildgenerierung (AUTOMATIC1111)</summary>

        <?php if (empty($sdEndpoints)): ?>
            <p style="color:var(--text-muted);margin-bottom:16px;">Noch keine SD-Endpunkte konfiguriert. Füge unten einen hinzu.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>URL</th>
                    <th>Timeout</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sdEndpoints as $sdEp): ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= (int) $sdEp['id'] ?></td>
                    <td style="font-family:monospace;font-size:.8rem;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($sdEp['base_url']) ?>">
                        <?= htmlspecialchars($sdEp['base_url']) ?>
                    </td>
                    <td><?= (int) $sdEp['timeout'] ?>s</td>
                    <td>
                        <span class="dot <?= $sdEp['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= $sdEp['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm"
                                onclick="startSdEdit(<?= htmlspecialchars(json_encode($sdEp), ENT_QUOTES) ?>)">
                            ✏ Bearbeiten
                        </button>
                        <span class="sep"> </span>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('SD-Endpunkt #<?= (int) $sdEp['id'] ?> wirklich löschen?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"     value="delete_sd_endpoint">
                            <input type="hidden" name="sd_ep_id"   value="<?= (int) $sdEp['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <!-- ── Add / Edit form ─────────────────────────────────────────────── -->
        <div class="ep-form-section">
            <h3 id="sd-ep-form-title">➕ SD-Endpunkt hinzufügen</h3>

            <form method="POST" id="sd-ep-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     id="sd-ep-action" value="add_sd_endpoint">
                <input type="hidden" name="sd_ep_id"   id="sd-ep-id"     value="">

                <div class="form-row">
                    <div class="form-group" style="flex:4">
                        <label for="sd-ep-url">AUTOMATIC1111 Base URL *</label>
                        <input type="url" id="sd-ep-url" name="sd_ep_base_url"
                               placeholder="http://192.168.1.10:7860" required
                               value="<?= $editSdEp ? htmlspecialchars($editSdEp['base_url']) : '' ?>">
                        <p class="hint">
                            Basis-URL der AUTOMATIC1111 Web-UI. Die API-Pfade (<code>/sdapi/v1/…</code>) werden automatisch ergänzt.
                        </p>
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px">
                        <label for="sd-ep-timeout">Timeout (Sekunden) *</label>
                        <input type="number" id="sd-ep-timeout" name="sd_ep_timeout"
                               min="1" max="600" required
                               value="<?= $editSdEp ? (int) $editSdEp['timeout'] : 120 ?>">
                        <p class="hint">1 – 600 s</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="sd-ep-active" name="sd_ep_is_active"
                               <?= (!$editSdEp || $editSdEp['is_active']) ? 'checked' : '' ?>>
                        Endpunkt aktiv (nimmt Bildgenerierungsanfragen entgegen)
                    </label>
                </div>

                <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="resetSdForm()">✕ Abbrechen</button>
                    <button type="button" id="sd-test-btn" class="btn">🔌 Verbindung testen</button>
                    <span id="sd-test-result" style="font-size:.85rem"></span>
                </div>
            </form>
        </div>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         ComfyUI Endpoint Management
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-comfy-card">
        <details class="config-panel" id="config-comfy" open>
        <summary>🖼️ Bildgenerierung (ComfyUI)</summary>

        <?php if (empty($comfyEndpoints)): ?>
            <p style="color:var(--text-muted);margin-bottom:16px;">Noch keine ComfyUI-Endpunkte konfiguriert. Füge unten einen hinzu.</p>
        <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>URL</th>
                    <th>Timeout</th>
                    <th>Default Checkpoint</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comfyEndpoints as $comfyEp): ?>
                <tr>
                    <td style="color:var(--text-muted)"><?= (int) $comfyEp['id'] ?></td>
                    <td style="font-family:monospace;font-size:.8rem;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($comfyEp['base_url']) ?>">
                        <?= htmlspecialchars($comfyEp['base_url']) ?>
                    </td>
                    <td><?= (int) $comfyEp['timeout'] ?>s</td>
                    <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($comfyEp['default_checkpoint']) ?>">
                        <?php if ($comfyEp['default_checkpoint'] !== ''): ?>
                            <?= htmlspecialchars($comfyEp['default_checkpoint']) ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">–</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="dot <?= $comfyEp['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= $comfyEp['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm"
                                onclick="startComfyEdit(<?= htmlspecialchars(json_encode($comfyEp), ENT_QUOTES) ?>)">
                            ✏ Bearbeiten
                        </button>
                        <span class="sep"> </span>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('ComfyUI-Endpunkt #<?= (int) $comfyEp['id'] ?> wirklich löschen?')">
                            <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"       value="delete_comfy_endpoint">
                            <input type="hidden" name="comfy_ep_id"  value="<?= (int) $comfyEp['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑 Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <!-- ── Add / Edit form ─────────────────────────────────────────────── -->
        <div class="ep-form-section">
            <h3 id="comfy-ep-form-title">➕ ComfyUI-Endpunkt hinzufügen</h3>

            <form method="POST" id="comfy-ep-form">
                <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"      id="comfy-ep-action" value="add_comfy_endpoint">
                <input type="hidden" name="comfy_ep_id" id="comfy-ep-id"     value="">

                <div class="form-row">
                    <div class="form-group" style="flex:4">
                        <label for="comfy-ep-url">ComfyUI Base URL *</label>
                        <input type="url" id="comfy-ep-url" name="comfy_ep_base_url"
                               placeholder="http://192.168.1.10:8188" required
                               value="<?= $editComfyEp ? htmlspecialchars($editComfyEp['base_url']) : '' ?>">
                        <p class="hint">
                            Basis-URL der ComfyUI-Instanz (Standard-Port: 8188). API-Pfade werden automatisch ergänzt.
                        </p>
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px">
                        <label for="comfy-ep-timeout">Timeout (Sekunden) *</label>
                        <input type="number" id="comfy-ep-timeout" name="comfy_ep_timeout"
                               min="1" max="600" required
                               value="<?= $editComfyEp ? (int) $editComfyEp['timeout'] : 120 ?>">
                        <p class="hint">1 – 600 s</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="comfy-ep-checkpoint-input">Default Checkpoint</label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="text" list="comfy-ep-checkpoint-list" id="comfy-ep-checkpoint-input"
                               name="comfy_ep_default_checkpoint"
                               placeholder="z. B. v1-5-pruned-emaonly.safetensors"
                               style="flex:1 1 260px"
                               value="<?= $editComfyEp ? htmlspecialchars($editComfyEp['default_checkpoint']) : '' ?>">
                        <datalist id="comfy-ep-checkpoint-list"></datalist>
                        <button type="button" id="comfy-ep-load-btn" class="btn">
                            ⟳ Checkpoints laden
                        </button>
                    </div>
                    <p class="hint">
                        Dateiname des Checkpoints, das für txt2img verwendet werden soll.
                        Leer lassen, um beim ersten Aufruf automatisch den ersten verfügbaren Checkpoint zu verwenden.
                    </p>
                </div>

                <div class="form-group">
                    <label class="inline">
                        <input type="checkbox" id="comfy-ep-active" name="comfy_ep_is_active"
                               <?= (!$editComfyEp || $editComfyEp['is_active']) ? 'checked' : '' ?>>
                        Endpunkt aktiv (nimmt Bildgenerierungsanfragen entgegen)
                    </label>
                </div>

                <div class="action-row" style="align-items:center;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="resetComfyForm()">✕ Abbrechen</button>
                    <button type="button" id="comfy-test-btn" class="btn">🔌 Verbindung testen</button>
                    <span id="comfy-test-result" style="font-size:.85rem"></span>
                </div>
            </form>
        </div>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Modellrouting
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-routing-card">
        <details class="config-panel" id="config-routing" open>
        <summary>🧭 Modellrouting</summary>

        <p class="hint" style="margin-bottom:18px">
            Das <strong>Entscheidungsmodell</strong> analysiert jeden Nutzer-Prompt und ordnet ihn einer Kategorie zu.
            Der Prompt wird mit den unter <strong>Entscheidungsfindung</strong> konfigurierten Kategorien als
            Systemnachricht an das Entscheidungsmodell gesendet. Anschließend wird der ursprüngliche Nutzer-Prompt
            an das Modell weitergeleitet, das der ermittelten Kategorie zugeordnet ist.
            Ist kein Entscheidungsmodell konfiguriert, wird das reguläre Modellrouting verwendet.
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_routing_settings">

            <div class="form-group">
                <label for="routing-decision-model">Entscheidungsmodell</label>
                <select id="routing-decision-model" name="routing_decision_model">
                    <option value="" <?= $routingDecisionModel === '' ? 'selected' : '' ?>>
                        Kein Entscheidungsmodell (regelbasiertes Routing deaktiviert)
                    </option>
                    <?php foreach ($availableGuestModels as $model): ?>
                        <?php $intelligence = modelIntelligenceLabel($model); ?>
                        <option value="<?= htmlspecialchars($model) ?>"
                            <?= $routingDecisionModel === $model ? 'selected' : '' ?>>
                            <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($routingDecisionModel !== '' && !isset($availableGuestModels[$routingDecisionModel])): ?>
                        <option value="<?= htmlspecialchars($routingDecisionModel) ?>" selected>
                            <?= htmlspecialchars($routingDecisionModel) ?> · derzeit nicht verfügbar
                        </option>
                    <?php endif; ?>
                </select>
                <p class="hint">
                    Dieses Modell klassifiziert den Nutzer-Prompt in eine der folgenden Kategorien.
                    Es sollte ein schnelles, kompaktes Modell sein.
                </p>
            </div>

            <?php if (empty($routingCategories)): ?>
                <p class="hint" style="color:var(--warning)">
                    Keine Kategorien gefunden. Füge unter <strong>Entscheidungsfindung</strong> Kategorien hinzu.
                </p>
            <?php else: ?>
                <h3>Kategorie-Zuordnung</h3>
                <p class="hint" style="margin-bottom:14px">
                    Jeder Kategorie kann ein Zielmodell zugeordnet werden.
                    Leer lassen, um auf das reguläre Standard-Modell zurückzufallen.
                </p>
                <table class="data-table" style="margin-bottom:18px">
                    <thead>
                        <tr>
                            <th>Kategorie</th>
                            <th>Zielmodell (Knowhow)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routingCategories as $cat): ?>
                            <?php
                            $fieldName     = 'routing_model_' . preg_replace('/[^a-zA-Z0-9]/', '_', $cat);
                            $assignedModel = $routingRules[$cat] ?? '';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($cat) ?></strong>
                                </td>
                                <td>
                                    <select name="<?= htmlspecialchars($fieldName) ?>"
                                            style="width:100%;max-width:480px;padding:6px 10px;
                                                   background:var(--bg);border:1px solid var(--border);
                                                   border-radius:var(--radius);color:var(--text);
                                                   font-size:.85rem;font-family:var(--font)">
                                        <option value="" <?= $assignedModel === '' ? 'selected' : '' ?>>
                                            – Standard-Modell verwenden –
                                        </option>
                                        <?php foreach ($availableGuestModels as $model): ?>
                                            <?php $intelligence = modelIntelligenceLabel($model); ?>
                                            <option value="<?= htmlspecialchars($model) ?>"
                                                <?= $assignedModel === $model ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($assignedModel !== '' && !isset($availableGuestModels[$assignedModel])): ?>
                                            <option value="<?= htmlspecialchars($assignedModel) ?>" selected>
                                                <?= htmlspecialchars($assignedModel) ?> · derzeit nicht verfügbar
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="action-row">
                <button type="submit" class="btn btn-primary">💾 Speichern</button>
            </div>
        </form>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Entscheidungsfindung – Kategorien & Definitionen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-decision-card">
        <details class="config-panel" id="config-decision" open>
        <summary>🗂️ Entscheidungsfindung</summary>

        <p class="hint" style="margin-bottom:18px">
            Verwalte die Kategorien, nach denen das <strong>Entscheidungsmodell</strong> Nutzer-Prompts klassifiziert.
            Jede Kategorie besitzt eine <em>Definition</em> (erklärt dem Modell, wann diese Kategorie zutrifft)
            und eine <em>Entscheidungsregel</em> (nummerierter Eintrag in der Prioritätsliste).
            Änderungen wirken sich sofort auf den erzeugten Klassifikations-Prompt aus.
        </p>

        <!-- Category list -->
        <?php if (empty($routingCategoriesData)): ?>
            <p class="hint" style="color:var(--warning)">Noch keine Kategorien vorhanden. Füge unten eine neue Kategorie hinzu.</p>
        <?php else: ?>
        <table class="data-table" id="rc-table" style="margin-bottom:24px">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Kategorie</th>
                    <th>Definition</th>
                    <th style="width:60px">Anz.</th>
                    <th style="width:110px">Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($routingCategoriesData as $rc): ?>
                <tr id="rc-row-<?= (int)$rc['id'] ?>">
                    <td style="text-align:center;color:var(--muted);font-size:.8rem"><?= (int)$rc['sort_order'] ?></td>
                    <td><strong><?= htmlspecialchars($rc['name']) ?></strong></td>
                    <td style="font-size:.85rem;color:var(--muted);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($rc['definition']) ?>">
                        <?= htmlspecialchars($rc['definition']) ?>
                    </td>
                    <td style="text-align:center;font-size:.8rem;color:var(--muted)"><?= (int)$rc['decision_priority'] ?></td>
                    <td>
                        <button type="button" class="btn" style="padding:4px 10px;font-size:.8rem"
                                onclick="rcEdit(<?= (int)$rc['id'] ?>,
                                    <?= htmlspecialchars(json_encode($rc['name']), ENT_QUOTES) ?>,
                                    <?= htmlspecialchars(json_encode($rc['definition']), ENT_QUOTES) ?>,
                                    <?= htmlspecialchars(json_encode($rc['decision_rule']), ENT_QUOTES) ?>,
                                    <?= (int)$rc['sort_order'] ?>,
                                    <?= (int)$rc['decision_priority'] ?>)">
                            ✏️ Bearbeiten
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Edit / Add form (hidden by default) -->
        <div id="rc-form-wrapper" style="display:none;background:var(--card-bg,#1e1e1e);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-bottom:24px">
            <h3 id="rc-form-title" style="margin:0 0 16px">Kategorie bearbeiten</h3>
            <form method="POST" id="rc-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" id="rc-action" value="update_routing_category">
                <input type="hidden" name="rc_id" id="rc-id" value="0">

                <div class="form-group">
                    <label for="rc-name">Kategoriename <small style="color:var(--muted)">(nur Buchstaben, Ziffern, Unterstriche)</small></label>
                    <input type="text" id="rc-name" name="rc_name" required
                           pattern="[A-Za-z0-9_]+"
                           placeholder="z.&thinsp;B. Programming"
                           style="max-width:320px">
                </div>

                <div class="form-group">
                    <label for="rc-definition">Definition</label>
                    <textarea id="rc-definition" name="rc_definition" rows="3"
                              placeholder="Beschreibe, wann diese Kategorie zutrifft …"
                              style="width:100%;max-width:700px;resize:vertical"></textarea>
                    <p class="hint">Erscheint als <code>* Kategorie: …</code> im Klassifikations-Prompt.</p>
                </div>

                <div class="form-group">
                    <label for="rc-decision-rule">Entscheidungsregel</label>
                    <textarea id="rc-decision-rule" name="rc_decision_rule" rows="2"
                              placeholder="z.&thinsp;B. Else if the primary task is programming, return Programming."
                              style="width:100%;max-width:700px;resize:vertical"></textarea>
                    <p class="hint">Vollständiger Regeltext inkl. „If / Else if / Otherwise" und „, return Kategorie."</p>
                </div>

                <div style="display:flex;gap:24px;flex-wrap:wrap">
                    <div class="form-group" style="flex:0 0 160px">
                        <label for="rc-sort-order">Anzeigereihenfolge</label>
                        <input type="number" id="rc-sort-order" name="rc_sort_order" min="0" max="999" value="0"
                               style="max-width:100px">
                        <p class="hint">Kleinere Zahl = weiter oben in der Kategorieliste.</p>
                    </div>
                    <div class="form-group" style="flex:0 0 160px">
                        <label for="rc-decision-priority">Entscheidungspriorität</label>
                        <input type="number" id="rc-decision-priority" name="rc_decision_priority" min="0" max="999" value="0"
                               style="max-width:100px">
                        <p class="hint">1 = höchste Priorität in den Entscheidungsregeln.</p>
                    </div>
                </div>

                <div class="action-row" style="margin-top:8px;gap:10px">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                    <button type="button" class="btn" onclick="rcCancel()">✕ Abbrechen</button>
                    <button type="button" id="rc-delete-btn" class="btn" style="display:none;margin-left:auto;color:var(--danger,#ef4444);border-color:var(--danger,#ef4444)"
                            onclick="rcDelete()">🗑 Kategorie löschen</button>
                </div>
            </form>
        </div>

        <!-- Prompt preview -->
        <details style="margin-bottom:16px">
            <summary style="cursor:pointer;font-size:.9rem;color:var(--muted);user-select:none">🔍 Erzeugten Klassifikations-Prompt anzeigen</summary>
            <pre id="rc-prompt-preview" style="margin-top:12px;padding:16px;background:var(--code-bg,#111);border:1px solid var(--border);border-radius:var(--radius);font-size:.78rem;white-space:pre-wrap;word-break:break-word;color:var(--text)"><?= htmlspecialchars(buildRoutingPrompt()) ?></pre>
        </details>

        <!-- Add new category button -->
        <div>
            <button type="button" class="btn btn-primary" onclick="rcAdd()">＋ Neue Kategorie hinzufügen</button>
        </div>

        <!-- Import from prompt.txt -->
        <hr style="margin:28px 0;border:none;border-top:1px solid var(--border)">
        <h3 style="margin:0 0 10px;font-size:1rem">📥 Import aus prompt.txt</h3>
        <p class="hint" style="margin-bottom:14px">
            Lade eine Textdatei hoch, die dem Schema der <code>lib/prompt.txt</code> entspricht.
            Die Datei wird analysiert und du erhältst eine Vorschau der erkannten Kategorien,
            bevor die Einstellungen gespeichert werden.
        </p>

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px">
            <label class="btn" style="cursor:pointer;margin:0" for="rc-import-file">
                📂 Datei auswählen …
            </label>
            <input type="file" id="rc-import-file" accept=".txt,text/plain"
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden"
                   onchange="rcImportPreview(this)">
            <span id="rc-import-filename" style="font-size:.85rem;color:var(--muted)">Keine Datei ausgewählt</span>
        </div>

        <!-- Preview panel (hidden until file is selected and parsed) -->
        <div id="rc-import-preview" style="display:none;margin-top:16px">
            <div style="background:rgba(239,68,68,.12);border:1px solid var(--danger,#ef4444);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:1.2rem;line-height:1.2">⚠️</span>
                <div>
                    <strong style="color:var(--danger,#ef4444)">Achtung:</strong>
                    Durch den Import werden <strong>alle vorhandenen Kategorien und Entscheidungsregeln unwiderruflich überschrieben</strong>.
                    Modellzuordnungen (Routing-Regeln) werden ebenfalls gelöscht.
                    Bitte prüfe die Vorschau sorgfältig.
                </div>
            </div>

            <p style="font-size:.9rem;font-weight:600;margin-bottom:8px">Erkannte Kategorien (<span id="rc-import-count">0</span>):</p>
            <div id="rc-import-error" style="display:none;color:var(--danger,#ef4444);margin-bottom:10px;font-size:.9rem"></div>

            <table class="data-table" id="rc-import-table" style="margin-bottom:20px;font-size:.85rem">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:130px">Kategorie</th>
                        <th>Definition</th>
                        <th style="width:60px">Anz.</th>
                        <th>Entscheidungsregel</th>
                    </tr>
                </thead>
                <tbody id="rc-import-tbody"></tbody>
            </table>

            <!-- Hidden form for confirmed import -->
            <form method="POST" id="rc-import-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="import_prompt_txt">
                <input type="hidden" name="prompt_categories" id="rc-import-json" value="">
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary" id="rc-import-confirm">✅ Import bestätigen</button>
                    <button type="button" class="btn" onclick="rcImportCancel()">✕ Abbrechen</button>
                </div>
            </form>
        </div>

        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Embedding-Endpunkte (Hybrid-RAG)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-embedding-card">
        <details class="config-panel" open>
            <summary>🧬 Embedding-Endpunkte</summary>

            <p class="hint" style="margin-bottom:16px">
                Konfiguriere OpenAI-kompatible Embedding-Server (z.B. LM Studio, Ollama, OpenAI).
                Embeddings werden für jeden Dokument-Chunk erzeugt und ermöglichen die semantische Suche.
            </p>

            <?php if (empty($embeddingEndpoints)): ?>
                <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:16px">
                    Noch keine Embedding-Endpunkte konfiguriert.
                </p>
            <?php else: ?>
                <table class="data-table" style="margin-bottom:20px">
                    <thead>
                        <tr>
                            <th>Alias</th>
                            <th>URL</th>
                            <th>Modell</th>
                            <th>Timeout</th>
                            <th>Aktiv</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($embeddingEndpoints as $embEp): ?>
                        <tr>
                            <td><?= htmlspecialchars($embEp['alias'] !== '' ? $embEp['alias'] : '–') ?></td>
                            <td style="font-size:.82rem;color:var(--text-muted)"><?= htmlspecialchars($embEp['base_url']) ?></td>
                            <td><?= htmlspecialchars($embEp['model']) ?></td>
                            <td><?= (int)$embEp['timeout'] ?>s</td>
                            <td><?= $embEp['is_active'] ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--text-muted)">–</span>' ?></td>
                            <td>
                                <a href="?edit_emb=<?= (int)$embEp['id'] ?>#config-embedding-card"
                                   style="font-size:.8rem;color:var(--accent)">Bearbeiten</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Add / Edit form -->
            <div class="ep-form-section">
                <h3><?= $editEmbEp !== null ? '✏️ Endpunkt bearbeiten' : '➕ Endpunkt hinzufügen' ?></h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action"
                           value="<?= $editEmbEp !== null ? 'update_embedding_endpoint' : 'add_embedding_endpoint' ?>">
                    <?php if ($editEmbEp !== null): ?>
                        <input type="hidden" name="emb_id" value="<?= (int)$editEmbEp['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="emb-alias">Alias (optional)</label>
                        <input type="text" id="emb-alias" name="emb_alias"
                               value="<?= htmlspecialchars($editEmbEp['alias'] ?? '') ?>" placeholder="z.B. LM Studio Embed">
                    </div>
                    <div class="form-group">
                        <label for="emb-base-url">Basis-URL <small style="color:var(--text-muted)">(ohne /v1/embeddings)</small></label>
                        <input type="url" id="emb-base-url" name="emb_base_url" required
                               value="<?= htmlspecialchars($editEmbEp['base_url'] ?? '') ?>"
                               placeholder="http://localhost:1234">
                    </div>
                    <div class="form-group">
                        <label for="emb-model">Modell</label>
                        <input type="text" id="emb-model" name="emb_model" required
                               value="<?= htmlspecialchars($editEmbEp['model'] ?? '') ?>"
                               placeholder="text-embedding-nomic-embed-text-v1.5">
                    </div>
                    <div class="form-group">
                        <label for="emb-api-key">API-Key <small style="color:var(--text-muted)">(leer lassen wenn nicht benötigt)</small></label>
                        <input type="password" id="emb-api-key" name="emb_api_key"
                               placeholder="<?= $editEmbEp !== null ? '(leer lassen = unverändert)' : '' ?>">
                    </div>
                    <div class="form-group">
                        <label for="emb-timeout">Timeout (Sekunden)</label>
                        <input type="number" id="emb-timeout" name="emb_timeout"
                               value="<?= (int)($editEmbEp['timeout'] ?? 60) ?>" min="5" max="600">
                    </div>
                    <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                        <input type="checkbox" id="emb-active" name="emb_is_active"
                               <?= ($editEmbEp === null || $editEmbEp['is_active']) ? 'checked' : '' ?>>
                        <label for="emb-active" style="margin:0">Endpunkt aktiv</label>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn">
                            <?= $editEmbEp !== null ? '💾 Speichern' : '➕ Hinzufügen' ?>
                        </button>
                        <?php if ($editEmbEp !== null): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_embedding_endpoint">
                                <input type="hidden" name="emb_id" value="<?= (int)$editEmbEp['id'] ?>">
                                <button type="submit" class="btn btn-danger"
                                        onclick="return confirm('Endpunkt wirklich löschen?')">🗑 Löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Hybrid-Suche Einstellungen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-hybrid-search-card">
        <details class="config-panel" open>
            <summary>🔀 Hybrid-Suche &amp; Embedding</summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_hybrid_search_settings">

                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="hybrid-enabled" name="hybrid_search_enabled"
                           <?= $hybridSearchEnabled ? 'checked' : '' ?>>
                    <label for="hybrid-enabled" style="margin:0;font-weight:600">Hybrid-Suche aktivieren (BM25 + Embedding)</label>
                </div>
                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="embedding-enabled" name="embedding_enabled"
                           <?= $embeddingEnabled ? 'checked' : '' ?>>
                    <label for="embedding-enabled" style="margin:0">Embeddings aktivieren</label>
                </div>

                <div class="form-group">
                    <label for="embedding-model">Standard-Embedding-Modell <small style="color:var(--text-muted)">(für Query-Embedding-Cache)</small></label>
                    <input type="text" id="embedding-model" name="embedding_model"
                           value="<?= htmlspecialchars($embeddingModel) ?>"
                           placeholder="text-embedding-nomic-embed-text-v1.5">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group">
                        <label for="bm25-weight">BM25-Gewichtung (0–1)</label>
                        <input type="number" id="bm25-weight" name="bm25_weight" step="0.05" min="0" max="1"
                               value="<?= htmlspecialchars($bm25Weight) ?>">
                    </div>
                    <div class="form-group">
                        <label for="embedding-weight">Embedding-Gewichtung (0–1)</label>
                        <input type="number" id="embedding-weight" name="embedding_weight" step="0.05" min="0" max="1"
                               value="<?= htmlspecialchars($embeddingWeight) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="embedding-timeout">Embedding-Timeout (Sekunden)</label>
                    <input type="number" id="embedding-timeout" name="embedding_timeout"
                           value="<?= (int)$embeddingTimeout ?>" min="5" max="600">
                </div>
                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="embedding-cache-enabled" name="embedding_cache_enabled"
                           <?= $embeddingCacheEnabled ? 'checked' : '' ?>>
                    <label for="embedding-cache-enabled" style="margin:0">Query-Embedding-Cache aktivieren (Datenbank)</label>
                </div>
                <div class="form-group">
                    <label for="cache-ttl">Cache-Lebensdauer (Tage)</label>
                    <input type="number" id="cache-ttl" name="embedding_cache_ttl_days"
                           value="<?= (int)$embeddingCacheTtlDays ?>" min="1" max="365">
                </div>

                <button type="submit" class="btn">💾 Speichern</button>
            </form>

            <!-- Embeddings neu berechnen -->
            <hr style="border-color:var(--border);margin:24px 0">
            <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">🔄 Embeddings neu berechnen</h3>
            <p class="hint">Berechnet fehlende oder veraltete Embeddings für alle Dokument-Chunks.
                Läuft im Hintergrund und kann mehrere Minuten dauern.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <button type="button" class="btn" id="rebuild-emb-btn">🔄 Neu berechnen (fehlende)</button>
                <button type="button" class="btn btn-secondary" id="rebuild-emb-all-btn">♻️ Alle neu berechnen</button>
            </div>
            <div id="rebuild-emb-result" style="margin-top:10px;font-size:.85rem;color:var(--text-muted)"></div>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Reranker Einstellungen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-reranker-card">
        <details class="config-panel" open>
            <summary>🏆 Reranker</summary>

            <p class="hint" style="margin-bottom:16px">
                Ein Reranker bewertet die fusionierten Suchergebnisse neu und gibt eine präzisere Rangfolge zurück.
                Kompatibel mit: Cohere Rerank, Jina Reranker, lokalen Rerank-APIs.
                Falls nicht konfiguriert oder nicht erreichbar, werden die RRF-Ergebnisse direkt verwendet.
            </p>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_reranker_settings">

                <div class="form-group" style="flex-direction:row;align-items:center;gap:10px">
                    <input type="checkbox" id="reranker-enabled" name="reranker_enabled"
                           <?= $rerankerEnabled ? 'checked' : '' ?>>
                    <label for="reranker-enabled" style="margin:0;font-weight:600">Reranker aktivieren</label>
                </div>
                <div class="form-group">
                    <label for="reranker-endpoint">Reranker-Endpunkt <small style="color:var(--text-muted)">(POST /v1/rerank)</small></label>
                    <input type="url" id="reranker-endpoint" name="reranker_endpoint"
                           value="<?= htmlspecialchars($rerankerEndpoint) ?>"
                           placeholder="http://localhost:8080">
                </div>
                <div class="form-group">
                    <label for="reranker-model">Reranker-Modell</label>
                    <input type="text" id="reranker-model" name="reranker_model"
                           value="<?= htmlspecialchars($rerankerModel) ?>"
                           placeholder="rerank-multilingual-v3.0">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group">
                        <label for="reranker-timeout">Timeout (Sekunden)</label>
                        <input type="number" id="reranker-timeout" name="reranker_timeout"
                               value="<?= (int)$rerankerTimeout ?>" min="5" max="300">
                    </div>
                    <div class="form-group">
                        <label for="reranker-top-k">Top-K Ergebnisse</label>
                        <input type="number" id="reranker-top-k" name="reranker_top_k"
                               value="<?= (int)$rerankerTopK ?>" min="1" max="50">
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="btn">💾 Speichern</button>
                    <button type="button" class="btn btn-secondary" id="test-reranker-btn">🔌 Verbindung testen</button>
                </div>
                <div id="reranker-test-result" style="margin-top:10px;font-size:.85rem"></div>
            </form>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         RAG-Statistik / Monitoring
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="embedding-stats-card">
        <details class="config-panel" open>
            <summary>📊 RAG-Statistik &amp; Monitoring</summary>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px">
                <div class="stat-box">
                    <span class="stat-label">Chunks gesamt</span>
                    <span class="stat-value"><?= number_format($embeddingStatsRow['total_chunks']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Mit Embedding</span>
                    <span class="stat-value" style="color:var(--success)"><?= number_format($embeddingStatsRow['chunks_with_embed']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ohne Embedding</span>
                    <span class="stat-value" style="color:<?= $embeddingStatsRow['chunks_missing'] > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>">
                        <?= number_format($embeddingStatsRow['chunks_missing']) ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Uploads ✓</span>
                    <span class="stat-value" style="color:var(--success)"><?= number_format($embeddingStatsRow['uploads_done']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ausstehend</span>
                    <span class="stat-value"><?= number_format($embeddingStatsRow['uploads_pending']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Fehler</span>
                    <span class="stat-value" style="color:<?= $embeddingStatsRow['uploads_error'] > 0 ? 'var(--error)' : 'var(--text-muted)' ?>">
                        <?= number_format($embeddingStatsRow['uploads_error']) ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Cache-Einträge</span>
                    <span class="stat-value"><?= number_format($embeddingStatsRow['cache_entries']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ø Query-Zeit (24h)</span>
                    <span class="stat-value">
                        <?= $embeddingStatsRow['avg_duration_ms'] !== null ? $embeddingStatsRow['avg_duration_ms'] . ' ms' : '–' ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Ø Rerank-Zeit (24h)</span>
                    <span class="stat-value">
                        <?= $embeddingStatsRow['avg_rerank_ms'] !== null ? $embeddingStatsRow['avg_rerank_ms'] . ' ms' : '–' ?>
                    </span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Fehler (24h)</span>
                    <span class="stat-value" style="color:<?= $embeddingStatsRow['error_count'] > 0 ? 'var(--error)' : 'var(--text-muted)' ?>">
                        <?= number_format($embeddingStatsRow['error_count']) ?>
                    </span>
                </div>
            </div>

            <p class="hint">
                Zeigt den Status der Hybrid-RAG-Pipeline. Embedding-Anfragen werden in
                <code>embedding_logs</code> protokolliert.
                Die Statistiken beziehen sich auf die letzten 24 Stunden.
            </p>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Systemmeldungen
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-system-messages-card">
        <details class="config-panel" id="config-system-messages" open>
            <summary>💬 Systemmeldungen</summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_system_messages">

                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">🧠 intelligence_upgrade</h3>
                <div class="form-group">
                    <label for="intelligence-upgrade-message">Meldungstext für Intelligenz-Upgrade-Angebot</label>
                    <textarea id="intelligence-upgrade-message" name="intelligence_upgrade_message"
                              rows="4" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($intelligenceUpgradeMessage) ?></textarea>
                    <p class="hint">
                        Dieser Text wird dem Benutzer angezeigt, wenn nach einer Anfrage Ressourcen für ein
                        intelligenteres Modell verfügbar sind und ein Upgrade angeboten wird.
                        Leer lassen, um den Standardtext zu verwenden:
                        <em>„Es stehen Ressourcen bereit um die Aufgabe erneut mit größerer Intelligenz zu bearbeiten.
                        Dies kann länger dauern als zuvor, kann jedoch genauere Antworten liefern. Fortfahren?"</em>
                    </p>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                </div>

                <hr style="margin:24px 0;border-color:var(--border)">

                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">📢 Anmeldebanner</h3>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="login_banner_enabled"
                               <?= $loginBannerEnabled ? 'checked' : '' ?>>
                        Anmeldebanner aktivieren
                    </label>
                    <p class="hint">Wenn aktiviert, erscheint beim ersten Seitenaufruf einer Browser-Session ein Overlay-Banner, das der Benutzer mit „OK" bestätigen muss.</p>
                </div>
                <div class="form-group">
                    <label for="login-banner-text">Bannertext</label>
                    <textarea id="login-banner-text" name="login_banner_text"
                              rows="5" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($loginBannerText) ?></textarea>
                    <p class="hint">HTML ist erlaubt (z.&nbsp;B. <code>&lt;b&gt;</code>, <code>&lt;br&gt;</code>). Leer lassen, um keinen Text anzuzeigen.</p>
                </div>

                <hr style="margin:24px 0;border-color:var(--border)">

                <h3 style="margin:0 0 12px;font-size:.95rem;font-weight:600">✉️ Registrierungs-E-Mail</h3>
                <div class="form-group">
                    <label for="registration-email-text">Begrüßungstext in der Bestätigungs-E-Mail</label>
                    <textarea id="registration-email-text" name="registration_email_text"
                              rows="3" style="width:100%;resize:vertical;font-family:inherit"
                    ><?= htmlspecialchars($registrationEmailText) ?></textarea>
                    <p class="hint">
                        Dieser Text erscheint in der E-Mail, die neue Benutzer nach der Registrierung zur
                        Bestätigung ihrer E-Mail-Adresse erhalten. Platzhalter <code>{sitename}</code> wird durch
                        den Namen der Anwendung ersetzt. Leer lassen, um den Standardtext zu verwenden:
                        <em>„danke für Deine Registrierung bei {sitename}."</em>
                    </p>
                </div>
            </form>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Logging – Configuration
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="log-config-card">
        <details class="config-panel" id="log-config" open>
            <summary>⚙️ Protokollierung – Konfiguration</summary>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action"     value="save_log_config">

                <div class="form-group">
                    <label for="log-level">Mindest-Log-Level</label>
                    <select id="log-level" name="log_level" style="max-width:200px">
                        <option value="info"    <?= $logLevel === 'info'    ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $logLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="error"   <?= $logLevel === 'error'   ? 'selected' : '' ?>>Error</option>
                    </select>
                    <p class="hint">Einträge unterhalb des gewählten Levels werden nicht gespeichert.</p>
                </div>

                <div class="form-group">
                    <label for="log-retention">Aufbewahrungsdauer (Tage)</label>
                    <input type="number" id="log-retention" name="log_retention_days"
                           min="1" max="3650"
                           value="<?= htmlspecialchars((string) $logRetentionDays) ?>"
                           style="max-width:120px">
                    <p class="hint">Log-Einträge, die älter als diese Anzahl von Tagen sind, werden automatisch gelöscht.</p>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">💾 Speichern</button>
                </div>
            </form>
        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Logging – Log viewer
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="log-viewer-card">
        <details class="config-panel" id="log-viewer" open>
            <summary>📋 Protokollierung – Log</summary>

            <!-- Filter form -->
            <form method="GET" action="#log-viewer-card" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px">
                <div class="form-group" style="margin:0;flex:0 0 150px">
                    <label for="lf-from" style="font-size:.8rem">Datum von</label>
                    <input type="date" id="lf-from" name="log_from"
                           value="<?= htmlspecialchars($logFilterDateFrom) ?>"
                           style="padding:6px 8px;font-size:.85rem">
                </div>
                <div class="form-group" style="margin:0;flex:0 0 150px">
                    <label for="lf-to" style="font-size:.8rem">Datum bis</label>
                    <input type="date" id="lf-to" name="log_to"
                           value="<?= htmlspecialchars($logFilterDateTo) ?>"
                           style="padding:6px 8px;font-size:.85rem">
                </div>
                <div class="form-group" style="margin:0;flex:1 1 200px">
                    <label for="lf-kw" style="font-size:.8rem">Stichwort</label>
                    <input type="text" id="lf-kw" name="log_keyword"
                           value="<?= htmlspecialchars($logFilterKeyword) ?>"
                           placeholder="z.&thinsp;B. Websuche"
                           style="padding:6px 8px;font-size:.85rem">
                </div>
                <div class="form-group" style="margin:0;flex:0 0 130px">
                    <label for="lf-lv" style="font-size:.8rem">Level</label>
                    <select id="lf-lv" name="log_lv" style="padding:6px 8px;font-size:.85rem">
                        <option value=""        <?= $logFilterLevel === ''        ? 'selected' : '' ?>>Alle</option>
                        <option value="info"    <?= $logFilterLevel === 'info'    ? 'selected' : '' ?>>Info</option>
                        <option value="warning" <?= $logFilterLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                        <option value="error"   <?= $logFilterLevel === 'error'   ? 'selected' : '' ?>>Error</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;flex:0 0 130px">
                    <label for="lf-sort" style="font-size:.8rem">Sortierung</label>
                    <select id="lf-sort" name="log_sort" style="padding:6px 8px;font-size:.85rem">
                        <option value="desc" <?= $logSortDir === 'desc' ? 'selected' : '' ?>>Neueste zuerst</option>
                        <option value="asc"  <?= $logSortDir === 'asc'  ? 'selected' : '' ?>>Älteste zuerst</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;align-items:center;padding-bottom:2px">
                    <button type="submit" class="btn btn-primary" style="padding:7px 16px;font-size:.85rem">🔍 Filtern</button>
                    <a href="#log-viewer-card" class="btn" style="padding:7px 12px;font-size:.85rem;text-decoration:none">✕ Zurücksetzen</a>
                </div>
            </form>

            <!-- Result count -->
            <p style="font-size:.82rem;color:var(--muted);margin-bottom:12px">
                <?= number_format($logTotal) ?> Einträge gefunden
                <?php if ($logTotal > $logPerPage): ?>
                    &nbsp;·&nbsp; Seite <?= $logPage ?> von <?= $logTotalPages ?>
                <?php endif; ?>
            </p>

            <?php if (empty($logRows)): ?>
                <p style="color:var(--muted);font-size:.9rem">Keine Log-Einträge gefunden.</p>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="data-table" style="font-size:.83rem">
                    <thead>
                        <tr>
                            <th style="white-space:nowrap;width:160px">Zeitstempel</th>
                            <th style="width:80px">Level</th>
                            <th>Meldung</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logRows as $lr): ?>
                        <?php
                            $lvColor = match ($lr['level']) {
                                'warning' => 'var(--warning,#f59e0b)',
                                'error'   => 'var(--danger,#ef4444)',
                                default   => 'var(--success,#22c55e)',
                            };
                        ?>
                        <tr>
                            <td style="white-space:nowrap;color:var(--muted)"><?= htmlspecialchars($lr['created_at']) ?></td>
                            <td style="font-weight:600;color:<?= $lvColor ?>"><?= htmlspecialchars(strtoupper($lr['level'])) ?></td>
                            <td style="word-break:break-word;max-width:700px"><?= htmlspecialchars($lr['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($logTotalPages > 1): ?>
                <!-- Pagination -->
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:16px;align-items:center;font-size:.83rem">
                    <?php
                        $baseLogUrl = '?' . http_build_query(array_filter([
                            'log_from'    => $logFilterDateFrom,
                            'log_to'      => $logFilterDateTo,
                            'log_keyword' => $logFilterKeyword,
                            'log_lv'      => $logFilterLevel,
                            'log_sort'    => $logSortDir,
                        ]));
                        $pageFrom = max(1, $logPage - 3);
                        $pageTo   = min($logTotalPages, $logPage + 3);
                    ?>
                    <?php if ($logPage > 1): ?>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=1') ?>#log-viewer-card" class="btn" style="padding:4px 10px">«</a>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . ($logPage - 1)) ?>#log-viewer-card" class="btn" style="padding:4px 10px">‹</a>
                    <?php endif; ?>
                    <?php for ($p = $pageFrom; $p <= $pageTo; $p++): ?>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . $p) ?>#log-viewer-card"
                           class="btn<?= $p === $logPage ? ' btn-primary' : '' ?>"
                           style="padding:4px 10px"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($logPage < $logTotalPages): ?>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . ($logPage + 1)) ?>#log-viewer-card" class="btn" style="padding:4px 10px">›</a>
                        <a href="<?= htmlspecialchars($baseLogUrl . '&log_page=' . $logTotalPages) ?>#log-viewer-card" class="btn" style="padding:4px 10px">»</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>

        </details>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         User accounts
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="users-card">
        <h2 style="display:flex;align-items:center;gap:10px">
            👤 Benutzerkonten
            <button id="create-user-btn"
                    class="btn btn-primary"
                    style="font-size:.8rem;padding:4px 10px;line-height:1.4"
                    title="Neuen Benutzer anlegen">＋</button>
        </h2>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Benutzername</th>
                    <th>E-Mail</th>
                    <th>Bestätigt</th>
                    <th>Rolle</th>
                    <th>Standard-Modell</th>
                    <th>Erstellt am</th>
                    <th>Letzter Login</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="user-row" data-user='<?= htmlspecialchars(json_encode([
                        'id'                   => (int) $u['id'],
                        'username'             => $u['username'],
                        'email'                => $u['email'] ?? '',
                        'email_verified'       => (int) ($u['email_verified'] ?? 0),
                        'default_model'        => $u['default_model'] ?? '',
                        'can_upload_documents' => (int) ($u['can_upload_documents'] ?? 0),
                        'role'                 => $u['role'] ?? 'user',
                        'auth_source'          => $u['auth_source'] ?? 'local',
                    ]), ENT_QUOTES) ?>'
                    style="cursor:pointer" title="Klicken für Benutzerdetails">
                        <td>
                            <?= htmlspecialchars($u['username']) ?>
                            <?php if ($u['username'] === $_SESSION['admin_user']): ?>
                                <span class="badge-you">Du</span>
                            <?php endif; ?>
                            <?php if (($u['auth_source'] ?? 'local') === 'ldap'): ?>
                                <span class="badge-you" style="background:rgba(59,130,246,.18);color:#3b82f6;border-color:rgba(59,130,246,.35)">AD</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['email'] !== null ? htmlspecialchars($u['email']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                        <td>
                            <?php if ((int)($u['email_verified'] ?? 0) === 1): ?>
                                <span style="color:var(--success)">✓</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($u['role'] ?? 'user') === 'admin'): ?>
                                <span class="badge-you" style="background:rgba(108,99,255,.18);color:#6c63ff;border-color:rgba(108,99,255,.35)">Admin</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">Benutzer</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($u['default_model'] ?? '') !== ''): ?>
                                <span style="font-size:.8rem;font-family:monospace" title="<?= htmlspecialchars($u['default_model']) ?>">
                                    <?= htmlspecialchars(strlen($u['default_model']) > 28 ? substr($u['default_model'], 0, 26) . '…' : $u['default_model']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:.8rem">Standard</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td><?= $u['last_login'] !== null ? htmlspecialchars($u['last_login']) : '<span style="color:var(--text-muted)">noch nie</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="hint" style="margin-top:12px">Klicke auf einen Benutzer, um Einstellungen zu ändern oder ein Passwort-Reset zu senden.</p>

        <div style="margin-top:12px;font-size:.82rem">
            <a href="../register.php" style="color:var(--accent);text-decoration:none">+ Registrierungsseite öffnen</a>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         User overlay
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="user-overlay" style="display:none;position:fixed;inset:0;z-index:1000;
         background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
         align-items:center;justify-content:center">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
                    padding:28px 32px;max-width:480px;width:calc(100% - 32px);
                    box-shadow:0 24px 60px rgba(0,0,0,.6);position:relative">
            <button id="user-overlay-close"
                    style="position:absolute;top:14px;right:16px;background:none;border:none;
                           color:var(--text-muted);font-size:1.2rem;cursor:pointer;line-height:1"
                    aria-label="Schließen">✕</button>

            <h2 style="font-size:1rem;margin-bottom:4px" id="overlay-username"></h2>
            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:20px" id="overlay-email"></p>

            <!-- Role selector -->
            <div class="form-group">
                <label for="overlay-role">Rolle</label>
                <select id="overlay-role" style="width:100%;padding:8px 12px;background:var(--bg);
                        border:1px solid var(--border);border-radius:var(--radius);
                        color:var(--text);font-size:.88rem;font-family:var(--font)">
                    <option value="user">Benutzer</option>
                    <option value="admin">Admin</option>
                </select>
                <p class="hint">
                    Nur Benutzer mit der Rolle "Admin" können den Verwaltungsbereich (/admin) aufrufen.
                </p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
                <button id="overlay-save-role" class="btn btn-sm">💾 Rolle speichern</button>
                <span id="overlay-role-result" style="font-size:.82rem"></span>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

            <!-- Model selector -->
            <div class="form-group">
                <label for="overlay-model">Standard-Modell für diesen Benutzer</label>
                <select id="overlay-model" style="width:100%;padding:8px 12px;background:var(--bg);
                        border:1px solid var(--border);border-radius:var(--radius);
                        color:var(--text);font-size:.88rem;font-family:var(--font)">
                    <option value="">Systemweites Standard-Modell verwenden</option>
                    <?php foreach ($availableGuestModels as $model): ?>
                        <?php $intelligence = modelIntelligenceLabel($model); ?>
                        <option value="<?= htmlspecialchars($model) ?>">
                            <?= htmlspecialchars($model) ?><?= $intelligence !== '–' ? ' · ' . htmlspecialchars($intelligence) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    Ist das gewählte Modell nicht verfügbar, wird automatisch das Modell mit der nächst-geringeren Intelligenz verwendet.
                </p>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
                <button id="overlay-save-model" class="btn btn-primary">💾 Modell speichern</button>
                <span id="overlay-model-result" style="font-size:.82rem"></span>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

            <!-- Document upload permission -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <span style="font-size:.88rem">Dokument-Upload erlauben</span>
                <label style="position:relative;display:inline-block;width:40px;height:22px;cursor:pointer">
                    <input type="checkbox" id="overlay-doc-upload"
                           style="opacity:0;width:0;height:0;position:absolute">
                    <span id="overlay-doc-upload-track"
                          style="position:absolute;inset:0;border-radius:22px;background:var(--surface-alt);
                                 transition:background .2s;display:block"></span>
                    <span id="overlay-doc-upload-thumb"
                          style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;
                                 background:#fff;transition:transform .2s;display:block"></span>
                </label>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:4px">
                <button id="overlay-save-doc-perm" class="btn btn-sm">💾 Berechtigung speichern</button>
                <span id="overlay-doc-perm-result" style="font-size:.82rem"></span>
            </div>
            <p class="hint" style="margin-bottom:12px">
                Erlaubt dem Benutzer, Dokumente (Bilder) in der Chat-Oberfläche hochzuladen und per Vision-Modell zu analysieren.
            </p>

            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

            <!-- Password reset (hidden for LDAP users) -->
            <div id="overlay-pw-reset-section">
                <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:12px">
                    Sendet eine E-Mail mit einem Link zum Zurücksetzen des Passworts.
                    Das Konto wird beim nächsten Login zur Passwortänderung aufgefordert.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button id="overlay-reset-pw" class="btn">📧 Passwort-Reset senden</button>
                    <span id="overlay-reset-result" style="font-size:.82rem"></span>
                </div>
            </div>
            <div id="overlay-ldap-notice" style="display:none">
                <p style="font-size:.85rem;color:#3b82f6">
                    🏢 Dieses Konto wird über Active Directory verwaltet. Passwortänderungen erfolgen direkt im AD.
                </p>
            </div>

            <input type="hidden" id="overlay-user-id" value="">
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Create user overlay
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="create-user-overlay" style="display:none;position:fixed;inset:0;z-index:1000;
         background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
         align-items:center;justify-content:center">
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
                    padding:28px 32px;max-width:480px;width:calc(100% - 32px);
                    box-shadow:0 24px 60px rgba(0,0,0,.6);position:relative">
            <button id="create-user-overlay-close"
                    style="position:absolute;top:14px;right:16px;background:none;border:none;
                           color:var(--text-muted);font-size:1.2rem;cursor:pointer;line-height:1"
                    aria-label="Schließen">✕</button>

            <h2 style="font-size:1rem;margin-bottom:20px">➕ Neuen Benutzer anlegen</h2>

            <div id="create-user-error" style="display:none;background:rgba(224,92,92,.12);
                 border:1px solid rgba(224,92,92,.4);border-radius:var(--radius);color:var(--error);
                 font-size:.85rem;padding:10px 14px;margin-bottom:16px"></div>

            <div class="form-group">
                <label for="cu-username">Benutzername <span style="color:var(--error)">*</span></label>
                <input type="text" id="cu-username" name="cu_username"
                       autocomplete="off" placeholder="z. B. max_mustermann"
                       style="width:100%;padding:9px 12px;background:var(--bg);
                              border:1px solid var(--border);border-radius:var(--radius);
                              color:var(--text);font-size:.9rem;font-family:var(--font)">
                <p class="hint">Erlaubt: Buchstaben, Ziffern und _ (max. 100 Zeichen). Keine E-Mail-Adresse nötig.</p>
            </div>

            <div class="form-group">
                <label for="cu-password">Passwort <span style="color:var(--error)">*</span></label>
                <input type="password" id="cu-password" autocomplete="new-password"
                       style="width:100%;padding:9px 12px;background:var(--bg);
                              border:1px solid var(--border);border-radius:var(--radius);
                              color:var(--text);font-size:.9rem;font-family:var(--font)">
                <div style="height:6px;background:var(--surface-alt);border-radius:3px;margin-top:8px;overflow:hidden">
                    <div id="cu-strength-bar" style="height:100%;width:0%;border-radius:3px;transition:width .25s,background .25s"></div>
                </div>
                <div id="cu-strength-label" style="font-size:.75rem;margin-top:4px;min-height:1.1em;transition:color .25s"></div>
                <p class="hint">Min. 8 Zeichen, Groß-/Kleinbuchstaben, Ziffer und Sonderzeichen (#?!@$%^&amp;*-).</p>
            </div>

            <div class="form-group">
                <label for="cu-password2">Passwort wiederholen <span style="color:var(--error)">*</span></label>
                <input type="password" id="cu-password2" autocomplete="new-password"
                       style="width:100%;padding:9px 12px;background:var(--bg);
                              border:1px solid var(--border);border-radius:var(--radius);
                              color:var(--text);font-size:.9rem;font-family:var(--font)">
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
                <button id="cu-submit" class="btn btn-primary">✔ Benutzer anlegen</button>
                <span id="cu-result" style="font-size:.82rem"></span>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Change password
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="password-card">
        <h2>🔑 Passwort ändern</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-group" style="max-width:360px">
                <label for="old_password">Aktuelles Passwort</label>
                <input type="password" id="old_password" name="old_password"
                       autocomplete="current-password" required>
            </div>

            <div class="form-row" style="max-width:740px">
                <div class="form-group">
                    <label for="new_password">Neues Passwort</label>
                    <input type="password" id="new_password" name="new_password"
                           autocomplete="new-password" minlength="8" required>
                    <p class="hint">Mindestens 8 Zeichen.</p>
                </div>
                <div class="form-group">
                    <label for="new_password_confirm">Neues Passwort wiederholen</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm"
                           autocomplete="new-password" minlength="8" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Passwort ändern</button>
        </form>
    </div>

</main>

</div><!-- /.page-body -->

<script>
(function () {
    'use strict';

    const formTitle    = document.getElementById('ep-form-title');
    const actionInput  = document.getElementById('ep-action');
    const idInput      = document.getElementById('ep-id');
    const aliasInput   = document.getElementById('ep-alias');
    const urlInput     = document.getElementById('ep-url');
    const timeoutInput = document.getElementById('ep-timeout');
    const modelInput   = document.getElementById('ep-model-input');
    const modelList    = document.getElementById('ep-model-list');
    const activeCheck  = document.getElementById('ep-active');
    const toolCallingCheck = document.getElementById('ep-supports-tool-calling');
    const loadBtn      = document.getElementById('ep-load-btn');
    const endpointConfigPanel = document.getElementById('config-endpoints');
    const specializedSelect = document.getElementById('ep-specialized-for');

    const sshDetails  = document.getElementById('ep-ssh-details');
    const sshHost     = document.getElementById('ep-ssh-host');
    const sshPort     = document.getElementById('ep-ssh-port');
    const sshUser     = document.getElementById('ep-ssh-user');
    const sshPassword = document.getElementById('ep-ssh-password');

    // Pre-fill the form when the page loaded with ?edit=<id>
    <?php if ($editEp): ?>
    document.getElementById('ep-form-title').textContent = '✏ Endpunkt bearbeiten';
    document.getElementById('ep-action').value = 'update_endpoint';
    document.getElementById('ep-id').value     = <?= (int) $editEp['id'] ?>;
    if (endpointConfigPanel) { endpointConfigPanel.open = true; }
    document.querySelector('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    /**
     * Populate the add/edit form with an existing endpoint's values.
     * Called by the "Edit" button in each table row.
     */
    window.startEdit = function (ep) {
        formTitle.textContent  = '✏ Endpunkt bearbeiten';
        actionInput.value      = 'update_endpoint';
        idInput.value          = ep.id;
        aliasInput.value       = ep.alias || '';
        urlInput.value         = ep.base_url;
        timeoutInput.value     = ep.timeout;
        modelInput.value       = ep.default_model || '';
        activeCheck.checked    = ep.is_active == 1;
        if (toolCallingCheck) toolCallingCheck.checked = ep.supports_tool_calling != 0;
        // Clear datalist options from a previous load-models call.
        modelList.innerHTML    = '';
        // Specialization
        if (specializedSelect) specializedSelect.value = ep.specialized_for_category || '';
        // SSH fields
        if (sshHost)     sshHost.value     = ep.ssh_host || '';
        if (sshPort)     sshPort.value     = ep.ssh_port || 22;
        if (sshUser)     sshUser.value     = ep.ssh_user || '';
        if (sshPassword) sshPassword.value = '';   // never pre-fill password
        if (sshDetails && (ep.ssh_host || '').trim() !== '') sshDetails.open = true;
        if (endpointConfigPanel) { endpointConfigPanel.open = true; }
        document.querySelector('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    };

    /** Reset form back to "add" mode. */
    window.resetForm = function () {
        formTitle.textContent = '➕ Endpunkt hinzufügen';
        actionInput.value     = 'add_endpoint';
        idInput.value         = '';
        aliasInput.value      = '';
        urlInput.value        = '';
        timeoutInput.value    = '120';
        modelInput.value      = '';
        activeCheck.checked   = true;
        if (toolCallingCheck) toolCallingCheck.checked = true;
        modelList.innerHTML   = '';
        if (specializedSelect) specializedSelect.value = '';
        if (sshHost)     sshHost.value     = '';
        if (sshPort)     sshPort.value     = '22';
        if (sshUser)     sshUser.value     = '';
        if (sshPassword) sshPassword.value = '';
        if (sshDetails)  sshDetails.open   = false;
    };

    /** Load available models from the URL currently typed in the form. */
    loadBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            alert('Bitte zuerst eine URL eingeben.');
            return;
        }

        loadBtn.disabled    = true;
        loadBtn.textContent = '⟳ Laden …';

        try {
            const res  = await fetch('../api/models.php?endpoint_url=' + encodeURIComponent(url) + '&timeout=10');
            const data = await res.json();

            if (data.error) {
                alert('Fehler: ' + data.error);
                return;
            }

            const models = data.models || [];
            if (models.length === 0) {
                alert('Keine Modelle gefunden.');
                return;
            }

            // Populate the <datalist> so the text input offers autocomplete.
            modelList.innerHTML = models
                .map(m => `<option value="${m.id.replace(/"/g, '&quot;')}">`)
                .join('');

            // If the input is empty, pre-fill with the first model.
            if (!modelInput.value && models[0]) {
                modelInput.value = models[0].id;
            }
        } catch (e) {
            alert('Netzwerkfehler: ' + e.message);
        } finally {
            loadBtn.disabled    = false;
            loadBtn.textContent = '⟳ Modelle laden';
        }
    });
})();

// ── SD endpoint form ──────────────────────────────────────────────────────────
(function () {
    'use strict';

    const sdFormTitle    = document.getElementById('sd-ep-form-title');
    const sdActionInput  = document.getElementById('sd-ep-action');
    const sdIdInput      = document.getElementById('sd-ep-id');
    const sdUrlInput     = document.getElementById('sd-ep-url');
    const sdTimeoutInput = document.getElementById('sd-ep-timeout');
    const sdActiveCheck  = document.getElementById('sd-ep-active');
    const sdConfigPanel  = document.getElementById('config-sd');

    if (!sdFormTitle) { return; }

    // Pre-fill the form when the page loaded with ?edit_sd=<id>
    <?php if ($editSdEp): ?>
    sdFormTitle.textContent = '✏ SD-Endpunkt bearbeiten';
    sdActionInput.value = 'update_sd_endpoint';
    sdIdInput.value     = <?= (int) $editSdEp['id'] ?>;
    if (sdConfigPanel) { sdConfigPanel.open = true; }
    document.getElementById('sd-ep-form').closest('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    window.startSdEdit = function (ep) {
        sdFormTitle.textContent  = '✏ SD-Endpunkt bearbeiten';
        sdActionInput.value      = 'update_sd_endpoint';
        sdIdInput.value          = ep.id;
        sdUrlInput.value         = ep.base_url;
        sdTimeoutInput.value     = ep.timeout;
        sdActiveCheck.checked    = ep.is_active == 1;
        if (sdConfigPanel) { sdConfigPanel.open = true; }
        document.getElementById('sd-ep-form').closest('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    };

    window.resetSdForm = function () {
        sdFormTitle.textContent = '➕ SD-Endpunkt hinzufügen';
        sdActionInput.value     = 'add_sd_endpoint';
        sdIdInput.value         = '';
        sdUrlInput.value        = '';
        sdTimeoutInput.value    = '120';
        sdActiveCheck.checked   = true;
        document.getElementById('sd-test-result').textContent = '';
    };
})();

// ── SD connection test ────────────────────────────────────────────────────────
(function () {
    'use strict';

    const testBtn    = document.getElementById('sd-test-btn');
    const testResult = document.getElementById('sd-test-result');
    const urlInput   = document.getElementById('sd-ep-url');

    if (!testBtn) { return; }

    testBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Bitte zuerst eine URL eingeben.';
            return;
        }

        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Teste …';
        testResult.textContent = '';

        try {
            const res  = await fetch('../api/sd_checkpoints.php?endpoint_url=' + encodeURIComponent(url) + '&timeout=10');
            const data = await res.json();
            if (data.error) {
                testResult.style.color = 'var(--error)';
                testResult.textContent = '✗ ' + data.error;
            } else {
                const count = (data.checkpoints || []).length;
                testResult.style.color = 'var(--success)';
                testResult.textContent = '✓ Verbunden – ' + count + ' Checkpoint(s) gefunden.';
            }
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Verbindung testen';
        }
    });
})();

// ── SearXNG connection test ───────────────────────────────────────────────────
(function () {
    'use strict';

    const testBtn    = document.getElementById('searxng-test-btn');
    const testResult = document.getElementById('searxng-test-result');
    const urlInput   = document.getElementById('searxng-base-url');

    if (!testBtn) { return; }

    testBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Bitte zuerst eine URL eingeben.';
            return;
        }

        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Teste …';
        testResult.textContent = '';

        try {
            const res  = await fetch('../api/test_searxng.php?url=' + encodeURIComponent(url));
            const data = await res.json();
            if (data.ok) {
                testResult.style.color = 'var(--success)';
                testResult.textContent = '✓ ' + data.message;
            } else {
                testResult.style.color = 'var(--error)';
                testResult.textContent = '✗ ' + data.message;
            }
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Verbindung testen';
        }
    });
})();

// ── ComfyUI endpoint form ─────────────────────────────────────────────────────
(function () {
    'use strict';

    const comfyFormTitle    = document.getElementById('comfy-ep-form-title');
    const comfyActionInput  = document.getElementById('comfy-ep-action');
    const comfyIdInput      = document.getElementById('comfy-ep-id');
    const comfyUrlInput     = document.getElementById('comfy-ep-url');
    const comfyTimeoutInput = document.getElementById('comfy-ep-timeout');
    const comfyActiveCheck  = document.getElementById('comfy-ep-active');
    const comfyLoadBtn      = document.getElementById('comfy-ep-load-btn');
    const comfyCheckpointInput = document.getElementById('comfy-ep-checkpoint-input');
    const comfyCheckpointList  = document.getElementById('comfy-ep-checkpoint-list');
    const comfyConfigPanel = document.getElementById('config-comfy');

    if (!comfyFormTitle) { return; }

    // Pre-fill the form when the page loaded with ?edit_comfy=<id>
    <?php if ($editComfyEp): ?>
    comfyFormTitle.textContent = '✏ ComfyUI-Endpunkt bearbeiten';
    comfyActionInput.value = 'update_comfy_endpoint';
    comfyIdInput.value     = <?= (int) $editComfyEp['id'] ?>;
    if (comfyConfigPanel) { comfyConfigPanel.open = true; }
    document.getElementById('comfy-ep-form').closest('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    <?php endif; ?>

    window.startComfyEdit = function (ep) {
        comfyFormTitle.textContent   = '✏ ComfyUI-Endpunkt bearbeiten';
        comfyActionInput.value       = 'update_comfy_endpoint';
        comfyIdInput.value           = ep.id;
        comfyUrlInput.value          = ep.base_url;
        comfyTimeoutInput.value      = ep.timeout;
        comfyActiveCheck.checked     = ep.is_active == 1;
        comfyCheckpointInput.value   = ep.default_checkpoint || '';
        comfyCheckpointList.innerHTML = '';
        if (comfyConfigPanel) { comfyConfigPanel.open = true; }
        document.getElementById('comfy-ep-form').closest('.ep-form-section').scrollIntoView({ behavior: 'smooth' });
    };

    window.resetComfyForm = function () {
        comfyFormTitle.textContent    = '➕ ComfyUI-Endpunkt hinzufügen';
        comfyActionInput.value        = 'add_comfy_endpoint';
        comfyIdInput.value            = '';
        comfyUrlInput.value           = '';
        comfyTimeoutInput.value       = '120';
        comfyActiveCheck.checked      = true;
        comfyCheckpointInput.value    = '';
        comfyCheckpointList.innerHTML = '';
        document.getElementById('comfy-test-result').textContent = '';
    };

    // Load checkpoints from ComfyUI
    comfyLoadBtn.addEventListener('click', async function () {
        const url = comfyUrlInput.value.trim();
        if (!url) {
            alert('Bitte zuerst eine URL eingeben.');
            return;
        }

        comfyLoadBtn.disabled    = true;
        comfyLoadBtn.textContent = '⟳ Laden …';

        try {
            const res  = await fetch('../api/comfy_checkpoints.php?endpoint_url=' + encodeURIComponent(url) + '&timeout=10');
            const data = await res.json();

            if (data.error) {
                alert('Fehler: ' + data.error);
                return;
            }

            const checkpoints = data.checkpoints || [];
            if (checkpoints.length === 0) {
                alert('Keine Checkpoints gefunden.');
                return;
            }

            comfyCheckpointList.innerHTML = checkpoints
                .map(c => `<option value="${c.replace(/"/g, '&quot;')}">`)
                .join('');

            if (!comfyCheckpointInput.value && checkpoints[0]) {
                comfyCheckpointInput.value = checkpoints[0];
            }
        } catch (e) {
            alert('Netzwerkfehler: ' + e.message);
        } finally {
            comfyLoadBtn.disabled    = false;
            comfyLoadBtn.textContent = '⟳ Checkpoints laden';
        }
    });
})();

// ── ComfyUI connection test ───────────────────────────────────────────────────
(function () {
    'use strict';

    const testBtn    = document.getElementById('comfy-test-btn');
    const testResult = document.getElementById('comfy-test-result');
    const urlInput   = document.getElementById('comfy-ep-url');

    if (!testBtn) { return; }

    testBtn.addEventListener('click', async function () {
        const url = urlInput.value.trim();
        if (!url) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Bitte zuerst eine URL eingeben.';
            return;
        }

        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Teste …';
        testResult.textContent = '';

        try {
            const res  = await fetch('../api/comfy_checkpoints.php?endpoint_url=' + encodeURIComponent(url) + '&timeout=10');
            const data = await res.json();
            if (data.error) {
                testResult.style.color = 'var(--error)';
                testResult.textContent = '✗ ' + data.error;
            } else {
                const count = (data.checkpoints || []).length;
                testResult.style.color = 'var(--success)';
                testResult.textContent = '✓ Verbunden – ' + count + ' Checkpoint(s) gefunden.';
            }
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Verbindung testen';
        }
    });
})();

// ── Load-distribution tree ────────────────────────────────────────────────────
(function () {
    'use strict';

    // Initial data injected from PHP (avoids an extra round-trip on first load).
    const INITIAL_DATA = <?= json_encode(
        array_map(function ($s) {
            return [
                'id'            => (int) $s['id'],
                'alias'         => (string) ($s['alias'] ?? ''),
                'base_url'      => $s['base_url'],
                'default_model' => $s['default_model'],
                'is_active'     => (int) $s['is_active'],
                'running'                 => (int) $s['cnt_running'],
                'today_jobs'              => (int) $s['today_jobs'],
                'today_prompt_tokens'     => (int) $s['today_prompt_tokens'],
                'today_completion_tokens' => (int) $s['today_completion_tokens'],
                'today_tokens'            => (int) $s['today_tokens'],
                'ssh_configured' => (bool) ($s['ssh_configured'] ?? false),
                'sys_stats'      => $s['sys_stats'] ?? null,
            ];
        }, $epStats),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>;

    const INITIAL_SEARXNG = <?= json_encode([
        'enabled'              => $searxngBaseUrl !== '',
        'base_url'             => $searxngBaseUrl,
        'running'              => $searxngStats['running'],
        'today_jobs'           => $searxngStats['today_jobs'],
        'avg_duration_seconds' => $searxngStats['avg_duration_seconds'],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const INITIAL_SD = <?= json_encode(
        array_map(function ($s) {
            return [
                'id'         => (int) $s['id'],
                'base_url'   => $s['base_url'],
                'is_active'  => (int) $s['is_active'],
                'running'    => (int) $s['cnt_running'],
                'today_jobs' => (int) $s['today_jobs'],
            ];
        }, $sdStats),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>;

    const INITIAL_COMFY = <?= json_encode(
        array_map(function ($s) {
            return [
                'id'         => (int) $s['id'],
                'base_url'   => $s['base_url'],
                'is_active'  => (int) $s['is_active'],
                'running'    => (int) $s['cnt_running'],
                'today_jobs' => (int) $s['today_jobs'],
            ];
        }, $comfyStats),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>;

    const INITIAL_CLIENTS = <?= json_encode([
        'current'   => $clientStats['current'],
        'today_min' => $clientStats['today_min'],
        'today_max' => $clientStats['today_max'],
        'today_avg' => $clientStats['today_avg'],
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const MODEL_COLORS = <?= json_encode($modelColorMap,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const PALETTE = ['#6c63ff','#22c55e','#f59e0b','#ef4444',
                     '#06b6d4','#a855f7','#f97316','#84cc16'];

    const svg       = document.getElementById('load-tree-svg');
    const container = document.getElementById('load-tree-container');
    const statusEl  = document.getElementById('tree-status');
    const liveDot   = document.getElementById('tree-live-dot');
    const resetBtn  = document.getElementById('tree-reset-btn');
    const NS        = 'http://www.w3.org/2000/svg';

    // ── Pan / zoom state ──────────────────────────────────────────────────────

    let treeW = 600, treeH = 400; // full diagram dimensions
    let vpX = 0, vpY = 0, vpW = 600, vpH = 400; // current viewport (SVG coords)

    function applyViewBox() {
        svg.setAttribute('viewBox', `${vpX} ${vpY} ${vpW} ${vpH}`);
    }

    function resetView() {
        vpX = 0; vpY = 0; vpW = treeW; vpH = treeH;
        applyViewBox();
    }

    // Mouse pan
    let isDragging = false, dStartX = 0, dStartY = 0, dStartVpX = 0, dStartVpY = 0;

    container.addEventListener('mousedown', function (e) {
        if (e.button !== 0) return;
        isDragging = true;
        dStartX = e.clientX; dStartY = e.clientY;
        dStartVpX = vpX; dStartVpY = vpY;
        container.classList.add('dragging');
        e.preventDefault();
    });

    window.addEventListener('mousemove', function (e) {
        if (!isDragging) return;
        const rect = container.getBoundingClientRect();
        const scaleX = vpW / Math.max(1, rect.width);
        const scaleY = vpH / Math.max(1, rect.height);
        vpX = dStartVpX - (e.clientX - dStartX) * scaleX;
        vpY = dStartVpY - (e.clientY - dStartY) * scaleY;
        applyViewBox();
    });

    window.addEventListener('mouseup', function () {
        isDragging = false;
        container.classList.remove('dragging');
    });

    // Scroll-wheel zoom
    container.addEventListener('wheel', function (e) {
        e.preventDefault();
        const factor = e.deltaY > 0 ? 1.13 : 1 / 1.13;
        const rect   = container.getBoundingClientRect();
        const mx = (e.clientX - rect.left) / Math.max(1, rect.width);
        const my = (e.clientY - rect.top)  / Math.max(1, rect.height);
        const cx = vpX + mx * vpW;
        const cy = vpY + my * vpH;
        const newW = Math.min(treeW * 6, Math.max(200, vpW * factor));
        const newH = newW * (vpH / Math.max(1, vpW));
        vpX = cx - mx * newW;
        vpY = cy - my * newH;
        vpW = newW; vpH = newH;
        applyViewBox();
    }, { passive: false });

    // Touch pan + pinch-zoom
    let lastTouchDist = 0;
    let touchStartVpX = 0, touchStartVpY = 0, touchStartX = 0, touchStartY = 0;

    container.addEventListener('touchstart', function (e) {
        if (e.touches.length === 1) {
            isDragging = true;
            touchStartX = e.touches[0].clientX; touchStartY = e.touches[0].clientY;
            touchStartVpX = vpX; touchStartVpY = vpY;
        } else if (e.touches.length === 2) {
            isDragging = false;
            lastTouchDist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
        }
        e.preventDefault();
    }, { passive: false });

    container.addEventListener('touchmove', function (e) {
        if (e.touches.length === 1 && isDragging) {
            const rect   = container.getBoundingClientRect();
            const scaleX = vpW / Math.max(1, rect.width);
            const scaleY = vpH / Math.max(1, rect.height);
            vpX = touchStartVpX - (e.touches[0].clientX - touchStartX) * scaleX;
            vpY = touchStartVpY - (e.touches[0].clientY - touchStartY) * scaleY;
            applyViewBox();
        } else if (e.touches.length === 2) {
            const dist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            if (lastTouchDist > 0) {
                const factor = lastTouchDist / dist;
                const cx = vpX + vpW / 2;
                const cy = vpY + vpH / 2;
                const newW = Math.min(treeW * 6, Math.max(200, vpW * factor));
                const newH = newW * (treeH / Math.max(1, treeW));
                vpX = cx - newW / 2;
                vpY = cy - newH / 2;
                vpW = newW; vpH = newH;
                applyViewBox();
            }
            lastTouchDist = dist;
        }
        e.preventDefault();
    }, { passive: false });

    container.addEventListener('touchend', function () {
        isDragging = false; lastTouchDist = 0;
    });

    if (resetBtn) resetBtn.addEventListener('click', resetView);

    // ── Helpers ───────────────────────────────────────────────────────────────

    function mk(tag, attrs) {
        const e = document.createElementNS(NS, tag);
        if (attrs) {
            for (const [k, v] of Object.entries(attrs)) {
                e.setAttribute(k, v);
            }
        }
        return e;
    }

    function txt(parent, text, attrs) {
        const e = mk('text', attrs);
        e.textContent = text;
        parent.appendChild(e);
        return e;
    }

    function formatNum(n) {
        if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
        if (n >= 1_000)     return (n / 1_000).toFixed(1) + 'K';
        return String(n);
    }

    function shortUrl(url) {
        try {
            const u = new URL(url);
            return u.hostname + (u.port ? ':' + u.port : '');
        } catch (_) {
            return url.length > 28 ? url.slice(0, 26) + '…' : url;
        }
    }

    function truncate(str, max) {
        return str.length > max ? str.slice(0, max - 1) + '…' : str;
    }

    function extractIntelligenceLabel(modelName) {
        if (!modelName) return '–';
        const matches = [...String(modelName).matchAll(/(\d+(?:[.,]\d+)?)\s*b\b/gi)];
        if (matches.length === 0) return '–';
        let best = null;
        for (const m of matches) {
            const v = parseFloat((m[1] || '').replace(',', '.'));
            if (!Number.isFinite(v) || v <= 0) continue;
            if (best === null || v > best) best = v;
        }
        if (best === null) return '–';
        const rounded = Math.round(best);
        if (Math.abs(best - rounded) < 1e-6) return `${rounded}b`;
        return `${best.toFixed(2).replace(/\.?0+$/, '')}b`;
    }

    // ── Tree renderer ─────────────────────────────────────────────────────────

    function renderLoadTree(endpoints, searxng, sdEndpoints, comfyEndpoints, clients) {
        svg.innerHTML = '';

        const hasSearxng  = searxng && searxng.enabled;
        const hasSd       = Array.isArray(sdEndpoints) && sdEndpoints.length > 0;
        const hasComfy    = Array.isArray(comfyEndpoints) && comfyEndpoints.length > 0;

        if ((!endpoints || endpoints.length === 0) && !hasSearxng && !hasSd && !hasComfy) {
            treeW = 400; treeH = 60;
            svg.setAttribute('viewBox', `0 0 ${treeW} ${treeH}`);
            txt(svg, 'Keine Endpunkte konfiguriert.', {
                x: 16, y: 38,
                fill: '#8e8ea0',
                'font-size': 13,
                'font-family': 'sans-serif',
            });
            resetView();
            return;
        }

        // ── SVG defs (gradients) ──────────────────────────────────────────────
        const defs = mk('defs', null);

        function addLinearGradient(id, x2, y2, stop0, stop1) {
            const g = mk('linearGradient', { id, x1: 0, y1: 0, x2, y2 });
            const s0 = mk('stop', { offset: '0%', 'stop-color': stop0 });
            const s1 = mk('stop', { offset: '100%', 'stop-color': stop1 });
            g.appendChild(s0); g.appendChild(s1);
            defs.appendChild(g);
        }

        addLinearGradient('grad-ep',    0, 1, '#363645', '#2a2a38');
        addLinearGradient('grad-root',  0, 1, '#312f52', '#242438');
        addLinearGradient('grad-mod',   0, 1, '#333342', '#28283a');
        addLinearGradient('grad-cli',   0, 1, '#1c2b1c', '#182418');
        addLinearGradient('grad-srxng', 0, 1, '#1a2f38', '#142028');
        addLinearGradient('grad-sd',    0, 1, '#2b200f', '#201808');
        addLinearGradient('grad-comfy', 0, 1, '#221430', '#180e22');
        svg.appendChild(defs);

        // Group LLM endpoints by model
        const groups = new Map();
        const colorMap = {};
        let ci = 0;
        for (const ep of (endpoints || [])) {
            const key = ep.default_model || '–';
            if (!groups.has(key)) {
                groups.set(key, []);
                colorMap[key] = MODEL_COLORS[key] || PALETTE[ci % PALETTE.length];
                ci++;
            }
            groups.get(key).push(ep);
        }

        // ── Layout constants ──────────────────────────────────────────────────
        const PAD       = 22;
        const CLIENT_W  = 112, CLIENT_H = 96;
        const CLIENT_GAP = 40;
        const ROOT_W = 112, ROOT_H = 82;
        const MOD_W  = 178, MOD_H  = 64;
        const EP_W        = 218, EP_H      = 152;
        const EP_H_SYSSTAT = 210;  // extended height when SSH system stats are shown
        const H_GAP  = 60;
        const V_GAP  = 14;
        const SRXNG_W = EP_W, SRXNG_H = 110;
        const SD_W    = EP_W, SD_H    = 90;
        const COMFY_W = EP_W, COMFY_H = 90;

        // Per-endpoint height: extended when sys_stats are available
        function epHeight(ep) {
            return (ep.sys_stats || ep.ssh_configured) ? EP_H_SYSSTAT : EP_H;
        }

        const COL0_X = PAD;
        const COL1_X = COL0_X + CLIENT_W + CLIENT_GAP;
        const COL2_X = COL1_X + ROOT_W + H_GAP;
        const COL3_X = COL2_X + MOD_W  + H_GAP;
        const TOTAL_W = COL3_X + EP_W + PAD;

        const totalEps = (endpoints || []).length;
        const LLM_H = totalEps > 0
            ? PAD * 2 + (endpoints || []).reduce((s, ep) => s + epHeight(ep) + V_GAP, -V_GAP)
            : PAD * 2 + ROOT_H;

        const SRXNG_V_GAP = 20;
        let TOTAL_H = LLM_H;
        if (hasSearxng) { TOTAL_H += SRXNG_V_GAP + SRXNG_H; }
        if (hasSd)      { TOTAL_H += SRXNG_V_GAP + sdEndpoints.length * SD_H + (sdEndpoints.length - 1) * V_GAP; }
        if (hasComfy)   { TOTAL_H += SRXNG_V_GAP + comfyEndpoints.length * COMFY_H + (comfyEndpoints.length - 1) * V_GAP; }
        TOTAL_H += PAD;

        let curY = PAD;
        const epCY  = {};
        const epHMap = {};
        const modCY = {};

        for (const [model, eps] of groups) {
            const startY = curY;
            for (const ep of eps) {
                const h = epHeight(ep);
                epHMap[ep.id] = h;
                epCY[ep.id] = curY + h / 2;
                curY += h + V_GAP;
            }
            curY -= V_GAP;
            modCY[model] = startY + (curY - startY) / 2;
            curY += V_GAP;
        }

        const rootCY = LLM_H / 2;

        let afterLlmY = LLM_H;
        const searxngCY = afterLlmY + SRXNG_V_GAP + SRXNG_H / 2;
        if (hasSearxng) { afterLlmY += SRXNG_V_GAP + SRXNG_H; }

        const sdStartY = afterLlmY + SRXNG_V_GAP;
        const sdCY = {};
        let sdCurY = sdStartY;
        for (const sdEp of (sdEndpoints || [])) {
            sdCY[sdEp.id] = sdCurY + SD_H / 2;
            sdCurY += SD_H + V_GAP;
        }
        let afterSdY = afterLlmY;
        if (hasSd) { afterSdY += SRXNG_V_GAP + sdEndpoints.length * SD_H + (sdEndpoints.length - 1) * V_GAP; }

        const comfyStartY = afterSdY + SRXNG_V_GAP;
        const comfyCY = {};
        let comfyCurY = comfyStartY;
        for (const comfyEp of (comfyEndpoints || [])) {
            comfyCY[comfyEp.id] = comfyCurY + COMFY_H / 2;
            comfyCurY += COMFY_H + V_GAP;
        }

        // Update pan/zoom state for new diagram size
        treeW = TOTAL_W;
        treeH = TOTAL_H;
        resetView();

        // ── Connector curves ──────────────────────────────────────────────────

        const CURVE_CTRL = H_GAP * 0.65;

        // Helper: build animated path
        function connPath(d, stroke, hasActive) {
            return mk('path', {
                d,
                fill: 'none',
                stroke,
                'stroke-width': 1.8,
                class: hasActive ? 'conn-active' : 'conn-idle',
            });
        }

        // Root → each model group
        for (const [model, eps] of groups) {
            const mY = modCY[model];
            const rX = COL1_X + ROOT_W;
            const groupRunning = eps.reduce((s, e) => s + e.running, 0);
            svg.appendChild(connPath(
                `M ${rX},${rootCY} C ${rX + CURVE_CTRL},${rootCY} ${COL2_X - CURVE_CTRL},${mY} ${COL2_X},${mY}`,
                'rgba(255,255,255,0.15)',
                groupRunning > 0
            ));
        }

        // Each model group → its endpoints
        for (const [model, eps] of groups) {
            const mY = modCY[model];
            const mX = COL2_X + MOD_W;
            for (const ep of eps) {
                const eY = epCY[ep.id];
                svg.appendChild(connPath(
                    `M ${mX},${mY} C ${mX + CURVE_CTRL},${mY} ${COL3_X - CURVE_CTRL},${eY} ${COL3_X},${eY}`,
                    'rgba(255,255,255,0.12)',
                    ep.running > 0
                ));
            }
        }

        // Root → SearXNG
        if (hasSearxng) {
            const rX   = COL1_X + ROOT_W;
            const ctrl = (COL3_X - rX) * 0.55;
            svg.appendChild(connPath(
                `M ${rX},${rootCY} C ${rX + ctrl},${rootCY} ${COL3_X - ctrl * 0.4},${searxngCY} ${COL3_X},${searxngCY}`,
                'rgba(6,182,212,0.35)',
                searxng.running > 0
            ));
        }

        // Root → SD endpoints
        if (hasSd) {
            for (const sdEp of sdEndpoints) {
                const rX   = COL1_X + ROOT_W;
                const eY   = sdCY[sdEp.id];
                const ctrl = (COL3_X - rX) * 0.55;
                svg.appendChild(connPath(
                    `M ${rX},${rootCY} C ${rX + ctrl},${rootCY} ${COL3_X - ctrl * 0.4},${eY} ${COL3_X},${eY}`,
                    'rgba(249,115,22,0.35)',
                    sdEp.running > 0
                ));
            }
        }

        // Root → ComfyUI endpoints
        if (hasComfy) {
            for (const comfyEp of comfyEndpoints) {
                const rX   = COL1_X + ROOT_W;
                const eY   = comfyCY[comfyEp.id];
                const ctrl = (COL3_X - rX) * 0.55;
                svg.appendChild(connPath(
                    `M ${rX},${rootCY} C ${rX + ctrl},${rootCY} ${COL3_X - ctrl * 0.4},${eY} ${COL3_X},${eY}`,
                    'rgba(168,85,247,0.35)',
                    comfyEp.running > 0
                ));
            }
        }

        // Client → Root connector
        {
            const cRX  = COL0_X + CLIENT_W;
            const ctrl = CLIENT_GAP * 0.5;
            const anyRunning = (endpoints || []).some(e => e.running > 0);
            svg.appendChild(connPath(
                `M ${cRX},${rootCY} C ${cRX + ctrl},${rootCY} ${COL1_X - ctrl},${rootCY} ${COL1_X},${rootCY}`,
                'rgba(255,255,255,0.22)',
                anyRunning
            ));
        }

        // ── Client node ───────────────────────────────────────────────────────
        {
            const cl      = clients || {};
            const current = cl.current  ?? 0;
            const minVal  = cl.today_min ?? 0;
            const maxVal  = cl.today_max ?? 0;
            const avgVal  = cl.today_avg ?? 0;

            const g = mk('g', { transform: `translate(${COL0_X},${rootCY - CLIENT_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: CLIENT_W, height: CLIENT_H,
                rx: 12,
                fill: 'url(#grad-cli)',
                stroke: 'rgba(34,197,94,0.5)',
                'stroke-width': 1.5,
            }));
            txt(g, '👥', {
                x: CLIENT_W / 2, y: 26,
                'text-anchor': 'middle', fill: '#ececf1', 'font-size': 18, 'font-family': 'sans-serif',
            });
            txt(g, String(current), {
                x: CLIENT_W / 2, y: 48,
                'text-anchor': 'middle', fill: '#22c55e', 'font-size': 18, 'font-weight': 700, 'font-family': 'sans-serif',
            });
            txt(g, 'Clients', {
                x: CLIENT_W / 2, y: 64,
                'text-anchor': 'middle', fill: '#8e8ea0', 'font-size': 10, 'font-family': 'sans-serif',
            });
            txt(g, `min ${minVal} · max ${maxVal} · Ø ${avgVal}`, {
                x: CLIENT_W / 2, y: 80,
                'text-anchor': 'middle', fill: '#6b7280', 'font-size': 9, 'font-family': 'sans-serif',
            });

            svg.appendChild(g);
        }

        // ── Root node ─────────────────────────────────────────────────────────
        {
            const g = mk('g', { transform: `translate(${COL1_X},${rootCY - ROOT_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: ROOT_W, height: ROOT_H,
                rx: 12,
                fill: 'url(#grad-root)',
                stroke: 'rgba(108,99,255,0.65)',
                'stroke-width': 1.5,
            }));
            txt(g, '⚡', {
                x: ROOT_W / 2, y: 30,
                'text-anchor': 'middle', fill: '#ececf1', 'font-size': 22, 'font-family': 'sans-serif',
            });
            txt(g, 'LLMInt', {
                x: ROOT_W / 2, y: 50,
                'text-anchor': 'middle', fill: '#ececf1', 'font-size': 12, 'font-weight': 700, 'font-family': 'sans-serif',
            });
            txt(g, 'System', {
                x: ROOT_W / 2, y: 66,
                'text-anchor': 'middle', fill: '#8e8ea0', 'font-size': 10, 'font-family': 'sans-serif',
            });

            svg.appendChild(g);
        }

        // ── Model group nodes ─────────────────────────────────────────────────
        for (const [model, eps] of groups) {
            const color = colorMap[model];
            const mY = modCY[model];
            const g  = mk('g', { transform: `translate(${COL2_X},${mY - MOD_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: MOD_W, height: MOD_H,
                rx: 10,
                fill: 'url(#grad-mod)',
                stroke: color + '66',
                'stroke-width': 1.5,
            }));
            // Coloured left accent bar
            g.appendChild(mk('rect', {
                x: 0, y: 8, width: 4, height: MOD_H - 16,
                rx: 2, fill: color,
            }));

            const groupRunning      = eps.reduce((s, e) => s + e.running, 0);
            const modelLabel        = truncate(model, 22);
            const intelligenceLabel = extractIntelligenceLabel(model);

            txt(g, modelLabel, {
                x: 16, y: 24, fill: '#ececf1', 'font-size': 11, 'font-weight': 700, 'font-family': 'sans-serif',
            });
            txt(g, `${eps.length} Endpunkt${eps.length !== 1 ? 'e' : ''} · ${intelligenceLabel}`, {
                x: 16, y: 40, fill: '#8e8ea0', 'font-size': 10, 'font-family': 'sans-serif',
            });

            if (groupRunning > 0) {
                txt(g, `▶ ${groupRunning} laufend`, {
                    x: 16, y: 56, fill: '#f59e0b', 'font-size': 10, 'font-family': 'sans-serif',
                });
                const dot = mk('circle', { class: 'pulse-dot', cx: MOD_W - 14, cy: 14, r: 5, fill: '#f59e0b' });
                g.appendChild(dot);
            }

            svg.appendChild(g);
        }

        // ── Endpoint nodes ────────────────────────────────────────────────────
        for (const ep of endpoints) {
            const model    = ep.default_model || '–';
            const color    = colorMap[model] || '#555';
            const eY       = epCY[ep.id];
            const curEpH   = epHMap[ep.id] || EP_H;
            const isActive = ep.is_active === 1;
            const g        = mk('g', { transform: `translate(${COL3_X},${eY - curEpH / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: EP_W, height: curEpH,
                rx: 10,
                fill: 'url(#grad-ep)',
                stroke: isActive ? color + '44' : 'rgba(239,68,68,0.4)',
                'stroke-width': 1.5,
            }));
            // Accent bar (left)
            g.appendChild(mk('rect', {
                x: 0, y: 10, width: 3, height: curEpH - 20,
                rx: 1.5,
                fill: isActive ? color : '#ef4444',
                opacity: 0.7,
            }));

            // Animated running pulse (behind status dot)
            if (ep.running > 0 && isActive) {
                g.appendChild(mk('circle', { class: 'pulse-dot', cx: 16, cy: 20, r: 4, fill: '#f59e0b' }));
            }
            // Status dot
            g.appendChild(mk('circle', { cx: 16, cy: 20, r: 4, fill: isActive ? '#22c55e' : '#8e8ea0' }));

            const endpointLabel = ep.alias ? ep.alias : shortUrl(ep.base_url);
            txt(g, truncate(endpointLabel, 26), {
                x: 28, y: 24, fill: '#ececf1', 'font-size': 10.5, 'font-weight': 700, 'font-family': 'monospace, sans-serif',
            });

            // Divider
            g.appendChild(mk('line', { x1: 10, y1: 34, x2: EP_W - 10, y2: 34, stroke: 'rgba(255,255,255,0.08)', 'stroke-width': 1 }));

            // Running count
            const runColor = ep.running > 0 ? '#f59e0b' : '#8e8ea0';
            txt(g, `▶  Laufend: ${ep.running}`, {
                x: 12, y: 52, fill: runColor, 'font-size': 11, 'font-family': 'sans-serif',
            });

            // Jobs today
            txt(g, `✓  Jobs heute: ${formatNum(ep.today_jobs)}`, {
                x: 12, y: 70, fill: '#22c55e', 'font-size': 11, 'font-family': 'sans-serif',
            });

            // Tokens today
            txt(g, `↑  Prompt heute: ${formatNum(ep.today_prompt_tokens)}`, {
                x: 12, y: 88, fill: '#6c63ff', 'font-size': 11, 'font-family': 'sans-serif',
            });
            txt(g, `↓  Completion: ${formatNum(ep.today_completion_tokens)}`, {
                x: 12, y: 106, fill: '#8b5cf6', 'font-size': 11, 'font-family': 'sans-serif',
            });
            txt(g, `⬡  Total Token: ${formatNum(ep.today_tokens)}`, {
                x: 12, y: 124, fill: '#a78bfa', 'font-size': 11, 'font-family': 'sans-serif',
            });

            // Slot utilisation bar (max 4 slots)
            const maxSlots  = 4;
            const barX      = 12, barY = 136, barW = EP_W - 24, barH = 5;
            g.appendChild(mk('rect', { x: barX, y: barY, width: barW, height: barH, rx: 2.5, fill: 'rgba(255,255,255,0.07)' }));
            if (ep.running > 0) {
                const fillW = Math.round(barW * Math.min(1, ep.running / maxSlots));
                g.appendChild(mk('rect', { x: barX, y: barY, width: fillW, height: barH, rx: 2.5, fill: '#f59e0b' }));
            }

            // ── SSH system stats (RAM, CPU load, CPU temp) ────────────────────
            if (ep.sys_stats || ep.ssh_configured) {
                g.appendChild(mk('line', { x1: 10, y1: 150, x2: EP_W - 10, y2: 150, stroke: 'rgba(255,255,255,0.06)', 'stroke-width': 1 }));
                const ss = ep.sys_stats || {};
                const ssOk = ss.ok === true;

                if (!ssOk && !ss.ram_total) {
                    // SSH configured but no successful fetch yet
                    txt(g, '🔑  SSH: Warte auf Daten …', {
                        x: 12, y: 168, fill: '#6b7280', 'font-size': 10, 'font-family': 'sans-serif',
                    });
                } else {
                    // RAM
                    let ramLabel = '–';
                    if (ss.ram_total && ss.ram_used !== null && ss.ram_used !== undefined) {
                        const used  = ss.ram_used  / 1073741824;
                        const total = ss.ram_total / 1073741824;
                        const pct   = Math.round(ss.ram_used / ss.ram_total * 100);
                        ramLabel = `${used.toFixed(1)} / ${total.toFixed(1)} GB  (${pct}%)`;
                    }
                    txt(g, `🧠  RAM: ${ramLabel}`, {
                        x: 12, y: 168, fill: '#60a5fa', 'font-size': 10.5, 'font-family': 'sans-serif',
                    });

                    // CPU load
                    const load1 = ss.cpu_load_1m != null ? Number(ss.cpu_load_1m).toFixed(2) : '–';
                    const load5 = ss.cpu_load_5m != null ? Number(ss.cpu_load_5m).toFixed(2) : '–';
                    txt(g, `📊  CPU-Last: ${load1}  (5 min: ${load5})`, {
                        x: 12, y: 186, fill: '#34d399', 'font-size': 10.5, 'font-family': 'sans-serif',
                    });

                    // CPU temperature
                    const tempLabel = ss.cpu_temp != null ? `${Number(ss.cpu_temp).toFixed(1)} °C` : '–';
                    const tempColor = ss.cpu_temp != null && ss.cpu_temp >= 80 ? '#ef4444'
                                    : ss.cpu_temp != null && ss.cpu_temp >= 65 ? '#f59e0b'
                                    : '#fb923c';
                    txt(g, `🌡  Temp: ${tempLabel}`, {
                        x: 12, y: 204, fill: tempColor, 'font-size': 10.5, 'font-family': 'sans-serif',
                    });
                }
            }

            svg.appendChild(g);
        }

        // ── SearXNG tile ──────────────────────────────────────────────────────
        if (hasSearxng) {
            const SRXNG_COLOR = '#06b6d4';
            const g = mk('g', { transform: `translate(${COL3_X},${searxngCY - SRXNG_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: SRXNG_W, height: SRXNG_H,
                rx: 10, fill: 'url(#grad-srxng)', stroke: SRXNG_COLOR + '66', 'stroke-width': 1.5,
            }));

            if (searxng.running > 0) {
                g.appendChild(mk('circle', { class: 'pulse-dot', cx: 14, cy: 18, r: 4, fill: SRXNG_COLOR }));
            }
            g.appendChild(mk('circle', { cx: 14, cy: 18, r: 4, fill: SRXNG_COLOR }));

            const srxngLabel = searxng.base_url ? truncate(shortUrl(searxng.base_url), 26) : 'SearXNG';
            txt(g, srxngLabel, {
                x: 26, y: 22, fill: '#ececf1', 'font-size': 10.5, 'font-weight': 700, 'font-family': 'monospace, sans-serif',
            });
            g.appendChild(mk('line', { x1: 10, y1: 32, x2: SRXNG_W - 10, y2: 32, stroke: 'rgba(255,255,255,0.07)', 'stroke-width': 1 }));

            const runColor = searxng.running > 0 ? '#f59e0b' : '#8e8ea0';
            txt(g, `▶  Laufend: ${searxng.running}`, { x: 12, y: 52, fill: runColor, 'font-size': 11, 'font-family': 'sans-serif' });
            txt(g, `✓  Jobs heute: ${formatNum(searxng.today_jobs)}`, { x: 12, y: 72, fill: '#22c55e', 'font-size': 11, 'font-family': 'sans-serif' });

            const avgDur      = searxng.avg_duration_seconds;
            const avgDurLabel = avgDur !== null && avgDur !== undefined
                ? `⏱  Ø Antwortzeit: ${avgDur.toFixed(1)} s` : `⏱  Ø Antwortzeit: –`;
            txt(g, avgDurLabel, { x: 12, y: 92, fill: '#94a3b8', 'font-size': 11, 'font-family': 'sans-serif' });

            svg.appendChild(g);
        }

        // ── SD endpoint tiles ─────────────────────────────────────────────────
        if (hasSd) {
            const SD_COLOR = '#f97316';
            for (const sdEp of sdEndpoints) {
                const eY       = sdCY[sdEp.id];
                const isActive = sdEp.is_active === 1;
                const g        = mk('g', { transform: `translate(${COL3_X},${eY - SD_H / 2})` });

                g.appendChild(mk('rect', {
                    x: 0, y: 0, width: SD_W, height: SD_H,
                    rx: 10, fill: 'url(#grad-sd)',
                    stroke: isActive ? SD_COLOR + '66' : 'rgba(239,68,68,0.4)', 'stroke-width': 1.5,
                }));

                if (sdEp.running > 0 && isActive) {
                    g.appendChild(mk('circle', { class: 'pulse-dot', cx: 14, cy: 18, r: 4, fill: '#f59e0b' }));
                }
                g.appendChild(mk('circle', { cx: 14, cy: 18, r: 4, fill: isActive ? SD_COLOR : '#8e8ea0' }));

                txt(g, truncate(shortUrl(sdEp.base_url), 26), {
                    x: 26, y: 22, fill: '#ececf1', 'font-size': 10.5, 'font-weight': 700, 'font-family': 'monospace, sans-serif',
                });
                g.appendChild(mk('line', { x1: 10, y1: 32, x2: SD_W - 10, y2: 32, stroke: 'rgba(255,255,255,0.07)', 'stroke-width': 1 }));

                const runColor = sdEp.running > 0 ? '#f59e0b' : '#8e8ea0';
                txt(g, `▶  Laufend: ${sdEp.running}`, { x: 12, y: 52, fill: runColor, 'font-size': 11, 'font-family': 'sans-serif' });
                txt(g, `🖼  Bilder heute: ${formatNum(sdEp.today_jobs)}`, { x: 12, y: 72, fill: '#22c55e', 'font-size': 11, 'font-family': 'sans-serif' });

                svg.appendChild(g);
            }
        }

        // ── ComfyUI endpoint tiles ─────────────────────────────────────────────
        if (hasComfy) {
            const COMFY_COLOR = '#a855f7';
            for (const comfyEp of comfyEndpoints) {
                const eY       = comfyCY[comfyEp.id];
                const isActive = comfyEp.is_active === 1;
                const g        = mk('g', { transform: `translate(${COL3_X},${eY - COMFY_H / 2})` });

                g.appendChild(mk('rect', {
                    x: 0, y: 0, width: COMFY_W, height: COMFY_H,
                    rx: 10, fill: 'url(#grad-comfy)',
                    stroke: isActive ? COMFY_COLOR + '66' : 'rgba(239,68,68,0.4)', 'stroke-width': 1.5,
                }));

                if (comfyEp.running > 0 && isActive) {
                    g.appendChild(mk('circle', { class: 'pulse-dot', cx: 14, cy: 18, r: 4, fill: '#f59e0b' }));
                }
                g.appendChild(mk('circle', { cx: 14, cy: 18, r: 4, fill: isActive ? COMFY_COLOR : '#8e8ea0' }));

                txt(g, truncate(shortUrl(comfyEp.base_url), 26), {
                    x: 26, y: 22, fill: '#ececf1', 'font-size': 10.5, 'font-weight': 700, 'font-family': 'monospace, sans-serif',
                });
                g.appendChild(mk('line', { x1: 10, y1: 32, x2: COMFY_W - 10, y2: 32, stroke: 'rgba(255,255,255,0.07)', 'stroke-width': 1 }));

                const runColor = comfyEp.running > 0 ? '#f59e0b' : '#8e8ea0';
                txt(g, `▶  Laufend: ${comfyEp.running}`, { x: 12, y: 52, fill: runColor, 'font-size': 11, 'font-family': 'sans-serif' });
                txt(g, `🖼  Bilder heute: ${formatNum(comfyEp.today_jobs)}`, { x: 12, y: 72, fill: '#22c55e', 'font-size': 11, 'font-family': 'sans-serif' });

                svg.appendChild(g);
            }
        }
    }

    // ── Live stat-box updates ─────────────────────────────────────────────────

    function fmtCount(n) {
        return Number(n).toLocaleString();
    }

    function setStatBox(id, val) {
        const el = document.getElementById(id);
        if (!el) return;
        const str = fmtCount(val);
        if (el.textContent !== str) {
            el.textContent = str;
            const box = el.closest('.stat-box');
            if (box) {
                box.classList.remove('stat-flash');
                // Force reflow so animation restarts
                void box.offsetWidth;
                box.classList.add('stat-flash');
            }
        }
    }

    function updateStatBoxes(data) {
        const t     = data.totals       || {};
        const s     = data.searxng      || {};
        const sdT   = data.sd_totals    || {};
        const comfyT = data.comfy_totals || {};

        setStatBox('db-llm-running',   t.total_running           ?? 0);
        setStatBox('db-llm-done24',    t.total_done_24h          ?? 0);
        setStatBox('db-llm-done',      t.total_done              ?? 0);
        setStatBox('db-llm-error',     t.total_error             ?? 0);
        setStatBox('db-prompt-tok',    t.grand_prompt_tokens     ?? 0);
        setStatBox('db-comp-tok',      t.grand_completion_tokens ?? 0);
        setStatBox('db-total-tok',     t.grand_tokens            ?? 0);
        setStatBox('db-srxng-running', s.running                 ?? 0);
        setStatBox('db-srxng-today',   s.today_jobs              ?? 0);
        setStatBox('db-sd-running',    sdT.total_running         ?? 0);
        setStatBox('db-sd-done24',     sdT.total_done_24h        ?? 0);
        setStatBox('db-sd-done',       sdT.total_done            ?? 0);
        setStatBox('db-comfy-running', comfyT.total_running      ?? 0);
        setStatBox('db-comfy-done24',  comfyT.total_done_24h     ?? 0);
        setStatBox('db-comfy-done',    comfyT.total_done         ?? 0);
    }

    // ── Status bar ────────────────────────────────────────────────────────────

    function setStatus(label, loading) {
        if (statusEl) statusEl.textContent = label;
        if (liveDot)  liveDot.style.background = loading ? '#f59e0b' : '#22c55e';
    }

    function tsLabel(ts) {
        const d = new Date(ts * 1000);
        return 'Aktualisiert ' + d.toLocaleTimeString('de-DE');
    }

    // ── Auto-refresh ──────────────────────────────────────────────────────────

    async function refreshTree() {
        setStatus('⟳ Aktualisiere …', true);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 10000);
        try {
            const res  = await fetch('load_stats.php', { cache: 'no-store', signal: controller.signal });
            clearTimeout(timer);
            const data = await res.json();
            if (data.ok && Array.isArray(data.endpoints)) {
                renderLoadTree(data.endpoints, data.searxng || null, data.sd_endpoints || [], data.comfy_endpoints || [], data.clients || null);
                updateStatBoxes(data);
                setStatus(tsLabel(data.ts), false);
            } else {
                setStatus('Fehler beim Laden', false);
            }
        } catch (err) {
            clearTimeout(timer);
            setStatus(err.name === 'AbortError' ? 'Zeitüberschreitung' : 'Netzwerkfehler', false);
        }
    }

    // Initial render using PHP-injected data
    renderLoadTree(INITIAL_DATA, INITIAL_SEARXNG, INITIAL_SD, INITIAL_COMFY, INITIAL_CLIENTS);
    setStatus(tsLabel(Math.floor(Date.now() / 1000)), false);

    // Refresh every 15 seconds
    setInterval(refreshTree, 15000);

    // ── SSH system-stats refresh (every 60 seconds) ───────────────────────────
    //
    // Polls refresh_sys_stats.php which SSH-connects to each configured endpoint
    // and caches the results. The next load_stats.php poll then picks them up.

    async function refreshSysStats() {
        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 55000);
            const res  = await fetch('refresh_sys_stats.php', { cache: 'no-store', signal: controller.signal });
            clearTimeout(timer);
            const data = await res.json();
            if (data.ok) {
                // Trigger a tree refresh so updated sys_stats are shown promptly
                refreshTree();
            }
        } catch (_e) {
            // Non-critical – silently ignore (SSH may simply not be configured)
        }
    }

    // Only poll if at least one endpoint has SSH configured
    if (INITIAL_DATA.some(ep => ep.ssh_configured)) {
        // First fetch shortly after page load so the tile shows data quickly
        setTimeout(refreshSysStats, 3000);
        setInterval(refreshSysStats, 60000);
    }

})();
</script>

<script>
// ── Detail tables toggle ──────────────────────────────────────────────────────
(function () {
    'use strict';
    const toggle = document.getElementById('dash-detail-toggle');
    const body   = document.getElementById('dash-detail-body');
    if (!toggle || !body) return;
    toggle.addEventListener('click', function () {
        const open = body.classList.toggle('open');
        toggle.classList.toggle('open', open);
    });
})();
</script>

<script>
// ── SMTP connection test ──────────────────────────────────────────────────────
(function () {
    'use strict';

    const testBtn    = document.getElementById('smtp-test-btn');
    const testResult = document.getElementById('smtp-test-result');
    const toInput    = document.getElementById('smtp-test-to');

    if (!testBtn) return;

    testBtn.addEventListener('click', async function () {
        const to = (toInput?.value || '').trim();
        if (!to) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Bitte eine Empfänger-E-Mail eingeben.';
            return;
        }

        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Sende …';
        testResult.textContent = '';

        try {
            const res  = await fetch('../api/test_smtp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ to }),
            });
            const data = await res.json();
            testResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
            testResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Test-E-Mail senden';
        }
    });
})();

// ── SMTP auth toggle ──────────────────────────────────────────────────────────
(function () {
    'use strict';
    const authChk    = document.getElementById('smtp-auth');
    const authFields = document.getElementById('smtp-auth-fields');
    if (!authChk || !authFields) return;
    authChk.addEventListener('change', function () {
        authFields.style.display = authChk.checked ? '' : 'none';
    });
})();

// ── LDAP settings toggle ──────────────────────────────────────────────────────
(function () {
    'use strict';
    const enableChk = document.getElementById('ldap-enabled');
    const fields    = document.getElementById('ldap-settings-fields');
    const testBtn   = document.getElementById('ldap-test-btn');
    if (!enableChk || !fields) return;
    enableChk.addEventListener('change', function () {
        fields.style.display = enableChk.checked ? '' : 'none';
        if (testBtn) testBtn.disabled = !enableChk.checked;
    });
})();

// ── LDAP connection test ──────────────────────────────────────────────────────
(function () {
    'use strict';

    const testBtn    = document.getElementById('ldap-test-btn');
    const testResult = document.getElementById('ldap-test-result');

    if (!testBtn || !testResult) return;

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    testBtn.addEventListener('click', async function () {
        testBtn.disabled    = true;
        testBtn.textContent = '⟳ Teste …';
        testResult.textContent = '';

        const host    = (document.getElementById('ldap-host')          || {}).value || '';
        const port    = parseInt((document.getElementById('ldap-port') || {}).value) || 389;
        const useSsl  = !!(document.getElementById('ldap-use-ssl')     || {}).checked;
        const bindDn  = (document.getElementById('ldap-bind-dn')       || {}).value || '';
        const bindPass= (document.getElementById('ldap-bind-password') || {}).value || '';

        try {
            const res  = await fetch('../api/test_ldap.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, ldap_host: host, ldap_port: port, ldap_use_ssl: useSsl, ldap_bind_dn: bindDn, ldap_bind_pass: bindPass }),
            });
            const data = await res.json();
            testResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
            testResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
        } catch (e) {
            testResult.style.color = 'var(--error)';
            testResult.textContent = '✗ Netzwerkfehler: ' + e.message;
        } finally {
            testBtn.disabled    = false;
            testBtn.textContent = '🔌 Verbindung testen';
        }
    });
})();

// ── User overlay ──────────────────────────────────────────────────────────────
(function () {
    'use strict';

    const overlay     = document.getElementById('user-overlay');
    const closeBtn    = document.getElementById('user-overlay-close');
    const usernameEl  = document.getElementById('overlay-username');
    const emailEl     = document.getElementById('overlay-email');
    const modelSelect = document.getElementById('overlay-model');
    const userIdInput = document.getElementById('overlay-user-id');
    const saveModelBtn = document.getElementById('overlay-save-model');
    const modelResult  = document.getElementById('overlay-model-result');
    const resetPwBtn   = document.getElementById('overlay-reset-pw');
    const resetResult  = document.getElementById('overlay-reset-result');
    const docUploadChk = document.getElementById('overlay-doc-upload');
    const docUploadTrack = document.getElementById('overlay-doc-upload-track');
    const docUploadThumb = document.getElementById('overlay-doc-upload-thumb');
    const saveDocPermBtn = document.getElementById('overlay-save-doc-perm');
    const docPermResult  = document.getElementById('overlay-doc-perm-result');
    const pwResetSection = document.getElementById('overlay-pw-reset-section');
    const ldapNotice     = document.getElementById('overlay-ldap-notice');
    const roleSelect     = document.getElementById('overlay-role');
    const saveRoleBtn    = document.getElementById('overlay-save-role');
    const roleResult     = document.getElementById('overlay-role-result');

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function updateToggleUI(checked) {
        if (!docUploadTrack || !docUploadThumb) return;
        docUploadTrack.style.background = checked ? 'var(--accent)' : 'var(--surface-alt)';
        docUploadThumb.style.transform  = checked ? 'translateX(18px)' : 'translateX(0)';
    }

    if (docUploadChk) {
        docUploadChk.addEventListener('change', function () {
            updateToggleUI(this.checked);
        });
    }

    function openOverlay(user) {
        if (!overlay) return;
        userIdInput.value      = user.id;
        usernameEl.textContent = user.username;
        emailEl.textContent    = user.email || '(keine E-Mail hinterlegt)';

        // Pre-select current model
        if (modelSelect) {
            modelSelect.value = user.default_model || '';
            // Fallback: if option doesn't exist, add it temporarily
            if (modelSelect.value !== (user.default_model || '')) {
                const opt = document.createElement('option');
                opt.value       = user.default_model;
                opt.textContent = user.default_model + ' (derzeit nicht verfügbar)';
                modelSelect.appendChild(opt);
                modelSelect.value = user.default_model;
            }
        }

        // Pre-select current role
        if (roleSelect) {
            roleSelect.value = user.role || 'user';
        }

        // Set document upload toggle
        if (docUploadChk) {
            docUploadChk.checked = !!user.can_upload_documents;
            updateToggleUI(docUploadChk.checked);
        }

        // Show/hide LDAP notice and password-reset section
        const isLdap = (user.auth_source === 'ldap');
        if (pwResetSection) pwResetSection.style.display = isLdap ? 'none' : '';
        if (ldapNotice)     ldapNotice.style.display     = isLdap ? ''     : 'none';

        if (modelResult)   modelResult.textContent  = '';
        if (resetResult)   resetResult.textContent   = '';
        if (docPermResult) docPermResult.textContent = '';
        if (roleResult)    roleResult.textContent    = '';

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Open overlay on row click
    document.querySelectorAll('.user-row').forEach(row => {
        row.addEventListener('click', function () {
            try {
                const user = JSON.parse(this.dataset.user);
                openOverlay(user);
            } catch (_) {}
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeOverlay();
    });

    // ── Save role ─────────────────────────────────────────────────────────────
    if (saveRoleBtn) {
        saveRoleBtn.addEventListener('click', async function () {
            const userId = userIdInput.value;
            const role   = roleSelect ? roleSelect.value : 'user';

            saveRoleBtn.disabled    = true;
            saveRoleBtn.textContent = '⟳ Speichern …';
            if (roleResult) roleResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_user_role', user_id: parseInt(userId), role, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (roleResult) {
                    roleResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    roleResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
                if (data.ok) {
                    document.querySelectorAll('.user-row').forEach(row => {
                        try {
                            const u = JSON.parse(row.dataset.user);
                            if (u.id === parseInt(userId)) {
                                u.role = role;
                                row.dataset.user = JSON.stringify(u);
                            }
                        } catch (_) {}
                    });
                    // Reflect change immediately; reload to refresh the table's role column/count.
                    setTimeout(() => window.location.reload(), 600);
                }
            } catch (e) {
                if (roleResult) {
                    roleResult.style.color = 'var(--error)';
                    roleResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                saveRoleBtn.disabled    = false;
                saveRoleBtn.textContent = '💾 Rolle speichern';
            }
        });
    }

    // ── Save model ────────────────────────────────────────────────────────────
    if (saveModelBtn) {
        saveModelBtn.addEventListener('click', async function () {
            const userId = userIdInput.value;
            const model  = modelSelect ? modelSelect.value : '';

            saveModelBtn.disabled    = true;
            saveModelBtn.textContent = '⟳ Speichern …';
            if (modelResult) modelResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_user_model', user_id: parseInt(userId), model, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (modelResult) {
                    modelResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    modelResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
            } catch (e) {
                if (modelResult) {
                    modelResult.style.color = 'var(--error)';
                    modelResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                saveModelBtn.disabled    = false;
                saveModelBtn.textContent = '💾 Modell speichern';
            }
        });
    }

    // ── Send password reset ───────────────────────────────────────────────────
    if (resetPwBtn) {
        resetPwBtn.addEventListener('click', async function () {
            const userId = userIdInput.value;

            resetPwBtn.disabled    = true;
            resetPwBtn.textContent = '⟳ Sende …';
            if (resetResult) resetResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'send_password_reset', user_id: parseInt(userId), csrf_token: CSRF }),
                });
                const data = await res.json();
                if (resetResult) {
                    resetResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    resetResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
            } catch (e) {
                if (resetResult) {
                    resetResult.style.color = 'var(--error)';
                    resetResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                resetPwBtn.disabled    = false;
                resetPwBtn.textContent = '📧 Passwort-Reset senden';
            }
        });
    }

    // ── Save document upload permission ───────────────────────────────────────
    if (saveDocPermBtn) {
        saveDocPermBtn.addEventListener('click', async function () {
            const userId  = userIdInput.value;
            const allowed = docUploadChk ? docUploadChk.checked : false;

            saveDocPermBtn.disabled    = true;
            saveDocPermBtn.textContent = '⟳ Speichern …';
            if (docPermResult) docPermResult.textContent = '';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_user_doc_permission', user_id: parseInt(userId), allowed, csrf_token: CSRF }),
                });
                const data = await res.json();
                if (docPermResult) {
                    docPermResult.style.color = data.ok ? 'var(--success)' : 'var(--error)';
                    docPermResult.textContent = (data.ok ? '✓ ' : '✗ ') + data.message;
                }
                // Update the row data in the table.
                if (data.ok) {
                    document.querySelectorAll('.user-row').forEach(row => {
                        try {
                            const u = JSON.parse(row.dataset.user);
                            if (u.id === parseInt(userId)) {
                                u.can_upload_documents = allowed ? 1 : 0;
                                row.dataset.user = JSON.stringify(u);
                            }
                        } catch (_) {}
                    });
                }
            } catch (e) {
                if (docPermResult) {
                    docPermResult.style.color = 'var(--error)';
                    docPermResult.textContent = '✗ Netzwerkfehler: ' + e.message;
                }
            } finally {
                saveDocPermBtn.disabled    = false;
                saveDocPermBtn.textContent = '💾 Berechtigung speichern';
            }
        });
    }
})();
</script>

<script>
// ── Create user overlay ────────────────────────────────────────────────────────
(function () {
    'use strict';

    const overlay      = document.getElementById('create-user-overlay');
    const openBtn      = document.getElementById('create-user-btn');
    const closeBtn     = document.getElementById('create-user-overlay-close');
    const usernameEl   = document.getElementById('cu-username');
    const passwordEl   = document.getElementById('cu-password');
    const password2El  = document.getElementById('cu-password2');
    const strengthBar  = document.getElementById('cu-strength-bar');
    const strengthLbl  = document.getElementById('cu-strength-label');
    const submitBtn    = document.getElementById('cu-submit');
    const resultEl     = document.getElementById('cu-result');
    const errorBox     = document.getElementById('create-user-error');

    const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const pwRules = {
        len:     p => p.length >= 8,
        upper:   p => /[A-Z]/.test(p),
        lower:   p => /[a-z]/.test(p),
        digit:   p => /[0-9]/.test(p),
        special: p => /[#?!@$%^&*\-]/.test(p),
    };
    const pwLevels = [
        { label: '',             color: 'transparent', width:  '0%' },
        { label: 'Sehr schwach', color: '#ef4444',     width: '10%' },
        { label: 'Schwach',      color: '#f97316',     width: '25%' },
        { label: 'Mittel',       color: '#f59e0b',     width: '50%' },
        { label: 'Stark',        color: '#22c55e',     width: '75%' },
        { label: 'Sehr stark',   color: '#16a34a',     width: '100%' },
    ];

    function evalPassword(p) {
        return Object.values(pwRules).filter(fn => fn(p)).length;
    }

    function updateStrength() {
        const p     = passwordEl ? passwordEl.value : '';
        const score = evalPassword(p);
        const lvl   = p.length === 0 ? pwLevels[0] : (pwLevels[score] || pwLevels[pwLevels.length - 1]);
        if (strengthBar) { strengthBar.style.width = lvl.width; strengthBar.style.background = lvl.color; }
        if (strengthLbl) { strengthLbl.textContent = lvl.label; strengthLbl.style.color = lvl.color; }
    }

    function resetForm() {
        if (usernameEl)  usernameEl.value  = '';
        if (passwordEl)  passwordEl.value  = '';
        if (password2El) password2El.value = '';
        if (resultEl)    resultEl.textContent = '';
        if (errorBox)  { errorBox.style.display = 'none'; errorBox.textContent = ''; }
        updateStrength();
    }

    function openOverlay() {
        if (!overlay) return;
        resetForm();
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (usernameEl) usernameEl.focus();
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    if (openBtn)  openBtn.addEventListener('click', openOverlay);
    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.style.display !== 'none') closeOverlay();
    });

    if (passwordEl) passwordEl.addEventListener('input', updateStrength);

    if (submitBtn) {
        submitBtn.addEventListener('click', async function () {
            const username  = usernameEl  ? usernameEl.value.trim()  : '';
            const password  = passwordEl  ? passwordEl.value          : '';
            const password2 = password2El ? password2El.value         : '';

            if (errorBox) { errorBox.style.display = 'none'; errorBox.textContent = ''; }
            if (resultEl) resultEl.textContent = '';

            if (!username || !password) {
                if (errorBox) { errorBox.textContent = 'Bitte alle Pflichtfelder ausfüllen.'; errorBox.style.display = 'block'; }
                return;
            }
            if (evalPassword(password) < 5) {
                if (errorBox) { errorBox.textContent = 'Das Passwort erfüllt nicht die Sicherheitsanforderungen.'; errorBox.style.display = 'block'; }
                return;
            }
            if (password !== password2) {
                if (errorBox) { errorBox.textContent = 'Die Passwörter stimmen nicht überein.'; errorBox.style.display = 'block'; }
                return;
            }

            submitBtn.disabled    = true;
            submitBtn.textContent = '⟳ Anlegen …';

            try {
                const res  = await fetch('../api/admin_user_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create_user', username, password, password2, csrf_token: CSRF }),
                });
                const data = await res.json();

                if (data.ok) {
                    // Add new row to the users table
                    const tbody = document.querySelector('#users-card table tbody');
                    if (tbody && data.user) {
                        const u = data.user;
                        const tr = document.createElement('tr');
                        tr.className = 'user-row';
                        tr.style.cursor = 'pointer';
                        tr.title = 'Klicken für Benutzerdetails';
                        tr.dataset.user = JSON.stringify({
                            id: u.id, username: u.username, email: u.email || '',
                            email_verified: u.email_verified, default_model: u.default_model || '',
                        });
                        tr.innerHTML =
                            '<td>' + u.username.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</td>' +
                            '<td><span style="color:var(--text-muted)">–</span></td>' +
                            '<td><span style="color:var(--success)">✓</span></td>' +
                            '<td><span style="color:var(--text-muted);font-size:.8rem">Standard</span></td>' +
                            '<td>' + (u.created_at || '').replace(/&/g,'&amp;') + '</td>' +
                            '<td><span style="color:var(--text-muted)">noch nie</span></td>';
                        tbody.appendChild(tr);

                        // Make the new row clickable (same as existing rows)
                        tr.addEventListener('click', function () {
                            try {
                                const user = JSON.parse(this.dataset.user);
                                const editOverlay = document.getElementById('user-overlay');
                                if (!editOverlay) return;
                                document.getElementById('overlay-user-id').value      = user.id;
                                document.getElementById('overlay-username').textContent = user.username;
                                document.getElementById('overlay-email').textContent    = user.email || '(keine E-Mail hinterlegt)';
                                const ms = document.getElementById('overlay-model');
                                if (ms) ms.value = user.default_model || '';
                                const mr = document.getElementById('overlay-model-result');
                                const rr = document.getElementById('overlay-reset-result');
                                if (mr) mr.textContent = '';
                                if (rr) rr.textContent = '';
                                editOverlay.style.display = 'flex';
                                document.body.style.overflow = 'hidden';
                            } catch (_) {}
                        });
                    }
                    closeOverlay();
                } else {
                    if (errorBox) { errorBox.textContent = data.message || 'Unbekannter Fehler.'; errorBox.style.display = 'block'; }
                }
            } catch (err) {
                if (errorBox) { errorBox.textContent = '✗ Netzwerkfehler: ' + err.message; errorBox.style.display = 'block'; }
            } finally {
                submitBtn.disabled    = false;
                submitBtn.textContent = '✔ Benutzer anlegen';
            }
        });
    }
})();
</script>

<script>
// ── Sidebar active link highlighting ──────────────────────────────────────────
(function () {
    'use strict';

    const sectionIds = [
        'dashboard-card', 'config-smtp-card', 'config-ldap-card', 'config-searxng-card',
        'config-endpoints-card', 'config-request-handling-card',
        'config-sd-card', 'config-comfy-card', 'config-system-messages-card',
        'config-embedding-card', 'config-hybrid-search-card', 'config-reranker-card', 'embedding-stats-card',
        'log-config-card', 'log-viewer-card', 'users-card', 'password-card'
    ];

    const links = {};
    sectionIds.forEach(id => {
        const el = document.querySelector(`.sidebar a[href="#${id}"]`);
        if (el) links[id] = el;
    });

    function updateActive() {
        let current = null;
        sectionIds.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            const rect = el.getBoundingClientRect();
            if (rect.top <= 80) current = id;
        });
        Object.values(links).forEach(a => a.classList.remove('active'));
        if (current && links[current]) links[current].classList.add('active');
    }

    window.addEventListener('scroll', updateActive, { passive: true });
    updateActive();
})();
</script>

<script>
/* ── Entscheidungsfindung: category CRUD ─────────────────────────────────── */
(function () {
    const wrapper   = document.getElementById('rc-form-wrapper');
    const title     = document.getElementById('rc-form-title');
    const actionIn  = document.getElementById('rc-action');
    const idIn      = document.getElementById('rc-id');
    const nameIn    = document.getElementById('rc-name');
    const defIn     = document.getElementById('rc-definition');
    const ruleIn    = document.getElementById('rc-decision-rule');
    const sortIn    = document.getElementById('rc-sort-order');
    const prioIn    = document.getElementById('rc-decision-priority');
    const delBtn    = document.getElementById('rc-delete-btn');

    window.rcEdit = function (id, name, definition, rule, sortOrder, priority) {
        title.textContent    = 'Kategorie bearbeiten';
        actionIn.value       = 'update_routing_category';
        idIn.value           = id;
        nameIn.value         = name;
        defIn.value          = definition;
        ruleIn.value         = rule;
        sortIn.value         = sortOrder;
        prioIn.value         = priority;
        delBtn.style.display = 'inline-flex';
        wrapper.style.display = 'block';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.rcAdd = function () {
        title.textContent    = 'Neue Kategorie hinzufügen';
        actionIn.value       = 'add_routing_category';
        idIn.value           = '0';
        nameIn.value         = '';
        defIn.value          = '';
        ruleIn.value         = '';
        sortIn.value         = '<?= !empty($routingCategoriesData) ? (max(array_column($routingCategoriesData, 'sort_order')) + 1) : 1 ?>';
        prioIn.value         = '<?= !empty($routingCategoriesData) ? (max(array_column($routingCategoriesData, 'decision_priority')) + 1) : 1 ?>';
        delBtn.style.display = 'none';
        wrapper.style.display = 'block';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
        nameIn.focus();
    };

    window.rcCancel = function () {
        wrapper.style.display = 'none';
    };

    window.rcDelete = function () {
        if (!confirm('Kategorie „' + nameIn.value + '" wirklich löschen?\nDie zugehörige Modellzuordnung wird ebenfalls entfernt.')) {
            return;
        }
        actionIn.value = 'delete_routing_category';
        document.getElementById('rc-form').submit();
    };
}());
</script>

<script>
/* ── Entscheidungsfindung: prompt.txt import ─────────────────────────────── */
(function () {

    /**
     * Parse the text content of a prompt.txt file into structured category objects.
     * Returns { ok: true, categories: [...] } or { ok: false, error: '...' }.
     */
    function parsePromptTxt(text) {
        const lines = text.split(/\r?\n/);
        let section = 'header';

        const categoryNames = [];   // ordered from "Classify…" section
        const definitions   = {};   // name → definition string
        const rules         = [];   // { priority, text }
        const validOutputs  = [];   // names from "Valid outputs only:" section

        for (const rawLine of lines) {
            const trimmed = rawLine.trim();

            // Section markers
            if (trimmed === 'Classify the user\'s input into exactly one of these categories:') {
                section = 'categories'; continue;
            }
            if (trimmed === 'Definitions:') { section = 'definitions'; continue; }
            if (/^Decision rules/i.test(trimmed))  { section = 'rules';        continue; }
            if (trimmed === 'Output rules:')        { section = 'output_rules'; continue; }
            if (trimmed === 'Valid outputs only:')  { section = 'valid_outputs'; continue; }
            if (trimmed === '') continue; // skip blank lines in any section

            if (section === 'categories') {
                if (/^[A-Za-z0-9_]+$/.test(trimmed)) categoryNames.push(trimmed);
            } else if (section === 'definitions') {
                const m = trimmed.match(/^\*\s+([A-Za-z0-9_]+):\s*(.*)$/);
                if (m) definitions[m[1]] = m[2];
            } else if (section === 'rules') {
                const m = trimmed.match(/^(\d+)\.\s+(.+)$/);
                if (m) rules.push({ priority: parseInt(m[1], 10), text: m[2] });
            } else if (section === 'valid_outputs') {
                if (/^[A-Za-z0-9_]+$/.test(trimmed)) validOutputs.push(trimmed);
            }
        }

        // Validation
        if (categoryNames.length === 0) {
            return { ok: false, error: 'Keine Kategorien in der Datei gefunden. Prüfe, ob der Abschnitt „Classify the user\'s input into exactly one of these categories:" vorhanden ist.' };
        }
        if (validOutputs.length === 0) {
            return { ok: false, error: 'Kein Abschnitt „Valid outputs only:" gefunden.' };
        }
        const catSet   = new Set(categoryNames);
        const validSet = new Set(validOutputs);
        const missing  = [...catSet].filter(n => !validSet.has(n));
        const extra    = [...validSet].filter(n => !catSet.has(n));
        if (missing.length || extra.length) {
            return { ok: false, error: 'Kategorien-Liste und „Valid outputs only:" stimmen nicht überein.' };
        }
        const noDef = categoryNames.filter(n => !definitions[n]);
        if (noDef.length) {
            return { ok: false, error: 'Fehlende Definitionen für: ' + noDef.join(', ') };
        }

        // Map each rule to a category via "return CategoryName" at the end
        const ruleByCategory = {};
        for (const rule of rules) {
            const m = rule.text.match(/return\s+([A-Za-z0-9_]+)\.?\s*$/);
            if (m && catSet.has(m[1])) {
                ruleByCategory[m[1]] = rule;
            }
        }

        const categories = categoryNames.map((name, idx) => ({
            name,
            definition:        definitions[name] || '',
            decision_rule:     ruleByCategory[name] ? ruleByCategory[name].text : '',
            sort_order:        idx + 1,
            decision_priority: ruleByCategory[name] ? ruleByCategory[name].priority : 0,
        }));

        return { ok: true, categories };
    }

    window.rcImportPreview = function (input) {
        const file = input.files[0];
        if (!file) return;

        document.getElementById('rc-import-filename').textContent = file.name;

        const reader = new FileReader();
        reader.onload = function (e) {
            const result = parsePromptTxt(e.target.result || '');
            const previewEl  = document.getElementById('rc-import-preview');
            const errorEl    = document.getElementById('rc-import-error');
            const countEl    = document.getElementById('rc-import-count');
            const tbody      = document.getElementById('rc-import-tbody');
            const jsonInput  = document.getElementById('rc-import-json');
            const confirmBtn = document.getElementById('rc-import-confirm');

            previewEl.style.display = 'block';

            if (!result.ok) {
                errorEl.textContent    = '⚠️ ' + result.error;
                errorEl.style.display  = 'block';
                tbody.innerHTML        = '';
                countEl.textContent    = '0';
                jsonInput.value        = '';
                confirmBtn.disabled    = true;
                return;
            }

            errorEl.style.display = 'none';
            confirmBtn.disabled   = false;
            countEl.textContent   = result.categories.length;
            jsonInput.value       = JSON.stringify(result.categories);

            tbody.innerHTML = result.categories.map(cat => {
                const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                return '<tr>' +
                    '<td style="text-align:center;color:var(--muted)">' + cat.sort_order + '</td>' +
                    '<td><strong>' + esc(cat.name) + '</strong></td>' +
                    '<td style="color:var(--muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(cat.definition) + '">' + esc(cat.definition) + '</td>' +
                    '<td style="text-align:center;color:var(--muted)">' + cat.decision_priority + '</td>' +
                    '<td style="font-size:.8rem;color:var(--muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(cat.decision_rule) + '">' + esc(cat.decision_rule || '–') + '</td>' +
                    '</tr>';
            }).join('');

            previewEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };
        reader.readAsText(file);
    };

    window.rcImportCancel = function () {
        document.getElementById('rc-import-preview').style.display = 'none';
        document.getElementById('rc-import-filename').textContent  = 'Keine Datei ausgewählt';
        document.getElementById('rc-import-file').value            = '';
        document.getElementById('rc-import-json').value            = '';
    };
}());
</script>

<script>
/* ── Hybrid-RAG: Embedding rebuild buttons ───────────────────────────────── */
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken) ?>;

    async function runRebuild(forceAll) {
        const resultEl = document.getElementById('rebuild-emb-result');
        if (resultEl) resultEl.textContent = '⟳ Verarbeite …';

        const body = new URLSearchParams({ csrf_token: csrfToken, batch_size: '200' });
        if (forceAll) body.set('force_all', '1');

        try {
            const res  = await fetch('../api/rebuild_embeddings.php', { method: 'POST', body });
            const data = await res.json();

            if (resultEl) {
                resultEl.textContent = data.ok
                    ? '✓ ' + (data.message || 'Fertig.')
                    : '✗ ' + (data.message || 'Fehler.');
                resultEl.style.color = data.ok ? 'var(--success)' : 'var(--error)';
            }
        } catch (err) {
            if (resultEl) {
                resultEl.textContent = '✗ Netzwerkfehler: ' + err.message;
                resultEl.style.color = 'var(--error)';
            }
        }
    }

    const rebuildBtn    = document.getElementById('rebuild-emb-btn');
    const rebuildAllBtn = document.getElementById('rebuild-emb-all-btn');

    if (rebuildBtn)    rebuildBtn.addEventListener('click', () => runRebuild(false));
    if (rebuildAllBtn) rebuildAllBtn.addEventListener('click', () => {
        if (!confirm('Alle Embeddings neu berechnen? Dies kann je nach Datenmenge sehr lange dauern.')) return;
        runRebuild(true);
    });
}());
</script>

<script>
/* ── Reranker connection test ─────────────────────────────────────────────── */
(function () {
    'use strict';
    const btn = document.getElementById('test-reranker-btn');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        const endpointEl = document.getElementById('reranker-endpoint');
        const modelEl    = document.getElementById('reranker-model');
        const resultEl   = document.getElementById('reranker-test-result');

        const endpoint = endpointEl ? endpointEl.value.trim() : '';
        const model    = modelEl    ? modelEl.value.trim()    : '';

        if (!endpoint) {
            if (resultEl) { resultEl.textContent = '✗ Bitte zuerst eine Endpunkt-URL eingeben.'; resultEl.style.color = 'var(--error)'; }
            return;
        }

        btn.disabled = true;
        btn.textContent = '⟳ Teste …';
        if (resultEl) resultEl.textContent = '';

        try {
            const res = await fetch(endpoint.replace(/\/$/, '') + '/v1/rerank', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ model: model || 'test', query: 'test', documents: ['hello world'] }),
                signal: AbortSignal.timeout(10000),
            });
            if (resultEl) {
                if (res.ok || res.status === 422) {
                    resultEl.textContent = '✓ Reranker erreichbar (HTTP ' + res.status + ')';
                    resultEl.style.color = 'var(--success)';
                } else {
                    resultEl.textContent = '⚠ HTTP ' + res.status + ' – Endpunkt antwortet, aber mit Fehler.';
                    resultEl.style.color = 'var(--warning)';
                }
            }
        } catch (err) {
            if (resultEl) {
                resultEl.textContent = '✗ Nicht erreichbar: ' + err.message;
                resultEl.style.color = 'var(--error)';
            }
        } finally {
            btn.disabled = false;
            btn.textContent = '🔌 Verbindung testen';
        }
    });
}());
</script>
