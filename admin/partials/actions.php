<?php

/**
 * admin/partials/actions.php
 *
 * POST action dispatcher of the admin area. All pages share it: the branch
 * that matches the submitted "action" runs, every other branch is skipped.
 * Each form posts to the page it is rendered on, so a page only ever sees
 * its own actions.
 *
 * Sets $flashOk / $flashError, which the page shell renders.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $csrfToken) {
        $flashError = 'Ungültiger CSRF-Token. Bitte die Seite neu laden.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Add endpoint ──────────────────────────────────────────────────────
        if ($action === 'add_endpoint') {
            $newAlias            = trim($_POST['ep_alias'] ?? '');
            $newUrl              = trim($_POST['ep_base_url'] ?? '');
            $newTimeout          = (int) ($_POST['ep_timeout'] ?? 120);
            $newMaxContext       = max(0, min(10000000, (int) ($_POST['ep_max_context'] ?? 0)));
            $newContextLimitSlot = max(0, min(10000000, (int) ($_POST['ep_context_limit_per_slot'] ?? 0)));
            $newModel            = trim($_POST['ep_default_model'] ?? '');
            $isActive            = isset($_POST['ep_is_active']) ? 1 : 0;
            $sshHost             = trim($_POST['ep_ssh_host'] ?? '');
            $sshPort             = max(1, min(65535, (int) ($_POST['ep_ssh_port'] ?? 22)));
            $sshUser             = trim($_POST['ep_ssh_user'] ?? '');
            $sshPassword         = $_POST['ep_ssh_password'] ?? '';
            $specializedFor      = trim($_POST['ep_specialized_for_category'] ?? '');
            $supportsToolCalling = isset($_POST['ep_supports_tool_calling']) ? 1 : 0;
            $isLlamacpp          = isset($_POST['ep_is_llamacpp']) ? 1 : 0;
            $reasoningEffort     = strtolower(trim($_POST['ep_reasoning_effort'] ?? 'high'));
            if (!in_array($reasoningEffort, reasoningEffortOptions(), true)) {
                $reasoningEffort = 'high';
            }
            $supportsVision      = isset($_POST['ep_supports_vision']) ? 1 : 0;

            if (strlen($newAlias) > 120) {
                $flashError = 'Alias darf maximal 120 Zeichen lang sein.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } elseif ($newMaxContext > 0 && $newContextLimitSlot > $newMaxContext) {
                $flashError = 'Das Kontextlimit je Userslot darf den maximalen Endpunkt-Kontext nicht überschreiten.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO endpoints (alias, base_url, timeout, max_context, context_limit_per_slot,
                                            default_model, specialized_for_category,
                                            supports_tool_calling, is_llamacpp, reasoning_effort, supports_vision, is_active, sort_order,
                                            ssh_host, ssh_port, ssh_user, ssh_password)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newMaxContext, $newContextLimitSlot,
                            $newModel, $specializedFor,
                            $supportsToolCalling, $isLlamacpp, $reasoningEffort, $supportsVision, $isActive, $maxOrder + 1,
                            $sshHost, $sshPort, $sshUser, $sshPassword !== '' ? $sshPassword : null]);
                $endpointLabel = $newAlias !== '' ? $newAlias : rtrim($newUrl, '/');
                writeLog('info', 'Neuer Modellendpunkt erfolgreich registriert (' . $endpointLabel . ', Modell: ' . $newModel . ').');
                $flashOk = 'Endpunkt hinzugefügt.';
            }

        // ── Update endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'update_endpoint') {
            $epId                = (int) ($_POST['ep_id'] ?? 0);
            $newAlias            = trim($_POST['ep_alias'] ?? '');
            $newUrl              = trim($_POST['ep_base_url'] ?? '');
            $newTimeout          = (int) ($_POST['ep_timeout'] ?? 120);
            $newMaxContext       = max(0, min(10000000, (int) ($_POST['ep_max_context'] ?? 0)));
            $newContextLimitSlot = max(0, min(10000000, (int) ($_POST['ep_context_limit_per_slot'] ?? 0)));
            $newModel            = trim($_POST['ep_default_model'] ?? '');
            $isActive            = isset($_POST['ep_is_active']) ? 1 : 0;
            $sshHost             = trim($_POST['ep_ssh_host'] ?? '');
            $sshPort             = max(1, min(65535, (int) ($_POST['ep_ssh_port'] ?? 22)));
            $sshUser             = trim($_POST['ep_ssh_user'] ?? '');
            $sshPassword         = $_POST['ep_ssh_password'] ?? null;
            $specializedFor      = trim($_POST['ep_specialized_for_category'] ?? '');
            $supportsToolCalling = isset($_POST['ep_supports_tool_calling']) ? 1 : 0;
            $isLlamacpp          = isset($_POST['ep_is_llamacpp']) ? 1 : 0;
            $reasoningEffort     = strtolower(trim($_POST['ep_reasoning_effort'] ?? 'high'));
            if (!in_array($reasoningEffort, reasoningEffortOptions(), true)) {
                $reasoningEffort = 'high';
            }
            $supportsVision      = isset($_POST['ep_supports_vision']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } elseif (strlen($newAlias) > 120) {
                $flashError = 'Alias darf maximal 120 Zeichen lang sein.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($newUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($newTimeout < 1 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 1 und 600 Sekunden liegen.';
            } elseif ($newMaxContext > 0 && $newContextLimitSlot > $newMaxContext) {
                $flashError = 'Das Kontextlimit je Userslot darf den maximalen Endpunkt-Kontext nicht überschreiten.';
            } else {
                $previousEndpoint = $db->prepare('SELECT alias, base_url, is_active FROM endpoints WHERE id = ? LIMIT 1');
                $previousEndpoint->execute([$epId]);
                $previousEndpoint = $previousEndpoint->fetch();

                // If the password field was left blank, keep the existing value.
                if ($sshPassword === '' || $sshPassword === null) {
                    $db->prepare(
                        'UPDATE endpoints
                            SET alias = ?, base_url = ?, timeout = ?, max_context = ?, context_limit_per_slot = ?,
                                default_model = ?,
                                specialized_for_category = ?, supports_tool_calling = ?, is_llamacpp = ?, reasoning_effort = ?, supports_vision = ?, is_active = ?,
                                ssh_host = ?, ssh_port = ?, ssh_user = ?
                          WHERE id = ?'
                    )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newMaxContext, $newContextLimitSlot,
                                $newModel, $specializedFor,
                                $supportsToolCalling, $isLlamacpp, $reasoningEffort, $supportsVision, $isActive, $sshHost, $sshPort, $sshUser,
                                $epId]);
                } else {
                    $db->prepare(
                        'UPDATE endpoints
                            SET alias = ?, base_url = ?, timeout = ?, max_context = ?, context_limit_per_slot = ?,
                                default_model = ?,
                                specialized_for_category = ?, supports_tool_calling = ?, is_llamacpp = ?, reasoning_effort = ?, supports_vision = ?, is_active = ?,
                                ssh_host = ?, ssh_port = ?, ssh_user = ?, ssh_password = ?
                          WHERE id = ?'
                    )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $newMaxContext, $newContextLimitSlot,
                                $newModel, $specializedFor,
                                $supportsToolCalling, $isLlamacpp, $reasoningEffort, $supportsVision, $isActive, $sshHost, $sshPort, $sshUser, $sshPassword,
                                $epId]);
                }
                if (is_array($previousEndpoint) && (int) ($previousEndpoint['is_active'] ?? 0) === 1 && $isActive !== 1) {
                    $endpointLabel = trim((string) ($previousEndpoint['alias'] ?? ''));
                    if ($endpointLabel === '') {
                        $endpointLabel = trim((string) ($previousEndpoint['base_url'] ?? ''));
                    }
                    writeLog('warning', 'Modellendpunkt ' . $endpointLabel . ' nicht mehr verfügbar.');
                }
                $flashOk = 'Endpunkt gespeichert.';
            }

        // ── Delete endpoint ───────────────────────────────────────────────────
        } elseif ($action === 'delete_endpoint') {
            $epId = (int) ($_POST['ep_id'] ?? 0);
            if ($epId > 0) {
                $previousEndpoint = $db->prepare('SELECT alias, base_url FROM endpoints WHERE id = ? LIMIT 1');
                $previousEndpoint->execute([$epId]);
                $previousEndpoint = $previousEndpoint->fetch();
                $db->prepare('DELETE FROM endpoints WHERE id = ?')->execute([$epId]);
                if (is_array($previousEndpoint)) {
                    $endpointLabel = trim((string) ($previousEndpoint['alias'] ?? ''));
                    if ($endpointLabel === '') {
                        $endpointLabel = trim((string) ($previousEndpoint['base_url'] ?? ''));
                    }
                    writeLog('warning', 'Modellendpunkt ' . $endpointLabel . ' nicht mehr verfügbar.');
                }
                $flashOk = 'Endpunkt gelöscht.';
            }

        // ── Reset circuit breaker ─────────────────────────────────────────────
        } elseif ($action === 'reset_circuit') {
            $epId = (int) ($_POST['ep_id'] ?? 0);
            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } else {
                $epRow = $db->prepare('SELECT alias, base_url FROM endpoints WHERE id = ? LIMIT 1');
                $epRow->execute([$epId]);
                $epRow = $epRow->fetch();

                if (!is_array($epRow)) {
                    $flashError = 'Endpunkt nicht gefunden.';
                } else {
                    $endpointLabel = trim((string) ($epRow['alias'] ?? ''));
                    if ($endpointLabel === '') {
                        $endpointLabel = trim((string) ($epRow['base_url'] ?? ''));
                    }
                    if (resetEndpointCircuit('endpoints', $epId)) {
                        writeLog('info', 'Circuit-Breaker für Modellendpunkt ' . $endpointLabel . ' manuell zurückgesetzt.');
                        $flashOk = 'Circuit-Breaker zurückgesetzt.';
                    } else {
                        $flashError = 'Circuit-Breaker konnte nicht zurückgesetzt werden.';
                    }
                }
            }

        // ── Pause / resume endpoint ───────────────────────────────────────────
        } elseif ($action === 'toggle_endpoint_pause') {
            $epId   = (int) ($_POST['ep_id'] ?? 0);
            $paused = (int) ($_POST['pause'] ?? 0) === 1;
            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } else {
                $epRow = $db->prepare('SELECT alias, base_url FROM endpoints WHERE id = ? LIMIT 1');
                $epRow->execute([$epId]);
                $epRow = $epRow->fetch();

                if (!is_array($epRow)) {
                    $flashError = 'Endpunkt nicht gefunden.';
                } else {
                    $endpointLabel = trim((string) ($epRow['alias'] ?? ''));
                    if ($endpointLabel === '') {
                        $endpointLabel = trim((string) ($epRow['base_url'] ?? ''));
                    }
                    if (setEndpointPaused('endpoints', $epId, $paused)) {
                        if ($paused) {
                            writeLog('warning', 'Modellendpunkt ' . $endpointLabel . ' manuell pausiert.');
                            $flashOk = 'Endpunkt pausiert.';
                        } else {
                            writeLog('info', 'Modellendpunkt ' . $endpointLabel . ' manuell fortgesetzt.');
                            $flashOk = 'Endpunkt fortgesetzt.';
                        }
                    } else {
                        $flashError = $paused
                            ? 'Endpunkt konnte nicht pausiert werden.'
                            : 'Endpunkt konnte nicht fortgesetzt werden.';
                    }
                }
            }

        // ── Save search settings ──────────────────────────────────────────────
        } elseif ($action === 'save_search_settings') {
            $newSearxngUrl = trim($_POST['searxng_base_url'] ?? '');

            if ($newSearxngUrl !== '' && filter_var($newSearxngUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige SearXNG-URL eingeben oder das Feld leer lassen.';
            } else {
                $searxngBaseUrl = $newSearxngUrl === '' ? '' : rtrim($newSearxngUrl, '/');
                setSetting('searxng_base_url', $searxngBaseUrl);
                $flashOk = $searxngBaseUrl === ''
                    ? 'SearXNG-Suche deaktiviert.'
                    : 'SearXNG-URL gespeichert.';
            }

        // ── Save OpenAI API access control ────────────────────────────────────
        } elseif ($action === 'save_openai_api_settings') {
            setSetting('openai_api_allow_anonymous', isset($_POST['openai_api_allow_anonymous']) ? '1' : '0');
            setSetting('openai_tools_allow_anonymous', isset($_POST['openai_tools_allow_anonymous']) ? '1' : '0');
            $flashOk = 'Zugriffsschutz der OpenAI-API gespeichert.';

        // ── Save request-handling settings ───────────────────────────────────
        } elseif ($action === 'save_request_handling') {
            $newGuestDefaultModel = trim($_POST['guest_default_model'] ?? '');

            if ($newGuestDefaultModel !== '') {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM endpoints
                      WHERE is_active = 1
                        AND default_model = ?'
                );
                $stmt->execute([$newGuestDefaultModel]);
                $isKnownModelGroup = (int) $stmt->fetchColumn() > 0;

                if (!$isKnownModelGroup) {
                    $flashError = 'Bitte ein verfügbares Modell bzw. eine verfügbare Modellgruppe aus den aktiven Endpunkten auswählen.';
                }
            }

            if ($flashError === '') {
                $guestDefaultModel = $newGuestDefaultModel;
                setSetting('default_model', $guestDefaultModel);
                $flashOk = $guestDefaultModel === ''
                    ? 'Anfragenhandling gespeichert. Neue Anfragen nutzen wieder automatisch das erste aktive Modell.'
                    : 'Anfragenhandling gespeichert.';
            }

        // ── Save new-user default model ───────────────────────────────────────
        } elseif ($action === 'save_new_user_model') {
            $newModel = trim($_POST['new_user_default_model'] ?? '');
            $newUserDefaultModel = $newModel;
            setSetting('new_user_default_model', $newModel);
            $flashOk = 'Standard-Modell für neue Benutzer gespeichert.';

        // ── Save balancer / routing engine settings ───────────────────────────
        } elseif ($action === 'save_balancer_settings') {
            $newMaxConcurrent   = max(1, min(100, (int) ($_POST['balancer_max_concurrent'] ?? 4)));
            $newFailThreshold   = max(1, min(100, (int) ($_POST['balancer_circuit_fail_threshold'] ?? 3)));
            $newCooldownSecs    = max(1, min(3600, (int) ($_POST['balancer_circuit_cooldown_seconds'] ?? 30)));
            $newOrphanTimeout   = max(10, min(86400, (int) ($_POST['balancer_orphan_timeout_seconds'] ?? 300)));
            $newFairnessWindow  = max(60, min(86400, (int) ($_POST['balancer_fairness_window_seconds'] ?? 900)));
            $newBackoffBaseMs   = max(1, min(60000, (int) ($_POST['balancer_backoff_base_ms'] ?? 200)));
            $newBackoffMaxMs    = max($newBackoffBaseMs, min(600000, (int) ($_POST['balancer_backoff_max_ms'] ?? 8000)));
            $newBackoffJitter   = isset($_POST['balancer_backoff_jitter']);
            $newFallbackChains  = trim($_POST['balancer_fallback_chains'] ?? '{}');

            $decodedFallback = json_decode($newFallbackChains === '' ? '{}' : $newFallbackChains, true);
            if (!is_array($decodedFallback)) {
                $flashError = 'Ungültiges JSON-Format für die Fallback-Ketten.';
            } else {
                setSetting('balancer_max_concurrent', (string) $newMaxConcurrent);
                setSetting('balancer_circuit_fail_threshold', (string) $newFailThreshold);
                setSetting('balancer_circuit_cooldown_seconds', (string) $newCooldownSecs);
                setSetting('balancer_orphan_timeout_seconds', (string) $newOrphanTimeout);
                setSetting('balancer_fairness_window_seconds', (string) $newFairnessWindow);
                setSetting('balancer_backoff_base_ms', (string) $newBackoffBaseMs);
                setSetting('balancer_backoff_max_ms', (string) $newBackoffMaxMs);
                setSetting('balancer_backoff_jitter', $newBackoffJitter ? '1' : '0');
                saveFallbackChains($decodedFallback);

                $balancerMaxConcurrent        = $newMaxConcurrent;
                $balancerCircuitFailThreshold = $newFailThreshold;
                $balancerCircuitCooldownSecs  = $newCooldownSecs;
                $balancerOrphanTimeoutSecs    = $newOrphanTimeout;
                $balancerFairnessWindowSecs   = $newFairnessWindow;
                $balancerBackoffBaseMs        = $newBackoffBaseMs;
                $balancerBackoffMaxMs         = $newBackoffMaxMs;
                $balancerBackoffJitter        = $newBackoffJitter;
                $balancerFallbackChainsJson   = getBalancerSetting('balancer_fallback_chains');
                $flashOk = 'Balancer- und Routing-Einstellungen gespeichert.';
            }

        // ── Save streaming setting ────────────────────────────────────────────
        } elseif ($action === 'save_streaming_settings') {
            $streamingEnabled = isset($_POST['streaming_enabled']);
            setSetting('streaming_enabled', $streamingEnabled ? '1' : '0');
            $flashOk = $streamingEnabled
                ? 'Streaming aktiviert. Antworten werden live im Chat angezeigt.'
                : 'Streaming deaktiviert. Antworten erscheinen erst vollständig nach Fertigstellung.';

        // ── Save intelligence group setting ───────────────────────────────────
        } elseif ($action === 'save_intelligence_group_settings') {
            $intelligenceGroupEnabled = isset($_POST['intelligence_group_enabled']);
            setSetting('intelligence_group_enabled', $intelligenceGroupEnabled ? '1' : '0');
            $flashOk = $intelligenceGroupEnabled
                ? 'Direkte Modellwahl aktiviert. Benutzer können Intelligenzgruppen mit "@@" ansprechen.'
                : 'Direkte Modellwahl deaktiviert. Das "@@"-Präfix wird nicht mehr ausgewertet.';

        // ── Save global system prompt ─────────────────────────────────────────
        } elseif ($action === 'save_global_system_prompt') {
            $newGlobalSystemPrompt = trim($_POST['global_system_prompt'] ?? '');
            $globalSystemPrompt = $newGlobalSystemPrompt === '' ? GLOBAL_SYSTEM_PROMPT_DEFAULT : $newGlobalSystemPrompt;
            setSetting('global_system_prompt', $newGlobalSystemPrompt);
            $flashOk = 'Globaler Systemprompt gespeichert.';

        // ── Save system messages ──────────────────────────────────────────────
        } elseif ($action === 'save_system_messages') {
            $newIntelligenceUpgradeMessage = trim($_POST['intelligence_upgrade_message'] ?? '');
            $intelligenceUpgradeMessage = $newIntelligenceUpgradeMessage;
            setSetting('intelligence_upgrade_message', $newIntelligenceUpgradeMessage);
            $loginBannerEnabled = isset($_POST['login_banner_enabled']);
            $loginBannerText    = trim($_POST['login_banner_text'] ?? '');
            setSetting('login_banner_enabled', $loginBannerEnabled ? '1' : '0');
            setSetting('login_banner_text', $loginBannerText);
            $newRegistrationEmailSubject = trim($_POST['registration_email_subject'] ?? '');
            $newRegistrationEmailBody    = trim($_POST['registration_email_body'] ?? '');
            $registrationEmailSubject = $newRegistrationEmailSubject !== ''
                ? $newRegistrationEmailSubject : REGISTRATION_EMAIL_SUBJECT_DEFAULT;
            $registrationEmailBody = $newRegistrationEmailBody !== ''
                ? $newRegistrationEmailBody : REGISTRATION_EMAIL_BODY_DEFAULT;
            setSetting('registration_email_subject', $newRegistrationEmailSubject);
            setSetting('registration_email_body', $newRegistrationEmailBody);
            $flashOk = 'Systemmeldungen gespeichert.';

        // ── Save vision model ─────────────────────────────────────────────────
        } elseif ($action === 'save_vision_settings') {
            $newVisionModel = trim($_POST['vision_model'] ?? '');
            $visionModel = $newVisionModel;
            setSetting('vision_model', $newVisionModel);

            $pdfVisionEnabled  = isset($_POST['pdf_vision_enabled']) ? '1' : '0';
            $pdfVisionDpi      = max(72, min(300, (int) ($_POST['pdf_vision_dpi'] ?? 150)));
            $pdfVisionMaxPages = max(1, min(200, (int) ($_POST['pdf_vision_max_pages'] ?? 30)));
            setSetting('pdf_vision_enabled', $pdfVisionEnabled);
            setSetting('pdf_vision_dpi', (string) $pdfVisionDpi);
            setSetting('pdf_vision_max_pages', (string) $pdfVisionMaxPages);

            $flashOk = $newVisionModel === ''
                ? 'Vision-Modell zurückgesetzt (Dokument-Upload deaktiviert).'
                : 'Vision-Modell gespeichert.';

        // ── Save speech recognition / dictation settings ──────────────────────
        } elseif ($action === 'save_speech_dictation_settings') {
            $newEnabled      = isset($_POST['speech_dictation_enabled']) ? '1' : '0';
            $newEndpointId   = (int) ($_POST['speech_dictation_endpoint_id'] ?? 0);
            $newLanguage     = trim($_POST['speech_dictation_language'] ?? '');
            $newBufferWords  = (int) ($_POST['speech_dictation_buffer_words'] ?? 4);
            $newStopTimeout  = (int) ($_POST['speech_dictation_stop_timeout_seconds'] ?? 3);
            $newMaxSegment   = (int) ($_POST['speech_dictation_max_segment_seconds'] ?? 15);
            $newMaxAudioMb   = (int) ($_POST['speech_dictation_max_audio_mb'] ?? 10);

            if ($newEndpointId < 0) {
                $flashError = 'Ungültiger Speech-Endpunkt ausgewählt.';
            } elseif ($newEndpointId > 0 && speechDictationEndpoint($newEndpointId) === null) {
                $flashError = 'Der ausgewählte Speech-Endpunkt existiert nicht mehr.';
            } elseif ($newBufferWords < 1 || $newBufferWords > 50) {
                $flashError = 'Die Anzahl gepufferter Wörter muss zwischen 1 und 50 liegen.';
            } elseif ($newStopTimeout < 1 || $newStopTimeout > 60) {
                $flashError = 'Die Wartezeit bis „Erkennung beenden" muss zwischen 1 und 60 Sekunden liegen.';
            } elseif ($newMaxSegment < 3 || $newMaxSegment > 120) {
                $flashError = 'Die maximale Segmentdauer muss zwischen 3 und 120 Sekunden liegen.';
            } elseif ($newMaxAudioMb < 1 || $newMaxAudioMb > 100) {
                $flashError = 'Die maximale Audiodateigröße muss zwischen 1 und 100 MB liegen.';
            } else {
                setSetting('speech_dictation_enabled', $newEnabled);
                setSetting('speech_dictation_endpoint_id', (string) $newEndpointId);
                setSetting('speech_dictation_language', $newLanguage === '' ? 'de' : $newLanguage);
                setSetting('speech_dictation_buffer_words', (string) $newBufferWords);
                setSetting('speech_dictation_stop_timeout_seconds', (string) $newStopTimeout);
                setSetting('speech_dictation_max_segment_seconds', (string) $newMaxSegment);
                setSetting('speech_dictation_max_audio_mb', (string) $newMaxAudioMb);

                // Re-read through the accessors so the form shows the effective
                // values (an environment variable may still win).
                $speechEnabled      = getSetting('speech_dictation_enabled', '1') === '1';
                $speechEndpointId   = speechDictationEndpointId();
                $speechUrl          = speechDictationUrl();
                $speechUrlSrc       = speechDictationUrlSource();
                $speechTimeout      = speechDictationTimeout();
                $speechLanguage     = speechDictationLanguage();
                $speechBufferWords  = speechDictationBufferWords();
                $speechStopTimeout  = speechDictationStopTimeoutSeconds();
                $speechMaxSegment   = speechDictationMaxSegmentSeconds();
                $speechMaxAudioMb   = (int) round(speechDictationMaxAudioBytes() / 1048576);

                $flashOk = 'Spracherkennungs-Einstellungen gespeichert.';
            }

        // ── Save SMTP settings ────────────────────────────────────────────────
        } elseif ($action === 'save_smtp_settings') {
            $newSmtpHost       = trim($_POST['smtp_host']        ?? '');
            $newSmtpPort       = (int) ($_POST['smtp_port']      ?? 587);
            $newSmtpEncryption = trim($_POST['smtp_encryption']  ?? 'tls');
            $newSmtpAuth       = isset($_POST['smtp_auth']) ? '1' : '0';
            $newSmtpUser       = trim($_POST['smtp_user']        ?? '');
            $newSmtpPass       = $_POST['smtp_pass']             ?? '';
            $newSmtpFromEmail  = trim($_POST['smtp_from_email']  ?? '');
            $newSmtpFromName   = trim($_POST['smtp_from_name']   ?? 'LLMInt');

            if ($newSmtpHost !== '' && !in_array($newSmtpEncryption, ['none', 'tls', 'ssl'], true)) {
                $flashError = 'Ungültige Verschlüsselungsoption.';
            } elseif ($newSmtpHost !== '' && ($newSmtpPort < 1 || $newSmtpPort > 65535)) {
                $flashError = 'Ungültiger SMTP-Port (1–65535).';
            } elseif ($newSmtpFromEmail !== '' && !filter_var($newSmtpFromEmail, FILTER_VALIDATE_EMAIL)) {
                $flashError = 'Ungültige Absender-E-Mail-Adresse.';
            } else {
                setSetting('smtp_host',       $newSmtpHost);
                setSetting('smtp_port',       (string) $newSmtpPort);
                setSetting('smtp_encryption', $newSmtpEncryption);
                setSetting('smtp_auth',       $newSmtpAuth);
                setSetting('smtp_user',       $newSmtpUser);
                // Only overwrite the password if the field was not left blank
                if ($newSmtpPass !== '') {
                    setSetting('smtp_pass', $newSmtpPass);
                }
                setSetting('smtp_from_email', $newSmtpFromEmail);
                setSetting('smtp_from_name',  $newSmtpFromName);

                $smtpHost       = $newSmtpHost;
                $smtpPort       = (string) $newSmtpPort;
                $smtpEncryption = $newSmtpEncryption;
                $smtpAuth       = $newSmtpAuth === '1';
                $smtpUser       = $newSmtpUser;
                if ($newSmtpPass !== '') { $smtpPass = $newSmtpPass; }
                $smtpFromEmail  = $newSmtpFromEmail;
                $smtpFromName   = $newSmtpFromName;

                $flashOk = 'SMTP-Einstellungen gespeichert.';
            }

        // ── Save LDAP / AD settings ───────────────────────────────────────────
        } elseif ($action === 'save_ldap_settings') {
            $newLdapEnabled         = isset($_POST['ldap_enabled'])      ? '1' : '0';
            $newLdapHost            = trim($_POST['ldap_host']           ?? '');
            $newLdapPort            = max(1, min(65535, (int) ($_POST['ldap_port'] ?? 389)));
            $newLdapUseSsl          = isset($_POST['ldap_use_ssl'])      ? '1' : '0';
            $newLdapDomain          = trim($_POST['ldap_domain']         ?? '');
            $newLdapBaseDn          = trim($_POST['ldap_base_dn']        ?? '');
            $newLdapBindDn          = trim($_POST['ldap_bind_dn']        ?? '');
            $newLdapBindPassword    = $_POST['ldap_bind_password']       ?? '';
            $newLdapUserAttr        = trim($_POST['ldap_user_attr']      ?? 'sAMAccountName');
            $newLdapEmailAttr       = trim($_POST['ldap_email_attr']     ?? 'mail');
            $newLdapDisplayNameAttr = trim($_POST['ldap_display_name_attr'] ?? 'displayName');
            $newLdapSspiEnabled     = isset($_POST['ldap_sspi_enabled']) ? '1' : '0';

            if ($newLdapEnabled === '1' && $newLdapHost === '') {
                $flashError = 'LDAP-Host darf nicht leer sein, wenn LDAP aktiviert ist.';
            } else {
                setSetting('ldap_enabled',          $newLdapEnabled);
                setSetting('ldap_host',             $newLdapHost);
                setSetting('ldap_port',             (string) $newLdapPort);
                setSetting('ldap_use_ssl',          $newLdapUseSsl);
                setSetting('ldap_domain',           $newLdapDomain);
                setSetting('ldap_base_dn',          $newLdapBaseDn);
                setSetting('ldap_bind_dn',          $newLdapBindDn);
                setSetting('ldap_user_attr',        $newLdapUserAttr ?: 'sAMAccountName');
                setSetting('ldap_email_attr',       $newLdapEmailAttr ?: 'mail');
                setSetting('ldap_display_name_attr',$newLdapDisplayNameAttr ?: 'displayName');
                setSetting('ldap_sspi_enabled',     $newLdapSspiEnabled);
                // Only overwrite password if the field was not left blank
                if ($newLdapBindPassword !== '') {
                    setSetting('ldap_bind_password', $newLdapBindPassword);
                }

                $ldapEnabled         = $newLdapEnabled === '1';
                $ldapHost            = $newLdapHost;
                $ldapPort            = (string) $newLdapPort;
                $ldapUseSsl          = $newLdapUseSsl === '1';
                $ldapDomain          = $newLdapDomain;
                $ldapBaseDn          = $newLdapBaseDn;
                $ldapBindDn          = $newLdapBindDn;
                $ldapUserAttr        = $newLdapUserAttr ?: 'sAMAccountName';
                $ldapEmailAttr       = $newLdapEmailAttr ?: 'mail';
                $ldapDisplayNameAttr = $newLdapDisplayNameAttr ?: 'displayName';
                $ldapSspiEnabled     = $newLdapSspiEnabled === '1';
                if ($newLdapBindPassword !== '') { $ldapBindPassword = $newLdapBindPassword; }

                $flashOk = $ldapEnabled
                    ? 'Active-Directory-Einstellungen gespeichert.'
                    : 'Active-Directory-Integration deaktiviert.';
            }

        // ── Add SpeechInt endpoint ────────────────────────────────────────────
        } elseif ($action === 'add_speech_endpoint') {
            $newAlias   = trim($_POST['speech_ep_alias'] ?? '');
            $newUrl     = trim($_POST['speech_ep_base_url'] ?? '');
            $newToken   = trim($_POST['speech_ep_token'] ?? '');
            $newTimeout = (int) ($_POST['speech_ep_timeout'] ?? 120);
            $isActive   = isset($_POST['speech_ep_is_active']) ? 1 : 0;

            if ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (!preg_match('#^https?://[A-Za-z0-9._\-]+(:\d+)?(/[^\s]*)?$#', $newUrl)) {
                $flashError = 'Bitte eine gültige http(s)-URL eingeben.';
            } elseif ($newTimeout < 10 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 10 und 600 Sekunden liegen.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM speech_endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO speech_endpoints (alias, base_url, token, timeout, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newToken, $newTimeout, $isActive, $maxOrder + 1]);
                $flashOk = 'Speech-Endpunkt hinzugefügt.';
            }

        // ── Update SpeechInt endpoint ─────────────────────────────────────────
        } elseif ($action === 'update_speech_endpoint') {
            $epId       = (int) ($_POST['speech_ep_id'] ?? 0);
            $newAlias   = trim($_POST['speech_ep_alias'] ?? '');
            $newUrl     = trim($_POST['speech_ep_base_url'] ?? '');
            $newToken   = trim($_POST['speech_ep_token'] ?? '');
            $clearToken = isset($_POST['speech_ep_clear_token']);
            $newTimeout = (int) ($_POST['speech_ep_timeout'] ?? 120);
            $isActive   = isset($_POST['speech_ep_is_active']) ? 1 : 0;

            if ($epId <= 0 || speechDictationEndpoint($epId) === null) {
                $flashError = 'Ungültiger Speech-Endpunkt.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (!preg_match('#^https?://[A-Za-z0-9._\-]+(:\d+)?(/[^\s]*)?$#', $newUrl)) {
                $flashError = 'Bitte eine gültige http(s)-URL eingeben.';
            } elseif ($newTimeout < 10 || $newTimeout > 600) {
                $flashError = 'Timeout muss zwischen 10 und 600 Sekunden liegen.';
            } else {
                $db->prepare(
                    'UPDATE speech_endpoints
                        SET alias = ?, base_url = ?, timeout = ?, is_active = ?
                      WHERE id = ?'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $isActive, $epId]);

                // The token is only ever displayed masked, so an empty field
                // means "keep the stored secret" unless it is cleared explicitly.
                if ($clearToken) {
                    $db->prepare('UPDATE speech_endpoints SET token = NULL WHERE id = ?')->execute([$epId]);
                } elseif ($newToken !== '') {
                    $db->prepare('UPDATE speech_endpoints SET token = ? WHERE id = ?')
                       ->execute([$newToken, $epId]);
                }
                $flashOk = 'Speech-Endpunkt gespeichert.';
            }

        // ── Delete SpeechInt endpoint ─────────────────────────────────────────
        } elseif ($action === 'delete_speech_endpoint') {
            $epId = (int) ($_POST['speech_ep_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM speech_endpoints WHERE id = ?')->execute([$epId]);
                if ((int) getSetting('speech_dictation_endpoint_id', '0') === $epId) {
                    setSetting('speech_dictation_endpoint_id', '0');
                }
                $flashOk = 'Speech-Endpunkt gelöscht.';
            }

        // ── Move SpeechInt endpoint up / down ─────────────────────────────────
        } elseif ($action === 'move_speech_endpoint') {
            $epId      = (int) ($_POST['speech_ep_id'] ?? 0);
            $direction = (string) ($_POST['speech_ep_direction'] ?? '');
            $endpoints = speechDictationEndpoints();

            $index = null;
            foreach ($endpoints as $position => $endpoint) {
                if ((int) $endpoint['id'] === $epId) {
                    $index = $position;
                    break;
                }
            }

            $swapWith = $direction === 'up' ? ($index === null ? null : $index - 1)
                                            : ($index === null ? null : $index + 1);

            if ($index === null || $swapWith === null || !isset($endpoints[$swapWith])) {
                $flashError = 'Der Endpunkt kann nicht weiter verschoben werden.';
            } else {
                // Rewrite the whole order so gaps or duplicate values in
                // sort_order are cleaned up along the way.
                $order = array_column($endpoints, 'id');
                [$order[$index], $order[$swapWith]] = [$order[$swapWith], $order[$index]];
                $stmt = $db->prepare('UPDATE speech_endpoints SET sort_order = ? WHERE id = ?');
                foreach ($order as $position => $id) {
                    $stmt->execute([$position, (int) $id]);
                }
                $flashOk = 'Reihenfolge der Speech-Endpunkte gespeichert.';
            }

        // ── Save image generation / notification settings ─────────────────────
        } elseif ($action === 'save_image_generation_settings') {
            $newEnabled      = isset($_POST['image_generation_enabled']) ? '1' : '0';
            $newEndpointId   = (int) ($_POST['image_endpoint_id'] ?? 0);
            $newNotify       = isset($_POST['image_notify_enabled']) ? '1' : '0';
            $newConsent      = trim($_POST['image_notify_consent_text'] ?? '');
            $newSubject      = trim($_POST['image_notify_email_subject'] ?? '');
            $newBody         = (string) ($_POST['image_notify_email_body'] ?? '');
            $newTrigger      = trim($_POST['image_prompt_trigger_text'] ?? '');
            $newAntiTrigger  = trim($_POST['image_prompt_anti_trigger_text'] ?? '');
            $newBaseUrl      = trim($_POST['image_notify_base_url'] ?? '');

            if ($newEndpointId < 0) {
                $flashError = 'Ungültiger Image-Endpunkt ausgewählt.';
            } elseif ($newEndpointId > 0 && imageIntEndpoint($newEndpointId) === null) {
                $flashError = 'Der ausgewählte Image-Endpunkt existiert nicht mehr.';
            } elseif ($newBaseUrl !== '' && filter_var($newBaseUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Die Basis-URL für die Links muss eine gültige URL sein (z. B. https://chat.example.org).';
            } elseif ($newNotify === '1' && $newSubject === '') {
                $flashError = 'Der Betreff der Benachrichtigungsmail darf nicht leer sein.';
            } else {
                setSetting('image_generation_enabled', $newEnabled);
                setSetting('image_endpoint_id', (string) $newEndpointId);
                setSetting('image_notify_enabled', $newNotify);
                setSetting('image_notify_consent_text', $newConsent === '' ? IMAGE_NOTIFY_CONSENT_DEFAULT : $newConsent);
                setSetting('image_notify_email_subject', $newSubject === '' ? IMAGE_NOTIFY_EMAIL_SUBJECT_DEFAULT : $newSubject);
                setSetting('image_notify_email_body', trim($newBody) === '' ? IMAGE_NOTIFY_EMAIL_BODY_DEFAULT : $newBody);
                setSetting('image_prompt_trigger_text', $newTrigger === '' ? IMAGE_PROMPT_TRIGGER_DEFAULT : $newTrigger);
                setSetting('image_prompt_anti_trigger_text', $newAntiTrigger === '' ? IMAGE_PROMPT_ANTI_TRIGGER_DEFAULT : $newAntiTrigger);
                setSetting('image_notify_base_url', rtrim($newBaseUrl, '/'));

                // Re-read through the accessors so the form shows the effective
                // values (an environment variable may still win).
                $imageEnabled         = imageIntEnabled();
                $imageEndpointId      = imageIntEndpointId();
                $imageUrl             = imageIntUrl();
                $imageUrlSrc          = imageIntUrlSource();
                $imageTimeout         = imageIntTimeout();
                $imageNotifyEnabled   = imageNotifyEnabled();
                $imageConsentText     = getImageNotifyConsentText();
                $imageMailSubject     = getImageNotifyEmailSubject();
                $imageMailBody        = getImageNotifyEmailBody();
                $imageTriggerText     = getImagePromptTriggerText();
                $imageAntiTriggerText = getImagePromptAntiTriggerText();
                $imageNotifyBaseUrl   = trim(getSetting('image_notify_base_url', ''));

                $flashOk = 'Einstellungen der Bildgenerierung gespeichert.';
            }

        // ── Add ImageInt endpoint ─────────────────────────────────────────────
        } elseif ($action === 'add_image_endpoint') {
            $newAlias   = trim($_POST['image_ep_alias'] ?? '');
            $newUrl     = trim($_POST['image_ep_base_url'] ?? '');
            $newToken   = trim($_POST['image_ep_token'] ?? '');
            $newTimeout = (int) ($_POST['image_ep_timeout'] ?? 1800);
            $isActive   = isset($_POST['image_ep_is_active']) ? 1 : 0;

            if ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (!preg_match('#^https?://[A-Za-z0-9._\-]+(:\d+)?(/[^\s]*)?$#', $newUrl)) {
                $flashError = 'Bitte eine gültige http(s)-URL eingeben.';
            } elseif ($newTimeout < 10 || $newTimeout > 86400) {
                $flashError = 'Timeout muss zwischen 10 und 86400 Sekunden liegen.';
            } else {
                $maxOrder = (int) $db->query(
                    'SELECT COALESCE(MAX(sort_order), -1) FROM image_endpoints'
                )->fetchColumn();
                $db->prepare(
                    'INSERT INTO image_endpoints (alias, base_url, token, timeout, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newToken, $newTimeout, $isActive, $maxOrder + 1]);
                $flashOk = 'Image-Endpunkt hinzugefügt.';
            }

        // ── Update ImageInt endpoint ──────────────────────────────────────────
        } elseif ($action === 'update_image_endpoint') {
            $epId       = (int) ($_POST['image_ep_id'] ?? 0);
            $newAlias   = trim($_POST['image_ep_alias'] ?? '');
            $newUrl     = trim($_POST['image_ep_base_url'] ?? '');
            $newToken   = trim($_POST['image_ep_token'] ?? '');
            $clearToken = isset($_POST['image_ep_clear_token']);
            $newTimeout = (int) ($_POST['image_ep_timeout'] ?? 1800);
            $isActive   = isset($_POST['image_ep_is_active']) ? 1 : 0;

            if ($epId <= 0 || imageIntEndpoint($epId) === null) {
                $flashError = 'Ungültiger Image-Endpunkt.';
            } elseif ($newUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (!preg_match('#^https?://[A-Za-z0-9._\-]+(:\d+)?(/[^\s]*)?$#', $newUrl)) {
                $flashError = 'Bitte eine gültige http(s)-URL eingeben.';
            } elseif ($newTimeout < 10 || $newTimeout > 86400) {
                $flashError = 'Timeout muss zwischen 10 und 86400 Sekunden liegen.';
            } else {
                $db->prepare(
                    'UPDATE image_endpoints
                        SET alias = ?, base_url = ?, timeout = ?, is_active = ?
                      WHERE id = ?'
                )->execute([$newAlias, rtrim($newUrl, '/'), $newTimeout, $isActive, $epId]);

                // The token is only ever displayed masked, so an empty field
                // means "keep the stored secret" unless it is cleared explicitly.
                if ($clearToken) {
                    $db->prepare('UPDATE image_endpoints SET token = NULL WHERE id = ?')->execute([$epId]);
                } elseif ($newToken !== '') {
                    $db->prepare('UPDATE image_endpoints SET token = ? WHERE id = ?')
                       ->execute([$newToken, $epId]);
                }
                $flashOk = 'Image-Endpunkt gespeichert.';
            }

        // ── Delete ImageInt endpoint ──────────────────────────────────────────
        } elseif ($action === 'delete_image_endpoint') {
            $epId = (int) ($_POST['image_ep_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM image_endpoints WHERE id = ?')->execute([$epId]);
                if ((int) getSetting('image_endpoint_id', '0') === $epId) {
                    setSetting('image_endpoint_id', '0');
                }
                $flashOk = 'Image-Endpunkt gelöscht.';
            }

        // ── Move ImageInt endpoint up / down ──────────────────────────────────
        } elseif ($action === 'move_image_endpoint') {
            $epId      = (int) ($_POST['image_ep_id'] ?? 0);
            $direction = (string) ($_POST['image_ep_direction'] ?? '');
            $endpoints = imageIntEndpoints();

            $index = null;
            foreach ($endpoints as $position => $endpoint) {
                if ((int) $endpoint['id'] === $epId) {
                    $index = $position;
                    break;
                }
            }

            $swapWith = $direction === 'up' ? ($index === null ? null : $index - 1)
                                            : ($index === null ? null : $index + 1);

            if ($index === null || $swapWith === null || !isset($endpoints[$swapWith])) {
                $flashError = 'Der Endpunkt kann nicht weiter verschoben werden.';
            } else {
                // Rewrite the whole order so gaps or duplicate values in
                // sort_order are cleaned up along the way.
                $order = array_column($endpoints, 'id');
                [$order[$index], $order[$swapWith]] = [$order[$swapWith], $order[$index]];
                $stmt = $db->prepare('UPDATE image_endpoints SET sort_order = ? WHERE id = ?');
                foreach ($order as $position => $id) {
                    $stmt->execute([$position, (int) $id]);
                }
                $flashOk = 'Reihenfolge der Image-Endpunkte gespeichert.';
            }

        // ── Save routing settings ─────────────────────────────────────────────
        } elseif ($action === 'save_routing_settings') {
            $newDecisionModel = trim($_POST['routing_decision_model'] ?? '');
            setSetting('routing_decision_model', $newDecisionModel);

            // Save per-category model assignments.
            $categories = loadRoutingCategories();
            foreach ($categories as $cat) {
                $fieldName = 'routing_model_' . preg_replace('/[^a-zA-Z0-9]/', '_', $cat);
                $catModel  = trim($_POST[$fieldName] ?? '');
                saveRoutingRule($cat, $catModel);
            }
            $flashOk = 'Modellrouting gespeichert.';

        // ── Add routing category ──────────────────────────────────────────────
        } elseif ($action === 'add_routing_category') {
            $catName      = trim($_POST['rc_name']              ?? '');
            $catDef       = trim($_POST['rc_definition']        ?? '');
            $catRule      = trim($_POST['rc_decision_rule']     ?? '');
            $catSort      = (int) ($_POST['rc_sort_order']      ?? 0);
            $catPriority  = (int) ($_POST['rc_decision_priority'] ?? 0);

            if ($catName === '') {
                $flashError = 'Kategoriename darf nicht leer sein.';
            } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $catName)) {
                $flashError = 'Kategoriename darf nur Buchstaben, Ziffern und Unterstriche enthalten.';
            } else {
                try {
                    saveRoutingCategory(0, $catName, $catDef, $catRule, $catSort, $catPriority);
                    $flashOk = 'Kategorie "' . htmlspecialchars($catName) . '" hinzugefügt.';
                } catch (Throwable $e) {
                    $flashError = 'Kategorie konnte nicht gespeichert werden (Name bereits vorhanden?).';
                }
            }

        // ── Update routing category ───────────────────────────────────────────
        } elseif ($action === 'update_routing_category') {
            $catId        = (int) ($_POST['rc_id']              ?? 0);
            $catName      = trim($_POST['rc_name']              ?? '');
            $catDef       = trim($_POST['rc_definition']        ?? '');
            $catRule      = trim($_POST['rc_decision_rule']     ?? '');
            $catSort      = (int) ($_POST['rc_sort_order']      ?? 0);
            $catPriority  = (int) ($_POST['rc_decision_priority'] ?? 0);

            if ($catId <= 0) {
                $flashError = 'Ungültige Kategorie-ID.';
            } elseif ($catName === '') {
                $flashError = 'Kategoriename darf nicht leer sein.';
            } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $catName)) {
                $flashError = 'Kategoriename darf nur Buchstaben, Ziffern und Unterstriche enthalten.';
            } else {
                try {
                    saveRoutingCategory($catId, $catName, $catDef, $catRule, $catSort, $catPriority);
                    $flashOk = 'Kategorie "' . htmlspecialchars($catName) . '" gespeichert.';
                } catch (Throwable $e) {
                    $flashError = 'Kategorie konnte nicht gespeichert werden (Name bereits vorhanden?).';
                }
            }

        // ── Delete routing category ───────────────────────────────────────────
        } elseif ($action === 'delete_routing_category') {
            $catId = (int) ($_POST['rc_id'] ?? 0);
            if ($catId > 0) {
                deleteRoutingCategory($catId);
                $flashOk = 'Kategorie gelöscht.';
            }

        // ── Import routing categories from prompt.txt ─────────────────────────
        } elseif ($action === 'import_prompt_txt') {
            $categoriesJson = $_POST['prompt_categories'] ?? '';
            $categories     = json_decode($categoriesJson, true);

            if (!is_array($categories) || empty($categories)) {
                $flashError = 'Keine gültigen Kategorien in der hochgeladenen Datei gefunden.';
            } else {
                $valid = true;
                foreach ($categories as $cat) {
                    if (!isset($cat['name'], $cat['definition'], $cat['decision_rule'], $cat['sort_order'], $cat['decision_priority'])) {
                        $valid = false;
                        break;
                    }
                    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $cat['name'])) {
                        $valid = false;
                        break;
                    }
                }
                if (!$valid) {
                    $flashError = 'Die hochgeladene Datei entspricht nicht dem erwarteten Schema.';
                } else {
                    $pdo = getDb();
                    $pdo->exec('DELETE FROM routing_rules');
                    $pdo->exec('DELETE FROM routing_categories');
                    foreach ($categories as $cat) {
                        saveRoutingCategory(
                            0,
                            (string) $cat['name'],
                            (string) $cat['definition'],
                            (string) $cat['decision_rule'],
                            (int)    $cat['sort_order'],
                            (int)    $cat['decision_priority']
                        );
                    }
                    $flashOk = count($categories) . ' Kategorien aus prompt.txt importiert. Alle vorherigen Einstellungen wurden überschrieben.';
                }
            }

        // ── Save logging configuration ────────────────────────────────────────
        } elseif ($action === 'save_log_config') {
            $newLogLevel = $_POST['log_level'] ?? 'info';
            if (!in_array($newLogLevel, ['info', 'warning', 'error'], true)) {
                $newLogLevel = 'info';
            }
            $newRetention = max(1, min(3650, (int) ($_POST['log_retention_days'] ?? 30)));
            setSetting('log_level', $newLogLevel);
            setSetting('log_retention_days', (string) $newRetention);
            $logLevel         = $newLogLevel;
            $logRetentionDays = $newRetention;
            $flashOk = 'Protokollierungseinstellungen gespeichert.';

        // ── Add embedding endpoint ────────────────────────────────────────────
        } elseif ($action === 'add_embedding_endpoint') {
            $epAlias   = trim($_POST['emb_alias']   ?? '');
            $epUrl     = trim($_POST['emb_base_url'] ?? '');
            $epModel   = trim($_POST['emb_model']   ?? '');
            $epApiKey  = $_POST['emb_api_key']      ?? '';
            $epTimeout = max(5, min(600, (int) ($_POST['emb_timeout'] ?? 60)));
            $epActive  = isset($_POST['emb_is_active']) ? 1 : 0;

            if ($epUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($epUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($epModel === '') {
                $flashError = 'Modellname darf nicht leer sein.';
            } else {
                $maxOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), -1) FROM embedding_endpoints')->fetchColumn();
                $db->prepare(
                    'INSERT INTO embedding_endpoints (alias, base_url, model, api_key, timeout, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$epAlias, rtrim($epUrl, '/'), $epModel, $epApiKey !== '' ? $epApiKey : null, $epTimeout, $epActive, $maxOrder + 1]);
                $flashOk = 'Embedding-Endpunkt hinzugefügt.';
            }

        // ── Update embedding endpoint ─────────────────────────────────────────
        } elseif ($action === 'update_embedding_endpoint') {
            $epId      = (int) ($_POST['emb_id'] ?? 0);
            $epAlias   = trim($_POST['emb_alias']   ?? '');
            $epUrl     = trim($_POST['emb_base_url'] ?? '');
            $epModel   = trim($_POST['emb_model']   ?? '');
            $epApiKey  = $_POST['emb_api_key']      ?? '';
            $epTimeout = max(5, min(600, (int) ($_POST['emb_timeout'] ?? 60)));
            $epActive  = isset($_POST['emb_is_active']) ? 1 : 0;

            if ($epId <= 0) {
                $flashError = 'Ungültige Endpunkt-ID.';
            } elseif ($epUrl === '') {
                $flashError = 'URL darf nicht leer sein.';
            } elseif (filter_var($epUrl, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige URL eingeben.';
            } elseif ($epModel === '') {
                $flashError = 'Modellname darf nicht leer sein.';
            } else {
                if ($epApiKey !== '') {
                    $db->prepare(
                        'UPDATE embedding_endpoints SET alias=?, base_url=?, model=?, api_key=?, timeout=?, is_active=? WHERE id=?'
                    )->execute([$epAlias, rtrim($epUrl, '/'), $epModel, $epApiKey, $epTimeout, $epActive, $epId]);
                } else {
                    $db->prepare(
                        'UPDATE embedding_endpoints SET alias=?, base_url=?, model=?, timeout=?, is_active=? WHERE id=?'
                    )->execute([$epAlias, rtrim($epUrl, '/'), $epModel, $epTimeout, $epActive, $epId]);
                }
                $embeddingModel = $epModel;
                setSetting('embedding_model', $epModel);
                $flashOk = 'Embedding-Endpunkt gespeichert.';
            }

        // ── Delete embedding endpoint ─────────────────────────────────────────
        } elseif ($action === 'delete_embedding_endpoint') {
            $epId = (int) ($_POST['emb_id'] ?? 0);
            if ($epId > 0) {
                $db->prepare('DELETE FROM embedding_endpoints WHERE id = ?')->execute([$epId]);
                $flashOk = 'Embedding-Endpunkt gelöscht.';
            }

        // ── Save hybrid search settings ───────────────────────────────────────
        } elseif ($action === 'save_hybrid_search_settings') {
            $newHybridEnabled      = isset($_POST['hybrid_search_enabled']) ? '1' : '0';
            $newEmbeddingEnabled   = isset($_POST['embedding_enabled'])     ? '1' : '0';
            $newEmbeddingModel     = trim($_POST['embedding_model']         ?? '');
            $newEmbeddingDims      = max(0, (int) ($_POST['embedding_dimensions']   ?? 0));
            $newEmbeddingTimeout   = max(5, min(600, (int) ($_POST['embedding_timeout'] ?? 60)));
            $newBm25Weight         = max(0.0, min(1.0, (float) str_replace(',', '.', $_POST['bm25_weight'] ?? '0.5')));
            $newEmbeddingWeight    = max(0.0, min(1.0, (float) str_replace(',', '.', $_POST['embedding_weight'] ?? '0.5')));
            $newCacheEnabled       = isset($_POST['embedding_cache_enabled']) ? '1' : '0';
            $newCacheTtl           = max(1, min(365, (int) ($_POST['embedding_cache_ttl_days'] ?? 7)));

            setSetting('hybrid_search_enabled',   $newHybridEnabled);
            setSetting('embedding_enabled',        $newEmbeddingEnabled);
            setSetting('embedding_model',          $newEmbeddingModel);
            setSetting('embedding_dimensions',     (string) $newEmbeddingDims);
            setSetting('embedding_timeout',        (string) $newEmbeddingTimeout);
            setSetting('bm25_weight',              number_format($newBm25Weight, 2, '.', ''));
            setSetting('embedding_weight',         number_format($newEmbeddingWeight, 2, '.', ''));
            setSetting('embedding_cache_enabled',  $newCacheEnabled);
            setSetting('embedding_cache_ttl_days', (string) $newCacheTtl);

            $hybridSearchEnabled   = $newHybridEnabled === '1';
            $embeddingEnabled      = $newEmbeddingEnabled === '1';
            $embeddingModel        = $newEmbeddingModel;
            $embeddingDimensions   = $newEmbeddingDims;
            $embeddingTimeout      = $newEmbeddingTimeout;
            $bm25Weight            = number_format($newBm25Weight, 2, '.', '');
            $embeddingWeight       = number_format($newEmbeddingWeight, 2, '.', '');
            $embeddingCacheEnabled = $newCacheEnabled === '1';
            $embeddingCacheTtlDays = $newCacheTtl;

            $flashOk = 'Hybrid-Search-Einstellungen gespeichert.';

        // ── Save reranker settings ────────────────────────────────────────────
        } elseif ($action === 'save_reranker_settings') {
            $newRerankerEnabled  = isset($_POST['reranker_enabled']) ? '1' : '0';
            $newRerankerEndpoint = trim($_POST['reranker_endpoint'] ?? '');
            $newRerankerModel    = trim($_POST['reranker_model']    ?? '');
            $newRerankerTimeout  = max(5, min(300, (int) ($_POST['reranker_timeout'] ?? 30)));
            $newRerankerTopK     = max(1, min(50,  (int) ($_POST['reranker_top_k']   ?? 5)));

            if ($newRerankerEndpoint !== '' && filter_var($newRerankerEndpoint, FILTER_VALIDATE_URL) === false) {
                $flashError = 'Bitte eine gültige Reranker-URL eingeben oder das Feld leer lassen.';
            } else {
                setSetting('reranker_enabled',  $newRerankerEnabled);
                setSetting('reranker_endpoint', $newRerankerEndpoint);
                setSetting('reranker_model',    $newRerankerModel);
                setSetting('reranker_timeout',  (string) $newRerankerTimeout);
                setSetting('reranker_top_k',    (string) $newRerankerTopK);

                $rerankerEnabled  = $newRerankerEnabled === '1';
                $rerankerEndpoint = $newRerankerEndpoint;
                $rerankerModel    = $newRerankerModel;
                $rerankerTimeout  = $newRerankerTimeout;
                $rerankerTopK     = $newRerankerTopK;

                $flashOk = 'Reranker-Einstellungen gespeichert.';
            }

        // ── Save vector store settings ────────────────────────────────────────
        } elseif ($action === 'save_vector_store_settings') {
            $newMode = (string) ($_POST['vector_store_mode'] ?? 'off');
            if (!in_array($newMode, ['off', 'remote', 'local'], true)) {
                $newMode = 'off';
            }
            $newTopK      = max(1, min(50, (int) ($_POST['vector_top_k'] ?? 5)));
            $newMinScore  = max(0.0, min(1.0, (float) str_replace(',', '.', (string) ($_POST['vector_min_score'] ?? '0'))));
            $newDocvecUrl = rtrim(trim((string) ($_POST['docvec_api_url'] ?? '')), '/');
            $newDocvecUser = trim((string) ($_POST['docvec_api_username'] ?? ''));
            $newDocvecPass = (string) ($_POST['docvec_api_password'] ?? '');
            $newDocvecTimeout = max(3, min(300, (int) ($_POST['docvec_api_timeout'] ?? 20)));
            $newDocvecVerify  = isset($_POST['docvec_api_verify_tls']) ? '1' : '0';
            $newMilvusUrl     = rtrim(trim((string) ($_POST['milvus_url'] ?? '')), '/');
            $newMilvusMetrics = rtrim(trim((string) ($_POST['milvus_metrics_url'] ?? '')), '/');
            $newMilvusToken   = trim((string) ($_POST['milvus_token'] ?? ''));
            $newMilvusTimeout = max(3, min(600, (int) ($_POST['milvus_timeout'] ?? 30)));
            $newMilvusCollection = trim((string) ($_POST['milvus_collection'] ?? ''));

            $urlInvalid = static fn(string $u): bool => $u !== '' && filter_var($u, FILTER_VALIDATE_URL) === false;
            if ($urlInvalid($newDocvecUrl) || $urlInvalid($newMilvusUrl) || $urlInvalid($newMilvusMetrics)) {
                $flashError = 'Bitte gültige URLs eingeben (http:// oder https://).';
            } elseif ($newMode === 'remote' && $newDocvecUrl === '') {
                $flashError = 'Für den API-Modus wird die URL der docvecwizard-Instanz benötigt.';
            } elseif ($newMilvusCollection !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,254}$/', $newMilvusCollection)) {
                $flashError = 'Ungültiger Collection-Name (erlaubt: Buchstaben, Ziffern, Unterstrich).';
            } else {
                setSetting('vector_store_mode',    $newMode);
                setSetting('vector_top_k',         (string) $newTopK);
                setSetting('vector_min_score',     number_format($newMinScore, 2, '.', ''));
                setSetting('docvec_api_url',       $newDocvecUrl);
                setSetting('docvec_api_username',  $newDocvecUser);
                if ($newDocvecPass !== '') {
                    setSetting('docvec_api_password', $newDocvecPass);
                    $docvecApiPassword = $newDocvecPass;
                }
                setSetting('docvec_api_timeout',    (string) $newDocvecTimeout);
                setSetting('docvec_api_verify_tls', $newDocvecVerify);
                setSetting('milvus_url',            $newMilvusUrl);
                setSetting('milvus_metrics_url',    $newMilvusMetrics);
                setSetting('milvus_token',          $newMilvusToken);
                setSetting('milvus_timeout',        (string) $newMilvusTimeout);
                setSetting('milvus_collection',     $newMilvusCollection);
                setSetting('vector_store_status_cache', '');

                $vectorStoreMode      = $newMode;
                $vectorTopK           = $newTopK;
                $vectorMinScore       = number_format($newMinScore, 2, '.', '');
                $docvecApiUrl         = $newDocvecUrl;
                $docvecApiUsername    = $newDocvecUser;
                $docvecApiTimeout     = $newDocvecTimeout;
                $docvecApiVerifyTls   = $newDocvecVerify === '1';
                $milvusUrlSetting     = $newMilvusUrl;
                $milvusMetricsSetting = $newMilvusMetrics;
                $milvusToken          = $newMilvusToken;
                $milvusTimeout        = $newMilvusTimeout;
                $milvusCollection     = $newMilvusCollection;

                $flashOk = 'Vektordatenbank-Einstellungen gespeichert (Modus: ' . vectorStoreLabel($newMode === 'off' ? '' : $newMode) . ($newMode === 'off' ? ' deaktiviert' : '') . ').';
            }

        // ── Reset local vector store (drop imported data) ─────────────────────
        } elseif ($action === 'vector_store_reset_local') {
            $dropped = [];
            try {
                $collections = $db->query('SELECT DISTINCT collection_name FROM vector_documents WHERE collection_name <> ""')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($collections as $col) {
                    if (milvusHasCollection((string) $col)) {
                        $res = milvusDropCollection((string) $col);
                        if ($res['ok']) {
                            $dropped[] = (string) $col;
                        }
                    }
                }
                $db->exec('DELETE FROM vector_chunks');
                $db->exec('DELETE FROM vector_documents');
                setSetting('milvus_collection', '');
                setSetting('vector_store_status_cache', '');
                $milvusCollection = '';
                writeLog('warning', 'Lokaler Vektorspeicher zurückgesetzt' . ($dropped !== [] ? ' (Collections: ' . implode(', ', $dropped) . ')' : '') . '.');
                $flashOk = 'Lokaler Vektorspeicher geleert' . ($dropped !== [] ? ' – Milvus-Collections entfernt: ' . implode(', ', $dropped) : '') . '.';
            } catch (Throwable $e) {
                $flashError = 'Zurücksetzen fehlgeschlagen: ' . $e->getMessage();
            }

        // ── Change password ───────────────────────────────────────────────────
        // ── API-Keys (OpenAI-kompatible API) ──────────────────────────────────
        } elseif ($action === 'create_api_key') {
            $apiKeysPostHandled = true;
            $keyName        = trim((string) ($_POST['name'] ?? ''));
            $keyDescription = trim((string) ($_POST['description'] ?? ''));
            $keyExpiresAt   = trim((string) ($_POST['expires_at'] ?? ''));
            $keyModel       = trim((string) ($_POST['model'] ?? ''));
            $keyModels      = listActiveEndpointModels();

            if ($keyName === '') {
                $flashError = 'Name darf nicht leer sein.';
            } elseif (mb_strlen($keyName) > 150) {
                $flashError = 'Name darf maximal 150 Zeichen enthalten.';
            } elseif ($keyModel !== '' && !in_array($keyModel, $keyModels, true)) {
                $flashError = 'Ungültiges Modell.';
            } else {
                $keyExpiresAtSql = null;
                if ($keyExpiresAt !== '') {
                    $keyDt = date_create($keyExpiresAt);
                    if (!$keyDt) {
                        $flashError = 'Ungültiges Ablaufdatum.';
                    } else {
                        $keyExpiresAtSql = $keyDt->format('Y-m-d H:i:s');
                    }
                }

                if ($flashError === '') {
                    $keyMaterial = openaiGenerateApiKeyMaterial();
                    $db->prepare(
                        'INSERT INTO api_keys (user_id, name, description, model, key_prefix, api_key_hash, created_at, expires_at, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, 1)'
                    )->execute([
                        $_SESSION['admin_id'],
                        $keyName,
                        mb_substr($keyDescription, 0, 255),
                        $keyModel,
                        $keyMaterial['prefix'],
                        $keyMaterial['hash'],
                        $keyExpiresAtSql,
                    ]);

                    $newApiKey  = $keyMaterial['plain'];
                    $flashOk    = 'API-Key erstellt. Bitte jetzt kopieren.';
                }
            }
        } elseif ($action === 'toggle_api_key') {
            $apiKeysPostHandled = true;
            $keyId  = (int) ($_POST['key_id'] ?? 0);
            $active = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            if ($keyId > 0) {
                $db->prepare('UPDATE api_keys SET is_active = ? WHERE id = ? AND user_id = ?')
                   ->execute([$active, $keyId, $_SESSION['admin_id']]);
                $flashOk = $active ? 'API-Key aktiviert.' : 'API-Key deaktiviert.';
            }
        } elseif ($action === 'delete_api_key') {
            $apiKeysPostHandled = true;
            $keyId = (int) ($_POST['key_id'] ?? 0);
            if ($keyId > 0) {
                $db->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')
                   ->execute([$keyId, $_SESSION['admin_id']]);
                $flashOk = 'API-Key gelöscht.';
            }
        } elseif ($action === 'change_password') {
            $oldPass  = $_POST['old_password']         ?? '';
            $newPass  = $_POST['new_password']          ?? '';
            $newPass2 = $_POST['new_password_confirm']  ?? '';

            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['admin_id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($oldPass, $row['password_hash'])) {
                $flashError = 'Aktuelles Passwort ist falsch.';
            } elseif ($newPass === '') {
                $flashError = 'Das neue Passwort darf nicht leer sein.';
            } elseif ($newPass !== $newPass2) {
                $flashError = 'Die neuen Passwörter stimmen nicht überein.';
            } elseif (strlen($newPass) < 8) {
                $flashError = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password_hash = ?, requires_password_change = 0 WHERE id = ?')
                   ->execute([$hash, $_SESSION['admin_id']]);
                unset($_SESSION['requires_password_change']);
                $flashOk = 'Passwort erfolgreich geändert.';
            }
        }
    }
}
