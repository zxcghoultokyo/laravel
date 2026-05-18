# Aintento — AI-чатбот для e-commerce (Laravel 12)

Multi-tenant SaaS-платформа: AI-асистент у вигляді чат-віджету для інтернет-магазинів. Клієнт встановлює JS-сніпет на свій сайт — далі все автоматично.

**Поточний стан:** проект призупинено. Production-деплой функціональний, код відкрито для вивчення.

---

## Стек

| Компонент | Технологія |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| AI | OpenAI GPT-4o (function calling) |
| Пошук | Meilisearch (self-hosted, fly.dev) |
| БД | MySQL 8 |
| Черги | Laravel Queue (database driver) |
| Frontend | Vite + Tailwind CSS, Livewire 3 |
| Деплой | Laravel Cloud (auto-deploy з `main`) |
| Платежі | WayForPay |

---

## Що робить проект

Підключає AI-чатбот до будь-якого інтернет-магазину на Horoshop (або іншій платформі). Власник магазину отримує:

- **AI-чатбот** — відповідає на питання про товари, ціни, наявність, доставку
- **Семантичний пошук** — Meilisearch + AI-fallback (embeddings)
- **Товарні картки** прямо у чаті (фото, ціна, кнопка "Купити")
- **Фільтри** — ціна, колір, бренд, вік (дитячі магазини), сезон
- **Follow-up контекст** — чат пам'ятає попередні запити в сесії
- **FAQ** — автовідповіді про доставку, оплату, повернення
- **Операторський режим** — ручне підключення оператора (WebSocket)

---

## Архітектура (огляд)

```
HTTP request
    └─ ResolveTenantMiddleware  (визначає tenant за X-Widget-Token)
        └─ CheckTenantLimitsMiddleware  (план / ліміти / widget_paused)
            └─ ChatController / SSE stream
                └─ AgentOrchestrator
                    ├─ StreamingFunctionCallingAgent  (SSE, widget)
                    └─ FunctionCallingAgent  (sync, API)
                        ├─ MeiliProductSearchTool   → Meilisearch → Eloquent fallback
                        ├─ OrderLookupTool          → Horoshop orders
                        └─ StoreInfoTool             → FAQ / store context
```

**Multi-tenant isolation:** всі моделі мають `TenantScope` — автоматична фільтрація за `tenant_id` на рівні Eloquent.

### Пайплайн обробки запиту

```
1. Нормалізація (транслітерація брендів, сленг → стандарт)
2. GPT function calling → вибирає інструмент + параметри
3. Meilisearch: фільтр tenant_id + in_stock + ціна/колір/вік/категорія
4. AI-реранжування кандидатів (контекст сесії, виключення показаних)
5. Відповідь: текст + товарні картки
```

Fallback: Meili → Eloquent LIKE; GPT → keyword extraction.

### Ключові сервіси

| Сервіс | Шлях | Призначення |
|---|---|---|
| `AgentOrchestrator` | `Services/Agent/` | Вибирає агент, запускає pipeline |
| `MeiliProductSearchTool` | `Services/Agent/Tools/` | Пошук + реранжування |
| `AiRouter` | `Services/Ai/` | HTTP до OpenAI |
| `MeiliClient` | `Services/Search/` | Клієнт Meilisearch |
| `BrandDetectionService` | `Services/Search/` | Нормалізація брендів |
| `PromptPresetService` | `Services/Ai/` | Per-tenant системні промпти |
| `HoroshopClient` | `Services/Horoshop/` | Синхронізація каталогу |

---

## Структура проекту

```
app/
  Console/Commands/     # Artisan: sync, indexing, onboarding, aliases
  Http/
    Controllers/Api/    # ChatController, DiagnosticController, AdminJobsController
    Middleware/         # ResolveTenant, CheckTenantLimits, WidgetCors
  Jobs/                 # IndexProductsToMeiliJob, OnboardTenantJob, CheckTrialEndingJob
  Livewire/             # Admin panel (TenantDashboard, TenantsManager, SyncReports)
  Models/               # Tenant, Product, ProductAiIndex, WidgetSettings, Category
  Services/
    Agent/              # Агенти, оркестратор, інструменти
    Ai/                 # AiRouter, prompt generation, embeddings
    Search/             # MeiliClient, BrandDetection, QueryExpander
    Horoshop/           # Sync, import
    Analytics/          # Funnel, A/B testing, exports
config/
  meilisearch.php       # MEILI_ENABLED, MEILI_HOST, MEILI_MASTER_KEY
  services.php          # OpenAI, Horoshop, admin token
database/migrations/    # 80+ migrations
routes/
  api.php               # /api/chat, /api/chat/stream, /api/diagnostic/*
  web.php               # Admin UI
secrets/                # Gitignored. Локальні credentials (secrets/README.md)
```

---

## Поточні тенанти (production)

| ID | Магазин | Опис | Стан |
|----|---------|------|------|
| 2 | Contractor | Тактичне спорядження, contractor.kiev.ua | Призупинено |
| 20 | Bavkatoys | Дитячі іграшки, bavkatoys.com | Призупинено |

Віджети вимкнені через `widget_paused = true` (міграція `2026_05_18`).  
Відновлення: `UPDATE tenants SET widget_paused = 0 WHERE id IN (2, 20);`

---

## Підписки

| План | Повідомлень/міс | Товарів |
|------|----------------|---------|
| Trial | 5 000 (14 днів) | 5 000 |
| Starter | 1 000 | 500 |
| Pro | 5 000 | 10 000 |
| Enterprise | ∞ | ∞ |

---

## ENV-змінні

Зберігати виключно через `.env` (gitignored). Локальні credentials для тестів — у `secrets/` (теж gitignored).

```bash
APP_KEY=base64:...
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o
OPENAI_MODEL_CHAT=gpt-4o
OPENAI_MODEL_ANALYZE=gpt-4o-mini
OPENAI_MODEL_RERANK=gpt-4o-mini
MEILI_ENABLED=true
MEILI_HOST=https://meilisearch-aimbot.fly.dev
MEILI_MASTER_KEY=...
HOROSHOP_DOMAIN=https://contractor.kiev.ua
HOROSHOP_API_LOGIN=owner
HOROSHOP_API_PASSWORD=...
QUEUE_CONNECTION=database
CACHE_DRIVER=file
ADMIN_API_TOKEN=...
DIAGNOSTIC_SECRET_KEY=...
BILLING_DRIVER=wayforpay
```

---

## Команди

```bash
composer run dev                                          # dev server + queue + Vite
php artisan queue:work --queue=meili,default --tries=1   # queue worker
php dispatch-meili-index.php 500                          # реіндексація Meili
composer run test                                         # тести
```

---

## Деплой

Auto-deploy на [Laravel Cloud](https://laravel.cloud) з `main`:

```bash
git push origin main
# → Cloud запускає php artisan migrate --force автоматично
```

---

## Безпека

- Жодних секретів у репозиторії — тільки `.env` та `secrets/` (обидва gitignored)
- При підозрі на витік — негайна ротація ключів (OpenAI / Meili / Horoshop / токени)
- `secrets/secrets.example.sh` — шаблон для локального заповнення

---

## Документація

| Файл | Зміст |
|---|---|
| [.github/copilot-instructions.md](.github/copilot-instructions.md) | Архітектурний контекст (AI-асистент) |
| [docs/CHAT_ARCHITECTURE.md](docs/CHAT_ARCHITECTURE.md) | Pipeline, агенти, SSE |
| [docs/MULTI_TENANT.md](docs/MULTI_TENANT.md) | Tenant isolation, scope |
| [docs/BILLING.md](docs/BILLING.md) | Плани, WayForPay |
| [docs/DIAGNOSTIC_API.md](docs/DIAGNOSTIC_API.md) | Debug endpoints |
| [docs/PRODUCT_ENRICHMENT.md](docs/PRODUCT_ENRICHMENT.md) | AI-збагачення товарів |
| [docs/PROMPT_GENERATION_ARCHITECTURE.md](docs/PROMPT_GENERATION_ARCHITECTURE.md) | Per-tenant промпти |
| [secrets/README.md](secrets/README.md) | Локальні credentials |

---

## Ліцензія

MIT
