<?php

/**
 * admin/index.php
 *
 * Protected admin dashboard.
 * Manages multiple LM Studio endpoints (CRUD), shows load-balancing
 * statistics, lists user accounts, and allows password changes.
 */

session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';

$db = getDb();
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));
$guestDefaultModel  = trim(getSetting('default_model', ''));
$newUserDefaultModel = trim(getSetting('new_user_default_model', ''));
$visionModel = trim(getSetting('vision_model', ''));

// ── SMTP settings ─────────────────────────────────────────────────────────────
$smtpHost       = getSetting('smtp_host', '');
$smtpPort       = getSetting('smtp_port', '587');
$smtpEncryption = getSetting('smtp_encryption', 'tls');
$smtpUser       = getSetting('smtp_user', '');
$smtpPass       = getSetting('smtp_pass', '');
$smtpFromEmail  = getSetting('smtp_from_email', '');
$smtpFromName   = getSetting('smtp_from_name', 'LLMInt');

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
            $newAlias   = trim($_POST['ep_alias'] ?? '');
            $newUrl     = trim($_POST['ep_base_url'] ?? '');
            $newTimeout = (int) ($_POST['ep_timeout'] ?? 120);
            $newModel   = trim($_POST['ep_default_model'] ?? '');
            $isActive   = isset($_POST['ep_is_active']) ? 1 : 0;

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
                    'INSERT INTO endpoints (alias, base_url, timeout, default_model, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newModel, $isActive, $maxOrder + 1]);
                $flashOk = 'Endpunkt hinzugefügt.';
            }

        // ── Update endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'update_endpoint') {
            $epId       = (int) ($_POST['ep_id'] ?? 0);
            $newAlias   = trim($_POST['ep_alias'] ?? '');
            $newUrl     = trim($_POST['ep_base_url'] ?? '');
            $newTimeout = (int) ($_POST['ep_timeout'] ?? 120);
            $newModel   = trim($_POST['ep_default_model'] ?? '');
            $isActive   = isset($_POST['ep_is_active']) ? 1 : 0;

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
                $db->prepare(
                    'UPDATE endpoints
                        SET alias = ?, base_url = ?, timeout = ?, default_model = ?, is_active = ?
                      WHERE id = ?'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newModel, $isActive, $epId]);
                $flashOk = 'Endpunkt gespeichert.';
            }

        // ── Delete endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'delete_endpoint') {
            $epId = (int) ($_POST['ep_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM endpoints WHERE id = ?')->execute([$epId]);
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
                $smtpUser       = $newSmtpUser;
                if ($newSmtpPass !== '') { $smtpPass = $newSmtpPass; }
                $smtpFromEmail  = $newSmtpFromEmail;
                $smtpFromName   = $newSmtpFromName;

                $flashOk = 'SMTP-Einstellungen gespeichert.';
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

// ── Load data ─────────────────────────────────────────────────────────────────

$endpoints = $db->query(
    'SELECT * FROM endpoints ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$users = $db->query(
    'SELECT id, username, email, email_verified, default_model, can_upload_documents, created_at, last_login
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
        GROUP BY e.id, e.alias, e.base_url, e.default_model, e.is_active
        ORDER BY e.sort_order ASC, e.id ASC
    ')->fetchAll();

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

// ── Connected-client stats ────────────────────────────────────────────────────

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
    <a href="#config-searxng-card">🔎 Websuche</a>
    <a href="#config-endpoints-card">🔗 Endpunkte</a>
    <a href="#config-request-handling-card">📨 Anfragenhandling</a>
    <a href="#config-sd-card">🎨 AUTOMATIC1111</a>
    <a href="#config-comfy-card">🖼️ ComfyUI</a>

    <span class="sidebar-label">Verwaltung</span>
    <a href="#users-card">👤 Benutzerkonten</a>
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
         SMTP / Outgoing Mail Server
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card" id="config-smtp-card">
        <details class="config-panel" id="config-smtp">
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
                        <span class="dot <?= $ep['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <?= $ep['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm"
                                onclick="startEdit(<?= htmlspecialchars(json_encode($ep), ENT_QUOTES) ?>)">
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
                    <label class="inline">
                        <input type="checkbox" id="ep-active" name="ep_is_active"
                               <?= (!$editEp || $editEp['is_active']) ? 'checked' : '' ?>>
                        Endpunkt aktiv (nimmt Anfragen entgegen)
                    </label>
                </div>

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
                    ]), ENT_QUOTES) ?>'
                    style="cursor:pointer" title="Klicken für Benutzerdetails">
                        <td>
                            <?= htmlspecialchars($u['username']) ?>
                            <?php if ($u['username'] === $_SESSION['admin_user']): ?>
                                <span class="badge-you">Du</span>
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

            <!-- Password reset -->
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:12px">
                Sendet eine E-Mail mit einem Link zum Zurücksetzen des Passworts.
                Das Konto wird beim nächsten Login zur Passwortänderung aufgefordert.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <button id="overlay-reset-pw" class="btn">📧 Passwort-Reset senden</button>
                <span id="overlay-reset-result" style="font-size:.82rem"></span>
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
    const loadBtn      = document.getElementById('ep-load-btn');
    const endpointConfigPanel = document.getElementById('config-endpoints');

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
        // Clear datalist options from a previous load-models call.
        modelList.innerHTML    = '';
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
        modelList.innerHTML   = '';
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
        const EP_W   = 218, EP_H   = 152;
        const H_GAP  = 60;
        const V_GAP  = 14;
        const SRXNG_W = EP_W, SRXNG_H = 110;
        const SD_W    = EP_W, SD_H    = 90;
        const COMFY_W = EP_W, COMFY_H = 90;

        const COL0_X = PAD;
        const COL1_X = COL0_X + CLIENT_W + CLIENT_GAP;
        const COL2_X = COL1_X + ROOT_W + H_GAP;
        const COL3_X = COL2_X + MOD_W  + H_GAP;
        const TOTAL_W = COL3_X + EP_W + PAD;

        const totalEps = (endpoints || []).length;
        const LLM_H = totalEps > 0
            ? PAD * 2 + totalEps * EP_H + (totalEps - 1) * V_GAP
            : PAD * 2 + ROOT_H;

        const SRXNG_V_GAP = 20;
        let TOTAL_H = LLM_H;
        if (hasSearxng) { TOTAL_H += SRXNG_V_GAP + SRXNG_H; }
        if (hasSd)      { TOTAL_H += SRXNG_V_GAP + sdEndpoints.length * SD_H + (sdEndpoints.length - 1) * V_GAP; }
        if (hasComfy)   { TOTAL_H += SRXNG_V_GAP + comfyEndpoints.length * COMFY_H + (comfyEndpoints.length - 1) * V_GAP; }
        TOTAL_H += PAD;

        let curY = PAD;
        const epCY  = {};
        const modCY = {};

        for (const [model, eps] of groups) {
            const startY = curY;
            for (const ep of eps) {
                epCY[ep.id] = curY + EP_H / 2;
                curY += EP_H + V_GAP;
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
            const isActive = ep.is_active === 1;
            const g        = mk('g', { transform: `translate(${COL3_X},${eY - EP_H / 2})` });

            g.appendChild(mk('rect', {
                x: 0, y: 0, width: EP_W, height: EP_H,
                rx: 10,
                fill: 'url(#grad-ep)',
                stroke: isActive ? color + '44' : 'rgba(239,68,68,0.4)',
                'stroke-width': 1.5,
            }));
            // Accent bar (left)
            g.appendChild(mk('rect', {
                x: 0, y: 10, width: 3, height: EP_H - 20,
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

        // Set document upload toggle
        if (docUploadChk) {
            docUploadChk.checked = !!user.can_upload_documents;
            updateToggleUI(docUploadChk.checked);
        }

        if (modelResult)   modelResult.textContent  = '';
        if (resetResult)   resetResult.textContent   = '';
        if (docPermResult) docPermResult.textContent = '';

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
        'dashboard-card', 'config-smtp-card', 'config-searxng-card',
        'config-endpoints-card', 'config-request-handling-card',
        'config-sd-card', 'config-comfy-card', 'users-card', 'password-card'
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

</body>
</html>
