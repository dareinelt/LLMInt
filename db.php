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

    try {
        $pdo->exec("
            ALTER TABLE endpoints
            ADD COLUMN alias VARCHAR(120) NOT NULL DEFAULT '' AFTER id
        ");
    } catch (Throwable $e) {
        // Column already exists or ALTER not possible on this DB state.
    }

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
    $pdo->exec("
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
    $pdo->exec("
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
    $pdo->exec("
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
    $pdo->exec("
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

    // Conversation sessions: persists chat history so a failed endpoint can be
    // replaced transparently. Rows expire 30 minutes after the last activity.
    // Extend users table with email-verification, password-reset and per-user model columns.
    foreach ([
        "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email",
        "ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(64) NULL AFTER email_verified",
        "ALTER TABLE users ADD COLUMN verification_expires TIMESTAMP NULL AFTER email_verification_token",
        "ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64) NULL AFTER verification_expires",
        "ALTER TABLE users ADD COLUMN password_reset_expires TIMESTAMP NULL AFTER password_reset_token",
        "ALTER TABLE users ADD COLUMN default_model VARCHAR(255) NOT NULL DEFAULT '' AFTER password_reset_expires",
        "ALTER TABLE users ADD COLUMN requires_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER default_model",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (Throwable $_e) { /* column already exists */ }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversation_sessions (
            session_id  CHAR(64)     NOT NULL,
            model       VARCHAR(255) NOT NULL DEFAULT '',
            messages    MEDIUMTEXT   NOT NULL,
            updated_at  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                                     ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (session_id),
            KEY idx_conv_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $epCount = (int) $pdo->query('SELECT COUNT(*) FROM endpoints')->fetchColumn();
    if ($epCount > 0) {
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute(['endpoints_bootstrapped', '1']);
        return;
    }

    $seededState = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $seededState->execute(['endpoints_bootstrapped']);
    $seededValue = $seededState->fetchColumn();
    if ($seededValue !== false && $seededValue !== null && (string) $seededValue !== '') {
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
    $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    )->execute(['endpoints_bootstrapped', '1']);
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

/**
 * Save (upsert) a conversation session's message history.
 * The row's updated_at timestamp is refreshed on every save,
 * which resets the 30-minute expiry window.
 *
 * @param string   $sessionId Hex session token (8–128 chars).
 * @param string   $model     Model name used for this session.
 * @param array    $messages  Full messages array (role/content pairs).
 */
function saveConversationSession(string $sessionId, string $model, array $messages): void
{
    if ($sessionId === '') {
        return;
    }
    try {
        getDb()->prepare(
            'INSERT INTO conversation_sessions (session_id, model, messages, updated_at)
             VALUES (?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE
                model      = VALUES(model),
                messages   = VALUES(messages),
                updated_at = NOW(3)'
        )->execute([
            $sessionId,
            $model,
            json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        // Best-effort – do not break the chat response on a save failure.
    }
}

/**
 * Load a conversation session from the database.
 * Returns null when the session does not exist or is expired.
 *
 * @return array{model: string, messages: array}|null
 */
function loadConversationSession(string $sessionId): ?array
{
    if ($sessionId === '') {
        return null;
    }
    try {
        $stmt = getDb()->prepare(
            'SELECT model, messages FROM conversation_sessions WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $messages = json_decode((string) $row['messages'], true);
        if (!is_array($messages)) {
            return null;
        }
        return ['model' => (string) $row['model'], 'messages' => $messages];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Delete all conversation sessions that have been inactive for more than
 * 30 minutes. Call this periodically (e.g., with a small probability on
 * each request) to keep the table tidy.
 */
function purgeExpiredConversationSessions(): void
{
    try {
        getDb()->exec(
            "DELETE FROM conversation_sessions
              WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
    } catch (Throwable $e) {
        // Best-effort
    }
}

/**
 * Extract the highest "Xb" parameter count from a model name.
 * Returns null when no numeric "Xb" label is found.
 */
function modelIntelligenceScore(string $modelName): ?float
{
    if ($modelName === '' || !preg_match_all('/(\d+(?:[.,]\d+)?)\s*b\b/i', $modelName, $m)) {
        return null;
    }
    $best = null;
    foreach ($m[1] as $raw) {
        $n = (float) str_replace(',', '.', $raw);
        if ($n > 0 && ($best === null || $n > $best)) {
            $best = $n;
        }
    }
    return $best;
}

/**
 * Resolve the effective model for a user, applying the intelligence-fallback rule.
 *
 * Logic:
 *  1. If $preferredModel is available in active endpoints → return $preferredModel.
 *  2. Otherwise, collect all active endpoint models sorted by intelligence score
 *     descending. Return the first model whose score is strictly lower than the
 *     preferred model's score.
 *  3. If no lower-intelligence model exists, return the lowest-scored available
 *     model (last resort).
 *  4. If no active models exist at all → return ''.
 *
 * @param string $preferredModel The model stored in users.default_model.
 * @return string The model to actually use.
 */
function resolveUserModel(string $preferredModel): string
{
    if ($preferredModel === '') {
        return '';
    }
    try {
        $db   = getDb();
        $rows = $db->query(
            'SELECT DISTINCT default_model FROM endpoints WHERE is_active = 1 AND default_model != \'\''
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return $preferredModel; // best-effort: assume available
    }

    if (empty($rows)) {
        return '';
    }

    // Preferred model is available → use it as-is
    if (in_array($preferredModel, $rows, true)) {
        return $preferredModel;
    }

    // Sort available models by intelligence score descending
    $scored = [];
    foreach ($rows as $m) {
        $scored[$m] = modelIntelligenceScore($m) ?? -1.0;
    }
    arsort($scored);

    $preferredScore = modelIntelligenceScore($preferredModel) ?? -1.0;

    // Find the first model whose score is strictly lower than the preferred one
    foreach ($scored as $model => $score) {
        if ($score < $preferredScore) {
            return $model;
        }
    }

    // No lower-intelligence model found – return the one with the lowest available score
    return array_key_last($scored) ?: '';
}
