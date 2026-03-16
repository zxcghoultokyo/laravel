# Copilot Instructions (AI Coding Agents)

Purpose: make agents productive fast in this Laravel 12 multi-tenant AI‑commerce SaaS backend. Keep to existing patterns and use the services layer.

## 🚨 Git Workflow (ОБОВ'ЯЗКОВО!)

- **Завжди працюй на `dev` гілці** — ніколи не пуш напряму в `main`
- Після змін: запусти тести, перевір що все працює як очікується
- Тільки після підтвердження що працює на dev: запитай користувача **"Пушимо на прод?"** перед мержем в main
- **Production** = `main` branch → `aintento.laravel.cloud`
- **Staging** = `dev` branch → `aintento-dev.laravel.cloud`

```bash
# Правильний порядок роботи:
git checkout dev
# ... робиш зміни ...
php artisan test --compact
git add -A && git commit -m "..." && git push origin dev
# ... чекаєш деплой на staging, перевіряєш ...
# ... питаєш користувача "Пушимо на прод?" ...
git checkout main && git merge dev && git push origin main
```

## 🏗️ Multi-Tenant Architecture

This is a **multi-tenant SaaS** where each tenant (customer) has their own:
- Products catalog (`products` table with `tenant_id`)
- Widget settings
- Chat sessions & messages
- Analytics data

**Key files:**
- `app/Models/Tenant.php` — Core tenant model with subscription logic
- `app/Scopes/TenantScope.php` — Global scope for automatic tenant filtering
- `app/Http/Middleware/ResolveTenantMiddleware.php` — Sets tenant from auth or API key
- `app/Models/Traits/BelongsToTenant.php` — Trait for tenant-scoped models

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
- `PromptPresetService`: per-tenant layered presets (base + overlays). [app/Services/Ai/PromptPresetService.php](app/Services/Ai/PromptPresetService.php)
- `PromptModulesService`: modular prompt foundation (core/search/follow-up). [app/Services/Ai/PromptModulesService.php](app/Services/Ai/PromptModulesService.php)
- `TenantPromptGenerator`: auto-generates prompt from catalog. [app/Services/Ai/TenantPromptGenerator.php](app/Services/Ai/TenantPromptGenerator.php)
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
- AI enrichment: [app/Jobs/AnalyzeProductsWithAiJob.php](app/Jobs/AnalyzeProductsWithAiJob.php) — generates keywords, slang, synonyms via GPT-4o-mini.
- Tenant onboarding: [app/Jobs/OnboardTenantJob.php](app/Jobs/OnboardTenantJob.php) — orchestrates full tenant setup.
- Category scenarios/scripts and index rebuild jobs live in [app/Jobs](app/Jobs).
- Queue runs alongside dev server; production uses `meili,default` queues.

## 🚀 Tenant Onboarding Flow

When a new tenant registers and connects their Horoshop store, `OnboardTenantJob` runs:

**Steps (with weights for progress):**
1. `horoshop_sync` (25%) — Sync products from Horoshop API
2. `categories_rebuild` (10%) — Build category tree from products
3. `brands_sync` (5%) — Extract and save brands
4. `ai_enrichment` (30%) — AI analysis of all products (keywords, slang, categories)
5. `synonyms_generation` (10%) — Generate synonyms for Meilisearch
6. `meili_indexing` (15%) — Index products in Meilisearch
7. `prompt_generation` (5%) — Generate per-tenant system prompt via TenantPromptGenerator

**Progress Tracking:**
- Model: [app/Models/TenantOnboardingProgress.php](app/Models/TenantOnboardingProgress.php)
- Livewire component: [app/Livewire/OnboardingProgress.php](app/Livewire/OnboardingProgress.php)
- View: [resources/views/livewire/onboarding-progress.blade.php](resources/views/livewire/onboarding-progress.blade.php)

**Key methods:**
```php
TenantOnboardingProgress::forTenant($tenantId);  // Get or create
$progress->start();                              // Mark as in_progress
$progress->updateStep('ai_enrichment', 'in_progress', 50, 'Analyzing...');
$progress->complete();                           // Mark all done
$progress->fail($errorMessage);                  // Mark as failed
```

**Real-time updates:** Jobs update progress directly:
- `AnalyzeProductsWithAiJob` calls `updateOnboardingProgress()` after each batch
- `IndexProductsToMeiliJob` calls `updateMeiliProgress()` during chunks
- Livewire polls every 3 seconds via `wire:poll.3000ms`

**AI Enrichment costs (~gpt-4o-mini):**
- ~$0.14 per 500 products (~6 грн)
- ~1 hour for 500 products (with rate limiting)

Config & Env
- OpenAI: `OPENAI_API_KEY`, `OPENAI_MODEL` (default gpt-4o for chat, gpt-4o-mini for enrichment). See [config/services.php](config/services.php).
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

## 🧪 TESTING CHAT — START HERE FIRST!

**IMPORTANT: Always test via Chat API first, not diagnostic endpoints!**

**Tenant 2 (attack.kiev.ua) token:** `zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj`

### Quick test command (USE THIS FIRST):
```bash
# Test any query - this is the ONLY command you need to start with
curl -s "https://aintento.laravel.cloud/api/chat" \
  -H "Content-Type: application/json" \
  -d '{"message": "YOUR_QUERY_HERE", "session_id": "test_'$(date +%s)'", "token": "zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj"}' | python3 -c "
import sys,json
d=json.load(sys.stdin)
print('source:', d.get('meta',{}).get('source','GPT'))
print('type:', d.get('type'))
print('products:', len(d.get('products',[])))
print('text:', d.get('text','')[:150])
for p in d.get('products',[])[:3]:
    print(f\"  - {p.get('title','')[:50]}\")
"
```

### Examples that WORK:
```bash
# Single word → short_query_handler (fast, no GPT)
"шоломи"     → 3 products ✓
"підсумки"   → products ✓
"берці"      → products ✓

# Multi-word → GPT (with intro text)
"покажи шоломи"      → GPT with intro ✓
"що беруть взимку"   → GPT semantic search ✓
```

### Response structure:
- `meta.source`: `short_query_handler` (1 word) or `GPT` (2+ words)
- `type`: `products` or `text`
- `products`: array with `title`, `price`, `images`, etc.
- `text`: intro/outro text from GPT

### Check chat history by session_id:
```bash
# Session ID from widget looks like: session_1769774712506_ppwhomvek
curl -s "https://aintento.laravel.cloud/api/diagnostic/chat-history/session_1769774712506_ppwhomvek?key=diagnostic_secret_key_2025" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print('messages:', d.get('message_count', 0))
for m in d.get('messages', [])[-5:]:
    role = m.get('role','')
    content = m.get('content','')[:100]
    print(f'{role}: {content}')
"
```

### ONLY if Chat API fails, then check:
1. Diagnostic search-db (does product exist?)
2. Diagnostic search-meili (is it indexed?)
3. Logs via `php artisan pail`

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

Per-tenant system prompts with layering support. Managed via `/admin/prompts`.

**Architecture:** [docs/PROMPT_GENERATION_ARCHITECTURE.md](docs/PROMPT_GENERATION_ARCHITECTURE.md)

Files:
- Model: [app/Models/PromptPreset.php](app/Models/PromptPreset.php)
- Service: [app/Services/Ai/PromptPresetService.php](app/Services/Ai/PromptPresetService.php)
- Generator: [app/Services/Ai/TenantPromptGenerator.php](app/Services/Ai/TenantPromptGenerator.php)
- Modules: [app/Services/Ai/PromptModulesService.php](app/Services/Ai/PromptModulesService.php)
- Livewire: [app/Livewire/Admin/PromptPresetsManager.php](app/Livewire/Admin/PromptPresetsManager.php)

**Prompt Stack (per-tenant):**
1. `getCriticalPrefix()` — security rules (anti-hallucination, max 3 products)
2. **BASE preset** (`is_default=true`) — custom or auto-generated
3. **Overlays** (`is_default=false`, sorted by `priority` DESC) — FAQ scripts, category-specific, campaigns
4. `getCriticalSuffix()` — phone, constraints
5. **Fallback** (if no presets): `PromptModulesService` shared modules

**TenantPromptGenerator:**
- `analyzeTenant()` — collects categories, brands, prices, detects `has_age_categories`
- `buildPrompt()` — reuses `PromptModulesService` modules + adds store profile
- `saveAsPreset()` — SAFE: won't overwrite custom presets (saves as inactive backup)
- Auto-runs in `OnboardTenantJob` step 7

**PromptModulesService (battle-tested foundation):**
- `getCoreModule()` — search_products(), max 3, format, no links
- `getSearchModule(bool $hasAge)` — OR synonyms, retry, seasonality, age filtering
- `getFollowUpModule()` — exclude_shown, negative feedback

Matching context (for overlays):
- `language` - uk, en, ru
- `tone` - official, spartan, friendly
- `campaign` - UTM campaign match
- `categories` - product categories

Variables use `{{variable_name}}` syntax, replaced at runtime.

**Diagnostic:**
```bash
# Generate prompt for tenant (supports ?dry_run=1)
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/generate-prompt/{tenantId}?key=diagnostic_secret_key_2025"
```

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
