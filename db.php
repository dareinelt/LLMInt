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

    // Specialisation flag: category name the endpoint's model is optimised for,
    // or empty string for general-purpose models.
    try {
        $pdo->exec("ALTER TABLE endpoints ADD COLUMN specialized_for_category VARCHAR(100) NOT NULL DEFAULT '' AFTER default_model");
    } catch (Throwable $_e) { /* column already exists */ }

    // Tool-calling support flag: whether the model served by this endpoint supports
    // the OpenAI-compatible tool-calling API. Defaults to 1 (supported).
    try {
        $pdo->exec("ALTER TABLE endpoints ADD COLUMN supports_tool_calling TINYINT(1) NOT NULL DEFAULT 1 AFTER specialized_for_category");
    } catch (Throwable $_e) { /* column already exists */ }

    // SSH credentials for system-metric polling (RAM, CPU load, CPU temp via lm-sensors).
    foreach ([
        "ALTER TABLE endpoints ADD COLUMN ssh_host     VARCHAR(255) NOT NULL DEFAULT '' AFTER is_active",
        "ALTER TABLE endpoints ADD COLUMN ssh_port     SMALLINT UNSIGNED NOT NULL DEFAULT 22 AFTER ssh_host",
        "ALTER TABLE endpoints ADD COLUMN ssh_user     VARCHAR(100) NOT NULL DEFAULT '' AFTER ssh_port",
        "ALTER TABLE endpoints ADD COLUMN ssh_password TEXT NULL AFTER ssh_user",
    ] as $sshAlter) {
        try { $pdo->exec($sshAlter); } catch (Throwable $_e) { /* column already exists */ }
    }

    // Cache table for SSH-polled system metrics (one row per endpoint).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS endpoint_sys_stats (
            endpoint_id  INT              NOT NULL,
            ram_total    BIGINT UNSIGNED  NULL,
            ram_used     BIGINT UNSIGNED  NULL,
            cpu_load_1m  FLOAT            NULL,
            cpu_load_5m  FLOAT            NULL,
            cpu_temp     FLOAT            NULL,
            fetch_ok     TINYINT(1)       NOT NULL DEFAULT 0,
            fetched_at   TIMESTAMP(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                                          ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (endpoint_id)
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

    // Active-client heartbeat tracking.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_clients (
            token      CHAR(128)    NOT NULL,
            last_seen  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                                    ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (token),
            KEY idx_ac_last_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Periodic samples of the concurrent client count (for min/max/avg).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_count_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cnt         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            recorded_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            KEY idx_ccl_recorded (recorded_at)
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
    // replaced transparently. Anonymous rows expire 30 minutes after the last
    // activity; user-linked rows are kept indefinitely.
    // Extend users table with email-verification, password-reset and per-user model columns.
    foreach ([
        "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email",
        "ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(64) NULL AFTER email_verified",
        "ALTER TABLE users ADD COLUMN verification_expires TIMESTAMP NULL AFTER email_verification_token",
        "ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64) NULL AFTER verification_expires",
        "ALTER TABLE users ADD COLUMN password_reset_expires TIMESTAMP NULL AFTER password_reset_token",
        "ALTER TABLE users ADD COLUMN default_model VARCHAR(255) NOT NULL DEFAULT '' AFTER password_reset_expires",
        "ALTER TABLE users ADD COLUMN requires_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER default_model",
        "ALTER TABLE users ADD COLUMN can_upload_documents TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_password_change",
        "ALTER TABLE users ADD COLUMN auth_source VARCHAR(10) NOT NULL DEFAULT 'local' AFTER can_upload_documents",
        "ALTER TABLE users ADD COLUMN ldap_dn VARCHAR(500) NULL AFTER auth_source",
        "ALTER TABLE document_uploads ADD COLUMN chunk_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER extracted_text",
        "ALTER TABLE document_uploads ADD COLUMN is_global_rag TINYINT(1) NOT NULL DEFAULT 1 AFTER chunk_count",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (Throwable $_e) { /* column already exists */ }
    }


    // External API keys for OpenAI-compatible endpoints.
    $pdo->exec("
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

    // Document uploads: one row per uploaded file, including vision-model analysis result.
    $pdo->exec("
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

    $pdo->exec("
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

    // Embedding columns on document_chunks (added lazily for backward compatibility).
    foreach ([
        "ALTER TABLE document_chunks ADD COLUMN embedding         MEDIUMTEXT   NULL AFTER chunk_text",
        "ALTER TABLE document_chunks ADD COLUMN embedding_dimension SMALLINT UNSIGNED NULL AFTER embedding",
        "ALTER TABLE document_chunks ADD COLUMN embedding_model   VARCHAR(255) NOT NULL DEFAULT '' AFTER embedding_dimension",
        "ALTER TABLE document_chunks ADD COLUMN embedding_created_at TIMESTAMP(3) NULL AFTER embedding_model",
    ] as $emAlter) {
        try { $pdo->exec($emAlter); } catch (Throwable $_e) { /* column already exists */ }
    }

    // Embedding status on document_uploads.
    try {
        $pdo->exec("ALTER TABLE document_uploads ADD COLUMN embedding_status ENUM('pending','processing','done','error','skipped') NOT NULL DEFAULT 'pending' AFTER processed_at");
    } catch (Throwable $_e) { /* column already exists */ }

    // Embedding endpoints: one row per OpenAI-compatible embedding API server.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS embedding_endpoints (
            id         INT          NOT NULL AUTO_INCREMENT,
            alias      VARCHAR(120) NOT NULL DEFAULT '',
            base_url   VARCHAR(500) NOT NULL,
            model      VARCHAR(255) NOT NULL DEFAULT '',
            api_key    TEXT         NULL,
            timeout    INT          NOT NULL DEFAULT 60,
            is_active  TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order INT          NOT NULL DEFAULT 0,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Cache for query embeddings (optional, controlled by embedding_cache_enabled setting).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS embedding_cache (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            query_hash CHAR(64)        NOT NULL,
            model      VARCHAR(255)    NOT NULL DEFAULT '',
            embedding  MEDIUMTEXT      NOT NULL,
            created_at TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            UNIQUE KEY uq_ec_hash_model (query_hash, model),
            KEY idx_ec_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Embedding request log for monitoring.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS embedding_logs (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type          ENUM('chunk','query','rerank') NOT NULL DEFAULT 'query',
            model         VARCHAR(255)    NOT NULL DEFAULT '',
            duration_ms   INT UNSIGNED    NULL,
            similarity    FLOAT           NULL,
            cache_hit     TINYINT(1)      NOT NULL DEFAULT 0,
            status        ENUM('ok','error') NOT NULL DEFAULT 'ok',
            created_at    TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            KEY idx_el_type_created (type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversation_sessions (
            session_id           CHAR(64)     NOT NULL,
            user_id              INT          NULL,
            title                VARCHAR(200) NOT NULL DEFAULT '',
            model                VARCHAR(255) NOT NULL DEFAULT '',
            messages             MEDIUMTEXT   NOT NULL,
            upgrade_model        VARCHAR(255) NOT NULL DEFAULT '',
            upgrade_accepted_at  TIMESTAMP(3) NULL,
            updated_at           TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                                              ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (session_id),
            KEY idx_conv_updated (updated_at),
            KEY idx_conv_user    (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add user_id / title columns if this is an older database without them.
    foreach ([
        "ALTER TABLE conversation_sessions ADD COLUMN user_id INT NULL AFTER session_id",
        "ALTER TABLE conversation_sessions ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT '' AFTER user_id",
        "ALTER TABLE conversation_sessions ADD KEY idx_conv_user (user_id)",
        "ALTER TABLE conversation_sessions ADD COLUMN upgrade_model VARCHAR(255) NOT NULL DEFAULT '' AFTER model",
        "ALTER TABLE conversation_sessions ADD COLUMN upgrade_accepted_at TIMESTAMP(3) NULL AFTER upgrade_model",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (Throwable $_e) { /* already exists */ }
    }

    // Rule-based model routing: one row per category → model assignment.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS routing_rules (
            category VARCHAR(100) NOT NULL,
            model    VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Routing categories stored in the DB (replaces lib/prompt.txt).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS routing_categories (
            id                INT          NOT NULL AUTO_INCREMENT,
            name              VARCHAR(100) NOT NULL,
            definition        TEXT         NOT NULL,
            decision_rule     TEXT         NOT NULL,
            sort_order        INT          NOT NULL DEFAULT 0,
            decision_priority INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rc_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed routing_categories from lib/prompt.txt if the table is still empty.
    $rcCount = (int) $pdo->query('SELECT COUNT(*) FROM routing_categories')->fetchColumn();
    if ($rcCount === 0) {
        $seedCategories = [
            [
                'name'              => 'Programming',
                'definition'        => 'Code, software development, debugging, APIs, algorithms, scripting, databases, DevOps, implementation, or technical programming questions.',
                'decision_rule'     => 'Else if the primary task is programming, return Programming.',
                'sort_order'        => 1,
                'decision_priority' => 2,
            ],
            [
                'name'              => 'Math',
                'definition'        => 'Mathematical calculations, equations, proofs, logic, statistics, or numerical problem solving.',
                'decision_rule'     => 'Else if the primary task is solving a mathematical problem, return Math.',
                'sort_order'        => 2,
                'decision_priority' => 3,
            ],
            [
                'name'              => 'Research',
                'definition'        => 'Requests to find, verify, compare, summarize, or analyze factual information that would normally require external knowledge or multiple sources.',
                'decision_rule'     => 'Else if the primary task is gathering or verifying factual information, return Research.',
                'sort_order'        => 3,
                'decision_priority' => 4,
            ],
            [
                'name'              => 'ImageAnalysis',
                'definition'        => 'Requests that require analyzing or answering questions about an image, photo, screenshot, diagram, chart, or other visual content.',
                'decision_rule'     => 'If the request depends on an image or other visual input, return ImageAnalysis.',
                'sort_order'        => 4,
                'decision_priority' => 1,
            ],
            [
                'name'              => 'GeneralConversation',
                'definition'        => 'Any other request, including casual conversation, writing, translation, brainstorming, opinions, or general assistance.',
                'decision_rule'     => 'Otherwise, return GeneralConversation.',
                'sort_order'        => 5,
                'decision_priority' => 5,
            ],
        ];
        $rcStmt = $pdo->prepare(
            'INSERT IGNORE INTO routing_categories (name, definition, decision_rule, sort_order, decision_priority)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($seedCategories as $sc) {
            $rcStmt->execute([$sc['name'], $sc['definition'], $sc['decision_rule'], $sc['sort_order'], $sc['decision_priority']]);
        }
    }

    // Application event log.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_logs (
            id         BIGINT       NOT NULL AUTO_INCREMENT,
            level      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
            message    TEXT         NOT NULL,
            created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            KEY idx_app_logs_created (created_at),
            KEY idx_app_logs_level   (level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ── Prompt Security ───────────────────────────────────────────────────────

    // Configurable rule signatures for rule-based threat detection.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prompt_security_rules (
            id          INT          NOT NULL AUTO_INCREMENT,
            category    VARCHAR(60)  NOT NULL DEFAULT '',
            name        VARCHAR(120) NOT NULL DEFAULT '',
            pattern     VARCHAR(500) NOT NULL DEFAULT '',
            is_regex    TINYINT(1)   NOT NULL DEFAULT 0,
            severity    TINYINT UNSIGNED NOT NULL DEFAULT 50,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            description TEXT         NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_psr_category  (category),
            KEY idx_psr_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Security event log: one row per evaluated (or blocked) chat request.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prompt_security_logs (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      INT             NULL,
            session_id   VARCHAR(128)    NOT NULL DEFAULT '',
            ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
            input_text   MEDIUMTEXT      NULL,
            matched_rule INT             NULL,
            matched_cat  VARCHAR(60)     NOT NULL DEFAULT '',
            score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            decision     ENUM('allow','warn','block') NOT NULL DEFAULT 'allow',
            ai_model     VARCHAR(255)    NOT NULL DEFAULT '',
            created_at   TIMESTAMP(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            KEY idx_psl_user    (user_id),
            KEY idx_psl_created (created_at),
            KEY idx_psl_decision(decision)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed default security rules if the table is still empty.
    $psrCount = (int) $pdo->query('SELECT COUNT(*) FROM prompt_security_rules')->fetchColumn();
    if ($psrCount === 0) {
        $defaultRules = [
            // ── Prompt Injection ─────────────────────────────────────────────
            ['prompt_injection', 'Ignore previous instructions', 'ignore.{0,10}(all|previous|prior|above).{0,15}(instruction|prompt|rule|directive)', 1, 85, 'Classic prompt injection – instructing the model to discard its system prompt.'],
            ['prompt_injection', 'Forget instructions', 'forget.{0,15}(everything|all|above|instruction|prompt)', 1, 80, 'Attempts to make the model discard prior context.'],
            ['prompt_injection', 'From now on override', 'from now on.{0,30}(you are|act as|ignore|forget|override|pretend)', 1, 75, 'Instruction override pattern.'],
            ['prompt_injection', 'Override instructions', '(override|replace|disregard).{0,20}(your|all|previous|prior).{0,15}(instruction|prompt|rule)', 1, 75, 'Explicit instruction override attempt.'],
            ['prompt_injection', 'New system prompt', '(new|updated|revised).{0,10}system.{0,10}prompt', 1, 75, 'Attempts to inject a replacement system prompt.'],
            // ── Jailbreak ────────────────────────────────────────────────────
            ['jailbreak', 'DAN mode', '\bDAN\b', 1, 85, 'Do Anything Now jailbreak keyword.'],
            ['jailbreak', 'Developer Mode', 'developer.?mode', 1, 80, 'Developer Mode jailbreak pattern.'],
            ['jailbreak', 'Jailbreak keyword', 'jailbreak', 1, 80, 'Explicit jailbreak mention.'],
            ['jailbreak', 'No restrictions', '(without|no).{0,10}(restriction|limit|filter|rule|constraint)', 1, 70, 'Attempt to operate without safety constraints.'],
            ['jailbreak', 'You are no longer', 'you are no longer.{0,30}(a|an|the|bound|restricted)', 1, 75, 'Role-stripping jailbreak.'],
            ['jailbreak', 'Act as unrestricted AI', 'act as.{0,30}(unrestricted|unfiltered|unlimited|jailbroken|uncensored)', 1, 80, 'Requests the model act as an unconstrained AI.'],
            // ── Prompt Leakage ───────────────────────────────────────────────
            ['prompt_leakage', 'Show system prompt', '(show|print|display|output|reveal|dump|repeat).{0,20}(system|your|the|internal|hidden).{0,20}(prompt|instruction|rule|directive|context)', 1, 70, 'Attempts to extract the system prompt.'],
            ['prompt_leakage', 'What are your instructions', 'what.{0,10}(are|were).{0,10}(your|the).{0,10}(instruction|rule|directive|guideline)', 1, 65, 'Prompt leakage via question.'],
            ['prompt_leakage', 'Repeat context verbatim', '(repeat|recite|copy).{0,20}(everything|all|above|context|prompt).{0,20}(verbatim|word.?for.?word|exactly)', 1, 70, 'Verbatim context extraction.'],
            // ── Tool Abuse ───────────────────────────────────────────────────
            ['tool_abuse', 'Use all tools', 'use.{0,10}all.{0,10}(available|your|the).{0,10}tool', 1, 65, 'Attempts to invoke all tools indiscriminately.'],
            ['tool_abuse', 'Call internal API', 'call.{0,20}(internal|hidden|private|admin).{0,20}api', 1, 75, 'Attempts to call internal or admin APIs.'],
            ['tool_abuse', 'Ignore tool permissions', 'ignore.{0,15}(tool|permission|access|right)', 1, 70, 'Attempts to bypass tool permission checks.'],
            // ── RAG Attacks ──────────────────────────────────────────────────
            ['rag_attack', 'Export knowledge base', '(export|dump|list|show|print).{0,20}(all|entire|complete|whole).{0,20}(document|knowledge|chunk|embedding)', 1, 75, 'Attempts to dump the RAG knowledge base.'],
            ['rag_attack', 'Show all documents', '(show|list|display|give me).{0,15}all.{0,15}document', 1, 65, 'Requests enumeration of all documents.'],
            ['rag_attack', 'Export chunk contents', '(export|dump|output).{0,15}(chunk|embed)', 1, 70, 'Attempts to extract raw RAG chunks.'],
            // ── Role Switching ───────────────────────────────────────────────
            ['role_switch', 'You are now admin', 'you are now.{0,20}(admin|root|superuser|administrator|developer)', 1, 80, 'Attempts to grant the model elevated privileges.'],
            ['role_switch', 'Act as root', 'act as.{0,15}(root|admin|administrator|superuser)', 1, 80, 'Role-elevation jailbreak.'],
            ['role_switch', 'You are ChatGPT without restrictions', 'you are.{0,30}(chatgpt|gpt|openai).{0,30}(without|no).{0,15}(restriction|limit|filter)', 1, 75, 'Persona-swap to unconstrained model.'],
            ['role_switch', 'Handle as developer', '(handle|respond|answer|reply).{0,15}as.{0,15}(developer|root|admin|superuser)', 1, 70, 'Requests a privileged persona.'],
            // ── Data Exfiltration ────────────────────────────────────────────
            ['data_exfiltration', 'Show API keys', '(show|give|print|output|list).{0,15}(api.?key|password|secret|token|credential)', 1, 90, 'Attempts to extract secrets or credentials.'],
            ['data_exfiltration', 'Export all users', '(export|dump|list|show).{0,15}all.{0,15}user', 1, 80, 'Attempts to enumerate user data.'],
            ['data_exfiltration', 'Show database entries', '(show|dump|print|output|select).{0,20}(database|db|table|row|record|entry)', 1, 75, 'Attempts to read database contents.'],
            ['data_exfiltration', 'Read config files', '(read|show|cat|open).{0,15}(config|configuration|env|\.env|settings)', 1, 80, 'Attempts to read configuration files.'],
        ];
        $psrStmt = $pdo->prepare(
            'INSERT IGNORE INTO prompt_security_rules (category, name, pattern, is_regex, severity, is_active, description)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        foreach ($defaultRules as $r) {
            $psrStmt->execute($r);
        }
    }

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
 * Write a log entry to the app_logs table.
 *
 * The effective minimum log level is controlled by the setting 'log_level'
 * (info | warning | error; default: info).  Entries below the configured
 * level are silently dropped.  The call is always best-effort: any database
 * error is swallowed so that logging never interrupts the request flow.
 *
 * @param string $level   'info', 'warning', or 'error'
 * @param string $message Human-readable log message (max ~64 KB stored).
 */
function writeLog(string $level, string $message): void
{
    static $levelOrder = ['info' => 1, 'warning' => 2, 'error' => 3];

    $level = strtolower(trim($level));
    if (!isset($levelOrder[$level])) {
        $level = 'info';
    }

    try {
        $configuredLevel = strtolower(trim(getSetting('log_level', 'info')));
        if (!isset($levelOrder[$configuredLevel])) {
            $configuredLevel = 'info';
        }

        if ($levelOrder[$level] < $levelOrder[$configuredLevel]) {
            return; // Below configured threshold – do not store.
        }

        getDb()->prepare(
            'INSERT INTO app_logs (level, message) VALUES (?, ?)'
        )->execute([$level, $message]);

        // Opportunistically purge old entries (~1 % of requests).
        if (mt_rand(1, 100) === 1) {
            purgeOldLogs();
        }
    } catch (Throwable $e) {
        // Best-effort – never let logging break the request flow.
    }
}

/**
 * Delete app_log entries that are older than the configured retention period.
 * The retention period is read from the setting 'log_retention_days' (default 30).
 */
function purgeOldLogs(): void
{
    try {
        $days = max(1, (int) getSetting('log_retention_days', '30'));
        getDb()->prepare(
            'DELETE FROM app_logs WHERE created_at < NOW() - INTERVAL ? DAY'
        )->execute([$days]);
    } catch (Throwable $e) {
        // Best-effort
    }
}

/**
 * Load all routing categories from the database, ordered by sort_order.
 *
 * @return array<int,array{id:int,name:string,definition:string,decision_rule:string,sort_order:int,decision_priority:int}>
 */
function loadRoutingCategoriesFromDb(): array
{
    try {
        return getDb()->query(
            'SELECT id, name, definition, decision_rule, sort_order, decision_priority
               FROM routing_categories
              ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Return ordered list of category names from the database.
 * Falls back to parsing lib/prompt.txt for backward compatibility.
 *
 * @return string[] Ordered list of category names.
 */
function loadRoutingCategories(): array
{
    $rows = loadRoutingCategoriesFromDb();
    if (!empty($rows)) {
        return array_column($rows, 'name');
    }
    // Legacy fallback: read from file.
    $promptFile = __DIR__ . '/lib/prompt.txt';
    if (!is_file($promptFile)) {
        return [];
    }
    $lines      = file($promptFile, FILE_IGNORE_NEW_LINES);
    $found      = false;
    $categories = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!$found) {
            if (stripos($trimmed, 'Valid outputs only:') !== false) {
                $found = true;
            }
            continue;
        }
        if ($trimmed !== '') {
            $categories[] = $trimmed;
        }
    }
    return $categories;
}

/**
 * Build the full routing system-prompt text from the categories stored in the
 * database.  The output is layout-identical to lib/prompt.txt.
 * Falls back to reading that file if the database table is empty.
 */
function buildRoutingPrompt(): string
{
    $rows = loadRoutingCategoriesFromDb();
    if (empty($rows)) {
        $promptFile = __DIR__ . '/lib/prompt.txt';
        return is_file($promptFile) ? (string) file_get_contents($promptFile) : '';
    }

    // Categories listed in display order.
    $catNames = array_column($rows, 'name');

    // Definitions in display order.
    $defLines = [];
    foreach ($rows as $row) {
        $defLines[] = '* ' . $row['name'] . ': ' . $row['definition'];
    }

    // Decision rules sorted by priority (lowest number = highest priority).
    $ruleRows = $rows;
    usort($ruleRows, static fn($a, $b) => (int) $a['decision_priority'] <=> (int) $b['decision_priority']);
    $ruleLines = [];
    $num = 1;
    foreach ($ruleRows as $row) {
        if (trim($row['decision_rule']) !== '') {
            $ruleLines[] = $num . '. ' . trim($row['decision_rule']);
            $num++;
        }
    }

    $nl = "\n";
    $prompt  = 'You are a deterministic intent classifier.' . $nl;
    $prompt .= $nl;
    $prompt .= 'Classify the user\'s input into exactly one of these categories:' . $nl;
    $prompt .= $nl;
    $prompt .= implode($nl, $catNames) . $nl;
    $prompt .= $nl;
    $prompt .= 'Definitions:' . $nl;
    $prompt .= $nl;
    $prompt .= implode($nl, $defLines) . $nl;
    $prompt .= $nl;
    $prompt .= 'Decision rules (highest priority first):' . $nl;
    $prompt .= $nl;
    $prompt .= implode($nl, $ruleLines) . $nl;
    $prompt .= $nl;
    $prompt .= 'Output rules:' . $nl;
    $prompt .= $nl;
    $prompt .= '* Return exactly one category.' . $nl;
    $prompt .= '* Do not explain your decision.' . $nl;
    $prompt .= '* Do not output any additional text.' . $nl;
    $prompt .= '* Do not output punctuation, Markdown, quotes, or code fences.' . $nl;
    $prompt .= $nl;
    $prompt .= 'Valid outputs only:' . $nl;
    $prompt .= $nl;
    $prompt .= implode($nl, $catNames) . $nl;

    return $prompt;
}

/**
 * Save (upsert) a routing category.
 * If $id is 0 a new row is inserted; otherwise the existing row is updated.
 */
function saveRoutingCategory(
    int    $id,
    string $name,
    string $definition,
    string $decisionRule,
    int    $sortOrder,
    int    $decisionPriority
): void {
    $db = getDb();
    if ($id > 0) {
        $db->prepare(
            'UPDATE routing_categories
                SET name = ?, definition = ?, decision_rule = ?,
                    sort_order = ?, decision_priority = ?
              WHERE id = ?'
        )->execute([$name, $definition, $decisionRule, $sortOrder, $decisionPriority, $id]);
    } else {
        $db->prepare(
            'INSERT INTO routing_categories (name, definition, decision_rule, sort_order, decision_priority)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$name, $definition, $decisionRule, $sortOrder, $decisionPriority]);
    }
}

/**
 * Delete a routing category by ID.
 * Also removes any associated routing rule.
 */
function deleteRoutingCategory(int $id): void
{
    $db = getDb();
    // Get the name first so we can clean up the routing_rules table.
    $row = $db->prepare('SELECT name FROM routing_categories WHERE id = ?');
    $row->execute([$id]);
    $cat = $row->fetchColumn();
    $db->prepare('DELETE FROM routing_categories WHERE id = ?')->execute([$id]);
    if ($cat !== false && $cat !== '') {
        $db->prepare('DELETE FROM routing_rules WHERE category = ?')->execute([$cat]);
    }
}

/**
 * Load all routing rules (category → model) from the database.
 *
 * @return array<string,string> Map of category name to model string.
 */
function loadRoutingRules(): array
{
    try {
        $rows = getDb()->query(
            'SELECT category, model FROM routing_rules'
        )->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['category']] = (string) $row['model'];
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Upsert a routing rule (category → model).
 */
function saveRoutingRule(string $category, string $model): void
{
    getDb()->prepare(
        'INSERT INTO routing_rules (category, model)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE model = VALUES(model)'
    )->execute([$category, $model]);
}

/**
 * Save (upsert) a conversation session's message history.
 * The row's updated_at timestamp is refreshed on every save,
 * which resets the expiry window for anonymous sessions.
 *
 * When $userId is provided the session is linked to that user and the
 * title is derived from the first user message (set once, never overwritten).
 *
 * @param string   $sessionId Hex session token (8–128 chars).
 * @param string   $model     Model name used for this session.
 * @param array    $messages  Full messages array (role/content pairs).
 * @param int|null $userId    ID of the logged-in user, or null for anonymous.
 */
function saveConversationSession(string $sessionId, string $model, array $messages, ?int $userId = null): void
{
    if ($sessionId === '') {
        return;
    }

    // Derive a one-line title from the first user message (only used when
    // userId is set and no title has been stored yet).
    $title = '';
    if ($userId !== null) {
        foreach ($messages as $msg) {
            if (is_array($msg) && ($msg['role'] ?? '') === 'user') {
                $raw   = is_string($msg['content']) ? $msg['content'] : '';
                $title = mb_substr(trim($raw), 0, 80, 'UTF-8');
                if (mb_strlen($raw, 'UTF-8') > 80) {
                    $title .= '…';
                }
                break;
            }
        }
    }

    try {
        getDb()->prepare(
            'INSERT INTO conversation_sessions (session_id, user_id, title, model, messages, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE
                user_id    = COALESCE(user_id, VALUES(user_id)),
                title      = IF(title = \'\', VALUES(title), title),
                model      = VALUES(model),
                messages   = VALUES(messages),
                updated_at = NOW(3)'
        )->execute([
            $sessionId,
            $userId,
            $title,
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
 * Persist an accepted intelligence-upgrade model for a session.
 * The timestamp is set to NOW so that requests within the next 20 minutes
 * are automatically routed to the upgraded model.
 *
 * @param string $sessionId   Hex session token.
 * @param string $upgradeModel Model name that was accepted as the upgrade.
 */
function setSessionUpgradeModel(string $sessionId, string $upgradeModel): void
{
    if ($sessionId === '' || $upgradeModel === '') {
        return;
    }
    try {
        getDb()->prepare(
            'UPDATE conversation_sessions
                SET upgrade_model = ?, upgrade_accepted_at = NOW(3)
              WHERE session_id = ?'
        )->execute([$upgradeModel, $sessionId]);
    } catch (Throwable $e) {
        // Best-effort – do not break the chat flow on a save failure.
    }
}

/**
 * Return the upgrade model for a session if an upgrade was accepted within
 * the last 20 minutes, or null otherwise.
 *
 * @param string $sessionId Hex session token.
 * @return string|null The upgrade model name, or null when not active.
 */
function getActiveSessionUpgradeModel(string $sessionId): ?string
{
    if ($sessionId === '') {
        return null;
    }
    try {
        $stmt = getDb()->prepare(
            "SELECT upgrade_model
               FROM conversation_sessions
              WHERE session_id = ?
                AND upgrade_model <> ''
                AND upgrade_accepted_at >= DATE_SUB(NOW(3), INTERVAL 20 MINUTE)"
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $upgradeModel = (string) ($row['upgrade_model'] ?? '');
        return $upgradeModel !== '' ? $upgradeModel : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Delete anonymous conversation sessions that have been inactive for more
 * than 30 minutes. User-linked sessions are kept indefinitely.
 * Call this periodically (e.g., with a small probability on each request).
 */
function purgeExpiredConversationSessions(): void
{
    try {
        getDb()->exec(
            "DELETE FROM conversation_sessions
              WHERE user_id IS NULL
                AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
    } catch (Throwable $e) {
        // Best-effort
    }
}

/**
 * Return a list of conversation sessions belonging to a user.
 * Each row contains session_id, title, model and updated_at.
 * Results are ordered newest-first.
 *
 * @return array<int,array{session_id:string,title:string,model:string,updated_at:string}>
 */
function listUserConversations(int $userId): array
{
    try {
        $stmt = getDb()->prepare(
            'SELECT session_id, title, model, updated_at
               FROM conversation_sessions
              WHERE user_id = ?
              ORDER BY updated_at DESC
              LIMIT 200'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
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
