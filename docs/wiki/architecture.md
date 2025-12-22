# 🏗️ Архітектура Системи — High-Level Overview

> **Остання оновлення**: 22.12.2025  
> **Версія**: 1.0 Beta

---

## 📋 Зміст
1. [System Architecture](#system-architecture)
2. [Component Diagram](#component-diagram)
3. [Data Flow](#data-flow)
4. [Services Overview](#services-overview)
5. [Technology Stack](#technology-stack)

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         FRONTEND LAYER                          │
│  ┌──────────────┐                                               │
│  │  Widget.js   │  ← Standalone Vanilla JS (zero dependencies)  │
│  │  (Public)    │                                               │
│  └──────┬───────┘                                               │
└─────────┼─────────────────────────────────────────────────────────┘
          │ HTTPS (POST /api/chat, GET /api/widget/settings)
          ↓
┌─────────────────────────────────────────────────────────────────┐
│                      LARAVEL APPLICATION                         │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  API Layer (routes/api.php)                              │  │
│  │  • ChatController::sendMessage()                         │  │
│  │  • WidgetController::settings()                          │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          ↓                                      │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  Service Layer (app/Services/)                           │  │
│  │  • ChatService         ← Orchestrates conversation flow  │  │
│  │  • AgentOrchestrator   ← AI pipeline controller         │  │
│  │  • ProductService      ← Horoshop sync & product mgmt    │  │
│  │  • AiRouter            ← OpenAI API client               │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          ↓                                      │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  Agent Tools (app/Services/Agent/Tools/)                 │  │
│  │  • MeiliProductSearchTool    ← Meilisearch queries       │  │
│  │  • DeduperTool               ← Remove duplicates         │  │
│  │  • AccessoryFilterTool       ← Filter accessories        │  │
│  │  • AiRerankTool              ← AI relevance scoring      │  │
│  │  • ProductDetailsTool        ← Fetch product cards       │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          ↓                                      │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │  Data Layer                                              │  │
│  │  • Eloquent Models (Product, Brand, Category, ...)      │  │
│  │  • Database (MySQL/PostgreSQL)                           │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
          │                          │
          │ API Calls                │ API Calls
          ↓                          ↓
┌─────────────────────┐    ┌───────────────────────────┐
│  OpenAI GPT-5.1     │    │  Horoshop E-Commerce API  │
│  • Intent classify  │    │  • Product catalog sync   │
│  • Query normalize  │    │  • Order status           │
│  • AI reranking     │    │  • Inventory data         │
└─────────────────────┘    └───────────────────────────┘
          │
          │ Search Queries
          ↓
┌─────────────────────┐
│  Meilisearch        │
│  • Fast search      │
│  • Typo tolerance   │
│  • Filters          │
└─────────────────────┘
```

---

## Component Diagram

### Core Components

#### 1. Frontend Widget
**Responsibility**: UI для спілкування з AI  
**Technology**: Vanilla JavaScript  
**Key Features**:
- Zero dependencies
- Token-based auth
- Session persistence (localStorage)
- Responsive design

---

#### 2. API Layer
**Responsibility**: HTTP endpoints для widget  
**Files**: 
- [ChatController.php](../../app/Http/Controllers/Api/ChatController.php)
- [WidgetController.php](../../app/Http/Controllers/Api/WidgetController.php)

**Endpoints**:
```
POST /api/chat                    → ChatController::sendMessage()
GET  /api/widget/settings         → WidgetController::settings()
POST /api/order-status            → OrderStatusController::show()
GET  /api/admin/jobs/sync-horoshop → AdminJobsController::syncHoroshop()
```

---

#### 3. ChatService
**Responsibility**: Головний orchestrator розмови  
**File**: [ChatService.php](../../app/Services/Chat/ChatService.php)

**Flow**:
```
handleMessage($message, $sessionId)
    ↓
Load session context (from Cache)
    ↓
Call AgentOrchestrator::handle()
    ↓
Format response for frontend
    ↓
Save session context
    ↓
Log message history (ChatMessage model)
    ↓
Return JSON response
```

---

#### 4. AgentOrchestrator
**Responsibility**: AI pipeline контролер  
**File**: [AgentOrchestrator.php](../../app/Services/Agent/AgentOrchestrator.php)

**Pipeline**:
```
handle($message, $context)
    ↓
1. createPlan() → Intent classification + query normalization
    ↓
2. match($intent) → Route to handler:
    • product_search → handleProductSearch()
    • order_status → handleOrderStatus()
    • faq → handleFaq()
    • smalltalk → handleSmallTalk()
    ↓
3. Return { message, products[], meta }
```

---

#### 5. Agent Tools
**Responsibility**: Specialized instruments для search pipeline

**Tools**:
1. **MeiliProductSearchTool** — Meilisearch query + brand detection
2. **DeduperTool** — Remove variant duplicates (by parent_article)
3. **AccessoryFilterTool** — Downrank/remove accessories
4. **AiRerankTool** — AI-powered relevance scoring (GPT-5.1)
5. **ProductDetailsTool** — Fetch full product cards from DB

---

#### 6. ProductService
**Responsibility**: Product sync з Horoshop  
**File**: [ProductService.php](../../app/Services/Horoshop/ProductService.php)

**Key Methods**:
- `syncFromHoroshop()` — Full catalog sync
- `upsertProductFromHoroshop()` — Create/update product
- `searchByCategoryPathContains()` — Eloquent search fallback

---

#### 7. AiRouter
**Responsibility**: Central OpenAI API client  
**File**: [AiRouter.php](../../app/Services/Ai/AiRouter.php)

**Key Methods**:
- `classify($message)` → Intent classification
- `normalizeSearchQuery($message)` → Query cleaning
- `callOpenAI($prompt, $temperature, $maxTokens)` → Base API call

---

#### 8. Background Jobs
**Responsibility**: Async tasks (sync, indexing)

**Jobs**:
- `SyncHoroshopProductsJob` — Daily 03:00 UTC
- `IndexProductsToMeiliJob` — Daily 03:30 UTC
- `RebuildCategoryIndexJob` — Daily 03:20 UTC
- `SyncBrandsJob` — Daily 03:30 UTC

---

## Data Flow

### Product Search Flow

```
1. User: "плитоноска зелена до 5000"
    ↓
2. Widget.js → POST /api/chat
    ↓
3. ChatController → ChatService::handleMessage()
    ↓
4. ChatService → AgentOrchestrator::handle()
    ↓
5. AgentOrchestrator::createPlan()
    ├─ AiRouter::classify() → PRODUCT_SEARCH
    └─ AiRouter::normalizeSearchQuery() → "плитоноска"
    └─ extractFiltersFromMessage() → {color: "зелена", budget_max: 5000}
    ↓
6. AgentOrchestrator::handleProductSearch()
    ├─ Step 1: MeiliProductSearchTool::search()
    │   ├─ BrandDetectionService::detectBrand() → No brand
    │   ├─ Meilisearch query (40 candidates)
    │   └─ Accessory filtering → 10 main products
    │
    ├─ Step 2: DeduperTool::dedupe() → 8 unique
    │
    ├─ Step 3: AccessoryFilterTool → already filtered
    │
    ├─ Step 4: AiRerankTool::rerank()
    │   ├─ Build prompt with 8 candidates
    │   ├─ OpenAI call (GPT-5.1)
    │   └─ Return top 5 relevant (AI decides)
    │
    └─ Step 5: ProductDetailsTool::getCards(5 IDs)
        └─ Eloquent query → 5 full products
    ↓
7. AgentOrchestrator → Return {message, products: [5], meta}
    ↓
8. ChatService → Format response for widget
    ↓
9. Widget.js → Render 5 product cards
```

**Time**: ~600-900ms (bottleneck: AiRerankTool ~500ms)

---

### Horoshop Sync Flow

```
1. Scheduler: 03:00 UTC → dispatch SyncHoroshopProductsJob
    ↓
2. Job → ProductService::syncFromHoroshop(limit=200)
    ↓
3. Loop:
    ├─ HoroshopClient::request('catalog/export', {limit: 200, offset})
    │   └─ HTTP POST https://horoshop.ua/api/catalog/export
    │       └─ Response: {status: 'OK', products: [200 items]}
    │
    ├─ For each product:
    │   └─ ProductService::upsertProductFromHoroshop($item)
    │       ├─ Extract fields (title, price, brand, images, ...)
    │       ├─ Compute derived (search_index, in_stock)
    │       └─ Product::updateOrCreate(['article' => $article], [...])
    │
    └─ offset += 200
    └─ Repeat until products empty
    ↓
4. Result: 2,834 products updated in DB
    ↓
5. Scheduler: 03:30 UTC → dispatch IndexProductsToMeiliJob
    ↓
6. Job → MeiliClient::productsIndex()->addDocuments($batch)
    └─ Batch size 500 → 6 batches → ~3 minutes
```

---

## Services Overview

| Service | Purpose | Dependencies | Location |
|---------|---------|--------------|----------|
| **ChatService** | Conversation orchestrator | AgentOrchestrator, AiRouter | [ChatService.php](../../app/Services/Chat/ChatService.php) |
| **AgentOrchestrator** | AI pipeline controller | 5 Tools, AiRouter | [AgentOrchestrator.php](../../app/Services/Agent/AgentOrchestrator.php) |
| **AiRouter** | OpenAI API client | - | [AiRouter.php](../../app/Services/Ai/AiRouter.php) |
| **ProductService** | Horoshop sync & search | HoroshopClient, AiRouter | [ProductService.php](../../app/Services/Horoshop/ProductService.php) |
| **BrandDetectionService** | Brand detection + boosting | Brand model | [BrandDetectionService.php](../../app/Services/Search/BrandDetectionService.php) |
| **MeiliClient** | Meilisearch wrapper | Meilisearch SDK | [MeiliClient.php](../../app/Services/Search/MeiliClient.php) |

---

## Technology Stack

### Backend
- **Framework**: Laravel 12 (PHP 8.3)
- **Database**: MySQL 8.0 / PostgreSQL 15
- **Cache**: Redis / Laravel Cache
- **Queue**: Laravel Queue (database driver)
- **Search**: Meilisearch 1.5+
- **AI**: OpenAI GPT-5.1

### Frontend
- **Widget**: Vanilla JavaScript (ES6+)
- **Build Tool**: Vite 7
- **Styling**: Tailwind CSS 4

### DevOps
- **Hosting**: Laravel Cloud
- **CI/CD**: GitHub Actions (автоматичний deploy на push main)
- **Monitoring**: Laravel Telescope (dev), Laravel Pulse (planned)
- **Logging**: Monolog → JSON structured logs

### External Services
- **Horoshop API** — Product catalog & order management
- **OpenAI API** — Intent classification, query normalization, AI reranking
- **Meilisearch** — Fast product search with typo tolerance

---

## Dependency Graph

```
Widget.js
    └─→ ChatController
        └─→ ChatService
            └─→ AgentOrchestrator
                ├─→ AiRouter
                │   └─→ OpenAI API
                │
                └─→ MeiliProductSearchTool
                    ├─→ BrandDetectionService
                    │   └─→ Brand model
                    │
                    └─→ MeiliClient
                        └─→ Meilisearch
                
                └─→ DeduperTool
                
                └─→ AccessoryFilterTool
                
                └─→ AiRerankTool
                    └─→ AiRouter
                        └─→ OpenAI API
                
                └─→ ProductDetailsTool
                    └─→ Product model
```

---

## Scalability Considerations

### Current Limits
- **Concurrent Users**: ~100 (Laravel Cloud shared plan)
- **API Requests**: ~50 req/sec
- **Meilisearch**: 2,834 products (can handle 1M+)
- **OpenAI Rate Limit**: 500 RPM (paid tier)

### Bottlenecks
1. **AiRerankTool** — 500-800ms per search (OpenAI API call)
2. **Database queries** — N+1 queries у ProductDetailsTool (можна optimize з eager loading)
3. **Horoshop API** — no retry logic, single-threaded sync

### Optimization Strategies
1. **Cache OpenAI classifications** — 80% cache hit → 80% cost reduction
2. **Parallel Meilisearch indexing** — 3 min → 1 min
3. **CDN for widget.js** — faster load time
4. **Read replicas** — scale database reads
5. **Queue workers** — scale background jobs

---

## Security Architecture

### Authentication
- **Widget**: Token-based (X-Widget-Token header)
- **Admin**: Laravel session + Sanctum tokens (future)

### CORS Policy
- **Allowed Origins**: `*` (для widget) або whitelist domains
- **Allowed Methods**: GET, POST, OPTIONS
- **Allowed Headers**: Content-Type, X-Widget-Token

### API Rate Limiting
- **Widget**: 60 requests/minute per token
- **Admin**: 120 requests/minute per user

### Data Privacy
- **User messages**: Logged у `chat_messages` table (GDPR compliance TODO)
- **OpenAI**: Messages sent to OpenAI (review privacy policy)
- **Session data**: Cached in Redis (auto-expire after 24h)

---

## Error Handling Strategy

### Graceful Degradation
```
OpenAI down → Fallback to keyword-based classification
Meilisearch down → Fallback to Eloquent search
Horoshop API down → Show cached products (TODO)
```

### Error Levels
- **CRITICAL**: Widget не працює → show fallback UI
- **WARNING**: AI response slow → show "processing..." longer
- **INFO**: Non-critical feature failed → log but continue

---

**Попередній документ**: [← Wiki Home](README.md)  
**Наступний документ**: [Search System →](search-system.md)
