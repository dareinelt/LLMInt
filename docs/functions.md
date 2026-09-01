# functions.md – Funktionsreferenz

Vollständige Übersicht aller top-level PHP-Funktionen in LLMInt, gruppiert nach Datei.
Für den architektonischen Kontext siehe [`architecture.md`](architecture.md); für eine
kompakte Navigationshilfe siehe [`agent_index.md`](agent_index.md).

> Hinweis: Dateien ohne eigene Funktionsdeklarationen (rein prozeduraler Code) sind mit
> einem kurzen Hinweis statt einer Tabelle aufgeführt.

---

## Inhalt

- [db.php](#dbphp)
- [config.php](#configphp)
- [lib/balancer_engine.php](#libbalancer_enginephp)
- [lib/healthcheck.php](#libhealthcheckphp)
- [lib/ldap_auth.php](#libldap_authphp)
- [lib/mailer.php](#libmailerphp)
- [lib/openai_api.php](#libopenai_apiphp)
- [lib/prompt_security.php](#libprompt_securityphp)
- [api/chat.php](#apichatphp)
- [api/balancer.php](#apibalancerphp)
- [api/embedding.php](#apiembeddingphp)
- [api/upload_document.php](#apiupload_documentphp)
- [api/doc_convert.php](#apidoc_convertphp)
- [api/pdf_render.php](#apipdf_renderphp)
- [api/vision.php](#apivisionphp)
- [api/sd_balancer.php](#apisd_balancerphp)
- [api/comfy_balancer.php](#apicomfy_balancerphp)
- [api/heartbeat.php](#apiheartbeatphp)
- [api/reset_password.php](#apireset_passwordphp)
- [api/verify_email.php](#apiverify_emailphp)
- [api/test_searxng.php](#apitest_searxngphp)
- [Weitere api/*.php ohne eigene Funktionen](#weitere-apiphp-ohne-eigene-funktionen)
- [admin/index.php](#adminindexphp)
- [admin/refresh_sys_stats.php](#adminrefresh_sys_statsphp)
- [Weitere admin/*.php ohne eigene Funktionen](#weitere-adminphp-ohne-eigene-funktionen)

---

## db.php

Kernmodul: PDO-Verbindung, Schema, Einstellungen, Logging, Routing, Chat-Sitzungen,
Intelligenzgruppen.

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `getDb` | `getDb(): PDO` | Liefert die zwischengespeicherte PDO-Verbindung; initialisiert sie beim ersten Aufruf aus Umgebungsvariablen. |
| `ensureRuntimeSchema` | `ensureRuntimeSchema(PDO $pdo): void` | Legt Kern-Tabellen an und ergänzt fehlende Spalten (einmal pro Prozess, idempotent). |
| `getSetting` | `getSetting(string $key, string $default = ''): string` | Liest einen Einstellungswert; liefert `$default`, wenn Schlüssel fehlt oder DB nicht erreichbar ist. |
| `setSetting` | `setSetting(string $key, string $value): void` | Schreibt einen Einstellungswert (Upsert). |
| `getGlobalSystemPrompt` | `getGlobalSystemPrompt(): string` | Liefert den globalen Systemprompt aus den Einstellungen oder einen eingebauten Standardwert. |
| `buildCurrentDateTimeSystemPrompt` | `buildCurrentDateTimeSystemPrompt(): string` | Baut den Datum/Uhrzeit-Hinweis, der jeder Chat-Anfrage beigefügt wird. |
| `getRegistrationEmailSubject` | `getRegistrationEmailSubject(): string` | Liefert den konfigurierten Betreff für die Registrierungs-E-Mail oder einen Standard. |
| `getRegistrationEmailBody` | `getRegistrationEmailBody(): string` | Liefert die konfigurierte Vorlage für die Registrierungs-E-Mail mit Fallback-Kette. |
| `renderRegistrationEmailTemplate` | `renderRegistrationEmailTemplate(string $template, array $vars): string` | Ersetzt `{key}`-Platzhalter in einer E-Mail-Vorlage durch Werte aus einem Array. |
| `currentUserRole` | `currentUserRole(): ?string` | Liefert die Rolle (`user`/`admin`) des angemeldeten Benutzers oder `null`. |
| `isCurrentUserAdmin` | `isCurrentUserAdmin(): bool` | Prüft, ob der angemeldete Benutzer Administratorrechte hat. |
| `requireAdminOrRedirect` | `requireAdminOrRedirect(string $loginUrl = 'login.php'): void` | Schützt HTML-Seiten; leitet zum Login um oder zeigt 403, falls kein Administrator. |
| `requireAdminOrJson403` | `requireAdminOrJson403(): void` | Schützt JSON-Endpunkte; sendet 403-JSON, falls kein Administrator. |
| `writeLog` | `writeLog(string $level, string $message): void` | Schreibt einen Log-Eintrag in `app_logs` (respektiert `log_level`, best effort). |
| `purgeOldLogs` | `purgeOldLogs(): void` | Löscht `app_logs`-Einträge, die älter als die Aufbewahrungsfrist sind (Standard 30 Tage). |
| `loadRoutingCategoriesFromDb` | `loadRoutingCategoriesFromDb(): array` | Lädt alle Routing-Kategorien aus der DB, sortiert nach `sort_order`. |
| `loadRoutingCategories` | `loadRoutingCategories(): array` | Liefert geordnete Liste von Kategorienamen aus DB oder Fallback-Datei. |
| `buildRoutingPrompt` | `buildRoutingPrompt(): string` | Baut den vollständigen Routing-Systemprompt aus DB-Kategorien (Format wie `lib/prompt.txt`). |
| `saveRoutingCategory` | `saveRoutingCategory(int $id, string $name, string $definition, string $decisionRule, int $sortOrder, int $decisionPriority): void` | Legt eine Routing-Kategorie an oder aktualisiert sie (Upsert, `$id=0` = neu). |
| `deleteRoutingCategory` | `deleteRoutingCategory(int $id): void` | Löscht eine Routing-Kategorie samt zugehöriger Routing-Regeln. |
| `loadRoutingRules` | `loadRoutingRules(): array` | Lädt alle Routing-Regeln (Kategorie → Modell) aus der Datenbank. |
| `saveRoutingRule` | `saveRoutingRule(string $category, string $model): void` | Legt eine Routing-Regel an oder aktualisiert sie (Upsert). |
| `saveConversationSession` | `saveConversationSession(string $sessionId, string $model, array $messages, ?int $userId = null): void` | Speichert die Nachrichten einer Chat-Sitzung; leitet bei gesetztem `$userId` einen Titel aus der ersten Nutzernachricht ab. |
| `loadConversationSession` | `loadConversationSession(string $sessionId): ?array` | Lädt eine Chat-Sitzung; liefert `null`, falls abgelaufen oder nicht vorhanden. |
| `setSessionUpgradeModel` | `setSessionUpgradeModel(string $sessionId, string $upgradeModel): void` | Speichert ein ausstehendes Modell-Upgrade für eine Sitzung. |
| `getActiveSessionUpgradeModel` | `getActiveSessionUpgradeModel(string $sessionId): ?string` | Liefert das ausstehende Upgrade, sofern noch aktiv (nicht angenommen). |
| `purgeExpiredConversationSessions` | `purgeExpiredConversationSessions(): void` | Löscht anonyme Sitzungen, die älter als 30 Minuten sind. |
| `listActiveClients` | `listActiveClients(?PDO $pdo = null): array` | Liefert die Liste aktuell aktiver Clients (innerhalb der letzten 90 Sekunden gesehen). |
| `listUserConversations` | `listUserConversations(int $userId): array` | Liefert gespeicherte Chat-Sitzungen eines Benutzers. |
| `canonicalModelName` | `canonicalModelName(string $model): string` | Liefert die kanonische Form eines Modellnamens (entfernt Intelligenzgruppen-Präfix). |
| `equivalentActiveModelNames` | `equivalentActiveModelNames(string $model): array` | Liefert äquivalente Modellnamen (mit/ohne Intelligenzgruppen-Präfix). |
| `modelIntelligenceScore` | `modelIntelligenceScore(string $modelName): ?float` | Liefert den Intelligenz-Score eines Modells aus den Einstellungen, `null` falls nicht konfiguriert. |
| `resolveUserModel` | `resolveUserModel(string $preferredModel): string` | Löst das bevorzugte Modell eines Benutzers auf das erste verfügbare aktive Modell auf. |
| `isIntelligenceGroupFeatureEnabled` | `isIntelligenceGroupFeatureEnabled(): bool` | Prüft, ob das Intelligenzgruppen-Feature aktiviert ist. |
| `intelligenceGroupLabel` | `intelligenceGroupLabel(float $score): string` | Ordnet einen Intelligenz-Score (0–100) einem Label zu (`basic`/`standard`/`advanced`/`expert`). |
| `normalizeIntelligenceGroupLabel` | `normalizeIntelligenceGroupLabel(string $raw): string` | Normalisiert ein benutzerseitig angegebenes Gruppenlabel auf die kanonische Form. |
| `listIntelligenceGroups` | `listIntelligenceGroups(): array` | Liefert konfigurierte Intelligenzgruppen mit Modellzuordnung. |
| `resolveIntelligenceGroupModel` | `resolveIntelligenceGroupModel(string $rawLabel): ?string` | Löst ein Gruppenlabel auf das am besten passende verfügbare Modell auf. |
| `extractIntelligenceGroupPrefix` | `extractIntelligenceGroupPrefix(string $text): array` | Extrahiert das Intelligenzgruppen-Präfix aus einer Nutzernachricht (z. B. `@@35b`). |
| `extractReasoningPrefix` | `extractReasoningPrefix(string $text): array` | Extrahiert das Reasoning-Präfix (`!!`) aus einer Nutzernachricht. |
| `setSessionIntelligenceGroup` | `setSessionIntelligenceGroup(string $sessionId, string $label, string $model, ?int $userId = null): void` | Speichert die Intelligenzgruppen-Auswahl für eine Sitzung. |
| `clearSessionIntelligenceGroup` | `clearSessionIntelligenceGroup(string $sessionId): void` | Löscht die gespeicherte Intelligenzgruppe einer Sitzung. |
| `getSessionIntelligenceGroup` | `getSessionIntelligenceGroup(string $sessionId): ?array` | Liefert die gespeicherte Intelligenzgruppe einer Sitzung. |
| `reasoningEffortOptions` | `reasoningEffortOptions(): array` | Liefert die gültigen Reasoning-Effort-Optionen (`none`/`low`/`medium`/`high`). |
| `normalizeReasoningEffort` | `normalizeReasoningEffort($value): ?string` | Normalisiert einen Reasoning-Effort-Wert auf die kanonische Form oder `null`. |

## config.php

**Keine Funktionen.** Eine selbstausführende Closure leitet die Konstanten
`LMSTUDIO_BASE_URL` und `LMSTUDIO_TIMEOUT` aus dem ersten aktiven Endpunkt bzw. den
Einstellungen ab.

---

## lib/balancer_engine.php

Gemeinsame Balancer-Logik für LLM-, AUTOMATIC1111- und ComfyUI-Endpunkte.

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `ensureBalancerHealthColumns` | `ensureBalancerHealthColumns(PDO $pdo, string $table): void` | Ergänzt Circuit-Breaker- und Latenzspalten einer Endpunkttabelle (idempotent). |
| `getBalancerSetting` | `getBalancerSetting(string $key): string` | Liest eine Balancer-Einstellung, liefert dokumentierten Standardwert falls nicht gesetzt. |
| `getBalancerMaxConcurrent` | `getBalancerMaxConcurrent(): int` | Liefert die maximale Anzahl gleichzeitiger Tasks je Endpunkt (Standard 4). |
| `getBalancerCircuitFailThreshold` | `getBalancerCircuitFailThreshold(): int` | Liefert die Anzahl aufeinanderfolgender Fehlschläge, bevor der Circuit öffnet (Standard 3). |
| `getBalancerCircuitCooldownSeconds` | `getBalancerCircuitCooldownSeconds(): int` | Liefert die Sekunden, die ein offener Circuit bis zur Half-Open-Probe geschlossen bleibt (Standard 30). |
| `getBalancerOrphanTimeoutSeconds` | `getBalancerOrphanTimeoutSeconds(): int` | Liefert die Sekunden, nach denen laufende Tasks als verwaist gelten (Standard 300). |
| `computeBackoffDelayMs` | `computeBackoffDelayMs(int $attempt): int` | Berechnet die exponentielle Backoff-Verzögerung inkl. optionalem Jitter für einen Versuch. |
| `backoffSleep` | `backoffSleep(int $attempt): void` | Wartet die berechnete Backoff-Verzögerung für einen Wiederholungsversuch ab. |
| `getFallbackChain` | `getFallbackChain(string $model): array` | Liefert die geordnete Liste der Fallback-Modelle für ein Modell. |
| `saveFallbackChains` | `saveFallbackChains(array $map): void` | Speichert die Fallback-Ketten-Konfiguration (Modell → [Fallback-Modelle]). |
| `recordEndpointOutcome` | `recordEndpointOutcome(string $table, int $endpointId, bool $success, ?float $latencyMs = null): void` | Erfasst den Anfrageerfolg, steuert die Circuit-Breaker-Zustandsmaschine und aktualisiert die geglättete Latenz. |
| `resetEndpointCircuit` | `resetEndpointCircuit(string $table, int $endpointId): bool` | Schließt den Circuit manuell und setzt die Fehlerserie zurück; liefert `true` bei Erfolg. |
| `setEndpointPaused` | `setEndpointPaused(string $table, int $endpointId, bool $paused): bool` | Pausiert/reaktiviert einen Endpunkt; Reaktivierung setzt den Circuit-Zustand zurück. |
| `balancerCircuitWhereClause` | `balancerCircuitWhereClause(): string` | Liefert das SQL-`WHERE`-Fragment, das Endpunkte mit offenem Circuit im Cooldown ausschließt. |
| `maybeHalfOpenCircuit` | `maybeHalfOpenCircuit(string $table, int $endpointId): void` | Überführt einen Endpunkt von `open` nach `half_open`, sobald der Cooldown abgelaufen ist. |
| `cleanupOrphanedTasks` | `cleanupOrphanedTasks(string $tasksTable, bool $force = false): int` | Markiert verwaiste laufende Tasks als Fehler; liefert die Anzahl bereinigter Tasks. |

## lib/healthcheck.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `probeLlmEndpoints` | `probeLlmEndpoints(int $timeoutSeconds = 3): array` | Prüft aktiv alle aktiven LLM-Endpunkte parallel über `/models`; liefert Ergebnisse je Endpunkt. |
| `isAnyLlmEndpointHealthy` | `isAnyLlmEndpointHealthy(): bool` | Liefert, ob mindestens ein LLM-Endpunkt erreichbar ist (10 Sekunden gecacht). Steuert den Wartungsmodus in `index.php`. |

## lib/ldap_auth.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `ldapEnabled` | `ldapEnabled(): bool` | Prüft, ob LDAP-Authentifizierung aktiviert und ein Host konfiguriert ist. |
| `ldapSsoEnabled` | `ldapSsoEnabled(): bool` | Prüft, ob Windows-SSO via `REMOTE_USER` aktiviert ist. |
| `ldapSsoUsername` | `ldapSsoUsername(): string` | Extrahiert den Benutzernamen aus `REMOTE_USER` (entfernt Domänen-Präfix/UPN-Suffix). |
| `ldapAuthenticate` | `ldapAuthenticate(string $username, string $password): ?array` | Authentifiziert gegen Active Directory; liefert bei Erfolg Benutzername/DN/E-Mail/Anzeigename. |
| `ldapFetchUserInfo` | `ldapFetchUserInfo($conn, string $username, string $baseDn): array` | Sucht im Verzeichnis nach dem Benutzer und liefert ausgewählte Attribute. |
| `ldapProvisionUser` | `ldapProvisionUser(array $info): ?int` | Findet oder erstellt den lokalen Benutzerdatensatz für einen AD-authentifizierten Benutzer. |
| `ldapTestConnection` | `ldapTestConnection(string $host, int $port, bool $useSsl, string $bindDn, string $bindPass): array` | Testet die LDAP-Verbindung mit übergebenen Parametern. |

## lib/mailer.php

| Funktion/Methode | Signatur | Beschreibung |
|---|---|---|
| `Mailer::__construct` | `__construct(string $host, int $port = 587, string $encryption = 'tls', string $user = '', string $pass = '', int $timeout = 15)` | Initialisiert den SMTP-Mailer mit Verbindungsparametern. |
| `Mailer::send` | `send(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $bodyText, string $bodyHtml = ''): void` | Sendet eine E-Mail per SMTP; wirft `RuntimeException` bei Fehlern. |
| `mailerFromSettings` | `mailerFromSettings(): Mailer` | Erstellt eine `Mailer`-Instanz aus den gespeicherten SMTP-Einstellungen. |
| `sendMail` | `sendMail(string $toEmail, string $toName, string $subject, string $bodyText, string $bodyHtml = ''): void` | Versendet eine E-Mail mit den gespeicherten SMTP-Einstellungen. |

## lib/openai_api.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `openaiIsStrictMode` | `openaiIsStrictMode(): bool` | Prüft, ob der strikte OpenAI-Modus über `LLMINT_OPENAI_STRICT_MODE` aktiv ist. |
| `openaiErrorBody` | `openaiErrorBody(string $message, string $type = 'invalid_request_error', mixed $code = null): array` | Baut den OpenAI-kompatiblen Fehlerantwort-Body. |
| `openaiSendError` | `openaiSendError(int $statusCode, string $message, string $type = 'invalid_request_error', mixed $code = null): void` | Sendet eine OpenAI-kompatible Fehlerantwort und beendet die Anfrage. |
| `openaiApiKeyHash` | `openaiApiKeyHash(string $plainKey): string` | Berechnet den SHA-256-Hash eines API-Keys. |
| `openaiGenerateApiKeyMaterial` | `openaiGenerateApiKeyMaterial(): array` | Generiert neues API-Key-Material (Klartext, Hash, Präfix). |
| `openaiReadBearerToken` | `openaiReadBearerToken(): string` | Extrahiert das Bearer-Token aus dem `Authorization`-Header. |
| `openaiAuthenticateApiRequest` | `openaiAuthenticateApiRequest(): array` | Authentifiziert eine API-Anfrage per Bearer-Token; liefert Key-/Benutzerdaten oder sendet 401. |
| `openaiAvailableModels` | `openaiAvailableModels(): array` | Liefert die Liste eindeutiger aktiver Endpunktmodelle. |
| `openaiNormalizeMessages` | `openaiNormalizeMessages(array $messages): array` | Normalisiert ein Nachrichtenarray ins OpenAI-Format inkl. Validierung. |
| `openaiNormalizeChatPayload` | `openaiNormalizeChatPayload(array $input): array` | Normalisiert und validiert den eingehenden Chat-Completion-Request-Payload. |

## lib/prompt_security.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `psLoadRules` | `psLoadRules(): array` | Lädt aktive Sicherheitsregeln aus der DB (pro Request gecacht). |
| `psNormalise` | `psNormalise(string $input): array` | Normalisiert Eingaben: Unicode, Zero-Width-Zeichen, HTML, URL-Decode, Base64-Heuristik. |
| `psMatchRules` | `psMatchRules(string $normalised): array` | Gleicht normalisierten Text mit allen aktiven Regeln ab. |
| `psComputeScore` | `psComputeScore(array $matchedRules): int` | Berechnet den aggregierten Risiko-Score (0–100) aus getroffenen Regeln mit Kategoriegewichtung. |
| `psAiEvaluate` | `psAiEvaluate(string $normalised): ?string` | Ruft optional einen sekundären LLM-Klassifikator auf. |
| `psAiLabelToScore` | `psAiLabelToScore(string $label): int` | Wandelt ein KI-Klassifikationslabel in einen Score-Zuschlag um. |
| `psDecide` | `psDecide(int $score): string` | Bestimmt die Sicherheitsentscheidung (`allow`/`warn`/`block`) anhand von Schwellwerten. |
| `psLog` | `psLog(string $originalInput, int $score, string $decision, array $matchedRules, string $aiModel, ?int $userId, string $sessionId, string $ipAddress): void` | Protokolliert das Sicherheitsereignis in `prompt_security_logs`. |
| `psEvaluate` | `psEvaluate(string $rawInput, ?int $userId = null, string $sessionId = '', string $ipAddress = ''): array` | Führt die vollständige Sicherheitspipeline aus und liefert Entscheidung/Score/Regeln/Meldung. |
| `psPurgeLogs` | `psPurgeLogs(): void` | Löscht `prompt_security_logs`-Einträge, die älter als die Aufbewahrungsfrist sind. |

---

## api/chat.php

Zentrale Chat-Pipeline (~3.300 Zeilen). Funktionen sind thematisch gruppiert.

### Websuche und Web-Fetch

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `buildSearxngSearchUrl` | `buildSearxngSearchUrl(string $baseUrl, string $query): string` | Baut die vollständige SearXNG-Such-URL mit Format- und Sprachparametern. |
| `runSearxngSearch` | `runSearxngSearch(string $baseUrl, string $query, int $timeout = 15): array` | Führt eine SearXNG-Suche aus und liefert Titel/URL/Snippet/Quelle je Treffer. |
| `assertFetchableWebUrl` | `assertFetchableWebUrl(string $url): array` | Validiert eine URL gegen SSRF: nur http/https, öffentliche IPs, keine Zugangsdaten in der URL. |
| `extractReadableText` | `extractReadableText(string $html): string` | Wandelt ein HTML-Dokument in kompakten Klartext um. |
| `extractHtmlTitle` | `extractHtmlTitle(string $html): string` | Extrahiert den `<title>` eines HTML-Dokuments. |
| `fetchWebPage` | `fetchWebPage(string $url, int $timeout = 15, int $maxChars = 6000): array` | Lädt eine Webseite und liefert lesbaren Textinhalt für das LLM. |

### Such-Protokollierung

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `startSearchLog` | `startSearchLog(string $query): int` | Legt einen `search_logs`-Eintrag mit Status `running` an, liefert die Log-ID. |
| `completeSearchLog` | `completeSearchLog(int $id, string $status, ?array $results = null): void` | Markiert einen Such-Log als abgeschlossen mit Status und optionalem Ergebnis-JSON. |
| `extractSearchSources` | `extractSearchSources(array $searchResult): array` | Extrahiert eine kompakte `{title, url}`-Quellenliste (ohne Duplikate). |

### Tool-Definitionen

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `createSearchToolDefinition` | `createSearchToolDefinition(): array` | OpenAI-kompatible Tool-Definition für die Websuche via SearXNG. |
| `createWebFetchToolDefinition` | `createWebFetchToolDefinition(): array` | OpenAI-kompatible Tool-Definition für das Nachladen von Webseiteninhalten. |
| `createImageGenerationToolDefinition` | `createImageGenerationToolDefinition(): array` | OpenAI-kompatible Tool-Definition für die Bildgenerierung mit AUTOMATIC1111. |
| `createDocumentQueryToolDefinition` | `createDocumentQueryToolDefinition(): array` | OpenAI-kompatible Tool-Definition für RAG-Dokumentenabfragen. |
| `createComfyToolDefinition` | `createComfyToolDefinition(): array` | OpenAI-kompatible Tool-Definition für die Bildgenerierung mit ComfyUI. |

### Bildgenerierung (AUTOMATIC1111 / ComfyUI)

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `hasSdEndpoints` | `hasSdEndpoints(): bool` | Prüft, ob mindestens ein aktiver Stable-Diffusion-Endpunkt konfiguriert ist. |
| `callSdGenerate` | `callSdGenerate(array $params, int $timeout = 120): array` | Ruft die AUTOMATIC1111-API zur Bildgenerierung auf, liefert `{image_url}` oder `{error}`. |
| `hasComfyEndpoints` | `hasComfyEndpoints(): bool` | Prüft, ob mindestens ein aktiver ComfyUI-Endpunkt konfiguriert ist. |
| `callComfyGenerate` | `callComfyGenerate(array $params, int $timeout = 120): array` | Generiert ein Bild über ComfyUI-Workflow-Queueing, liefert `{image_url}` oder `{error}`. |

### RAG / Dokumentenabfrage

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `tokenizeQueryTerms` | `tokenizeQueryTerms(string $query): array` | Zerlegt eine Anfrage in Kleinbuchstaben-Terme, filtert Terme < 2 Zeichen. |
| `buildRagSnippet` | `buildRagSnippet(string $text, array $terms, int $maxLen = 1200): string` | Extrahiert ein Textausschnitt um den ersten Treffer-Term mit Trunkierungsmarkern. |
| `scoreRagChunk` | `scoreRagChunk(string $chunkText, string $query, array $terms): float` | Berechnet einen BM25-artigen Relevanz-Score für einen Chunk. |
| `hasDocumentUploads` | `hasDocumentUploads(?int $userId, string $chatSessionId = ''): bool` | Prüft, ob analysierte Uploads für den Benutzer (eigene, global oder an den Chat angehängte) vorliegen. |
| `listChatSessionDocuments` | `listChatSessionDocuments(?int $userId, string $chatSessionId): array` | Liefert die an eine Chat-Sitzung angehängten Uploads. |
| `buildChatDocumentSystemPrompt` | `buildChatDocumentSystemPrompt(array $documents, bool $useDocQueryTool): string` | Erzeugt den Systemhinweis auf die angehängten Dateien; ohne Tool-Unterstützung wird der Text direkt eingebettet. |
| `queryDocuments` | `queryDocuments(string $query, ?int $userId, string $chatSessionId = ''): array` | Durchsucht Dokument-Chunks per Hybrid-RAG (BM25 + Embeddings + RRF + optionalem Reranking); an den Chat angehängte Dateien werden bevorzugt. |

### Token-Abrechnung

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `extractUsage` | `extractUsage(array $data): array` | Extrahiert `prompt_tokens`/`completion_tokens`/`total_tokens` aus einer API-Antwort. |
| `estimateImageTokenCount` | `estimateImageTokenCount(mixed $imageUrlPart): int` | Schätzt die Tokenzahl eines Bildanhangs (85 bei `low`, 1105 bei `high`/`auto`). |
| `estimateTokenCount` | `estimateTokenCount(array $messages): int` | Schätzt die Gesamttokenzahl eines Nachrichtenarrays (~4 Zeichen/Token plus Bildbudget). |
| `computeTokensPerSecond` | `computeTokensPerSecond(float $requestStart, ?float $firstTokenElapsedMs, ?int $completionTokens): ?float` | Berechnet die Token-Generierungsgeschwindigkeit. |

### Nachrichtenverarbeitung

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `normalizeAssistantContent` | `normalizeAssistantContent(mixed $content): string` | Wandelt Nachrichteninhalt (String oder Teile-Array) in einen reinen Textstring um. |
| `mergeSystemMessages` | `mergeSystemMessages(array $messages): array` | Fasst alle Systemnachrichten zu einer einzigen am Anfang zusammen (Jinja-Template-Kompatibilität). |
| `messageContentHasImage` | `messageContentHasImage(mixed $content): bool` | Prüft, ob der Nachrichteninhalt einen `image_url`-Teil enthält. |
| `stripImageContentParts` | `stripImageContentParts(array $messages): array` | Entfernt alle `image_url`-Teile – Bildanhänge sind angemeldeten Nutzern vorbehalten. |
| `payloadMessagesHaveImage` | `payloadMessagesHaveImage(array $messages): bool` | Prüft, ob irgendeine Nachricht im Payload ein Bild enthält. |

### Kontextlimits

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `resolveContextLimits` | `resolveContextLimits(array $endpoint): array` | Ermittelt effektive Kontextlimits (Endpunkt-Maximum, Obergrenze je Slot). |
| `addContextUsageToResponseDetails` | `addContextUsageToResponseDetails(array &$responseDetails, array $endpoint, int $totalTokens, ?string $finishReason): void` | Ergänzt Response-Details um Kontextauslastung und ein Flag für erreichtes Kontextlimit. |

### Streaming und Server-Sent Events

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `ensureSseHeaders` | `ensureSseHeaders(): void` | Sendet `text/event-stream`-Content-Type- und Cache-Control-Header (idempotent). |
| `emitSseData` | `emitSseData(array\|string $payload): void` | Sendet eine SSE-`data:`-Zeile mit JSON-Payload. |
| `emitSyntheticStream` | `emitSyntheticStream(array $data, ?array $upgradeSuggestion = null, ?array $responseDetails = null, bool $contentAlreadySent = false): void` | Wandelt eine Nicht-Streaming-Antwort in SSE-Format um und sendet Abschluss-Events. |
| `streamChatCompletionRequest` | `streamChatCompletionRequest(string $url, array $payload, int $timeout, bool $forwardToClient, float $requestStart, bool &$firstTokenLogged, bool &$streamStartedLogged, bool &$clientAborted, ?float &$firstTokenElapsedMs = null): array` | **Haupteinstiegspunkt**: führt die Streaming-Chat-Completion aus, puffert Tool-Calls, leitet Inhalte an den Client weiter, liefert die zusammengesetzte Antwort. |
| `mergeToolCallDeltas` | `mergeToolCallDeltas(array &$toolCalls, array $deltas): void` | Führt gestreamte `tool_call`-Deltas in die akkumulierte Tool-Call-Liste zusammen. |

### Response-Details und Intelligence Upgrade

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `isOpenAiStrictMode` | `isOpenAiStrictMode(): bool` | Prüft, ob der strikte OpenAI-API-Kompatibilitätsmodus aktiv ist. |
| `formatIntelligenceLabel` | `formatIntelligenceLabel(float $value): string` | Formatiert einen Intelligenzwert als String (z. B. `"7b"`, `"3.5b"`). |
| `buildIntelligenceUpgradePayload` | `buildIntelligenceUpgradePayload(array $suggestion): array` | Baut das Upgrade-Angebot-Payload für die SSE-Ausgabe. |
| `emitIntelligenceUpgradeSse` | `emitIntelligenceUpgradeSse(?array $suggestion): void` | Sendet den Intelligence-Upgrade-Vorschlag als SSE-Event. |
| `buildResponseDetails` | `buildResponseDetails(array $endpoint): array` | Baut das Response-Detail-Objekt mit Endpunkt-Alias/URL-Label. |
| `emitResponseDetailsSse` | `emitResponseDetailsSse(?array $responseDetails): void` | Sendet die Response-Details als SSE-Event. |

### Logging und Hilfsfunktionen

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `getClientIp` | `getClientIp(): string` | Ermittelt die Client-IP (prüft zuerst `X-Forwarded-For`). |
| `getEndpointLogLabel` | `getEndpointLogLabel(array $endpoint): string` | Liefert das Anzeige-Label eines Endpunkts (Alias oder `base_url`). |
| `elapsedMilliseconds` | `elapsedMilliseconds(float $startedAt): int` | Liefert die vergangenen Millisekunden seit einem Zeitstempel. |
| `isTimeoutMessage` | `isTimeoutMessage(string $message): bool` | Prüft, ob eine Fehlermeldung auf einen Timeout hindeutet. |
| `logToolInvoked` | `logToolInvoked(string $toolName): void` | Protokolliert den Aufruf eines Tools durch das Modell. |
| `logToolResult` | `logToolResult(string $toolName, mixed $toolResult): void` | Protokolliert das Ergebnis eines Tool-Aufrufs (Erfolg oder Fehler). |
| `logResponseFinished` | `logResponseFinished(float $requestStart, ?int $promptTokens, ?int $completionTokens, ?float $tokensPerSecond = null): void` | Protokolliert den Abschluss einer Antwortgenerierung mit Zeit- und Tokenmetriken. |

---

## api/balancer.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `pickEndpointForModel` | `pickEndpointForModel(string $model, ?int $maxConcurrent = null, bool $requireToolCalling = false, bool $requireVision = false): ?array` | Wählt den besten verfügbaren LLM-Endpunkt für ein Modell, reserviert einen Task, liefert Endpunkt + `task_id` oder `null`. |
| `hasActiveEndpointForModel` | `hasActiveEndpointForModel(string $model, bool $requireVision = false): bool` | Prüft, ob mindestens ein aktiver Endpunkt für das Modell existiert. |
| `extractModelIntelligenceB` | `extractModelIntelligenceB(string $model): ?float` | Extrahiert einen Intelligenzwert aus gängigen Modellnamen (z. B. `1b`, `4b`, `27b`). |
| `getUpgradeModelSuggestionForRequestedModel` | `getUpgradeModelSuggestionForRequestedModel(string $requestedModel, string $detectedCategory = ''): ?array` | Liefert ein größeres Modell mit freier Kapazität für ein Intelligence-Upgrade, falls vorhanden. |
| `completeTask` | `completeTask(int $taskId, string $status = 'done', ?int $promptTokens = null, ?int $completionTokens = null, ?int $totalTokens = null, ?float $latencyMs = null, ?float $tokensPerSecond = null): void` | Markiert einen Task als abgeschlossen und speichert Tokenverbrauch/Latenzmetriken. |

## api/embedding.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `pickEmbeddingEndpoint` | `pickEmbeddingEndpoint(): ?array` | Wählt zufällig einen aktiven Embedding-Endpunkt aus der Datenbank. |
| `hasActiveEmbeddingEndpoint` | `hasActiveEmbeddingEndpoint(): bool` | Prüft, ob mindestens ein aktiver Embedding-Endpunkt konfiguriert ist. |
| `generateEmbedding` | `generateEmbedding(string $text, array $endpoint): ?array` | Ruft eine OpenAI-kompatible `/v1/embeddings`-API auf, liefert den Vektor oder `null` bei Fehler. |
| `generateEmbeddingAuto` | `generateEmbeddingAuto(string $text, string $type = 'query'): ?array` | Wählt automatisch einen Endpunkt und erzeugt ein Embedding. |
| `cosineSimilarity` | `cosineSimilarity(array $a, array $b): float` | Berechnet die Cosine-Similarity zweier Vektoren (-1.0 bis 1.0). |
| `embeddingFromJson` | `embeddingFromJson(?string $json): ?array` | Dekodiert ein gespeichertes JSON-Embedding in ein Float-Array. |
| `getCachedQueryEmbedding` | `getCachedQueryEmbedding(string $query, string $model): ?array` | Sucht ein gecachtes Embedding für Anfragetext und Modell. |
| `setCachedQueryEmbedding` | `setCachedQueryEmbedding(string $query, string $model, array $embedding): void` | Speichert ein Embedding im Cache und räumt opportunistisch alte Einträge auf. |
| `logEmbeddingRequest` | `logEmbeddingRequest(string $type, string $model, int $durationMs, ?float $similarity, bool $cacheHit, string $status): void` | Protokolliert eine Embedding-Anfrage-Metrik in `embedding_logs` (best effort). |
| `rerankDocuments` | `rerankDocuments(string $query, array $candidates, int $topK = 5): array` | Reranked Kandidaten-Chunks über eine OpenAI-kompatible Reranker-API mit Fallback. |
| `generateAndStoreChunkEmbeddings` | `generateAndStoreChunkEmbeddings(PDO $db, int $uploadId): void` | Erzeugt und speichert Embeddings für alle Chunks eines Uploads (nach dem Chunking). |

## api/upload_document.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `normalizeDocumentText` | `normalizeDocumentText(string $text): string` | Normalisiert Dokumenttext (entfernt überflüssige Leerzeichen/Leerzeilen). |
| `buildDocumentChunks` | `buildDocumentChunks(string $text, int $maxChars = 1800, int $overlapChars = 250): array` | Teilt Text in überlappende Chunks für RAG-Embedding und Suche. |
| `persistDocumentChunks` | `persistDocumentChunks(PDO $db, int $uploadId, int $userId, array $chunks): int` | Speichert Dokument-Chunks in der Datenbank, liefert die Anzahl gespeicherter Chunks. |
| `extractPdfText` | `extractPdfText(string $pdfPath): array` | Liest die Textebene eines PDFs mit `pdftotext`; Rückfallweg, wenn die Vision-Auswertung nicht möglich ist. |
| `analyzePdfWithVision` | `analyzePdfWithVision(string $pdfPath): array` | Rastert die PDF-Seiten zu JPEGs und lässt jede Seite einzeln vom Vision-Modell lesen; erzeugt Chunks mit Seitenreferenz und fällt pro Seite auf die Textebene zurück. |
| `resolveUploadKind` | `resolveUploadKind(string $mimeType, string $originalName): array` | Entscheidet anhand von Dateiendung und MIME-Typ zwischen `image`, `pdf` und `document`. |
| `failUpload` | `failUpload(PDO $db, int $uploadId, string $message): void` | Markiert einen Upload als fehlerhaft und beendet die Anfrage mit JSON-Fehler. |
| `finishUpload` | `finishUpload(PDO $db, int $uploadId, int $userId, string $text, array $chunks, string $message): void` | Speichert Text und Chunks, stößt die Embedding-Erzeugung an und antwortet mit dem Upload-Datensatz. |

## api/doc_convert.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `docConvertMimeMap` | `docConvertMimeMap(): array` | Zuordnung Dateiendung → erwarteter MIME-Typ der unterstützten Office-/Textformate. |
| `docConvertExtensions` | `docConvertExtensions(): array` | Liste aller vom Konverter unterstützten Dateiendungen. |
| `docConvertLocalFallbackExtensions` | `docConvertLocalFallbackExtensions(): array` | Endungen, die auch ohne den Dienst rein in PHP gelesen werden können. |
| `docConvertBaseUrl` | `docConvertBaseUrl(): string` | Basis-URL des Konverter-Dienstes (`DOCCONVERT_URL`). |
| `docConvertToken` | `docConvertToken(): string` | Optionales Shared Secret für den `X-Auth-Token`-Header. |
| `docConvertTimeout` | `docConvertTimeout(): int` | Timeout einer Konvertierungsanfrage in Sekunden. |
| `docConvertEnabled` | `docConvertEnabled(): bool` | Ob der Konverter konfiguriert ist (reine Konfigurationsprüfung, ohne Netzwerkzugriff). |
| `docConvertHealth` | `docConvertHealth(): array` | Fragt `GET /health` des Dienstes ab. |
| `convertDocumentViaService` | `convertDocumentViaService(string $path, string $originalName, string $mimeType): array` | Lädt die Datei zum Dienst hoch und liefert Text plus strukturbewusste Chunks. |
| `convertPlainTextLocally` | `convertPlainTextLocally(string $path, string $ext): array` | PHP-Fallback für reine Textformate (BOM-Entfernung, cp1252→UTF-8, HTML-Bereinigung). |

## api/pdf_render.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `pdfRunCommand` | `pdfRunCommand(string $cmd): array` | Führt ein externes Kommando aus und liefert Exit-Code, stdout und stderr. |
| `pdfRenderAvailable` | `pdfRenderAvailable(): bool` | Prüft, ob `pdftoppm` vorhanden ist. |
| `pdfVisionEnabled` | `pdfVisionEnabled(): bool` | Admin-Einstellung `pdf_vision_enabled`. |
| `pdfVisionDpi` | `pdfVisionDpi(): int` | Render-Auflösung (`pdf_vision_dpi`, 72–300). |
| `pdfVisionMaxPages` | `pdfVisionMaxPages(): int` | Maximal analysierte Seiten (`pdf_vision_max_pages`, 1–200). |
| `pdfPageCount` | `pdfPageCount(string $pdfPath): int` | Seitenzahl über `pdfinfo`. |
| `extractPdfPageText` | `extractPdfPageText(string $pdfPath, int $page): string` | Textebene einer einzelnen Seite. |
| `renderPdfPagesToImages` | `renderPdfPagesToImages(string $pdfPath, int $maxPages, int $dpi): array` | Rastert Seiten mit `pdftoppm` in ein temporäres Verzeichnis. |
| `cleanupPdfRenderDir` | `cleanupPdfRenderDir(string $dir): void` | Löscht ein temporäres Render-Verzeichnis samt Seitenbildern. |

## api/vision.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `visionModelName` | `visionModelName(): string` | Im Adminbereich konfiguriertes Vision-Modell. |
| `visionModelConfigured` | `visionModelConfigured(): bool` | Ob ein Vision-Modell hinterlegt ist. |
| `analyzeImageWithVision` | `analyzeImageWithVision(string $imagePath, string $mimeType, string $prompt = VISION_DEFAULT_PROMPT): array` | Schickt ein Bild an das Vision-Modell (inkl. Endpunktwahl über den Balancer und Task-Abrechnung) und liefert den extrahierten Text. |

## api/sd_balancer.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `pickSdEndpoint` | `pickSdEndpoint(string $mode = 'txt2img', ?int $maxConcurrent = null): ?array` | Wählt den besten verfügbaren Stable-Diffusion-Endpunkt für txt2img/img2img. |
| `completeSdTask` | `completeSdTask(int $taskId, string $status = 'done', ?float $latencyMs = null): void` | Markiert einen SD-Task als abgeschlossen und erfasst das Ergebnis für die Gesundheitsprüfung. |

## api/comfy_balancer.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `pickComfyEndpoint` | `pickComfyEndpoint(?int $maxConcurrent = null): ?array` | Wählt den besten verfügbaren ComfyUI-Endpunkt, reserviert einen Task. |
| `completeComfyTask` | `completeComfyTask(int $taskId, string $status = 'done', ?float $latencyMs = null): void` | Markiert einen ComfyUI-Task als abgeschlossen und erfasst das Ergebnis. |

## api/heartbeat.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `heartbeatClientIp` | `heartbeatClientIp(): ?string` | Ermittelt die wahrscheinliche Client-IP (prüft `X-Forwarded-For` bei Proxy-Betrieb). |

## api/reset_password.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `validatePassword` | `validatePassword(string $pass): bool` | Prüft ein Passwort gegen die Sicherheitsrichtlinie (≥8 Zeichen, Groß-/Kleinbuchstabe, Ziffer, Sonderzeichen). |
| `showPage` | `showPage(string $title, string $message, bool $success): void` | Rendert eine HTML-Seite für das Passwort-Reset-Formular bzw. eine Statusmeldung. |

## api/verify_email.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `showPage` | `showPage(string $title, string $message, bool $success): void` | Rendert eine HTML-Seite mit dem Status der E-Mail-Verifikation. |

## api/test_searxng.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `buildTestUrl` | `buildTestUrl(string $baseUrl): string\|false` | Baut eine Test-Such-URL für den SearXNG-Verbindungstest. |

## Weitere api/*.php ohne eigene Funktionen

Diese Dateien enthalten ausschließlich prozeduralen Code (kein top-level `function`):

| Datei | Zweck |
|---|---|
| `api/chat_sessions.php` | Sitzungsverwaltung: `action=list\|load\|delete` |
| `api/comfy_checkpoints.php` | Proxy für verfügbare ComfyUI-Checkpoints |
| `api/comfy_generate.php` | Bildgenerierung via ComfyUI (Workflow-Queueing/Polling) |
| `api/document_status.php` | Upload-Status als JSON (optional per `session_id` auf einen Chat gefiltert) |
| `api/document_delete.php` | Entfernt einen Upload samt Datei und Chunks |
| `api/healthcheck.php` | Ruft `isAnyLlmEndpointHealthy()` auf und liefert JSON |
| `api/models.php` | Proxy für `GET /v1/models` eines Endpunkts |
| `api/admin_user_action.php` | Admin-Benutzeraktionen (`create_user`, `send_password_reset`, `set_user_model`, `set_user_doc_permission`, `set_user_role`) |
| `api/rebuild_embeddings.php` | Neuberechnung von Chunk-Embeddings |
| `api/sd_checkpoints.php` | Proxy für `GET /sdapi/v1/sd-models` (AUTOMATIC1111) |
| `api/sd_generate.php` | Bildgenerierung via AUTOMATIC1111 |
| `api/test_ldap.php` | Ruft `ldapTestConnection()` auf |
| `api/test_smtp.php` | Ruft `sendMail()` zum Testversand auf |
| `api/openai_common/chat_completions.php` | Authentifiziert, normalisiert Payload, bindet `api/chat.php`-Logik ein |
| `api/openai_common/models.php` | Authentifiziert, ruft `openaiAvailableModels()` auf |
| `api/openai/v1/chat/completions/index.php` | Setzt `LLMINT_OPENAI_TOOL_MODE='disabled'`, bindet `openai_common/chat_completions.php` ein |
| `api/openai/v1/models/index.php` | Bindet `openai_common/models.php` ein |
| `api/openai-tools/v1/chat/completions/index.php` | Setzt `LLMINT_OPENAI_TOOL_MODE='enabled'`, bindet `openai_common/chat_completions.php` ein |
| `api/openai-tools/v1/models/index.php` | Bindet `openai_common/models.php` ein |

---

## admin/index.php

Überwiegend prozeduraler UI-Code (~7.000 Zeilen) mit `action`-basierten POST-Handlern.
Nur eine top-level Funktion:

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `modelIntelligenceLabel` | `modelIntelligenceLabel(string $model): string` | Liefert das menschenlesbare Intelligenz-Label eines Modells (`basic`/`standard`/`advanced`/`expert`). |

**Verarbeitete `action`-Werte (POST + CSRF):** `add_endpoint`, `update_endpoint`,
`delete_endpoint`, `reset_circuit`, `toggle_endpoint_pause`, `save_search_settings`,
`save_request_handling`, `save_new_user_model`, `save_balancer_settings`,
`save_streaming_settings`, `save_intelligence_group_settings`,
`save_global_system_prompt`, `save_system_messages`, `save_vision_settings`,
`save_smtp_settings`, `save_ldap_settings`, `add_sd_endpoint`, `update_sd_endpoint`,
`delete_sd_endpoint`, `add_comfy_endpoint`, `update_comfy_endpoint`,
`delete_comfy_endpoint`, `save_routing_settings`, `add_routing_category`,
`update_routing_category`, `delete_routing_category`, `import_prompt_txt`,
`save_log_config`, `add_embedding_endpoint`, `update_embedding_endpoint`,
`delete_embedding_endpoint`, `save_hybrid_search_settings`, `save_reranker_settings`,
`change_password`.

## admin/refresh_sys_stats.php

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `fetchSysStats` | `fetchSysStats(string $host, int $port, string $user, string $password): array` | Verbindet sich per SSH mit dem Endpunkt-Host, sammelt RAM/CPU/Temperatur-Metriken. |

## Weitere admin/*.php ohne eigene Funktionen

| Datei | Zweck |
|---|---|
| `admin/api_keys.php` | CRUD für OpenAI-kompatible API-Keys (erzeugen/aktivieren/löschen) |
| `admin/load_stats.php` | JSON-Livedaten für das Dashboard (Endpunktlast, Tokenverbrauch, aktive Clients, SD/ComfyUI-Zahlen) |
| `admin/login.php` | Anmeldung (LDAP/SSO/lokal) mit Sitzungsverwaltung |
| `admin/logout.php` | Beendet die Sitzung und leitet zum Login um |
| `admin/prompt_security.php` | Verwaltung der Prompt-Security-Regeln, -Logs und -Einstellungen (4 Tabs) |

---

## Siehe auch

- [`architecture.md`](architecture.md) – Systemarchitektur und Datenflüsse
- [`agent_index.md`](agent_index.md) – Schnellreferenz für Coding-Agenten
- [`../description.md`](../description.md) – Ausführliche Architektur- und Funktionsreferenz
