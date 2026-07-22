<?php

// LM Studio REST API base URL.
// Default: http://localhost:1234/v1  (LM Studio default port)
// Override via environment variable LMSTUDIO_BASE_URL.
define('LMSTUDIO_BASE_URL', rtrim(
    getenv('LMSTUDIO_BASE_URL') ?: 'http://localhost:1234/v1',
    '/'
));

// Request timeout in seconds for cURL calls to LM Studio.
define('LMSTUDIO_TIMEOUT', 120);
