# Production Configuration & Debugging

## Environment Variables (Production на contractor.kiev.ua)

### Core Application
```
APP_KEY=base64:gFu0hElUakUI69dU+9fcI=
APP_ENV=production
APP_DEBUG=false
APP_NAME=Contractor
APP_URL=https://contractor.kiev.ua
```

### OpenAI Integration
```
OPENAI_API_KEY=sk-proj-5Lf9p1J3RZK0DYA
OPENAI_MODEL=gpt-5.1
OPENAI_BASE_URL=https://api.openai.com/v1
```

### Meilisearch (Hosted on Fly.io)
```
MEILI_ENABLED=1
MEILI_HOST=https://meilisearch-aimbot.fly.dev
MEILI_MASTER_KEY=58684e6d92692
MEILI_INDEX_PRODUCTS=products
```
**⚠️ CRITICAL**: 
- Якщо `MEILI_ENABLED=0` → код падає на Eloquent fallback (повільно)
- Перевірити що Meili **доступний з Laravel Cloud** (network connectivity)
- Master key потрібен для синхронізації індексів

### Horoshop Platform (Taktyka API)
```
HOROSHOP_DOMAIN=https://contractor.kiev.ua
HOROSHOP_API_LOGIN=owner
HOROSHOP_API_PASSWORD=wel01
```

### Caching & Queue
```
CACHE_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
```

### Admin
```
ADMIN_JOBS_TOKEN=someret
```

### Logging
```
LOG_CHANNEL=stderr
```

---

## Debugging на Проді

### 1. Перевірити Meilisearch статус
```bash
# Через tinker
php artisan tinker
>>> config('meilisearch.enabled') ? 'Meili OK' : 'DISABLED'
>>> exit

# Або прямо через curl на fly.io
curl -i https://meilisearch-aimbot.fly.dev/health
```

### 2. Перевірити що продукти індексовані в Meili
```bash
# Через tinker
php artisan tinker
>>> $index = app('meilisearch')->index('products');
>>> $index->getRawInfo()['numberOfDocuments']
>>> exit
```

### 3. Протестувати пошук
```bash
# Meili search API
curl -X POST https://meilisearch-aimbot.fly.dev/indexes/products/search \
  -H "Authorization: Bearer 58684e6d92692" \
  -H "Content-Type: application/json" \
  -d '{"q": "плитоноска", "limit": 5}'

# Через Laravel Eloquent fallback
php artisan tinker
>>> \App\Models\Product::where('title', 'like', '%плитонос%')->count()
>>> \App\Models\Product::where('title', 'like', '%плитонос%')->first(['article', 'title'])->dump()
>>> exit
```

### 4. Перевірити пошук через чат
1. Відкрити https://contractor.kiev.ua
2. Відправити запит: `"плитоноска мультикам"`
3. Перевірити логи:
```bash
tail -100 storage/logs/laravel.log | grep -i "meili\|eloquent\|plitono"
```

### 5. Перевірити OpenAI інтеграцію
```bash
php artisan tinker
>>> app(\App\Services\Ai\AiRouter::class)->classify('плитоноска')
```

---

## Common Issues

### ❌ "Meilisearch is disabled (MEILI_ENABLED=0)"
- **Причина**: Env var не прочитано або файл `.env` не синхронізований
- **Фікс**: 
  ```bash
  # На Laravel Cloud:
  php artisan config:cache
  php artisan config:clear
  # або redeploy
  ```

### ❌ "Meilisearch connection refused"
- **Причина**: Network issue між Laravel Cloud та Fly.io
- **Фікс**: Перевірити що Fly.io Meili доступна:
  ```bash
  curl -i https://meilisearch-aimbot.fly.dev/health
  ```
- Якщо не відповідає: перезавантажити Meili на Fly

### ❌ "No products found" у пошуку
- **Причина 1**: Eloquent fallback не знайшов товарів:
  ```bash
  php artisan tinker
  >>> \App\Models\Product::count()  # Скільки всього товарів
  >>> \App\Models\Product::where('title', 'like', '%плитонос%')->count()  # Скільки плитоносок
  ```
- **Причина 2**: Meili не синхронізував індекс:
  ```bash
  # На проді запустити re-index
  php artisan meili:reindex-products
  # або через адмін
  curl https://contractor.kiev.ua/admin/jobs/rebuild-category-index?token=someret
  ```

### ❌ "Query expanded to too many synonyms"
- **Причина**: `normalizeSearchQuery()` у AiRouter додав занадто багато синонімів
- **Фікс**: Переконатися що запит містить специфічний продуктовий термін:
  ```php
  // app/Services/Ai/AiRouter.php line ~120
  $specificTerms = ['плитоноск', 'бронеплит', 'берц', ...];
  // Якщо запит містить один із цих термінів → ранній повернення
  ```

---

## Monitoring Commands

### Real-time log tail
```bash
# На Laravel Cloud SSH
tail -f storage/logs/laravel.log | grep -v "production.DEBUG"
```

### Check recent searches
```bash
php artisan tinker --execute="
\App\Models\ChatMessage::latest('created_at')
  ->limit(10)
  ->get(['id', 'message', 'created_at'])
  ->dump();
"
```

### Check product count by type
```bash
php artisan tinker --execute="
echo 'Total products: ' . \App\Models\Product::count() . \"\n\";
echo 'Plate carriers: ' . \App\Models\Product::where('title', 'like', '%плитонос%')->count() . \"\n\";
echo 'Armor plates: ' . \App\Models\Product::where('title', 'like', '%бронеплит%')->count() . \"\n\";
echo 'Boots: ' . \App\Models\Product::where('title', 'like', '%берц%')->count() . \"\n\";
"
```

### Check cache usage
```bash
# Session contexts cached in /storage/framework/cache
ls -lah storage/framework/cache/data/
```

---

## Deployment Pipeline

1. **Code push** → GitHub
2. **Laravel Cloud auto-deploys** (git hook)
3. **Run migrations** (auto or manual)
4. **Clear config cache**:
   ```bash
   php artisan config:cache
   ```
5. **Re-index if needed**:
   ```bash
   php artisan meili:reindex-products
   ```
6. **Verify** via test chat query

---

## Key Files Reference

- **Config**: 
  - [config/services.php](config/services.php) — OpenAI, Horoshop
  - [config/meilisearch.php](config/meilisearch.php) — Meili settings
  
- **Core Services**:
  - [app/Services/Ai/AiRouter.php](app/Services/Ai/AiRouter.php) — Intent classification, query normalization
  - [app/Services/Agent/AgentOrchestrator.php](app/Services/Agent/AgentOrchestrator.php) — Chat orchestration
  - [app/Services/Agent/Tools/MeiliProductSearchTool.php](app/Services/Agent/Tools/MeiliProductSearchTool.php) — Search execution
  
- **Models**:
  - [app/Models/Product.php](app/Models/Product.php) — Product entity
  - [app/Models/ChatSession.php](app/Models/ChatSession.php) — Session tracking
  
- **Logs**:
  - [storage/logs/laravel.log](storage/logs/laravel.log) — All events (check for errors)

