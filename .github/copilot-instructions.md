# Copilot Instructions (AI Coding Agents)

Purpose: make agents productive fast in this Laravel 12 multi-tenant AI‑commerce SaaS backend. Keep to existing patterns and use the services layer.

## 🏗️ Multi-Tenant Architecture

This is a **multi-tenant SaaS** where each tenant (customer) has their own:
- Products catalog (`products` table with `tenant_id`)
- Widget settings
- Chat sessions & messages
- Analytics data

**Key files:**
- `app/Models/Tenant.php` — Core tenant model with subscription logic
- `app/Scopes/TenantScope.php` — Global scope for automatic tenant filtering
- `app/Http/Middleware/SetTenantContext.php` — Sets tenant from auth or API key
- `app/Models/Concerns/BelongsToTenant.php` — Trait for tenant-scoped models

**Subscription Logic (Tenant.php):**
```php
isOnTrial()           // Has trial_ends_at in future
isTrialExpired()      // Trial ended
hasActiveSubscription() // plan + plan_expires_at valid
canUseWidget()        // Trial OR active subscription
```

**Plans:** starter (799₴), pro (1999₴), enterprise (custom)
**Trial:** 14 days from registration

Big Picture
- Core logic lives in services: [app/Services](app/Services) (Ai, Agent, Chat, Search, Horoshop, Catalog).
- **Primary chat flow**: Widget (SSE) → StreamingFunctionCallingAgent → OpenAI function calling → tools → response
- **Fallback flow**: POST /api/chat → FunctionCallingAgent (same logic, non-streaming)
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

### GPT Response Without Tool Calls (Critical!)

When GPT knows products from history, it may respond with JSON **without calling search_products**:
```json
{"intro": "Ось підсумок:", "products": [{"article": "ab3-775", "comment": "..."}]}
```

**Both agents must handle this in the `else` branch** (when no tool_calls):
1. Call `parseStructuredResponse($responseText, [])` to lookup products by article in DB
2. Return products with images for card display
3. Extract intro/outro text separately

## Chat History & Context

History stored in DB, NOT in session/cache:
- `ChatSession` - session record with `session_id`
- `ChatMessage` - messages with `role`, `content`, `meta` (includes shown products)
- Load: `loadConversationHistory($sessionId)` in both agents
- Assistant messages include `[Показані товари: ...]` marker for context

Diagnostic endpoint: `GET /api/diagnostic/chat-history/{sessionId}?key=diagnostic_secret_key_2025`

## ⚠️ Session Identifiers (ВАЖЛИВО!)

**Є ДВА типи session ID — не плутай!**

| Назва | Тип | Де використовується | Приклад |
|-------|-----|---------------------|---------|
| `session_id` | string | Публічний ID з widget, API params, analytics tables | `"session_1768827245332_z8lvumz8k"` |
| `chat_session_id` | int | FK до `chat_sessions.id` в `chat_messages` | `42` |

**Таблиці:**
- `chat_sessions`: має і `id` (int PK) і `session_id` (string, unique)
- `chat_messages`: використовує `chat_session_id` (FK до `chat_sessions.id`)
- Legacy analytics (`chat_events`, `chat_conversions`, `chat_session_outcomes`): використовують `session_id` (string)

**Helper:** [app/Services/Chat/SessionResolver.php](app/Services/Chat/SessionResolver.php)
```php
SessionResolver::getBySessionId($sessionId);        // string → ChatSession
SessionResolver::resolveChatSessionId($sessionId);  // string → int (FK)
SessionResolver::resolveSessionId($chatSessionId);  // int → string
```

## Key Components

**Agents (Primary)**:
- `FunctionCallingAgent`: non-streaming, returns full response. [app/Services/Agent/FunctionCallingAgent.php](app/Services/Agent/FunctionCallingAgent.php)
- `StreamingFunctionCallingAgent`: SSE streaming for widget. [app/Services/Agent/StreamingFunctionCallingAgent.php](app/Services/Agent/StreamingFunctionCallingAgent.php)

**Supporting Services**:
- `ChatService`: orchestrates chat/session and formatting. [app/Services/Chat/ChatService.php](app/Services/Chat/ChatService.php)
- `PromptPresetService`: custom prompts by context (language, campaign, category). [app/Services/Ai/PromptPresetService.php](app/Services/Ai/PromptPresetService.php)
- `ToneService`: tone settings (official/spartan/friendly). [app/Services/Ai/ToneService.php](app/Services/Ai/ToneService.php)
- `MeiliProductSearchTool`: Meilisearch with Eloquent fallback. [app/Services/Agent/Tools/MeiliProductSearchTool.php](app/Services/Agent/Tools/MeiliProductSearchTool.php)
- `ProductDetailsTool`: full product cards with images. [app/Services/Agent/Tools/ProductDetailsTool.php](app/Services/Agent/Tools/ProductDetailsTool.php)
- `BrandDetectionService`: brand transliteration & detection. [app/Services/Search/BrandDetectionService.php](app/Services/Search/BrandDetectionService.php)

## 🔤 Brand Detection & Transliteration

`BrandDetectionService` handles Ukrainian slang/transliteration → canonical brand names:

**File:** [app/Services/Search/BrandDetectionService.php](app/Services/Search/BrandDetectionService.php)

**Supported transliterations:**
| Input (Ukrainian/Latin slang) | Output (Brand) |
|-------------------------------|----------------|
| `опс кор`, `ops core`, `opscore` | `Ops-Core` |
| `саломон`, `salomon` | `Salomon` |
| `пелтор`, `peltor` | `Peltor` |
| `кріптек`, `kryptek` | `Kryptek` |
| `мілтек`, `милтек`, `mil-tec` | `Mil-Tec` |
| `хелікон`, `helikon` | `Helikon-Tex` |
| `кондор`, `condor` | `Condor` |
| `тарга`, `targa` | `Targa` |

**Usage in agents:**
```php
$brandService = app(BrandDetectionService::class);
$result = $brandService->detectBrand($query);
// Returns: ['brand' => 'Ops-Core', 'enhanced_query' => 'шолом Ops-Core', 'original' => 'шолом опс кор']
```

**Adding new brands:** Edit `BRAND_TRANSLITERATION` constant in `BrandDetectionService.php`

**Legacy (deprecated)**:
- `AgentOrchestrator`: old plan-execute-respond flow. [app/Services/Agent/AgentOrchestrator.php](app/Services/Agent/AgentOrchestrator.php)
- `AiRouter`: classification (still used for some fallbacks). [app/Services/Ai/AiRouter.php](app/Services/Ai/AiRouter.php)

API & Contract
- **Primary SSE**: GET /api/chat/stream → [StreamingChatController.php](app/Http/Controllers/Api/StreamingChatController.php)
- **Fallback JSON**: POST /api/chat → [ChatController.php](app/Http/Controllers/Api/ChatController.php)
- Response format: `{ type: 'text|products', text, products?, session_id, meta? }`.
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

**Base URL:** `https://aintento.laravel.cloud/api/diagnostic`
**Key:** `?key=diagnostic_secret_key_2025`

Quick debug commands:
```bash
# Check chat history for a session
curl "https://aintento.laravel.cloud/api/diagnostic/chat-history/{sessionId}?key=diagnostic_secret_key_2025"

# Search products in DB
curl "https://aintento.laravel.cloud/api/diagnostic/search-db?key=diagnostic_secret_key_2025&q=футболка"

# Search in Meilisearch
curl "https://aintento.laravel.cloud/api/diagnostic/search-meili?key=diagnostic_secret_key_2025&q=футболка"

# DB stats
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=diagnostic_secret_key_2025"
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
- High‑level overview: [README.md](README.md). 
- Chat architecture: [docs/CHAT_ARCHITECTURE.md](docs/CHAT_ARCHITECTURE.md)
- Chat examples: [docs/CHAT_EXAMPLES.md](docs/CHAT_EXAMPLES.md).
- Admin UI Roadmap: [docs/ADMIN_UI_ROADMAP.md](docs/ADMIN_UI_ROADMAP.md)
- Billing: [config/billing.php](config/billing.php) — plans and payment config
- Multi-tenant: `app/Models/Tenant.php`, `app/Scopes/TenantScope.php`

## Product Images

Images extracted via `extractProductImages(Product $product)` method (in both agents and MeiliProductSearchTool):

```php
// Priority order:
1. $product->raw['pictures'][*]['url']  // Horoshop format
2. $product->raw['images'][*]['url']    // Alternative format
3. $product->images                      // DB column (JSON or string)
4. $product->raw['image']                // Single image fallback
5. $product->raw['main_image']           // Main image fallback
```

## Prompt Presets

Custom system prompts with variables support. Managed via `/admin/prompts`.

Files:
- Model: [app/Models/PromptPreset.php](app/Models/PromptPreset.php)
- Service: [app/Services/Ai/PromptPresetService.php](app/Services/Ai/PromptPresetService.php)
- Livewire: [app/Livewire/Admin/PromptPresetsManager.php](app/Livewire/Admin/PromptPresetsManager.php)

Matching context:
- `language` - uk, en, ru
- `tone` - official, spartan, friendly
- `campaign` - UTM campaign match
- `categories` - product categories

Variables use `{{variable_name}}` syntax, replaced at runtime.

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
