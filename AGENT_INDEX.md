# LLMInt Agent Index

Quick-reference for coding agents to navigate the codebase without reading all files.

---

## Project Overview

**LLMInt** – A self-hosted PHP/MySQL chat interface for local LLMs (LM Studio, vLLM, Ollama) with multi-model routing, load balancing, RAG, image generation (SD/ComfyUI), and OpenAI-compatible API.

- **Language**: PHP 8.2+ (no framework, no Composer, no build step)
- **Database**: MySQL/MariaDB via PDO (utf8mb4)
- **Frontend**: Server-rendered HTML + inline CSS/Vanilla JS in `index.php` & `admin/index.php`
- **Architecture**: Each URL maps directly to a PHP file (no router)
- **Entry Points**:
  - `index.php` – Chat UI (3,520 lines)
  - `admin/index.php` – Admin dashboard (7,047 lines)
  - `api/*.php` – JSON/SSE endpoints
  - `login.php`, `register.php`, `logout.php` – Auth
  - `setup.php` – Installer

---

## Core Files & Responsibilities

| File | Lines | Purpose |
|------|-------|---------|
| **db.php** | 1,774 | PDO singleton, `ensureRuntimeSchema()` (idempotent migrations), settings, logging, routing, chat sessions, intelligence groups |
| **config.php** | 27 | `LMSTUDIO_BASE_URL`, `LMSTUDIO_TIMEOUT` from active endpoint or settings |
| **lib/balancer_engine.php** | 468 | Shared balancer logic (LLM, SD, ComfyUI): circuit breaker, fallbacks, health, orphan cleanup |
| **lib/prompt_security.php** | 499 | Prompt injection detection: rules, normalization, scoring, AI evaluation, logging |
| **lib/openai_api.php** | 209 | OpenAI API key handling, payload normalization, error formatting |
| **lib/ldap_auth.php** | 320 | LDAP bind, user sync, Kerberos SSO |
| **lib/mailer.php** | 334 | Custom SMTP client (no PHPMailer) |
| **api/chat.php** | 3,339 | **Main chat pipeline**: prompt security → routing → balancer → tools (search, web, RAG, image gen) → streaming → token accounting |
| **api/balancer.php** | 337 | `pickEndpointForModel()`, `completeTask()`, upgrade suggestions, model availability |
| **api/embedding.php** | 541 | Embeddings: generation, cache, cosine similarity, reranking, chunk embeddings |
| **api/upload_document.php** | 488 | File upload, text extraction, chunking, vision analysis |
| **admin/index.php** | 7,047 | Admin UI: endpoints, routing, balancer, RAG, LDAP/SMTP, users, logs |
| **admin/prompt_security.php** | 878 | Prompt security rule management |
| **admin/load_stats.php** | 317 | Live dashboard stats (JSON) |
| **admin/refresh_sys_stats.php** | 211 | SSH system metrics per endpoint |
| **admin/api_keys.php** | 196 | OpenAI-compatible API key management |

---

## Key Functions by Domain

### Database & Settings (`db.php`)
- `getDb(): PDO` – Singleton connection
- `ensureRuntimeSchema(PDO): void` – **All schema changes go here** (idempotent)
- `getSetting(key, default): string` / `setSetting(key, value): void`
- `writeLog(level, message): void`
- `loadRoutingCategories(): array`, `saveRoutingCategory()`, `deleteRoutingCategory()`
- `saveConversationSession()`, `loadConversationSession()`, `setSessionUpgradeModel()`
- `listIntelligenceGroups()`, `resolveIntelligenceGroupModel()`, `extractIntelligenceGroupPrefix()`
- `modelIntelligenceScore(model): ?float`, `resolveUserModel(preferred): string`

### Chat Pipeline (`api/chat.php`)
- `streamChatCompletionRequest()` – **Main entry** (line 1612)
- `createSearchToolDefinition()`, `createWebFetchToolDefinition()`, `createImageGenerationToolDefinition()`, `createDocumentQueryToolDefinition()`
- `runSearxngSearch()`, `fetchWebPage()`, `queryDocuments()`
- `callSdGenerate()`, `callComfyGenerate()`
- `hasSdEndpoints()`, `hasComfyEndpoints()`
- `emitSseData()`, `emitSyntheticStream()`, `emitResponseDetailsSse()`, `emitIntelligenceUpgradeSse()`
- `estimateTokenCount()`, `resolveContextLimits()`, `extractUsage()`
- `logToolInvoked()`, `logToolResult()`, `logResponseFinished()`, `computeTokensPerSecond()`

### Balancer (`lib/balancer_engine.php`, `api/balancer.php`)
- `pickEndpointForModel(model, requireVision, allowFallback): ?array`
- `completeTask(table, taskId, status, latencyMs): void`
- `getFallbackChain(model): array`, `saveFallbackChains(map): void`
- `recordEndpointOutcome(table, endpointId, success, latencyMs): void`
- `maybeHalfOpenCircuit(table, endpointId): void`
- `cleanupOrphanedTasks(tasksTable, force): int`
- `getUpgradeModelSuggestionForRequestedModel(requested, category): ?array`

### Embeddings (`api/embedding.php`)
- `generateEmbeddingAuto(text, type): ?array`
- `cosineSimilarity(a, b): float`
- `getCachedQueryEmbedding()`, `setCachedQueryEmbedding()`
- `rerankDocuments(query, candidates, topK): array`
- `generateAndStoreChunkEmbeddings(db, uploadId): void`

### Prompt Security (`lib/prompt_security.php`)
- `psEvaluate(input): array` – **Main entry** (returns decision: allow/block/log)
- `psLoadRules(): array`, `psNormalise(input): array`, `psMatchRules(): array`
- `psComputeScore(matched): int`, `psAiEvaluate(normalised): ?string`
- `psDecide(score): string`, `psLog(...): void`

### Auth (`lib/ldap_auth.php`, `login.php`, `register.php`)
- `ldapEnabled(): bool`, `ldapSsoEnabled(): bool`, `ldapSsoUsername(): string`
- `ldapAuthenticate(username, password): ?array`
- `ldapProvisionUser(info): ?int`
- `ldapTestConnection(...): array`

### Image Generation
- **SD** (`api/sd_balancer.php`, `api/sd_generate.php`, `api/sd_checkpoints.php`)
  - `pickSdEndpoint(mode, maxConcurrent): ?array`
  - `completeSdTask(taskId, status, latencyMs): void`
- **ComfyUI** (`api/comfy_balancer.php`, `api/comfy_generate.php`, `api/comfy_checkpoints.php`)
  - `pickComfyEndpoint(maxConcurrent): ?array`
  - `completeComfyTask(taskId, status, latencyMs): void`

### OpenAI-Compatible API
- `api/openai/v1/chat/completions/index.php` – Strict mode, no tools
- `api/openai-tools/v1/chat/completions/index.php` – Tools enabled
- `api/openai_common/chat_completions.php` – Shared logic
- `lib/openai_api.php` – Auth, normalization, errors

### Admin Helpers
- `admin/load_stats.php` – JSON stats for dashboard
- `admin/refresh_sys_stats.php` – `fetchSysStats(host, port, user, pass): array`
- `admin/refresh_sys_stats.php` – SSH metrics collection

---

## Database Schema (Key Tables)

| Table | Purpose |
|-------|---------|
| `settings` | Key-value config (`setting_key`, `setting_value`) |
| `users` | Accounts: `username`, `password_hash`, `email`, `role` (user/admin), `auth_source`, `can_upload_documents`, `default_model` |
| `api_keys` | OpenAI API key hashes per user |
| `endpoints` | LLM endpoints: `base_url`, `default_model`, `timeout`, `is_active`, `specialized_for_category`, balancer health fields |
| `tasks` | LLM request lifecycle: `endpoint_id`, `status`, token counters |
| `endpoint_sys_stats` | SSH system metrics per endpoint |
| `sd_endpoints`, `sd_tasks` | AUTOMATIC1111 endpoints & jobs |
| `comfy_endpoints`, `comfy_tasks` | ComfyUI endpoints & jobs |
| `document_uploads` | Upload metadata, processing/embedding status, `is_global_rag` |
| `document_chunks` | Chunks with embeddings (FK → document_uploads, CASCADE) |
| `embedding_endpoints` | Embedding servers: `base_url`, `model`, `timeout` |
| `embedding_cache` | Cached query embeddings |
| `search_logs` | SearXNG search history |
| `active_clients`, `client_count_log` | Heartbeat tracking |

---

## Important Patterns

1. **No Router** – New API endpoints = new file in `api/` requiring `../db.php`
2. **Settings** – All runtime config in `settings` table; use `getSetting()`/`setSetting()`
3. **Schema Migrations** – Add `ALTER TABLE ... ADD COLUMN` in `ensureRuntimeSchema()` with try/catch
4. **SSE Streaming** – Use `ensureSseHeaders()`, `emitSseData()`, `emitSyntheticStream()`
5. **Balancer Health** – Circuit breaker: `circuit_state` (open/closed/half-open), `consecutive_failures`, `cooldown_until`
6. **Intelligence Groups** – Model aliases like `@@35b` resolved via `resolveIntelligenceGroupModel()`
7. **Prompt Security** – Runs before routing; decisions: `allow`, `block`, `log_only`
8. **Tool Calling** – Defined in `api/chat.php` via `create*ToolDefinition()` functions
9. **Session Auth** – `$_SESSION['admin_id']`, `$_SESSION['admin_user']` set in entry points

---

## Common Tasks & Where to Look

| Task | File(s) |
|------|---------|
| Add new setting | `db.php` → `ensureRuntimeSchema()` (if new table) or just use `setSetting()` |
| Add API endpoint | Create `api/new_endpoint.php`, require `../db.php` |
| Modify chat pipeline | `api/chat.php` (main), `api/balancer.php` (model selection) |
| Add tool/function | `api/chat.php` → new `createXxxToolDefinition()` + handler in `streamChatCompletionRequest()` |
| Change balancer logic | `lib/balancer_engine.php` (shared), `api/balancer.php` (LLM-specific) |
| Modify prompt security | `lib/prompt_security.php` (logic), `admin/prompt_security.php` (UI) |
| Add embedding provider | `api/embedding.php` → `pickEmbeddingEndpoint()`, `generateEmbedding()` |
| Modify RAG/chunking | `api/upload_document.php` (chunking), `api/embedding.php` (embeddings), `api/chat.php` (query) |
| Admin UI changes | `admin/index.php` (main), `admin/prompt_security.php`, `admin/api_keys.php` |
| Auth changes | `login.php`, `register.php`, `lib/ldap_auth.php` |
| OpenAI API changes | `lib/openai_api.php`, `api/openai*/**` |
| Image generation | `api/sd_*.php`, `api/comfy_*.php`, `lib/balancer_engine.php` |

---

## Configuration Keys (from `settings` table)

| Key | Default | Description |
|-----|---------|-------------|
| `default_model` | '' | Fallback model name |
| `lmstudio_base_url` | '' | Legacy LM Studio URL |
| `lmstudio_timeout` | '120' | Request timeout |
| `streaming_enabled` | '1' | Token-by-token streaming |
| `vision_model` | '' | Vision model for image analysis |
| `login_banner_enabled` | '0' | Show banner on login |
| `login_banner_text` | '' | Banner message |
| `intelligence_group_enabled` | '1' | Enable @@ prefix routing |
| `rag_enabled` | '1' | Enable document retrieval |
| `search_enabled` | '1' | Enable web search (SearXNG) |
| `searxng_base_url` | '' | SearXNG instance URL |
| `embedding_endpoint_url` | '' | Embedding server URL |
| `embedding_model` | '' | Embedding model name |
| `balancer_strategy` | 'latency' | `latency` / `round_robin` / `capacity` |
| `circuit_fail_threshold` | '3' | Failures before circuit opens |
| `circuit_cooldown_seconds` | '60' | Cooldown before half-open |
| `max_concurrent_per_endpoint` | '3' | Max parallel requests per endpoint |

---

## Docker / Deployment

- `Dockerfile` – PHP 8.2 Apache with extensions (pdo_mysql, gd, curl, ssh2, ldap)
- `docker-compose.yml` – Services: `app` (web), `db` (MariaDB), `phpmyadmin` (with basic auth)
- Volumes: `doc_uploads/`, `sd_output/`, `db_data`
- `.env.example` – Template for environment variables

---

## Testing / Linting

- **No automated tests** in repo
- **Lint**: `php -l <file>` for syntax check
- **Manual testing** via browser / curl against running container

---

## File Tree (Code Only)

```
/
├── index.php              # Chat UI (3.5k lines)
├── login.php              # Login (local/LDAP/SSO)
├── register.php           # Registration + email verification
├── logout.php             # Logout
├── setup.php              # Installer
├── config.php             # Constants from DB/env
├── db.php                 # Core DB + schema + helpers
├── description.md         # This reference (detailed)
├── README.md              # User docs
├── Demo.md                # Demo guide
├── docker-compose.yml
├── Dockerfile
├── .env.example
├── admin/
│   ├── index.php          # Admin dashboard (7k lines)
│   ├── prompt_security.php
│   ├── load_stats.php
│   ├── refresh_sys_stats.php
│   ├── api_keys.php
│   └── login.php / logout.php
├── api/
│   ├── chat.php           # Main pipeline (3.3k)
│   ├── balancer.php       # Model selection & completion
│   ├── embedding.php      # Embeddings & RAG
│   ├── upload_document.php
│   ├── chat_sessions.php
│   ├── models.php
│   ├── heartbeat.php
│   ├── reset_password.php
│   ├── verify_email.php
│   ├── admin_user_action.php
│   ├── document_status.php
│   ├── rebuild_embeddings.php
│   ├── test_ldap.php
│   ├── test_smtp.php
│   ├── test_searxng.php
│   ├── sd_balancer.php
│   ├── sd_generate.php
│   ├── sd_checkpoints.php
│   ├── comfy_balancer.php
│   ├── comfy_generate.php
│   ├── comfy_checkpoints.php
│   ├── openai/v1/
│   │   ├── chat/completions/index.php
│   │   └── models/index.php
│   └── openai-tools/v1/
│       ├── chat/completions/index.php
│       └── models/index.php
├── lib/
│   ├── balancer_engine.php
│   ├── prompt_security.php
│   ├── openai_api.php
│   ├── ldap_auth.php
│   ├── mailer.php
│   └── prompt.txt         # Fallback routing categories
├── doc_uploads/           # Runtime uploads (protected)
├── sd_output/             # Generated images (protected)
├── docker/                # Docker configs
├── ressources/            # Static assets
└── assets/                # More static assets
```

---

## Quick Start for Changes

1. **Read** `description.md` for detailed architecture
2. **Find** the relevant file from the tables above
3. **Modify** – follow existing patterns (no framework conventions)
4. **Schema changes** → add to `ensureRuntimeSchema()` in `db.php`
5. **Test** with `php -l file.php` and manual browser test

---

*Generated for agent-friendly navigation. Keep updated as codebase evolves.*