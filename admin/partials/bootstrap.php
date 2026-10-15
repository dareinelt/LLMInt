<?php

/**
 * admin/partials/bootstrap.php
 *
 * Shared bootstrap of the admin area: session, library includes, admin
 * authentication, the settings that are read before the request is handled
 * and the CSRF token. Included by every admin page - see
 * admin/partials/nav.php for the list of pages.
 *
 * The admin area used to be a single page (admin/index.php); the request
 * handling and the data loading were split into the two includes below and
 * the markup into one page per navigation entry.
 */

session_start();

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/balancer_engine.php';
require_once __DIR__ . '/../../lib/openai_api.php';
require_once __DIR__ . '/../../lib/speech_dictation.php';
require_once __DIR__ . '/../../lib/image_generation.php';
require_once __DIR__ . '/../../lib/topology.php';

requireAdminOrRedirect('login.php');

$db = getDb();
$searxngBaseUrl = trim(getSetting('searxng_base_url', ''));
$guestDefaultModel  = trim(getSetting('default_model', ''));
$newUserDefaultModel = trim(getSetting('new_user_default_model', ''));
$visionModel = trim(getSetting('vision_model', ''));
$pdfVisionEnabled  = getSetting('pdf_vision_enabled', '1') === '1';
$pdfVisionDpi      = max(72, min(300, (int) getSetting('pdf_vision_dpi', '150')));
$pdfVisionMaxPages = max(1, min(200, (int) getSetting('pdf_vision_max_pages', '30')));

// ── Speech recognition / dictation ───────────────────────────────────────────
// The effective URL is read through the library so the admin card always shows
// the value that is really in effect (environment variables win over the
// `speech_endpoints` table).
$speechEnabled        = getSetting('speech_dictation_enabled', '1') === '1';
$speechEndpointId     = speechDictationEndpointId();
$speechUrl            = speechDictationUrl();
$speechUrlSrc         = speechDictationUrlSource();
$speechTimeout        = speechDictationTimeout();
$speechLanguage       = speechDictationLanguage();
$speechBufferWords    = speechDictationBufferWords();
$speechStopTimeout    = speechDictationStopTimeoutSeconds();
$speechMaxSegment     = speechDictationMaxSegmentSeconds();
$speechMaxAudioMb     = (int) round(speechDictationMaxAudioBytes() / 1048576);
$speechPillCount      = count(speechDictationPills());
$speechCommandCount   = count(speechDictationCommands());
$speechEndpoints      = speechDictationEndpoints();
$speechStatus         = speechDictationDashboardStatus(false);
$editSpeechEp         = null;

// Populate $editSpeechEp if the URL requests editing a specific Speech endpoint.
if (isset($_GET['edit_speech']) && (int) $_GET['edit_speech'] > 0) {
    $editSpeechEp = speechDictationEndpoint((int) $_GET['edit_speech']);
}

// ── Image generation (ImageInt) ──────────────────────────────────────────────
// Same shape as the SpeechInt block above: the effective URL is read through
// the library so the card always shows the value that is really in effect
// (the `IMAGEINT_URL` environment variable wins over the `image_endpoints` table).
$imageEnabled         = imageIntEnabled();
$imageEndpointId      = imageIntEndpointId();
$imageUrl             = imageIntUrl();
$imageUrlSrc          = imageIntUrlSource();
$imageTimeout         = imageIntTimeout();
$imageSyncTimeout     = imageIntSyncTimeout();
$imageEndpoints       = imageIntEndpoints();
$imageStatus          = imageIntDashboardStatus(false);
$imageNotifyEnabled   = imageNotifyEnabled();
$imageConsentText     = getImageNotifyConsentText();
$imageMailSubject     = getImageNotifyEmailSubject();
$imageMailBody        = getImageNotifyEmailBody();
$imageTriggerText     = getImagePromptTriggerText();
$imageAntiTriggerText = getImagePromptAntiTriggerText();
$imageNotifyBaseUrl   = trim(getSetting('image_notify_base_url', ''));
$editImageEp          = null;

// Populate $editImageEp if the URL requests editing a specific Image endpoint.
if (isset($_GET['edit_image']) && (int) $_GET['edit_image'] > 0) {
    $editImageEp = imageIntEndpoint((int) $_GET['edit_image']);
}

$routingDecisionModel = trim(getSetting('routing_decision_model', ''));
$intelligenceUpgradeMessage = getSetting('intelligence_upgrade_message', '');
if ($intelligenceUpgradeMessage === '') {
    $intelligenceUpgradeMessage = 'Es stehen Ressourcen bereit um die Aufgabe erneut mit größerer Intelligenz zu bearbeiten. Dies kann länger dauern als zuvor, kann jedoch genauere Antworten liefern. Fortfahren?';
}
$globalSystemPrompt = getSetting('global_system_prompt', '');
if ($globalSystemPrompt === '') {
    $globalSystemPrompt = GLOBAL_SYSTEM_PROMPT_DEFAULT;
}
$loginBannerEnabled = getSetting('login_banner_enabled', '0') === '1';
$loginBannerText    = getSetting('login_banner_text', '');
$registrationEmailSubject = getRegistrationEmailSubject();
$registrationEmailBody    = getRegistrationEmailBody();
$streamingEnabled = getSetting('streaming_enabled', '1') === '1';
$intelligenceGroupEnabled = isIntelligenceGroupFeatureEnabled();
$balancerMaxConcurrent        = getBalancerMaxConcurrent();
$balancerCircuitFailThreshold = getBalancerCircuitFailThreshold();
$balancerCircuitCooldownSecs  = getBalancerCircuitCooldownSeconds();
$balancerOrphanTimeoutSecs    = getBalancerOrphanTimeoutSeconds();
$balancerFairnessWindowSecs   = getBalancerFairnessWindowSeconds();
$balancerBackoffBaseMs        = (int) getBalancerSetting('balancer_backoff_base_ms');
$balancerBackoffMaxMs         = (int) getBalancerSetting('balancer_backoff_max_ms');
$balancerBackoffJitter        = getBalancerSetting('balancer_backoff_jitter') === '1';
$balancerFallbackChainsJson   = getBalancerSetting('balancer_fallback_chains');
$routingCategories = loadRoutingCategories();
$routingCategoriesData = loadRoutingCategoriesFromDb();
$routingRules = loadRoutingRules();

// ── Logging settings ──────────────────────────────────────────────────────────
$logLevel         = getSetting('log_level', 'info');
$logRetentionDays = (int) getSetting('log_retention_days', '30');

// ── SMTP settings ─────────────────────────────────────────────────────────────
$smtpHost       = getSetting('smtp_host', '');
$smtpPort       = getSetting('smtp_port', '587');
$smtpEncryption = getSetting('smtp_encryption', 'tls');
$smtpAuth       = getSetting('smtp_auth', '1') === '1';
$smtpUser       = getSetting('smtp_user', '');
$smtpPass       = getSetting('smtp_pass', '');
$smtpFromEmail  = getSetting('smtp_from_email', '');
$smtpFromName   = getSetting('smtp_from_name', 'LLMInt');

// ── LDAP / Active Directory settings ─────────────────────────────────────────
$ldapEnabled         = getSetting('ldap_enabled',          '0') === '1';
$ldapHost            = getSetting('ldap_host',             '');
$ldapPort            = getSetting('ldap_port',             '389');
$ldapUseSsl          = getSetting('ldap_use_ssl',          '0') === '1';
$ldapDomain          = getSetting('ldap_domain',           '');
$ldapBaseDn          = getSetting('ldap_base_dn',          '');
$ldapBindDn          = getSetting('ldap_bind_dn',          '');
$ldapBindPassword    = getSetting('ldap_bind_password',    '');
$ldapUserAttr        = getSetting('ldap_user_attr',        'sAMAccountName');
$ldapEmailAttr       = getSetting('ldap_email_attr',       'mail');
$ldapDisplayNameAttr = getSetting('ldap_display_name_attr','displayName');
$ldapSspiEnabled     = getSetting('ldap_sspi_enabled',     '0') === '1';

// ── Hybrid-RAG / Embedding settings ──────────────────────────────────────────
require_once __DIR__ . '/../../api/embedding.php';
$embeddingEnabled      = getSetting('embedding_enabled',      '0') === '1';
$embeddingModel        = getSetting('embedding_model',        '');
$embeddingDimensions   = (int) getSetting('embedding_dimensions',   '0');
$embeddingTimeout      = (int) getSetting('embedding_timeout',      '60');
$embeddingCacheEnabled = getSetting('embedding_cache_enabled', '0') === '1';
$embeddingCacheTtlDays = (int) getSetting('embedding_cache_ttl_days', '7');
$hybridSearchEnabled   = getSetting('hybrid_search_enabled',  '0') === '1';
$bm25Weight            = getSetting('bm25_weight',            '0.5');
$embeddingWeight       = getSetting('embedding_weight',       '0.5');
$rerankerEnabled       = getSetting('reranker_enabled',       '0') === '1';
$rerankerEndpoint      = getSetting('reranker_endpoint',      '');
$rerankerModel         = getSetting('reranker_model',         '');
$rerankerTimeout       = (int) getSetting('reranker_timeout', '30');
$rerankerTopK          = (int) getSetting('reranker_top_k',   '5');

// ── Vector store (docvecwizard / Milvus) settings ────────────────────────────
require_once __DIR__ . '/../../api/vector_store.php';
$vectorStoreMode    = vectorStoreMode();
$vectorTopK         = vectorStoreTopK();
$vectorMinScore     = getSetting('vector_min_score', '0');
$docvecApiUrl       = getSetting('docvec_api_url', '');
$docvecApiUsername  = getSetting('docvec_api_username', '');
$docvecApiPassword  = getSetting('docvec_api_password', '');
$docvecApiTimeout   = (int) getSetting('docvec_api_timeout', '20');
$docvecApiVerifyTls = getSetting('docvec_api_verify_tls', '0') === '1';
$milvusUrlSetting   = getSetting('milvus_url', '');
$milvusMetricsSetting = getSetting('milvus_metrics_url', '');
$milvusToken        = getSetting('milvus_token', '');
$milvusTimeout      = (int) getSetting('milvus_timeout', '30');
$milvusCollection   = getSetting('milvus_collection', '');

// ── Generate CSRF token ───────────────────────────────────────────────────────

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Handle POST actions ───────────────────────────────────────────────────────

$flashOk    = '';
$flashError = '';
$editEp     = null; // endpoint being edited (populated by data.php from the ?edit=<id> deeplink)
$newApiKey  = '';   // plaintext of a freshly created API key (shown only once)
$apiKeysPostHandled = false;

require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/data.php';
