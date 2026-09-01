# agent_index.md — Agent Navigation Index

Quick-reference for coding agents to navigate the LLMInt codebase without reading every
file. For deeper detail see [`architecture.md`](architecture.md) (system design, data
flow, diagrams) and [`functions.md`](functions.md) (full function reference). The
German-language [`description.md`](../description.md) at the repository root remains
the original, prose-style architecture reference.

---

## Project Overview

**LLMInt** – A self-hosted PHP/MySQL chat interface for local LLMs (LM Studio, vLLM,
Ollama) with multi-model routing, load balancing, hybrid RAG, image generation
(AUTOMATIC1111/ComfyUI), prompt-injection protection, and an OpenAI-compatible API.

- **Language**: PHP 8.2+ (no framework, no Composer, no build step)
- **Database**: MySQL/MariaDB via PDO (`utf8mb4`)
- **Frontend**: Server-rendered HTML + inline CSS/Vanilla JS in `index.php` &
  `admin/index.php`
- **Architecture**: Each URL maps directly to a PHP file (no router)
- **Entry points**:
  - `index.php` – Chat UI (~3,500 lines)
  - `admin/index.php` – Admin dashboard (~7,000 lines)
  - `api/*.php` – JSON/SSE endpoints
  - `login.php`, `register.php`, `logout.php` – Auth
  - `setup.php` – Installer

---

## Core Files & Responsibilities

| File | Purpose |
|---|---|
| `db.php` | PDO singleton, `ensureRuntimeSchema()` (idempotent migrations), settings, logging, routing, chat sessions, intelligence groups |
| `config.php` | `LMSTUDIO_BASE_URL`, `LMSTUDIO_TIMEOUT` from active endpoint or settings |
| `setup.php` | One-time installer: tables, seed settings, default admin |
| `lib/balancer_engine.php` | Shared balancer logic (LLM, SD, ComfyUI): circuit breaker, fallbacks, health, orphan cleanup |
| `lib/healthcheck.php` | Active `/models` probing of LLM endpoints; drives `index.php` maintenance-mode fallback |
| `lib/prompt_security.php` | Prompt-injection detection: rules, normalization, scoring, AI evaluation, logging |
| `lib/openai_api.php` | OpenAI API key handling, payload normalization, error formatting |
| `lib/ldap_auth.php` | LDAP bind, user sync, Kerberos SSO |
| `lib/mailer.php` | Custom SMTP client (no PHPMailer) |
| `api/chat.php` | **Main chat pipeline**: prompt security → routing → balancer → tools (search, web fetch, RAG, image gen) → streaming → token accounting |
| `api/balancer.php` | `pickEndpointForModel()`, `completeTask()`, upgrade suggestions, model availability |
| `api/embedding.php` | Embeddings: generation, cache, cosine similarity, reranking, chunk embeddings |
| `api/upload_document.php` | File upload, format routing (docconvert / PDF→images+vision / vision), chunking |
| `api/doc_convert.php` | HTTP client + config for the `docconvert` Python service, plain-text PHP fallback |
| `api/pdf_render.php` | `pdftoppm`/`pdfinfo` wrappers: PDF pages → JPEG, per-page text layer |
| `api/vision.php` | `analyzeImageWithVision()` – shared vision-model call incl. balancer + task accounting |
| `docconvert/` | Python/FastAPI container converting Office & text files into structured chunks (TTL disk cache) |
| `admin/index.php` | Admin UI: endpoints, routing, balancer, RAG, LDAP/SMTP, users, logs |
| `admin/prompt_security.php` | Prompt security rule management |
| `admin/load_stats.php` | Live dashboard stats (JSON) |
| `admin/refresh_sys_stats.php` | SSH system metrics per endpoint |
| `admin/api_keys.php` | OpenAI-compatible API key management |

Full function-level detail for every file above: [`functions.md`](functions.md).

---

## Key Functions by Domain

See [`functions.md`](functions.md) for signatures and descriptions. Quick lookup by
domain:

- **Database & settings** (`db.php`): `getDb()`, `ensureRuntimeSchema()`,
  `getSetting()`/`setSetting()`, `writeLog()`, `loadRoutingCategories()`,
  `saveConversationSession()`, `listIntelligenceGroups()`,
  `resolveIntelligenceGroupModel()`, `resolveUserModel()`.
- **Chat pipeline** (`api/chat.php`): `streamChatCompletionRequest()` (main entry),
  `create*ToolDefinition()`, `runSearxngSearch()`, `fetchWebPage()`,
  `queryDocuments()`, `callSdGenerate()`, `callComfyGenerate()`, `emitSseData()`,
  `emitSyntheticStream()`, `estimateTokenCount()`, `resolveContextLimits()`,
  `logToolInvoked()`/`logToolResult()`/`logResponseFinished()`.
- **Balancer** (`lib/balancer_engine.php`, `api/balancer.php`):
  `pickEndpointForModel()`, `completeTask()`, `getFallbackChain()`,
  `recordEndpointOutcome()`, `maybeHalfOpenCircuit()`, `cleanupOrphanedTasks()`,
  `getUpgradeModelSuggestionForRequestedModel()`.
- **Healthcheck / maintenance mode** (`lib/healthcheck.php`): `probeLlmEndpoints()`,
  `isAnyLlmEndpointHealthy()`.
- **Embeddings** (`api/embedding.php`): `generateEmbeddingAuto()`,
  `cosineSimilarity()`, `getCachedQueryEmbedding()`/`setCachedQueryEmbedding()`,
  `rerankDocuments()`, `generateAndStoreChunkEmbeddings()`.
- **Prompt security** (`lib/prompt_security.php`): `psEvaluate()` (main entry),
  `psLoadRules()`, `psNormalise()`, `psMatchRules()`, `psComputeScore()`,
  `psAiEvaluate()`, `psDecide()`, `psLog()`.
- **Auth** (`lib/ldap_auth.php`, `login.php`, `register.php`): `ldapEnabled()`,
  `ldapSsoEnabled()`, `ldapAuthenticate()`, `ldapProvisionUser()`,
  `ldapTestConnection()`.
- **Image generation**: `pickSdEndpoint()`/`completeSdTask()` (`api/sd_balancer.php`),
  `pickComfyEndpoint()`/`completeComfyTask()` (`api/comfy_balancer.php`).
- **OpenAI-compatible API**: `api/openai/v1/**` (no tools), `api/openai-tools/v1/**`
  (with tools), shared via `api/openai_common/*.php` and `lib/openai_api.php`.

---

## Database Schema (Key Tables)

| Table | Purpose |
|---|---|
| `settings` | Key-value config (`setting_key`, `setting_value`) |
| `users` | Accounts: username, password hash, email, role (user/admin), auth_source, can_upload_documents, default_model |
| `api_keys` | OpenAI API key hashes per user |
| `endpoints` | LLM endpoints: base_url, default_model, timeout, is_active, capabilities, balancer health fields |
| `tasks` | LLM request lifecycle: endpoint_id, status, token counters |
| `endpoint_sys_stats` | SSH system metrics per endpoint |
| `sd_endpoints`, `sd_tasks` | AUTOMATIC1111 endpoints & jobs |
| `comfy_endpoints`, `comfy_tasks` | ComfyUI endpoints & jobs |
| `document_uploads`, `document_chunks` | Upload metadata and chunks with embeddings |
| `embedding_endpoints`, `embedding_cache`, `embedding_logs` | Embedding servers, cache, metrics |
| `conversation_sessions` | Chat history (session_id, messages JSON, model, upgrade_model, group_label) |
| `routing_categories`, `routing_rules` | Category definitions and category → model mapping |
| `search_logs` | SearXNG search history |
| `active_clients`, `client_count_log` | Heartbeat tracking |
| `app_logs` | Application log |
| `prompt_security_rules`, `prompt_security_logs` | Security rules and logged events |

Full data model description: [`architecture.md`](architecture.md#4-datenmodell-überblick).

---

## Important Patterns

1. **No router** – New API endpoints = new file in `api/` requiring `../db.php`.
2. **Settings** – All runtime config lives in the `settings` table; use
   `getSetting()`/`setSetting()`.
3. **Schema migrations** – Add `ALTER TABLE ... ADD COLUMN` inside
   `ensureRuntimeSchema()` wrapped in try/catch.
4. **SSE streaming** – Use `ensureSseHeaders()`, `emitSseData()`,
   `emitSyntheticStream()`.
5. **Balancer health** – Circuit breaker states: `closed`/`open`/`half_open`, driven by
   `consecutive_failures` and `cooldown_until`.
6. **Intelligence groups** – Model aliases like `@@35b` resolved via
   `resolveIntelligenceGroupModel()`.
7. **Prompt security** – Runs before routing; decisions: `allow`, `warn`, `block`.
8. **Tool calling** – Defined in `api/chat.php` via `create*ToolDefinition()`
   functions, executed in the tool loop inside `streamChatCompletionRequest()`'s
   caller.
9. **Session auth** – `$_SESSION['admin_id']`, `$_SESSION['admin_user']` set in entry
   points; CSRF via `$_SESSION['csrf_token']`.
10. **No new dependencies** unless strictly necessary; no build tools; all SQL via
    prepared PDO statements; escape output with `htmlspecialchars()`.

---

## Common Tasks & Where to Look

| Task | File(s) |
|---|---|
| Add new setting | `admin/index.php` (form + `action` handler), read via `getSetting()` at usage site |
| Add new table/column | `db.php` → `ensureRuntimeSchema()` (idempotent); `setup.php` for first-install tables |
| Add API endpoint | Create `api/new_endpoint.php`, `require_once __DIR__ . '/../db.php'` |
| Modify chat pipeline | `api/chat.php` (main), `api/balancer.php` (model selection) |
| Add tool/function | `api/chat.php` → new `create*ToolDefinition()` + handler in the tool-execution loop |
| Change balancer logic | `lib/balancer_engine.php` (shared), `api/balancer.php` (LLM-specific) |
| Modify prompt security | `lib/prompt_security.php` (logic), `admin/prompt_security.php` (UI) |
| Add embedding provider | `api/embedding.php` → `pickEmbeddingEndpoint()`, `generateEmbedding()` |
| Modify RAG/chunking | `api/upload_document.php` (routing/chunking), `docconvert/app/*.py` (format parsing), `api/embedding.php` (embeddings), `api/chat.php` → `queryDocuments()` |
| Add a document format | `docconvert/app/converters.py` (parser + `SUPPORTED_FORMATS`), `api/doc_convert.php` (MIME map), `index.php` (accept lists) |
| Admin UI changes | `admin/index.php` (main), `admin/prompt_security.php`, `admin/api_keys.php` |
| Auth changes | `login.php`, `register.php`, `lib/ldap_auth.php` |
| OpenAI API changes | `lib/openai_api.php`, `api/openai*/**` |
| Image generation | `api/sd_*.php`, `api/comfy_*.php`, `lib/balancer_engine.php` |

---

## Configuration Keys (from `settings` table)

| Key | Description |
|---|---|
| `default_model`, `new_user_default_model`, `vision_model` | Model defaults |
| `streaming_enabled` | Token-by-token streaming |
| `intelligence_group_enabled` | Enable `@@` prefix routing |
| `global_system_prompt`, `intelligence_upgrade_message` | Prompt content |
| `routing_decision_model` | Classifier model; categories in `routing_categories`, mapping in `routing_rules` |
| `balancer_max_concurrent`, `balancer_circuit_fail_threshold`, `balancer_circuit_cooldown_seconds`, `balancer_orphan_timeout_seconds`, `balancer_backoff_base_ms`, `balancer_backoff_max_ms`, `balancer_backoff_jitter`, `balancer_fallback_chains` | Balancer/resilience tuning |
| `embedding_enabled`, `embedding_model`, `embedding_timeout`, `embedding_cache_enabled`, `hybrid_search_enabled`, `bm25_weight`, `embedding_weight`, `reranker_enabled`, `reranker_endpoint`, `reranker_model`, `reranker_top_k` | RAG tuning |
| `pdf_vision_enabled`, `pdf_vision_dpi`, `pdf_vision_max_pages`, `upload_max_mb` | Document upload: PDF→image vision analysis and size limit |
| `searxng_base_url` | SearXNG instance URL |
| `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_user`, `smtp_pass` | Outbound mail |
| `ldap_enabled`, `ldap_host`, `ldap_port`, `ldap_use_ssl`, `ldap_domain`, `ldap_base_dn`, `ldap_bind_dn`, `ldap_bind_password`, `ldap_user_attr`, `ldap_email_attr`, `ldap_display_name_attr`, `ldap_sspi_enabled` | LDAP/AD & SSO |
| `log_level`, `log_retention_days` | Operations/logging |
| `prompt_security_*` | Prompt security activation, mode, thresholds, logging, AI classifier |

Legacy keys: `lmstudio_base_url`, `lmstudio_timeout`, `endpoints_bootstrapped`.

---

## Docker / Deployment

- `Dockerfile` – PHP 8.2 Apache with extensions (pdo_mysql, gd, curl, ldap, poppler
  for `pdftotext`, Kerberos components).
- `docker-compose.yml` – Services: `db` (MySQL 8.0), `web` (app), `phpmyadmin` (Basic
  Auth protected).
- Volumes: `doc_uploads/`, `sd_output/`, `db_data`.
- `.env.example` – Template for environment variables.

---

## Testing / Linting

- **No automated tests** in the repository.
- **Lint**: `php -l <file>` for syntax check.
- **Manual testing** via browser / curl against a running container.

---

## File Tree (Code Only)

```
/
├── index.php              # Chat UI
├── login.php / register.php / logout.php
├── setup.php              # Installer
├── config.php             # Constants from DB/env
├── db.php                 # Core DB + schema + helpers
├── description.md         # Detailed architecture/function reference (German)
├── README.md              # User docs
├── Demo.md                # Non-technical demo guide
├── docker-compose.yml / Dockerfile / .env.example
├── docs/
│   ├── agent_index.md     # This file
│   ├── architecture.md    # System architecture & diagrams
│   ├── functions.md       # Full function reference
│   └── images/            # Diagrams used in README.md
├── admin/
│   ├── index.php          # Admin dashboard
│   ├── prompt_security.php
│   ├── load_stats.php
│   ├── refresh_sys_stats.php
│   ├── api_keys.php
│   └── login.php / logout.php
├── api/
│   ├── chat.php           # Main pipeline
│   ├── balancer.php       # Model selection & completion
│   ├── embedding.php      # Embeddings & RAG
│   ├── upload_document.php
│   ├── doc_convert.php    # Client for the docconvert container
│   ├── pdf_render.php     # pdftoppm/pdfinfo helpers (PDF → page images)
│   ├── vision.php         # Shared vision-model image analysis
│   ├── chat_sessions.php
│   ├── models.php / heartbeat.php / healthcheck.php
│   ├── reset_password.php / verify_email.php / admin_user_action.php
│   ├── document_status.php / document_delete.php / rebuild_embeddings.php
│   ├── test_ldap.php / test_smtp.php / test_searxng.php
│   ├── sd_balancer.php / sd_generate.php / sd_checkpoints.php
│   ├── comfy_balancer.php / comfy_generate.php / comfy_checkpoints.php
│   └── openai/v1/**, openai-tools/v1/**, openai_common/**
├── lib/
│   ├── balancer_engine.php
│   ├── prompt_security.php
│   ├── openai_api.php
│   ├── ldap_auth.php
│   ├── mailer.php
│   ├── healthcheck.php
│   └── prompt.txt         # Fallback routing categories
├── doc_uploads/           # Runtime uploads (protected)
├── sd_output/             # Generated images (protected)
├── docker/                # Docker configs
├── ressources/            # Static assets
└── assets/                # More static assets
```

---

## Quick Start for Changes

1. **Read** [`architecture.md`](architecture.md) for the system design and diagrams.
2. **Look up** the relevant function(s) in [`functions.md`](functions.md).
3. **Find** the relevant file from the tables above.
4. **Modify** – follow existing patterns (no framework conventions).
5. **Schema changes** → add to `ensureRuntimeSchema()` in `db.php`.
6. **Test** with `php -l file.php` and manual browser test.

---

*Generated for agent-friendly navigation. Keep updated as codebase evolves.*
