# description.md – Architektur- und Funktionsreferenz

Dieses Dokument beschreibt LLMInt so genau, dass ein Coding-Agent Änderungen planen und
umsetzen kann, **ohne die gesamte Code-Basis zu scannen**. Es nennt zu jeder Funktion die
zuständige Datei, die relevanten Funktionsnamen, Tabellen und Einstellungsschlüssel.

Sprache im Code: Bezeichner überwiegend Englisch, UI-Texte, Log-Meldungen und Fehlermeldungen
auf Deutsch. Kommentare sind auf Englisch verfasst.

---

## 1. Kurzprofil

| Merkmal | Wert |
|---|---|
| Sprache/Laufzeit | PHP 8.2 (Docker-Image `php:8.2-apache`), mindestens PHP 8.0 |
| Framework | keines – reine PHP-Dateien, kein Composer, keine Autoloader, keine Build-Kette |
| Persistenz | MySQL/MariaDB über PDO (`utf8mb4`) |
| Frontend | serverseitig gerendertes HTML mit inline CSS und inline Vanilla-JS in `index.php` bzw. `admin/index.php`; keine JS-Abhängigkeiten, kein npm |
| Einstiegspunkte | `index.php` (Chat), `admin/index.php` (Administration), `api/*.php` (JSON/SSE) |
| Tests/Linting | im Repository nicht vorhanden; Prüfung erfolgt über `php -l` und manuelles Testen |
| Abhängigkeiten laden | ausschließlich `require_once __DIR__ . '/...'` |

**Wichtig für Änderungen:** Es gibt keinen Router. Jede URL entspricht direkt einer Datei.
Neue Endpunkte werden als neue Datei unter `api/` angelegt und binden `../db.php` ein.

---

## 2. Dateikarte

| Pfad | Zeilen (ca.) | Zuständigkeit |
|---|---:|---|
| `index.php` | 3.520 | Chat-Oberfläche: PHP-Bootstrap (Session, Einstellungen), CSS, HTML, gesamtes Frontend-JS |
| `db.php` | 1.774 | PDO-Verbindung, idempotentes Schema (`ensureRuntimeSchema`), Einstellungen, Logging, Routing-Stammdaten, Chat-Sessions, Intelligenzgruppen |
| `config.php` | 27 | Definiert `LMSTUDIO_BASE_URL` und `LMSTUDIO_TIMEOUT` aus erstem aktivem Endpunkt bzw. Einstellungen |
| `setup.php` | 345 | Installer: Tabellen, Migrationen, Seed-Einstellungen, Standardadministrator `admin/admin` |
| `login.php`, `logout.php`, `register.php` | 292/15/443 | Anmeldung (lokal, LDAP, SSO), Abmeldung, Selbstregistrierung mit E-Mail-Verifikation |
| `api/chat.php` | 3.339 | Zentrale Chat-Pipeline: Prompt Security, Routing, Balancer, Tools, Streaming, Upgrade, Token-Abrechnung |
| `api/balancer.php` | 337 | `pickEndpointForModel()`, `completeTask()`, Upgrade-Vorschlag, Modellverfügbarkeit |
| `api/embedding.php` | 541 | Embeddings erzeugen, Cache, Cosine-Similarity, Reranking, Chunk-Embeddings |
| `api/upload_document.php` | 488 | Datei-Upload, Textextraktion, Chunking, Vision-Analyse |
| `api/openai*/**` | – | OpenAI-kompatible Fassade (`v1/models`, `v1/chat/completions`) |
| `admin/index.php` | 7.047 | Administration: Dashboard, Endpunkte, Routing, Balancer, RAG, LDAP/SMTP, Benutzer, Logs |
| `admin/prompt_security.php` | 878 | Prompt-Security-Regeln, Logs und Einstellungen |
| `admin/load_stats.php` | 317 | JSON-Livedaten für das Dashboard |
| `admin/refresh_sys_stats.php` | 211 | SSH-Abfrage von RAM/CPU/Temperatur je Endpunkt |
| `admin/api_keys.php` | 196 | API-Keys für die OpenAI-kompatible API |
| `lib/balancer_engine.php` | 468 | Gemeinsame Balancer-Logik für LLM, AUTOMATIC1111 und ComfyUI |
| `lib/prompt_security.php` | 499 | Regelwerk, Normalisierung, Scoring, Entscheidung, Logging |
| `lib/openai_api.php` | 209 | API-Key-Handling, Payload-Normalisierung, Fehlerformat |
| `lib/ldap_auth.php` | 320 | LDAP-Bind, Benutzerabgleich, Kerberos-SSO |
| `lib/mailer.php` | 334 | Eigener SMTP-Client (kein PHPMailer) |
| `lib/prompt.txt` | – | Fallback-Kategorien/Prompt für das Routing, importierbar in die DB |
| `doc_uploads/`, `sd_output/` | – | Laufzeitdaten (per `.htaccess` geschützt), Docker-Volumes |
| `docker/`, `Dockerfile`, `docker-compose.yml` | – | Container-Setup inklusive phpMyAdmin mit Basic Auth |
| `README.md`, `Demo.md` | – | technische bzw. nicht-technische Dokumentation |

---

## 3. Bootstrapping und Konfiguration

1. Jede Datei bindet `db.php` ein. `getDb()` liefert eine PDO-Singleton-Verbindung aus den
   Umgebungsvariablen `DB_HOST` (Standard `localhost`), `DB_PORT` (`3306`), `DB_NAME`
   (`llmint`), `DB_USER` (`root`), `DB_PASS` (leer).
2. Beim ersten `getDb()`-Aufruf läuft `ensureRuntimeSchema(PDO $pdo)`: `CREATE TABLE IF NOT
   EXISTS` für alle Laufzeittabellen, `ALTER TABLE ... ADD COLUMN` in `try/catch` für
   Migrationen sowie Seeding von Routing-Kategorien und Prompt-Security-Regeln.
   **Konsequenz:** Schemaänderungen gehören in `ensureRuntimeSchema()` (idempotent), damit
   bestehende Installationen automatisch migrieren. `setup.php` enthält zusätzlich die
   Erstinstallation inklusive `users`-Tabelle und Standardadministrator.
3. `config.php` definiert `LMSTUDIO_BASE_URL` und `LMSTUDIO_TIMEOUT` aus dem ersten aktiven
   Endpunkt, ersatzweise aus den Einstellungen `lmstudio_base_url` / `lmstudio_timeout`.
4. Konfiguration zur Laufzeit liegt in der Tabelle `settings` und wird ausschließlich über
   `getSetting(string $key, string $default = '')` und `setSetting(string $key, string $value)`
   gelesen bzw. geschrieben.

---

## 4. Datenmodell

`setup.php` legt zusätzlich `users` an; alle übrigen Tabellen werden auch von
`ensureRuntimeSchema()` sichergestellt.

| Tabelle | Zweck |
|---|---|
| `settings` | Key-Value-Konfiguration (`setting_key`, `setting_value`) |
| `users` | Konten: `username`, `password_hash`, `email`, `email_verified`, `email_verification_token`, `password_reset_token`, `default_model`, `requires_password_change`, `can_upload_documents`, `role` (`user`/`admin`), `auth_source` (`local`/`ldap`), `ldap_dn`, `last_login` |
| `api_keys` | Hashes der OpenAI-kompatiblen API-Keys je Benutzer |
| `endpoints` | LLM-Endpunkte: `base_url`, `default_model`, `timeout`, `is_active`, Fähigkeiten (Tool Calling, Vision), Balancer-Gesundheit (`circuit_state`, `consecutive_failures`, `cooldown_until`, `avg_latency_ms`, `cost_weight`, `capacity_weight`) |
| `tasks` | Lebenszyklus jeder LLM-Anfrage: `endpoint_id`, `status` (`running`/`done`/`error`), Tokenzähler, `tokens_per_second` |
| `endpoint_sys_stats` | per SSH gelesene Systemmetriken je Endpunkt |
| `search_logs` | SearXNG-Suchen mit Status und Ergebnissen |
| `active_clients` | Heartbeat-Token aktiver Browser-Tabs (IP, Hostname, `last_seen`) |
| `client_count_log` | Zeitreihe gleichzeitiger Clients |
| `sd_endpoints`, `sd_tasks` | AUTOMATIC1111-Endpunkte und deren Aufträge |
| `comfy_endpoints`, `comfy_tasks` | ComfyUI-Endpunkte und deren Aufträge |
| `document_uploads` | Upload-Metadaten, Verarbeitungs- und Embedding-Status, `is_global_rag` |
| `document_chunks` | Chunks mit optionalem Embedding (`FK` auf `document_uploads`, `ON DELETE CASCADE`) |
| `embedding_endpoints` | Embedding-Server (`base_url`, `model`, `timeout`) |
| `embedding_cache` | zwischengespeicherte Query-Embeddings |
| `embedding_logs` | Laufzeit-/Trefferstatistik der Embedding-Aufrufe |
| `conversation_sessions` | Chatverläufe (`session_id`, `messages` als JSON, `model`, `upgrade_model`, `group_label`) |
| `routing_categories` | Kategoriedefinitionen inklusive Entscheidungsregel und Priorität |
| `routing_rules` | Zuordnung Kategorie → Zielmodell |
| `app_logs` | Anwendungslog (`info`/`warning`/`error`) |
| `prompt_security_rules` | Erkennungsregeln (Muster, Regex-Flag, Severity, Kategorie) |
| `prompt_security_logs` | Sicherheitsereignisse mit Score und Entscheidung |

---

## 5. Authentifizierung, Sitzungen und Rechte

- Angemeldete Benutzer werden über `$_SESSION['admin_user']` (Anzeigename) und
  `$_SESSION['admin_id']` (Benutzer-ID) identifiziert – auch für nicht-administrative Nutzer.
  `$_SESSION['requires_password_change']` erzwingt einen Passwortwechsel.
- Rollenprüfung ausschließlich über `currentUserRole()`, `isCurrentUserAdmin()` und die
  Guards `requireAdminOrRedirect()` (HTML) bzw. den JSON-Guard in den Admin-APIs.
- CSRF: `$_SESSION['csrf_token']` wird in `index.php` und `admin/index.php` erzeugt; alle
  Formulare und die Admin-/Upload-APIs prüfen das Feld `csrf_token`. `login.php` nutzt
  zusätzlich `$_SESSION['login_csrf']`.
- Anmeldereihenfolge in `login.php` und `admin/login.php`: Kerberos-SSO (`REMOTE_USER` über
  `ldapSsoEnabled()`/`ldapSsoUsername()`) → LDAP (`ldapAuthenticate()`, danach
  `ldapProvisionUser()`) → lokale Prüfung mit `password_verify()`.
- `register.php` erzeugt Verifikationstoken, versendet Mail über `sendMail()` und wird durch
  `api/verify_email.php` abgeschlossen; Passwort-Reset läuft über `api/reset_password.php`.
- Dokument-Upload erfordert `users.can_upload_documents = 1`.

---

## 6. Chat-Pipeline (`api/chat.php`)

Aufruf: `POST api/chat.php` mit JSON-Body. Antwort ist JSON oder – bei `stream: true` –
`text/event-stream`.

**Akzeptierte Body-Felder:** `model`, `messages`, `stream`, `temperature`, `max_tokens`,
`top_p`, `stop`, `stream_options`, `session_id`, `intelligence_group`, `force_search_query`,
`intelligence_upgrade_accepted`, `action` (`decline_intelligence_upgrade`).

**Ablauf:**

1. Session starten, Payload prüfen.
2. Prompt Security über `psEvaluate()`; bei `block` wird abgebrochen.
3. Intelligenzgruppe auswerten (`extractIntelligenceGroupPrefix()`,
   `resolveIntelligenceGroupModel()`, Persistenz per `setSessionIntelligenceGroup()`);
   anschließend Reasoning-Präfix auswerten (`extractReasoningPrefix()`, alternativ das Feld
   `reasoning` im Request). Ohne Aktivierung entfällt `reasoning_effort` im Forward-Payload
   und `chat_template_kwargs.enable_thinking` wird auf `false` gesetzt.
4. Optionales Routing: Entscheidungsmodell klassifiziert die letzte Nutzernachricht anhand
   von `buildRoutingPrompt()`; `loadRoutingRules()` bildet die Kategorie auf ein Modell ab.
5. Endpunktauswahl über `pickEndpointForModel()`; solange kein Slot frei ist, wird bei
   Streaming ein `{"status":"queued"}`-Frame gesendet und gewartet.
6. Kontextabschätzung (`estimateTokenCount()`, `resolveContextLimits()`); Überschreitung
   führt zu HTTP 413 bzw. einem Fehler-Frame.
7. Systemprompts zusammenführen (`mergeSystemMessages()`, `getGlobalSystemPrompt()`,
   `buildCurrentDateTimeSystemPrompt()`).
8. Tool-Definitionen ergänzen, sofern der Endpunkt Tool Calling unterstützt.
9. Anfrage an den Endpunkt (`streamChatCompletionRequest()`); Tool-Calls werden in einer
   Schleife ausgeführt und als `tool`-Nachrichten zurückgespielt.
10. Fehlerbehandlung: `recordEndpointOutcome()`, `backoffSleep()`, weiterer Endpunkt,
    danach `getFallbackChain()`.
11. Abschluss: `completeTask()` mit Tokenzahlen und `tokens_per_second`,
    `saveConversationSession()`, Upgrade-Vorschlag und Antwortdetails.

### 6.1 Tools für das LLM

| Tool | Voraussetzung | Parameter |
|---|---|---|
| `search_web` | `searxng_base_url` gesetzt | `query` (erforderlich) |
| `web_fetch` | wie `search_web` | `url` (erforderlich), `max_chars` (500–20000, Standard 6000) |
| `generate_image` | aktive `sd_endpoints` | `prompt` (erforderlich), `negative_prompt`, `width`, `height` |
| `generate_image_comfy` | aktive `comfy_endpoints` | wie `generate_image` |
| `query_documents` | vorhandene Uploads | `query` (erforderlich) |

Neue Tools benötigen jeweils eine `create...ToolDefinition()`-Funktion, eine Verfügbarkeits-
prüfung und einen Zweig in der Tool-Ausführungsschleife von `api/chat.php`.

### 6.2 SSE-Protokoll

Alle Frames werden über `emitSseData()` als `data: <json>` gesendet.

| Frame | Inhalt |
|---|---|
| OpenAI-Chunk | `{id, object:"chat.completion.chunk", created, model, choices:[{index, delta, finish_reason}]}` – `delta.content` bzw. `delta.reasoning_content` |
| Warteschlange | `{status:"queued", message:"..."}` |
| Fehler | `{error:"..."}` |
| Upgrade-Angebot | `{type:"intelligence_upgrade", upgrade:{...}}` |
| Antwortdetails | `{type:"response_details", details:{...}}` mit Endpunkt, Dauer, Suchquellen und Kontextauslastung |
| Ende | `[DONE]` |

Im OpenAI-Strict-Modus (`isOpenAiStrictMode()`, gesetzt durch die OpenAI-Fassaden) entfallen
die LLMInt-spezifischen Frames.

---

## 7. Balancer

`lib/balancer_engine.php` ist die gemeinsame Basis für LLM-, AUTOMATIC1111- und
ComfyUI-Endpunkte; die Tabellen werden als Parameter übergeben.

Funktionen: `ensureBalancerHealthColumns()`, `getBalancerMaxConcurrent()`,
`getBalancerCircuitFailThreshold()`, `getBalancerCircuitCooldownSeconds()`,
`getBalancerOrphanTimeoutSeconds()`, `computeBackoffDelayMs()`, `backoffSleep()`,
`getFallbackChain()`, `saveFallbackChains()`, `recordEndpointOutcome()`,
`resetEndpointCircuit()`, `setEndpointPaused()`, `balancerCircuitWhereClause()`,
`maybeHalfOpenCircuit()`, `cleanupOrphanedTasks()`.

Auswahl in `pickEndpointForModel()` (`api/balancer.php`):

1. nur aktive Endpunkte mit passendem `default_model` (exakt oder funktional
   äquivalent gemäß `equivalentActiveModelNames()`/`canonicalModelName()` in
   `db.php` – Endpunkte, die dasselbe Modell nur unter anderem Pfad/anderer
   Quantisierung anbieten, werden zu einem gemeinsamen Routing-Pool
   zusammengefasst), geschlossenem bzw. abgelaufenem
   Circuit und optional geforderten Fähigkeiten (Tool Calling, Vision),
2. Bewertung aus normalisierter Auslastung (`capacity_weight`), geglätteter Latenz und
   `cost_weight` mit den Gewichten `balancer_weight_capacity`, `balancer_weight_latency`,
   `balancer_weight_cost`,
3. Fairness-Tiebreaker über die älteste Zuweisung,
4. Reservierung in einer Transaktion mit `SELECT ... FOR UPDATE` und erneuter Kapazitäts-
   prüfung, anschließend `INSERT` in `tasks` mit Status `running`.

Abschluss über `completeTask()`; Bildpfade nutzen `pickSdEndpoint()`/`completeSdTask()`
bzw. `pickComfyEndpoint()`/`completeComfyTask()`.

---

## 8. Hybrid-RAG

- Upload: `api/upload_document.php` prüft Session, `can_upload_documents`, CSRF und den
  MIME-Typ (Text/Markdown, PDF, PNG/JPG/WEBP/GIF), speichert nach `doc_uploads/`, extrahiert
  Text (PDF über `pdftotext`, Bilder optional über das Vision-Modell) und legt Chunks an.
- Embeddings: `generateAndStoreChunkEmbeddings()`, `generateEmbeddingAuto()`,
  `pickEmbeddingEndpoint()`, `getCachedQueryEmbedding()`/`setCachedQueryEmbedding()`.
- Suche: `queryDocuments()` in `api/chat.php` kombiniert BM25-artiges Scoring
  (`tokenizeQueryTerms()`, `scoreRagChunk()`, `buildRagSnippet()`) mit `cosineSimilarity()`,
  Reciprocal Rank Fusion und optionalem `rerankDocuments()`.
- Statusabfrage im Frontend: `api/document_status.php`; Neuberechnung über
  `api/rebuild_embeddings.php`.

Einstellungen: `embedding_enabled`, `embedding_model`, `embedding_timeout`,
`embedding_cache_enabled`, `hybrid_search_enabled`, `bm25_weight`, `embedding_weight`,
`reranker_enabled`, `reranker_endpoint`, `reranker_model`, `reranker_top_k`.

---

## 9. Prompt Security

`lib/prompt_security.php` wird von `api/chat.php` vor dem Modellaufruf verwendet.

Kette: `psLoadRules()` → `psNormalise()` (Zero-Width-Zeichen, HTML, URL-/Base64-Dekodierung)
→ `psMatchRules()` → `psComputeScore()` → optional `psAiEvaluate()` und `psAiLabelToScore()`
→ `psDecide()` (`allow` / `warn` / `block`) → `psLog()`; `psPurgeLogs()` räumt auf.

Verwaltung: `admin/prompt_security.php` (Dashboard, Regeln, Logs, Einstellungen).

---

## 10. Frontend `index.php`

- Aufbau: PHP-Bootstrap (Session, Einstellungen, Modellliste) → `<style>` → HTML → `<script>`.
- Chat senden: `sendMessage()` baut das Nachrichtenarray (inklusive Bildanhängen als
  `image_url`-Parts) und ruft `executeStreamingRequest()` auf, das per `fetch('api/chat.php')`
  mit `Content-Type: application/json` sendet und den Body über `getReader()` liest.
- SSE-Verarbeitung: `processSseLine()`, Darstellung über `updateStreamingBubble()`,
  `renderBubbleContent()`, `renderMarkdown()` (eigener Markdown-Renderer inklusive
  Tabellen, Codeblöcken und `renderMath()`/`extractMath()`/`reinsertMath()`).
- Zusatzanzeigen: `setSourcePillsForBubble()` (Web-Quellen), `setResponseDetailsForBubble()`
  und `buildContextCircleHtml()` (Kontextauslastung), `showUpgradePrompt()` (Intelligence
  Upgrade), `thinkingRobotHtml()`/`tickThinkingRobot()` (Wartezustand).
- Sitzungen: `generateSessionId()`, `refreshSessionList()`, `loadSession()`,
  `restoreCurrentSession()`, `deleteSession()`, `startNewChat()` gegen
  `api/chat_sessions.php?action=list|load|delete`.
- Intelligenzgruppen: `applyGroupPrefixFromInput()`, `renderGroupPill()`, `setActiveGroup()`,
  `removeActiveGroup()`.
- Reasoning: `applyReasoningPrefixFromInput()`, `renderReasoningPill()`; das `!!`-Präfix
  aktiviert Reasoning für genau einen Prompt und wird als 💡-Pille dargestellt.
- Dokumente: `openUploadModal()`, `setFile()`, Upload per `FormData` inklusive `csrf_token`
  an `api/upload_document.php`, Statusanzeige über `loadStatus()`/`renderUploads()`.
- Präsenz: `sendHeartbeat()` gegen `api/heartbeat.php`.

---

## 11. Administration

`admin/index.php` verarbeitet alle Änderungen als POST mit `action`-Feld und CSRF-Token.
Verfügbare Aktionen:

`add_endpoint`, `update_endpoint`, `delete_endpoint`, `reset_circuit`,
`toggle_endpoint_pause`, `save_search_settings`, `save_request_handling`,
`save_new_user_model`, `save_balancer_settings`, `save_streaming_settings`,
`save_intelligence_group_settings`, `save_global_system_prompt`, `save_system_messages`,
`save_vision_settings`, `save_smtp_settings`, `save_ldap_settings`, `add_sd_endpoint`,
`update_sd_endpoint`, `delete_sd_endpoint`, `add_comfy_endpoint`, `update_comfy_endpoint`,
`delete_comfy_endpoint`, `save_routing_settings`, `add_routing_category`,
`update_routing_category`, `delete_routing_category`, `import_prompt_txt`, `save_log_config`,
`add_embedding_endpoint`, `update_embedding_endpoint`, `delete_embedding_endpoint`,
`save_hybrid_search_settings`, `save_reranker_settings`, `change_password`.

Die Oberfläche ist in Karten mit stabilen IDs gegliedert, unter anderem `dashboard-card`,
`config-endpoints-card`, `config-request-handling-card`, `config-balancer-card`,
`config-routing-card`, `config-decision-card`, `config-sd-card`, `config-comfy-card`,
`config-embedding-card`, `config-hybrid-search-card`, `config-reranker-card`,
`embedding-stats-card`, `config-global-system-prompt-card`, `config-system-messages-card`,
`config-smtp-card`, `config-ldap-card`, `config-searxng-card`, `log-config-card`,
`log-viewer-card`, `users-card`, `password-card`.

Ergänzende Dateien: `admin/load_stats.php` (Livedaten für das Dashboard),
`admin/refresh_sys_stats.php` (SSH-Metriken), `admin/api_keys.php` (API-Keys),
`admin/prompt_security.php` (Sicherheitsmodul).

---

## 12. HTTP-Endpunkte

| Pfad | Methode | Auth | Zweck |
|---|---|---|---|
| `api/chat.php` | POST | Session optional | Chat inklusive Routing, Tools, Streaming |
| `api/chat_sessions.php` | GET/POST | Session | `action=list\|load\|delete` |
| `api/models.php` | GET | – | Modelle eines Endpunkts abfragen |
| `api/heartbeat.php` | POST | – | Präsenz-Token melden |
| `api/document_status.php` | GET | Session | Upload-Status des Benutzers |
| `api/upload_document.php` | POST | Session + CSRF | Dokument-Upload |
| `api/rebuild_embeddings.php` | POST | Admin + CSRF | Embeddings neu berechnen |
| `api/sd_generate.php`, `api/comfy_generate.php` | POST | Session | Bildgenerierung |
| `api/sd_checkpoints.php`, `api/comfy_checkpoints.php` | GET | – | verfügbare Checkpoints |
| `api/test_searxng.php`, `api/test_ldap.php`, `api/test_smtp.php` | GET/POST | Admin | Verbindungstests |
| `api/admin_user_action.php` | POST | Admin + CSRF | Benutzerverwaltung |
| `api/verify_email.php`, `api/reset_password.php` | GET/POST | Token | E-Mail-Verifikation, Passwort-Reset |
| `api/openai/v1/models`, `api/openai/v1/chat/completions` | GET/POST | API-Key | OpenAI-kompatibel, ohne Tools |
| `api/openai-tools/v1/models`, `api/openai-tools/v1/chat/completions` | GET/POST | API-Key | OpenAI-kompatibel, mit Tools |

`api/balancer.php`, `api/sd_balancer.php`, `api/comfy_balancer.php` und `api/embedding.php`
sind reine Bibliotheken und werden eingebunden, nicht direkt aufgerufen.

---

## 13. Wichtige Einstellungsschlüssel

| Bereich | Schlüssel |
|---|---|
| Modelle/Anfragen | `default_model`, `new_user_default_model`, `vision_model`, `streaming_enabled`, `intelligence_group_enabled`, `global_system_prompt`, `intelligence_upgrade_message` |
| Routing | `routing_decision_model`, Kategorien in `routing_categories`, Zuordnungen in `routing_rules` |
| Balancer | `balancer_max_concurrent`, `balancer_circuit_fail_threshold`, `balancer_circuit_cooldown_seconds`, `balancer_orphan_timeout_seconds`, `balancer_backoff_base_ms`, `balancer_backoff_max_ms`, `balancer_backoff_jitter`, `balancer_weight_latency`, `balancer_weight_cost`, `balancer_weight_capacity`, `balancer_fallback_chains` |
| RAG | `embedding_enabled`, `embedding_model`, `embedding_timeout`, `embedding_cache_enabled`, `hybrid_search_enabled`, `bm25_weight`, `embedding_weight`, `reranker_enabled`, `reranker_endpoint`, `reranker_model`, `reranker_top_k` |
| Integrationen | `searxng_base_url`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_user`, `smtp_pass`, `ldap_enabled`, `ldap_host`, `ldap_port`, `ldap_use_ssl`, `ldap_domain`, `ldap_base_dn`, `ldap_bind_dn`, `ldap_bind_password`, `ldap_user_attr`, `ldap_email_attr`, `ldap_display_name_attr`, `ldap_sspi_enabled` |
| Betrieb | `log_level`, `log_retention_days`, `login_banner_enabled`, `login_banner_text`, `registration_email_subject`, `registration_email_body` |
| Prompt Security | Schlüssel mit Präfix `prompt_security_` (Aktivierung, Modus, Schwellwerte, Logging, KI-Klassifikator) |

Legacy-Schlüssel: `lmstudio_base_url`, `lmstudio_timeout`, `endpoints_bootstrapped`.

---

## 14. Betrieb und Container

- `Dockerfile`: Basis `php:8.2-apache`, installiert `pdo_mysql`, `curl`, `mbstring`,
  `fileinfo`, LDAP, XML/ZIP, Intl, Poppler (`pdftotext`) sowie Kerberos-Komponenten;
  `ENTRYPOINT` ist `docker/entrypoint.sh` (wartet auf die Datenbank, ruft `setup.php` auf).
- `docker-compose.yml`: Dienste `db` (MySQL 8.0 mit Healthcheck), `web`
  (Port `HTTP_PORT`, Standard 8080) und `phpmyadmin` (Port `PMA_PORT`, Standard 8081, durch
  HTTP Basic Auth geschützt). Volumes: `db_data`, `doc_uploads`, `sd_output`.
- `.env.example`: `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_ROOT_PASS`, `HTTP_PORT`, `PMA_PORT`,
  `PMA_BASIC_AUTH_USER`, `PMA_BASIC_AUTH_PASSWORD`, `TZ`.

---

## 15. Leitfaden für typische Änderungen

| Vorhaben | Betroffene Stellen |
|---|---|
| Neue Einstellung | Formular und `action`-Zweig in `admin/index.php`, Lesen per `getSetting()` an der Nutzungsstelle, Dokumentation in `README.md` |
| Neue Tabelle oder Spalte | `ensureRuntimeSchema()` in `db.php` (idempotent), bei Erstinstallation zusätzlich `setup.php` |
| Neues LLM-Tool | Definition, Verfügbarkeitsprüfung und Ausführungszweig in `api/chat.php`; bei Bedarf Anzeige im Frontend |
| Neuer HTTP-Endpunkt | neue Datei unter `api/`, `require_once __DIR__ . '/../db.php'`, Auth-/CSRF-Prüfung analog zu bestehenden Dateien |
| Änderung an der Endpunktauswahl | `lib/balancer_engine.php` und `pickEndpointForModel()` in `api/balancer.php`; Bildpfade nutzen dieselbe Engine |
| Frontend-Anpassung | `index.php`; JS und CSS liegen inline, keine Build-Schritte |
| Neue Sicherheitsregel | Seed in `ensureRuntimeSchema()` oder Pflege über `admin/prompt_security.php` |

**Konventionen:** keine neuen Abhängigkeiten ohne Not, keine Build-Werkzeuge, alle
SQL-Zugriffe über vorbereitete PDO-Statements, Ausgaben mit `htmlspecialchars()` escapen,
neue Fehler- und Statusmeldungen auf Deutsch, Logging über `writeLog('info'|'warning'|'error', ...)`.
Syntaxprüfung mit `php -l <datei>`.
