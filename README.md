## AI‑Powered Commerce — Backend (Laravel 12)

Проєкт: **Multi-tenant SaaS** для e‑commerce з AI‑чатботом, пошуком на Meilisearch та інтеграцією Horoshop. Продакшн-фокус: черги, ідемпотентність, tenant isolation, backward‑compat для job‑ів.

**Посилання:**
- Архітектурні інструкції: [.github/copilot-instructions.md](.github/copilot-instructions.md)
- Multi-tenant архітектура: [docs/MULTI_TENANT.md](docs/MULTI_TENANT.md)
- Billing система: [docs/BILLING.md](docs/BILLING.md)
- Chat архітектура: [docs/CHAT_ARCHITECTURE.md](docs/CHAT_ARCHITECTURE.md)

---

## 🏗️ Multi-Tenant Architecture

Кожен **tenant** (клієнт-магазин) має ізольовані:
- Каталог товарів
- Налаштування віджета
- Чат-сесії та історію
- Аналітику
- Білінг та підписку

**Ключові файли:**
```
app/Models/Tenant.php                    # Core tenant model
app/Scopes/TenantScope.php               # Global query scope
app/Http/Middleware/SetTenantContext.php # Sets tenant from request
app/Models/Concerns/BelongsToTenant.php  # Trait for scoped models
```

**Підписки:**
| План | Ціна | Повідомлень | Товарів |
|------|------|-------------|---------|
| Starter | 799₴/міс | 1,000 | 500 |
| Pro | 1,999₴/міс | 5,000 | 10,000 |
| Enterprise | Custom | ∞ | ∞ |

**Trial:** 14 днів з повним функціоналом Pro

### Архітектура (огляд)
- **Сервіси**: бізнес‑логіка в `app/Services/`.
	- `Ai/`: маршрутизація інтенцій, ранжування, індексація
	- `Chat/`: діалог, сесії, мульти‑інтент
	- `Search/`: Meili, парсинг запитів, кандидати
	- `Horoshop/`: синхронізація зовнішніх даних
	- `Catalog/`: категорії, alias‑и, сценарії
- **Моделі**: [app/Models](app/Models) з кастами (`json`, `boolean`, `integer`). Ключові: `Product`, `ProductAiIndex`, `Category`, `CategoryAlias`.
- **Черги/Job‑и**: [app/Jobs](app/Jobs) — індексація, побудова сценаріїв, синхронізація.
- **API**: [routes/api.php](routes/api.php), контролери в [app/Http/Controllers/Api](app/Http/Controllers/Api).

### Дані та пайплайни
- **Інжест даних (Horoshop → MySQL)**: Сирі дані зберігаються у `products.raw`; нормалізація й оновлення полів `search_index`, `category_path`, кількісні/статусні поля.
- **AI‑збагачення (ProductAiIndex)**: Зберігає `product_type`, `ai_category`, `keywords`, тощо — не всі продукти покриті відразу (частковість очікувана).
- **Індексація (Meilisearch)**: Job [app/Jobs/IndexProductsToMeiliJob.php](app/Jobs/IndexProductsToMeiliJob.php) формує документи для Meili. Backward‑compat збережено (старі job‑payload з `chunk`).
- **Пошук**:
	- Кандидати: Meili за категорією/текстом
	- Реранжування: `AiRouter::rankProductsByRelevance()` (AI + негативні терміни/контекст)
	- Fallback: механічні фільтри/дедуплікація в `ChatService`

### Чат‑оркестрація

**Архітектура з двома агентами:**
- **Streaming (SSE)**: GET /api/chat/stream → [StreamingFunctionCallingAgent](app/Services/Agent/StreamingFunctionCallingAgent.php) ← Widget використовує це
- **JSON (fallback)**: POST /api/chat → [FunctionCallingAgent](app/Services/Agent/FunctionCallingAgent.php)

Обидва агенти використовують OpenAI function calling з tools:
- `search_products` → MeiliProductSearchTool
- `get_product_details` → ProductDetailsTool
- `get_popular_products` → DB query
- `get_order_status` → OrderSearchService

Детальна документація: [docs/CHAT_ARCHITECTURE.md](docs/CHAT_ARCHITECTURE.md)

---

## Ролі компонентів
- **`SearchQueryParser`**: нормалізація запиту, price‑фільтри, сигнали з БД (`product_synonyms`, `color_synonyms`). Файл: [app/Services/Search/SearchQueryParser.php](app/Services/Search/SearchQueryParser.php)
- **`AiRouter`**: класифікація інтенції, нормалізація, реранжування. Файл: [app/Services/Ai/AiRouter.php](app/Services/Ai/AiRouter.php)
- **`ChatService`**: відповіді користувачу, сесії, показ продуктів, fallback‑логіка. Файл: [app/Services/Chat/ChatService.php](app/Services/Chat/ChatService.php)
- **`CategoryIndexService`**: побудова `categories` і `category_aliases` з `products.category_path`. Файл: [app/Services/Catalog/CategoryIndexService.php](app/Services/Catalog/CategoryIndexService.php)
- **Job `IndexProductsToMeiliJob`**: ідемпотентна індексація в Meili, сумісність зі старими payload. Файл: [app/Jobs/IndexProductsToMeiliJob.php](app/Jobs/IndexProductsToMeiliJob.php)

---

## Налаштування та середовище
- Ключові ENV (приклад):
	- `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_BASE_URL` (за замовчуванням official API)
	- `MEILI_ENABLED`, `MEILI_HOST`, `MEILI_MASTER_KEY`, `MEILI_INDEX_PRODUCTS`
	- `HOROSHOP_DOMAIN`, `HOROSHOP_API_LOGIN`, `HOROSHOP_API_PASSWORD`
	- `CACHE_DRIVER`, `QUEUE_CONNECTION`
	- `ADMIN_JOBS_TOKEN`
- Конфіг: [config/services.php](config/services.php), [config/meilisearch.php](config/meilisearch.php)

---

## Локальний запуск
- Дев‑режим (PHP server, queue listener, logs, Vite):
	- `composer run dev`
- Тести:
	- `composer run test`
- Запуск воркера (dev):
	- `php artisan queue:work --queue=meili,default --tries=1`

---

## Міграції (ключові таблиці)
- `products`: фактична схема — див. MySQL (`SHOW COLUMNS FROM products`).
- `product_ai_index`: AI‑сигнали на продукт.
- `categories`: шлях/нормалізований шлях/slug/лічильник.
- `category_aliases`: alias‑и для резолюції категорій.

Міграції додані в [database/migrations](database/migrations). Нове: [2025_12_18_000020_create_category_aliases_table.php](database/migrations/2025_12_18_000020_create_category_aliases_table.php).

---

## Backward‑compat черг
- Старі job‑и могли містити поле `chunk`. Клас 
	[app/Jobs/IndexProductsToMeiliJob.php](app/Jobs/IndexProductsToMeiliJob.php)
	підтримує `chunk` та `chunkSize` через `effectiveChunkSize()`.
- Рекомендовані команди у production:
	- `php artisan migrate --force`
	- `php artisan queue:flush`
	- `php artisan queue:restart`
	- `php artisan queue:retry all` (за потреби)

---

## Індексація в Meilisearch
Документ формується на базі реальних полів БД:
- id, article, parent_article, title, category_path, brand, color
- search_index, in_stock, display_in_showcase, quantity, presence_raw
- price, price_old (float)
- we_recommended, popularity, counters
- updated_at_ts (timestamp з `updated_at`)
- ai_product_type, ai_category (через зв’язок `aiIndex`)

Немає використання вигаданих колонок (напр. `camo_group`, `category_id`, `updated_at_ts` у БД) — лише те, що існує.

---

## Пошук і ранжування
- Кандидати з Meili (категорія/текст)
- Реранжування AI (контекст сесії, негативні терміни, пояснення)
- Fallback: механічні фільтри + дедуплікація за назвою
- Відповіді чіткі: коли немає релевантних — чесний 0 з підказкою

---

## Продакшн експлуатація
- Worker окремим процесом (daemon) з чергою `meili,default`
- Логи: `php artisan pail` або канал у `.env` (`LOG_CHANNEL`)
- Моніторинг витрат AI та частковості `product_ai_index`

---

## Admin Panel

**URL**: `/admin`

- **Dashboard** (`/admin`): Health status, metrics, circuit breakers, активні сесії
- **Діалоги** (`/admin/chats`): Всі чат-сесії з фільтрами
- **Деталі чату** (`/admin/chats/{id}`): Повна історія + можливість "взяти в роботу"
- **Налаштування віджету** (`/admin/widget`): Конфігурація

### Live Chat Takeover

1. Відкрий чат → натисни **"Взяти в роботу"**
2. AI автоматично вимикається для цієї сесії
3. Пиши повідомлення клієнту напряму
4. **"Повернути AI"** → AI знову відповідає

Для real-time оновлень (WebSocket) див. [docs/WEBSOCKET_SETUP.md](docs/WEBSOCKET_SETUP.md)

---

## Аудит поточного стану і слабкі місця
- Часткове покриття `product_ai_index` → реранжування має fallback.
- `category_aliases` — потрібне заповнення і регулярна перебудова з `CategoryIndexService`.
- Відсутній scheduler: задачі не зареєстровані у [app/Console/Kernel.php](app/Console/Kernel.php).
- Секрети у середовищі — обов’язково зберігати поза репозиторієм, регулярно ротувати.

---

## Короткий roadmap покращень
- Категорійна розвідка:
	- Автоматичне оновлення `categories`/`category_aliases` кроном; ваги alias‑ів → вплив на пошук.
- AI‑ранжування:
	- Змішаний скоринг: AI score + бізнес‑сигнали (популярність, в наявності).
	- Кешування реренк‑відповідей на короткий час.
- Чат/UX:
	- Пояснення у відповіді: чому показані саме ці товари, скільки в наявності, що відфільтровано.
	- Більше прозорості для “0 результатів” з мікро‑підказками.
- Інфраструктура:
	- Додати `schedule:work` процес у Cloud, healthchecks для воркерів.
	- Unit/Feature тести для stateful‑пошуку, guard‑фільтрів, fallback‑шляхів.

---

## Безпека
- Не зберігати секрети у репозиторії.
- При витоках — негайна ротація ключів (AI/Meili/токени/паролі).

---

## Ліцензія
MIT (як для шаблону Laravel). 
