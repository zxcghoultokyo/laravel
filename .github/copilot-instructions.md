# Copilot Instructions for AI Coding Agents

## Project Overview
This is a Laravel 12 e-commerce application for a tactical equipment store with AI-powered product search, chat interface, and integration with the Horoshop platform. The system uses OpenAI for intent classification and query normalization.

## Architecture Patterns

### Service-Based Architecture
- **Service Layer**: Core business logic separated into namespace-organized services in `app/Services/`
  - `Ai/`: AI routing, recommendations, product indexing, scenario handlers
  - `Chat/`: Conversation flow, session management, multi-intent handling
  - `Search/`: Product search engine, query parsing, ranking
  - `Horoshop/`: External platform integration (sync, orders, products)
  - `Catalog/`: Category management

### Intent-Driven Conversation Flow
The chat system (`ChatService`) uses intent classification to route user messages:
1. **Quick Rules**: Pattern-based shortcuts for common queries (categories, shortcuts)
2. **AI Routing** (`AiRouter::classify()`): OpenAI classifies intent (PRODUCT_SEARCH, ORDER_STATUS, FAQ, SMALL_TALK, FALLBACK)
3. **Handler Dispatch**: Specific handlers process each intent type
4. **Response Normalization**: Standardized JSON response format with `type`, `text`, `data`, `session_id`

**Key Files**: 
- [app/Services/Chat/ChatService.php](app/Services/Chat/ChatService.php#L1) — main orchestrator
- [app/Services/Ai/AiRouter.php](app/Services/Ai/AiRouter.php#L1) — OpenAI integration

### Eloquent Models & Relationships
- `Product`: Main product entity with JSON arrays (title_json, images, raw), search_index, popularity metrics
- `Category`: Hierarchical categories with path normalization (path_norm, slug)
- `CategoryAlias`: Maps alternative category names to canonical Category
- `Scenario`: Generated from product combinations, drives AI recommendations
- `ProductTag`, `ProductSynonym`, `ColorSynonym`: Enrichment layers for search

**Pattern**: Models use Eloquent casts for type safety (`json`, `boolean`, `integer`, `array`)

### Background Jobs & Queue Processing
- `GenerateCategoryScenariosJob`: Builds scenario data from product combinations
- `GenerateCategoryScriptsJob`: AI-generates product recommendation scripts per category
- `RebuildCategoryIndexJob`: Rebuilds search indices
- `SyncHoroshopProductsJob`: Syncs external product data

**Setup**: Uses Laravel queue system with Composer script `composer run dev` running queue listener concurrently

## Critical Development Workflows

### Local Development
```bash
composer run dev
```
Starts concurrently:
- PHP development server
- Queue listener (--tries=1 for dev)
- Log tail (pail)
- Vite build watcher

### Testing
```bash
composer run test
```
Clears config, then runs PHPUnit (Unit + Feature suites). Uses SQLite in-memory database for speed.

### Code Quality
- **Linting**: `laravel/pint` (PSR-12 standard)
- **Testing Framework**: PHPUnit 11.5+
- **Frontend**: Vite + Tailwind CSS 4 + Laravel plugin

## Project-Specific Conventions

### API Response Standardization
All chat/response endpoints return:
```json
{
  "type": "text|products|order_status|...",
  "text": "human-readable message",
  "data": { "...": "structured data" },
  "session_id": "uuid-for-session-tracking"
}
```
See [app/Http/Controllers/Api/ChatController.php](app/Http/Controllers/Api/ChatController.php#L1) for pattern.

### Session Management
- Sessions tracked via UUID in `session_id`
- Context stored in Laravel Cache using session key pattern
- Fallback: Generate UUID client-side if not provided
- See `ChatService::loadSessionContext()` / `saveSessionContext()`

### AI Integration Pattern
- **AiRouter**: Central service for all OpenAI calls
- **Configuration**: Uses `config('services.openai')` with env vars: `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_BASE_URL`
- **Error Handling**: Always provides fallbacks (defaults to keyword extraction) if API fails
- **Prompts**: Multi-intent classification, query normalization stored in method strings

### Horoshop Integration
- `HoroshopClient`: Low-level HTTP wrapper
- `ProductService`: Orchestrates product sync with AiRouter for smart categorization
- `OrderService`: Handles order status queries
- **Config**: `HOROSHOP_DOMAIN`, `HOROSHOP_API_LOGIN`, `HOROSHOP_API_PASSWORD` in env

**Dependency Injection**: Registered as singletons in [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php#L1)

### Database & Migrations
- **Strategy**: Forward-compatible migrations; old ones often have version numbers (2025_12_10...)
- **Naming**: Descriptive: `alter_search_index_in_products`, `upd_product_synonyms_table`
- **Casts**: Leverage Eloquent casts for JSON/array fields to avoid string manipulation

## Integration Points & External Dependencies

### OpenAI API
- Endpoint: configurable (default: https://api.openai.com/v1)
- Model: configurable (default: gpt-5.1)
- Used by: `AiRouter`, `ProductIndexBuilder`, scenario generation
- Rate limits & costs: Monitor in logs, implement caching where possible (ProductIndexBuilder uses Cache)

### Horoshop Platform
- **Sync Direction**: Horoshop → Laravel (products, orders)
- **Data Flow**: Horoshop raw responses stored as-is in `Product.raw` JSON field
- **Reliability**: Implements retry logic; see AdminJobsController for manual triggers

### Laravel Service Container
- All services registered as singletons in AppServiceProvider
- Constructor injection used consistently
- Facade access available but not preferred (use injected services)

## Frontend Integration
- **Entry Point**: [resources/js/app.js](resources/js/app.js) + [resources/css/app.css](resources/css/app.css)
- **Build Tool**: Vite 7 with Laravel plugin
- **Styling**: Tailwind CSS 4 via @tailwindcss/vite
- **API Client**: Axios included in package.json
- **Watch Ignored**: Storage framework views (to prevent rebuilds)

## Key Commands & Helpers
- `php artisan test` — run tests
- `php artisan queue:listen` — listen for background jobs
- `php artisan pail` — tail logs in real-time
- `npm run dev` — Vite watch mode
- `npm run build` — Production Vite build
- Admin endpoints: `/admin/jobs/sync-horoshop`, `/admin/jobs/rebuild-category-index`

## File Structure Rules
- **Controllers**: `app/Http/Controllers/Api/*.php` (API-only currently)
- **Services**: `app/Services/{Domain}/*.php` (business logic isolated)
- **Models**: `app/Models/*.php` (Eloquent entities)
- **Jobs**: `app/Jobs/*.php` (queued background work)
- **Config**: `config/*.php` (env-driven, read-only at runtime)
- **Routes**: `routes/api.php` (primary), `routes/console.php` (CLI)
- **Migrations**: `database/migrations/*.php` (timestamped naming)

## When Adding New Features
1. **Business Logic**: Create in `app/Services/{Domain}/NewService.php`
2. **External Data**: Add model in `app/Models/New.php` with appropriate casts
3. **Background Work**: Extend base Job class, register in AppServiceProvider if needed
4. **API Endpoint**: Add route in `routes/api.php`, controller in `app/Http/Controllers/Api/`
5. **Configuration**: Add to `config/services.php` with env fallbacks
6. **Testing**: Place Feature tests in `tests/Feature/`, Unit tests in `tests/Unit/`
