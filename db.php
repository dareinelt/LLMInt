<?php

/**
 * db.php
 *
 * MySQL database connection and settings helper.
 *
 * Connection parameters are read from environment variables:
 *   DB_HOST  (default: localhost)
 *   DB_PORT  (default: 3306)
 *   DB_NAME  (default: llmint)
 *   DB_USER  (default: root)
 *   DB_PASS  (default: empty)
 */

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'llmint';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    ensureRuntimeSchema($pdo);

    return $pdo;
}

/**
 * Creates core tables that newer app versions require, if they are missing.
 * This keeps legacy databases compatible without requiring a manual setup run.
 */
function ensureRuntimeSchema(PDO $pdo): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    $pdo->exec("
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS endpoints (
            id            INT          NOT NULL AUTO_INCREMENT,
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

    $pdo->exec("
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_jobs (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status      ENUM('running','done','error') NOT NULL DEFAULT 'running',
            started_at  TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            finished_at TIMESTAMP(3)    NULL,
            PRIMARY KEY (id),
            KEY idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $epCount = (int) $pdo->query('SELECT COUNT(*) FROM endpoints')->fetchColumn();
    if ($epCount > 0) {
        return;
    }

    $seedUrl = 'http://localhost:1234/v1';
    $seedTimeout = 120;

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('lmstudio_base_url', 'lmstudio_timeout', 'default_model')");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        if (!empty($settings['lmstudio_base_url'])) {
            $seedUrl = rtrim($settings['lmstudio_base_url'], '/');
        }
        if (!empty($settings['lmstudio_timeout'])) {
            $seedTimeout = max(1, (int) $settings['lmstudio_timeout']);
        }
        $seedModel = $settings['default_model'] ?? '';
    } catch (Throwable $e) {
        $seedModel = '';
    }

    $pdo->prepare(
        'INSERT INTO endpoints (base_url, timeout, default_model, is_active, sort_order) VALUES (?, ?, ?, 1, 0)'
    )->execute([$seedUrl, $seedTimeout, $seedModel]);
}

/**
 * Read a single value from the settings table.
 * Returns $default if the key does not exist or the DB is unavailable.
 */
function getSetting(string $key, string $default = ''): string
{
    try {
        $stmt = getDb()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return ($row !== false && $row['setting_value'] !== null)
            ? (string) $row['setting_value']
            : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Persist a single value to the settings table (upsert).
 */
function setSetting(string $key, string $value): void
{
    getDb()->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    )->execute([$key, $value]);
}
