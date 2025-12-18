# Команди для індексації products в Meilisearch

## ✅ Правильні команди для production:

### 1. Налаштування Meilisearch індексу (один раз)
```bash
php artisan meili:setup-products
```
Встановлює settings для індексу (searchable attributes, filters, etc.)

### 2. Індексація всіх товарів
```bash
php artisan meili:reindex-products
```

Опції:
```bash
# З chunk size (дефолт: 500 товарів на job)
php artisan meili:reindex-products --chunk=1000
```

### 3. Sync products from Horoshop
```bash
php artisan sync:horoshop-products
```
Синхронізує товари з Хорошопу в локальну БД, потім треба зробити reindex.

## 📋 Повний flow для першого деплою:

```bash
# 1. Sync з Horoshop
php artisan sync:horoshop-products

# 2. Setup Meilisearch
php artisan meili:setup-products

# 3. Index всі products
php artisan meili:reindex-products

# 4. Restart queue
php artisan queue:restart

# 5. Optimize
php artisan optimize
```

## 🔍 Перевірка після індексації:

```bash
php artisan tinker
$meili = app(\App\Services\Search\MeiliClient::class);
$index = $meili->productsIndex();
$stats = $index->stats();
echo "Documents: " . $stats['numberOfDocuments'] . PHP_EOL;
exit
```

## 4. Перевірка індексу
```bash
php artisan tinker
```

```php
$meili = app(\App\Services\Search\MeiliClient::class);
$index = $meili->productsIndex();
$stats = $index->stats();
echo "Documents: " . $stats['numberOfDocuments'] . PHP_EOL;
```

## 5. Якщо потрібно видалити та переіндексувати
```bash
# Видалити індекс
php artisan tinker --execute="app(\App\Services\Search\MeiliClient::class)->client()->deleteIndex('products');"

# Створити новий та заповнити
php artisan app:index-products-to-meili
```

## Production deployment

```bash
# 1. Deploy code
git push origin main

# 2. На production через SSH або Laravel Cloud console:
php artisan migrate --force
php artisan app:index-products-to-meili
php artisan queue:restart
php artisan optimize
```
