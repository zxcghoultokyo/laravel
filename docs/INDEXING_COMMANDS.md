# Команди для індексації products в Meilisearch

## ✅ Правильні команди для production:

### 1. Налаштування Meilisearch індексу (один раз)
```bash
php artisan meili:setup-products
```
Встановлює settings для індексу (searchable attributes, filterable, sortable, ranking, synonyms).

### 2. Індексація всіх товарів (async)
```bash
php artisan meili:reindex-products
```
Dispatch `IndexProductsToMeiliJob` — async через queue (chunk=500).

Опції:
```bash
# З custom chunk size
php artisan meili:reindex-products --chunk=1000
```

### 3. Індексація всіх товарів (sync)
```bash
php artisan meili:reindex-products-sync
```
Синхронна індексація для тестів або дрібних каталогів (limit=100 за замовчуванням).

### 4. Sync products from Horoshop
```bash
php artisan horoshop:sync
```
Синхронізує товари з Хорошопу в локальну БД, потім треба зробити reindex.

## 📋 Повний flow для нового тенанта:

```bash
# 1. Sync з Horoshop (per-tenant)
php artisan horoshop:sync --tenant={id}

# 2. Rebuild категорій
php artisan categories:rebuild --tenant={id}

# 3. AI Enrichment
php artisan products:build-ai-index --tenant={id}

# 4. Setup Meilisearch (один раз, якщо новий індекс)
php artisan meili:setup-products

# 5. Reindex в Meilisearch
php artisan meili:reindex-products

# 6. Генерація промпта
curl -X POST "https://aintento.laravel.cloud/api/diagnostic/generate-prompt/{tenantId}?key=<DIAGNOSTIC_KEY>"
```

## 🔍 Перевірка після індексації:

```bash
# Через diagnostic API
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=<DIAGNOSTIC_KEY>"

# Через tinker
php artisan tinker --execute="
\$meili = app(\App\Services\Search\MeiliClient::class);
\$index = \$meili->productsIndex();
\$stats = \$index->stats();
echo 'Documents: ' . \$stats['numberOfDocuments'] . PHP_EOL;
"
```

## 🔄 Якщо потрібно повністю перестворити індекс:

```bash
# Видалити індекс
php artisan tinker --execute="app(\App\Services\Search\MeiliClient::class)->client()->deleteIndex('products');"

# Створити новий та заповнити
php artisan meili:setup-products
php artisan meili:reindex-products
```

## 🚀 Production deployment

```bash
# 1. Deploy code
git push origin main

# 2. На production через Laravel Cloud console:
php artisan migrate --force
php artisan meili:reindex-products
php artisan queue:restart
php artisan optimize
```

## ⏰ Автоматичний Schedule

Indexing запускається автоматично через `routes/console.php`:

| Час | Task | Опис |
|-----|------|------|
| 03:00 | `sync-all-tenants` | Horoshop sync для всіх активних тенантів |
| 04:00 | `ai-enrichment-all-tenants` | AI enrichment нових товарів |
| 05:00 | `meili-reindex-all` | Meilisearch повна переіндексація |
| 30 хв | `ai-enrichment-health-check` | Auto-restart якщо coverage < 95% |

---

*Last updated: March 2026*
