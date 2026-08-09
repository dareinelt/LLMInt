<?php

/**
 * setup.php
 *
 * One-time initialisation script.
 * Creates the required MySQL tables and seeds a default admin user.
 *
 * Run once from the command line or via a browser (protect / delete afterwards):
 *   php setup.php
 *
 * Default credentials: admin / admin  – change immediately after first login!
 */

require_once __DIR__ . '/db.php';

$db = getDb();

// ── Create tables ────────────────────────────────────────────────────────────

$db->exec("
    CREATE TABLE IF NOT EXISTS settings (
        id            INT          NOT NULL AUTO_INCREMENT,
        setting_key   VARCHAR(100) NOT NULL,
        setting_value TEXT,
        updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id                         INT          NOT NULL AUTO_INCREMENT,
        username                   VARCHAR(100) NOT NULL,
        password_hash              VARCHAR(255) NOT NULL,
        email                      VARCHAR(255),
        email_verified             TINYINT(1)   NOT NULL DEFAULT 0,
        email_verification_token   VARCHAR(64)  NULL,
        verification_expires       TIMESTAMP    NULL,
        password_reset_token       VARCHAR(64)  NULL,
        password_reset_expires     TIMESTAMP    NULL,
        default_model              VARCHAR(255) NOT NULL DEFAULT '',
        requires_password_change   TINYINT(1)   NOT NULL DEFAULT 0,
        can_upload_documents       TINYINT(1)   NOT NULL DEFAULT 0,
        role                       ENUM('user','admin') NOT NULL DEFAULT 'user',
        auth_source                VARCHAR(10)  NOT NULL DEFAULT 'local',
        ldap_dn                    VARCHAR(500) NULL,
        created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login                 TIMESTAMP    NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_username (username),
        UNIQUE KEY uq_email    (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");


// API keys for OpenAI-compatible external clients.
$db->exec("
    CREATE TABLE IF NOT EXISTS api_keys (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      INT             NOT NULL,
        name         VARCHAR(150)    NOT NULL DEFAULT '',
        description  VARCHAR(255)    NOT NULL DEFAULT '',
        key_prefix   VARCHAR(20)     NOT NULL DEFAULT '',
        api_key_hash CHAR(64)        NOT NULL,
        created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at TIMESTAMP       NULL,
        expires_at   TIMESTAMP       NULL,
        is_active    TINYINT(1)      NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_api_key_hash (api_key_hash),
        KEY idx_api_user (user_id, is_active),
        KEY idx_api_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Endpoints: each row represents one LM Studio (or compatible) API instance.
// Endpoints sharing the same default_model form a load-balancing group.
$db->exec("
    CREATE TABLE IF NOT EXISTS endpoints (
        id            INT          NOT NULL AUTO_INCREMENT,
        alias         VARCHAR(120) NOT NULL DEFAULT '',
        base_url      VARCHAR(500) NOT NULL,
        timeout       INT          NOT NULL DEFAULT 120,
        default_model VARCHAR(255) NOT NULL DEFAULT '',
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order    INT          NOT NULL DEFAULT 0,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Tasks: records every chat request dispatched to an endpoint.
// Used for load-balancing capacity tracking and later statistical analysis.
$db->exec("
    CREATE TABLE IF NOT EXISTS tasks (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        endpoint_id       INT             NOT NULL,
        model             VARCHAR(255)    NOT NULL,
        status            ENUM('running','done','error') NOT NULL DEFAULT 'running',
        prompt_tokens     INT UNSIGNED    NULL,
        completion_tokens INT UNSIGNED    NULL,
        total_tokens      INT UNSIGNED    NULL,
        tokens_per_second DECIMAL(8,2)    NULL,
        started_at        TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at       TIMESTAMP(3)    NULL,
        PRIMARY KEY (id),
        KEY idx_endpoint_status (endpoint_id, status),
        KEY idx_model_started   (model, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Migration for existing installations that predate the tokens_per_second column.
try {
    $db->exec("ALTER TABLE tasks ADD COLUMN tokens_per_second DECIMAL(8,2) NULL AFTER total_tokens");
} catch (Throwable $_e) {
    // column already exists
}

// Search logs: records every SearXNG web-search executed during a chat request.
$db->exec("
    CREATE TABLE IF NOT EXISTS search_logs (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        query       VARCHAR(400)    NOT NULL DEFAULT '',
        status      ENUM('running','done','error') NOT NULL DEFAULT 'running',
        started_at  TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at TIMESTAMP(3)    NULL,
        PRIMARY KEY (id),
        KEY idx_status_started (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// AUTOMATIC1111 / Stable Diffusion endpoints.
$db->exec("
    CREATE TABLE IF NOT EXISTS sd_endpoints (
        id          INT          NOT NULL AUTO_INCREMENT,
        base_url    VARCHAR(500) NOT NULL,
        timeout     INT          NOT NULL DEFAULT 120,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Image-generation tasks: one row per txt2img / img2img request.
$db->exec("
    CREATE TABLE IF NOT EXISTS sd_tasks (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        endpoint_id INT             NOT NULL,
        mode        ENUM('txt2img','img2img') NOT NULL DEFAULT 'txt2img',
        status      ENUM('running','done','error') NOT NULL DEFAULT 'running',
        started_at  TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at TIMESTAMP(3)    NULL,
        PRIMARY KEY (id),
        KEY idx_sd_ep_status    (endpoint_id, status),
        KEY idx_sd_status_start (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ComfyUI endpoints.
$db->exec("
    CREATE TABLE IF NOT EXISTS comfy_endpoints (
        id                 INT          NOT NULL AUTO_INCREMENT,
        base_url           VARCHAR(500) NOT NULL,
        timeout            INT          NOT NULL DEFAULT 120,
        default_checkpoint VARCHAR(255) NOT NULL DEFAULT '',
        is_active          TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order         INT          NOT NULL DEFAULT 0,
        created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ComfyUI generation tasks: one row per generate request.
$db->exec("
    CREATE TABLE IF NOT EXISTS comfy_tasks (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        endpoint_id INT             NOT NULL,
        status      ENUM('running','done','error') NOT NULL DEFAULT 'running',
        started_at  TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at TIMESTAMP(3)    NULL,
        PRIMARY KEY (id),
        KEY idx_comfy_ep_status    (endpoint_id, status),
        KEY idx_comfy_status_start (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Tables created (or already exist).\n";

// ── Document uploads table ────────────────────────────────────────────────────

$db->exec("
    CREATE TABLE IF NOT EXISTS document_uploads (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id        INT             NOT NULL,
        original_name  VARCHAR(500)    NOT NULL DEFAULT '',
        stored_name    VARCHAR(200)    NOT NULL DEFAULT '',
        mime_type      VARCHAR(100)    NOT NULL DEFAULT '',
        file_size      INT UNSIGNED    NOT NULL DEFAULT 0,
        status         ENUM('pending','processing','done','error') NOT NULL DEFAULT 'pending',
        extracted_text MEDIUMTEXT      NULL,
        chunk_count    INT UNSIGNED    NOT NULL DEFAULT 0,
        is_global_rag  TINYINT(1)      NOT NULL DEFAULT 1,
        error_message  TEXT            NULL,
        uploaded_at    TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        processed_at   TIMESTAMP(3)    NULL,
        PRIMARY KEY (id),
        KEY idx_du_user_status (user_id, status),
        KEY idx_du_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Migration for existing installations that predate the role column.
try {
    $db->exec("ALTER TABLE users ADD COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER can_upload_documents");
} catch (Throwable $_e) {
    // column already exists
}

try {
    $db->exec("ALTER TABLE document_uploads ADD COLUMN chunk_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER extracted_text");
} catch (Throwable $_e) {
    // column already exists
}

try {
    $db->exec("ALTER TABLE document_uploads ADD COLUMN is_global_rag TINYINT(1) NOT NULL DEFAULT 1 AFTER chunk_count");
} catch (Throwable $_e) {
    // column already exists
}

$db->exec("
    CREATE TABLE IF NOT EXISTS document_chunks (
        id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        document_upload_id BIGINT UNSIGNED NOT NULL,
        user_id            INT             NOT NULL,
        chunk_index        INT UNSIGNED    NOT NULL,
        chunk_text         MEDIUMTEXT      NOT NULL,
        created_at         TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (id),
        UNIQUE KEY uniq_doc_chunk (document_upload_id, chunk_index),
        KEY idx_dc_user (user_id),
        KEY idx_dc_doc (document_upload_id),
        CONSTRAINT fk_dc_doc_upload
            FOREIGN KEY (document_upload_id) REFERENCES document_uploads(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Seed default settings ────────────────────────────────────────────────────

$defaults = [
    'lmstudio_base_url' => 'http://localhost:1234/v1',
    'lmstudio_timeout'  => '120',
    'searxng_base_url'  => '',
];

$insert = $db->prepare(
    'INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)'
);

foreach ($defaults as $key => $value) {
    $insert->execute([$key, $value]);
}

echo "Default settings seeded.\n";

// ── Migrate / seed endpoints table ───────────────────────────────────────────

$epCount = (int) $db->query('SELECT COUNT(*) FROM endpoints')->fetchColumn();

if ($epCount === 0) {
    // Carry over the old single-endpoint settings if they were customised.
    $oldUrl     = getSetting('lmstudio_base_url', '');
    $oldTimeout = max(1, (int) getSetting('lmstudio_timeout', '120'));
    $oldModel   = getSetting('default_model', '');

    $seedUrl = ($oldUrl !== '' && $oldUrl !== 'http://localhost:1234/v1')
        ? $oldUrl
        : 'http://localhost:1234/v1';

    $db->prepare(
        'INSERT INTO endpoints (base_url, timeout, default_model, is_active, sort_order) VALUES (?, ?, ?, 1, 0)'
    )->execute([$seedUrl, $oldTimeout, $oldModel]);

    if ($seedUrl !== 'http://localhost:1234/v1') {
        echo "Existing endpoint migrated to endpoints table.\n";
    } else {
        echo "Default endpoint seeded.\n";
    }
} else {
    echo "Endpoints already configured – skipping seed.\n";
}

setSetting('endpoints_bootstrapped', '1');

// ── Seed default admin user (only when no users exist) ───────────────────────

$count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($count === 0) {
    $hash = password_hash('admin', PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO users (username, password_hash, can_upload_documents, role) VALUES (?, ?, 1, 'admin')")->execute(['admin', $hash]);
    echo "\n";
    echo "Default admin user created:\n";
    echo "  Username : admin\n";
    echo "  Password : admin\n";
    echo "\n!! Change the password immediately after first login !!\n";
} else {
    echo "Admin user(s) already exist – skipping.\n";

    // ── Migration safety net ──────────────────────────────────────────────────
    // If this install predates the role column, every existing user was just
    // migrated to role='user'. Without at least one admin nobody could reach
    // the admin panel anymore, so promote the 'admin' account (or, failing
    // that, the oldest account) once.
    $adminCount = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminCount === 0) {
        $stmt = $db->prepare("SELECT id, username FROM users WHERE username = 'admin' LIMIT 1");
        $stmt->execute();
        $promote = $stmt->fetch();

        if (!$promote) {
            $stmt = $db->query('SELECT id, username FROM users ORDER BY id ASC LIMIT 1');
            $promote = $stmt->fetch();
        }

        if ($promote) {
            $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$promote['id']]);
            echo "Kein Administrator gefunden – \"{$promote['username']}\" wurde automatisch zum Admin befördert.\n";
            echo "Bitte in der Benutzerverwaltung prüfen, ob dies korrekt ist.\n";
        }
    }
}

echo "\nSetup complete.\n";
