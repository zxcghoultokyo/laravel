# Команди для індексації products в Meilisearch

## 1. Індексація всіх товарів
```bash
php artisan app:index-products-to-meili
```

## 2. Rebuild category index (якщо потрібно)
```bash
php artisan app:rebuild-category-index
```

## 3. Sync products from Horoshop (якщо потрібні свіжі дані)
```bash
php artisan app:sync-horoshop-products
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
