# Copilot Instructions (AI Coding Agents)

Purpose: make agents productive fast in this Laravel 12 AI‑commerce backend. Keep to existing patterns and use the services layer.

Big Picture
- Core logic lives in services: [app/Services](app/Services) (Ai, Agent, Chat, Search, Horoshop, Catalog).
- Chat flow: quick rules → `AiRouter::classify()` → Agent Orchestrator → normalized response.
- Data sources: Horoshop → MySQL (`products.raw`) → AI enrichment (`product_ai_index`) → Meilisearch indexing.
- Fallbacks everywhere: if OpenAI or Meili are off, keyword parsing and Eloquent search kick in.

## ⚠️ CRITICAL: Chat Architecture (TWO AGENTS!)

**Widget uses SSE streaming by default!** There are TWO separate agents:

| Endpoint | Agent | File |
|----------|-------|------|
| `POST /api/chat` | `FunctionCallingAgent` | [app/Services/Agent/FunctionCallingAgent.php](app/Services/Agent/FunctionCallingAgent.php) |
| `GET /api/chat/stream` (SSE) | `StreamingFunctionCallingAgent` | [app/Services/Agent/StreamingFunctionCallingAgent.php](app/Services/Agent/StreamingFunctionCallingAgent.php) |

**If chat context/history is broken — check BOTH agents!**

- Widget ([public/widget.js](public/widget.js)) uses SSE streaming (`sendMessageStreaming()`)
- Both agents must load history from `ChatSession`/`ChatMessage` tables
- Context extraction: `extractConversationContext()` parses product categories, sizes from history
- System prompt gets `[КОНТЕКСТ РОЗМОВИ: ...]` with extracted context

## Chat History & Context

History stored in DB, NOT in session/cache:
- `ChatSession` - session record with `session_id`
- `ChatMessage` - messages with `role`, `content`, `meta` (includes shown products)
- Load: `loadConversationHistory($sessionId)` in both agents
- Assistant messages include `[Показані товари: ...]` marker for context

Diagnostic endpoint: `GET /api/diagnostic/chat-history/{sessionId}?key=diagnostic_secret_key_2025`

Key Components
- `ChatService`: orchestrates chat/session and formatting. [app/Services/Chat/ChatService.php](app/Services/Chat/ChatService.php)
- `AiRouter`: OpenAI classification/normalization/rerank with safe fallbacks. [app/Services/Ai/AiRouter.php](app/Services/Ai/AiRouter.php)
- `AgentOrchestrator`: plan‑execute‑respond for `product_search`. [app/Services/Agent/AgentOrchestrator.php](app/Services/Agent/AgentOrchestrator.php)
- Agent tools: Meili search, details, dedupe, accessory filter, AI rerank. [app/Services/Agent/Tools](app/Services/Agent/Tools)
- `SearchQueryParser`: price/color parsing, synonyms. [app/Services/Search/SearchQueryParser.php](app/Services/Search/SearchQueryParser.php)
- Catalog index/aliases: [app/Services/Catalog/CategoryIndexService.php](app/Services/Catalog/CategoryIndexService.php)

API & Contract
- Primary endpoint: POST /api/chat → [app/Http/Controllers/Api/ChatController.php](app/Http/Controllers/Api/ChatController.php)
- Response format is normalized: `{ type: 'text|products', text, products?, session_id, meta? }`.
- Other endpoints: order status/search, debug, admin jobs in [routes/api.php](routes/api.php).

Data & Models
- `Product` stores raw vendor payload in `raw` (JSON) and `search_index`. [app/Models/Product.php](app/Models/Product.php)
- `ProductAiIndex` adds `ai_product_type`, `ai_category`, `keywords`. [app/Models/ProductAiIndex.php](app/Models/ProductAiIndex.php)
- `Category` and `CategoryAlias` support path normalization and alias mapping. [app/Models](app/Models)
- Meili documents are built from real DB fields (no invented columns).

Jobs & Queues
- Meili indexing: [app/Jobs/IndexProductsToMeiliJob.php](app/Jobs/IndexProductsToMeiliJob.php) (supports legacy `chunk`).
- Category scenarios/scripts and index rebuild jobs live in [app/Jobs](app/Jobs).
- Queue runs alongside dev server; production uses `meili,default` queues.

Config & Env
- OpenAI: `OPENAI_API_KEY`, `OPENAI_MODEL` (default gpt‑5.1), `OPENAI_BASE_URL`. See [config/services.php](config/services.php).
- Meili: `MEILI_ENABLED`, `MEILI_HOST`, `MEILI_MASTER_KEY`, index name in [config/meilisearch.php](config/meilisearch.php).
- Horoshop: `HOROSHOP_DOMAIN`, `HOROSHOP_API_LOGIN`, `HOROSHOP_API_PASSWORD`.

## 🔧 Diagnostic API (Production Debugging)

**Base URL:** `https://aimbot.laravel.cloud/api/diagnostic`
**Key:** `?key=diagnostic_secret_key_2025`

Quick debug commands:
```bash
# Check chat history for a session
curl "https://aimbot.laravel.cloud/api/diagnostic/chat-history/{sessionId}?key=diagnostic_secret_key_2025"

# Search products in DB
curl "https://aimbot.laravel.cloud/api/diagnostic/search-db?key=diagnostic_secret_key_2025&q=футболка"

# Search in Meilisearch
curl "https://aimbot.laravel.cloud/api/diagnostic/search-meili?key=diagnostic_secret_key_2025&q=футболка"

# DB stats
curl "https://aimbot.laravel.cloud/api/diagnostic/db-stats?key=diagnostic_secret_key_2025"
```

Full docs: [docs/DIAGNOSTIC_API.md](docs/DIAGNOSTIC_API.md)

Developer Workflows
- Dev loop: `composer run dev` (PHP server, queue listener, logs, Vite).
- Tests: `composer run test` (SQLite in‑memory). Quick agent smoke: `php test-agent.php`.
- Useful: `php artisan pail` (logs), `php artisan queue:work --queue=meili,default --tries=1`.

Conventions & Patterns
- Services registered as singletons in [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php).
- Session context stored by `ChatService` keyed by `session_id` (cache).
- Prefer service methods over facades; keep controller thin and returns normalized payloads.
- If Meili disabled, fall back to Eloquent LIKE with filters; if OpenAI fails, use keyword extraction.

When Adding Features
- Put business logic in a new service in the correct domain (e.g., `app/Services/Search/*`).
- Add API routes in [routes/api.php](routes/api.php) and a controller in [app/Http/Controllers/Api](app/Http/Controllers/Api).
- Extend jobs in [app/Jobs](app/Jobs) for background work; stay idempotent and consider legacy payloads.
- Update config under [config](config) and guard with env fallbacks.

References
- High‑level overview: [README.md](README.md). Agent details: [AGENT_ORCHESTRATOR.md](AGENT_ORCHESTRATOR.md). Examples: [docs/CHAT_EXAMPLES.md](docs/CHAT_EXAMPLES.md).

Meili Documents (example)
- Built in [app/Jobs/IndexProductsToMeiliJob.php](app/Jobs/IndexProductsToMeiliJob.php); settings set `filterableAttributes` and `searchableAttributes` idempotently.
- Fields: id, article, parent_article, title, category_path, brand, color, search_index, description, attributes_text, attrs, in_stock, display_in_showcase, quantity, presence_raw, price, price_old, we_recommended, popularity, orders_count, views_count, added_to_cart_count, updated_at_ts, ai_product_type, ai_category, has_ai_type, has_ai_category.
- Sources: DB columns from `products` + `aiIndex` relation; description/attributes via [app/Support/ProductRawExtractor.php](app/Support/ProductRawExtractor.php) with parent fallback.
- Minimal doc shape:
	{ id, title, category_path, price, in_stock, ai_product_type, updated_at_ts }

Session Search State
- Cache keys: ctx `chat_ctx_{buildSessionKey(session_id)}`; search `chat_search_{buildSessionKey(session_id)}`.
- State fields: `category_key`, `filters` (budget_min/max, camo, color), `negative_terms`, `shown_ids`, `last_question`.
- Methods: load/save ctx — `loadSessionContext()`, `saveSessionContext()`; search — `loadSearchState()`, `saveSearchState()`, `mergeSearchState()` in [app/Services/Chat/ChatService.php](app/Services/Chat/ChatService.php).
- UX helpers: follow‑up detection `isFollowupMoreRequest()`; force show `shouldForceShowProducts()`; shown ids appended after each product reply.
- Defaults: when `category_key==='plate_carriers'` — auto add negatives (e.g., "панель", "pouch", "cummerbund").
