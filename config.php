<?php

require_once __DIR__ . '/db.php';

// LM Studio REST API base URL.
// Priority: MySQL settings table → LMSTUDIO_BASE_URL env var → built-in default.
define('LMSTUDIO_BASE_URL', rtrim(
    getSetting(
        'lmstudio_base_url',
        getenv('LMSTUDIO_BASE_URL') ?: 'http://localhost:1234/v1'
    ),
    '/'
));

// Request timeout in seconds for cURL calls to LM Studio.
// Priority: MySQL settings table → built-in default.
define('LMSTUDIO_TIMEOUT', max(1, (int) getSetting('lmstudio_timeout', '120')));
