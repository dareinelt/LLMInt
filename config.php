<?php

require_once __DIR__ . '/db.php';

// Derive LMSTUDIO_BASE_URL and LMSTUDIO_TIMEOUT from the first active endpoint.
// Falls back to legacy settings values so existing code that imports config.php
// continues to work without modification.
(static function (): void {
    try {
        $ep = getDb()->query(
            "SELECT base_url, timeout FROM endpoints WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1"
        )->fetch();
    } catch (PDOException $e) {
        $ep = null;
    }

    if ($ep) {
        define('LMSTUDIO_BASE_URL', rtrim($ep['base_url'], '/'));
        define('LMSTUDIO_TIMEOUT', max(1, (int) $ep['timeout']));
    } else {
        define('LMSTUDIO_BASE_URL', rtrim(
            getSetting('lmstudio_base_url', getenv('LMSTUDIO_BASE_URL') ?: 'http://localhost:1234/v1'),
            '/'
        ));
        define('LMSTUDIO_TIMEOUT', max(1, (int) getSetting('lmstudio_timeout', '120')));
    }
})();
