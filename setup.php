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

echo "Tables created (or already exist).\n";

// ── Seed default settings ────────────────────────────────────────────────────

$defaults = [
    'lmstudio_base_url' => 'http://localhost:1234/v1',
    'lmstudio_timeout'  => '120',
];

$insert = $db->prepare(
    'INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)'
);

foreach ($defaults as $key => $value) {
    $insert->execute([$key, $value]);
}

echo "Default settings seeded.\n";

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
