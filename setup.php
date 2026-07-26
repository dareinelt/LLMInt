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
        id            INT          NOT NULL AUTO_INCREMENT,
        username      VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        email         VARCHAR(255),
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login    TIMESTAMP    NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_username (username)
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
        started_at        TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at       TIMESTAMP(3)    NULL,
        PRIMARY KEY (id),
        KEY idx_endpoint_status (endpoint_id, status),
        KEY idx_model_started   (model, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

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
    $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute(['admin', $hash]);
    echo "\n";
    echo "Default admin user created:\n";
    echo "  Username : admin\n";
    echo "  Password : admin\n";
    echo "\n!! Change the password immediately after first login !!\n";
} else {
    echo "Admin user(s) already exist – skipping.\n";
}

echo "\nSetup complete.\n";
