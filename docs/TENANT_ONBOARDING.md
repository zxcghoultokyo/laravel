# Tenant Onboarding Checklist

Документ описує всі кроки та задачі які мають виконуватись при створенні нового тенанта.

## 🚀 Автоматичний Онбординг (IMPLEMENTED ✅)

При створенні тенанта автоматично запускається `OnboardTenantJob` який:

| Дія | Статус | Деталі |
|-----|--------|--------|
| Sync продуктів з Horoshop | ✅ | Синхронно, всі товари |
| Rebuild категорій | ✅ | Per-tenant |
| Sync брендів | ✅ | Per-tenant |
| AI Enrichment | ✅ | ВСІ товари (батчами по 50) |
| Meili індексація | ✅ | Per-tenant |

### Прогрес-бар в реальному часі

Livewire компонент `<livewire:onboarding-progress />` показує:
- Загальний прогрес (%)
- Поточний крок з деталями
- Статистику по кожному етапу (кількість товарів, категорій, брендів)

**Де відображається:**
- `/onboarding` - крок 3 (синхронізація)
- `/dashboard` - якщо онбординг в процесі або не завершений

### Кроки онбордингу:

| Крок | Назва | Вага | Опис |
|------|-------|------|------|
| `horoshop_sync` | Синхронізація товарів | 25% | API Horoshop → БД |
| `categories_rebuild` | Побудова категорій | 10% | Витягування з товарів |
| `brands_sync` | Синхронізація брендів | 5% | Витягування з товарів |
| `ai_enrichment` | AI збагачення | 40% | Ключові слова, сленг, категоризація |
| `meili_indexing` | Індексація пошуку | 20% | Meilisearch |

## 📋 Повний Чекліст Онбордингу

### Phase 1: Створення тенанта
```bash
# 1. Створити тенант (через адмін панель або)
php artisan tinker
>>> \App\Models\Tenant::create(['name' => 'New Shop', 'slug' => 'new-shop'])
```

### Phase 2: Налаштування інтеграції
```bash
# 2. Налаштувати Horoshop credentials в widget_settings
# Через адмін: /admin/tenants/{id}/horoshop

# 3. Налаштувати widget (кольори, стиль, тон)
# Через адмін: /admin/tenants/{id}/widget
```

### Phase 3: Sync даних (КРИТИЧНО!)
```bash
# 4. Запустити sync продуктів
php artisan horoshop:sync --tenant={tenant_id}

# 5. Rebuild категорій (ПІСЛЯ sync!)
php artisan categories:rebuild --tenant={tenant_id}

# 6. Sync брендів
php artisan brands:sync --tenant={tenant_id}
```

### Phase 4: AI обробка
```bash
# 7. AI enrichment продуктів
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/run-enrichment?key=diagnostic_secret_key_2025&tenant_id={tenant_id}&batch_size=100"

# 8. Почекати поки enrichment закінчиться (перевірити)
curl "https://aintento.laravel.cloud/api/diagnostic/ai-enrich-stats?key=diagnostic_secret_key_2025&tenant_id={tenant_id}"
```

### Phase 5: Пошукова індексація
```bash
# 9. Reindex в Meilisearch (після enrichment!)
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/reindex-meili?key=diagnostic_secret_key_2025&tenant_id={tenant_id}"
```

### Phase 6: Перевірка
```bash
# 10. Перевірити статистику
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=diagnostic_secret_key_2025&tenant_id={tenant_id}"

# 11. Тестовий чат
curl -X POST "https://aintento.laravel.cloud/api/chat" \
  -H "Content-Type: application/json" \
  -d '{"message":"покажи товари","session_id":"onboarding-test","tenant_id":{tenant_id}}'
```

## 🔄 Diagnostic API Endpoints для Онбордингу

| Endpoint | Метод | Опис |
|----------|-------|------|
| `/api/diagnostic/sync-horoshop?tenant_id=X` | POST | Sync продуктів |
| `/api/diagnostic/rebuild-categories?tenant_id=X` | POST | Rebuild категорій |
| `/api/diagnostic/run-enrichment?tenant_id=X` | POST | AI enrichment |
| `/api/diagnostic/reindex-meili?tenant_id=X` | POST | Meili індексація |
| `/api/diagnostic/db-stats?tenant_id=X` | GET | Статистика |
| `/api/diagnostic/ai-enrich-stats?tenant_id=X` | GET | Статус enrichment |

## ⚠️ Частi Помилки

### 1. Категорії не створились
**Симптом**: Cross-sell не працює, категорії пусті
**Причина**: Забули запустити `categories:rebuild` після sync
**Рішення**: 
```bash
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/rebuild-categories?key=...&tenant_id=X"
```

### 2. Пошук не працює
**Симптом**: Товари не знаходяться в чаті
**Причина**: Не проіндексовано в Meili або немає AI enrichment
**Рішення**: Спочатку enrichment, потім reindex

### 3. Cross-sell повертає null
**Симптом**: `cross_sell: null` в API відповіді
**Причина**: Категорії не мають `tenant_id` або їх немає
**Рішення**: Rebuild категорій

## 📅 Scheduled Tasks (Автоматичні)

| Час | Task | Опис |
|-----|------|------|
| 03:00 | `sync-all-tenants` | Horoshop sync для всіх активних тенантів |
| 03:30 | `brands:sync` | Sync брендів |
| 04:00 | `ai-enrichment-all-tenants` | AI enrichment |
| 05:00 | `meili-reindex-all` | Meilisearch reindex |
| 05:30 | `colors:detect` | Детекція кольорів |
| 06:00 | `products:update-orders-count` | Оновлення статистики |
| **Неділя 02:00** | `categories:rebuild` | Rebuild категорій |
| 08:00, 20:00 | `orders:sync` | Sync замовлень |

## 🛠️ Автоматизація Онбордингу (IMPLEMENTED ✅)

OnboardTenantJob автоматично запускається при створенні тенанта:

```php
// app/Jobs/OnboardTenantJob.php
class OnboardTenantJob implements ShouldQueue
{
    public function __construct(public int $tenantId) {}
    
    public function handle()
    {
        // 1. Sync products from Horoshop (if configured)
        SyncHoroshopProductsJob::dispatchSync($this->tenantId);
        
        // 2. Rebuild categories
        app(CategoryIndexService::class)->rebuildForTenant($this->tenantId);
        
        // 3. Start AI enrichment (async)
        AnalyzeProductsWithAiJob::dispatch(100, 0, false, $this->tenantId);
        
        // 4. Reindex in Meili (delayed by 10 min)
        IndexProductsToMeiliJob::dispatch($this->tenantId)->delay(now()->addMinutes(10));
    }
}
```

**Trigger points:**
- `TenantsManager::create()` - адмін панель (негайно)
- `RegisteredUserController::store()` - реєстрація (з затримкою 5 хв)
- `/api/diagnostic/onboard-tenant` - ручний запуск

---

## 📊 Аудит Таблиць на Мультитенантність

### ✅ Таблиці з tenant_id (OK)

| Таблиця | Модель | TenantScope |
|---------|--------|-------------|
| `products` | Product | ✅ |
| `categories` | Category | ✅ |
| `chat_sessions` | ChatSession | ✅ |
| `chat_messages` | ChatMessage | через session |
| `widget_settings` | WidgetSettings | ✅ |
| `canned_responses` | CannedResponse | ✅ |
| `greetings` | Greeting | ✅ |
| `prompt_presets` | PromptPreset | ✅ |
| `proactive_trigger_rules` | ProactiveTriggerRule | ✅ |
| `store_contexts` | StoreContext | ✅ |
| `sync_logs` | SyncLog | ✅ |
| `users` | User | ✅ |
| `payments` | Payment | ✅ |
| `subscriptions` | Subscription | ✅ |

### ⚠️ Таблиці БЕЗ tenant_id (Потребують аналізу)

| Таблиця | Модель | Потрібен tenant_id? | Коментар |
|---------|--------|---------------------|----------|
| `orders` | Order | ✅ **ТАК** | Замовлення прив'язані до магазину! |
| `order_items` | OrderItem | ❌ Ні | Через order |
| `brands` | Brand | ✅ **ТАК** | Різні магазини мають різні бренди |
| `cross_sell_rules` | CrossSellRule | ✅ **ТАК** | Правила per-tenant |
| `category_aliases` | CategoryAlias | ✅ **ТАК** | Аліаси per-tenant |
| `category_scripts` | CategoryScript | ✅ **ТАК** | Скрипти per-tenant |
| `product_ai_index` | ProductAiIndex | ❌ Ні | Через product_id (product має tenant_id) |
| `product_cross_sells` | ProductCrossSell | ❌ Ні | Через product_id |
| `product_synonyms` | ProductSynonym | ❔ Може | Глобальні чи per-tenant? |
| `product_tags` | ProductTag | ❔ Може | Глобальні чи per-tenant? |
| `color_synonyms` | ColorSynonym | ❌ Ні | Глобальний словник кольорів |
| `scenarios` | Scenario | ❔ Може | Сценарії per-tenant? |
| `search_eval_cases` | SearchEvalCase | ❌ Ні | Тестові дані |
| `ai_generation_logs` | AiGenerationLog | ❔ Може | Для дебагу per-tenant |

### 🔴 КРИТИЧНІ (в роботі - міграції створено)

1. **`orders`** - ✅ Міграція створена: `2026_01_23_230000_add_tenant_id_to_orders_table.php`
2. **`brands`** - ✅ Міграція створена: `2026_01_23_230100_add_tenant_id_to_brands_table.php`
3. **`cross_sell_rules`** - правила cross-sell глобальні (TODO)

### 🟡 ВАЖЛИВІ (виправити найближчим часом)

4. **`category_aliases`** - аліаси категорій глобальні
5. **`category_scripts`** - скрипти категорій глобальні

### 🟢 МОЖНА ЗАЛИШИТИ ГЛОБАЛЬНИМИ

- `color_synonyms` - універсальний словник кольорів
- `product_ai_index` - має зв'язок через product
- `product_cross_sells` - має зв'язок через product
- `search_eval_cases` - тестові дані для QA

---

## 🔧 План виправлення

### Sprint 1 (Критичне) - ✅ DONE
- [x] Додати `tenant_id` до `orders` - міграція створена
- [x] Оновити Order model для tenant filtering
- [x] Міграція даних (backfill з chat_sessions)
- [x] Додати `tenant_id` до `brands` - міграція створена
- [x] Оновити Brand model для tenant filtering

### Sprint 2 (Важливе)  
- [ ] Оновити brands:sync для per-tenant
- [ ] Додати `tenant_id` до `cross_sell_rules`
- [ ] Оновити CrossSellService для rules per-tenant

### Sprint 3 (Бажане)
- [ ] Додати `tenant_id` до `category_aliases`
- [ ] Додати `tenant_id` до `category_scripts`

---

*Last updated: 2026-01-23*
