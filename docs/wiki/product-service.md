# 📦 Product Service — Синхронізація та Індексація

> **Остання оновлення**: 22.12.2025  
> **Зовнішня інтеграція**: Horoshop API  
> **Індексація**: Meilisearch

---

## 📋 Зміст
1. [Огляд](#огляд)
2. [Horoshop Integration](#horoshop-integration)
3. [Product Model](#product-model)
4. [Синхронізація](#синхронізація)
5. [Індексація в Meilisearch](#індексація-в-meilisearch)
6. [Background Jobs](#background-jobs)
7. [Brands Management](#brands-management)

---

## Огляд

**ProductService** — центральний сервіс для управління товарами:
- Синхронізація з Horoshop (зовнішня e-commerce платформа)
- Збереження в локальну БД (Product model)
- Індексація в Meilisearch для швидкого пошуку
- Управління брендами (Brand model)

**Flow**:
```
Horoshop API
    ↓ SyncHoroshopProductsJob (щоденно 03:00)
Laravel Database (products table)
    ↓ IndexProductsToMeiliJob (щоденно 03:30)
Meilisearch
    ↓ MeiliProductSearchTool
Search Results
```

---

## Horoshop Integration

**Horoshop** — сторонній e-commerce SaaS для керування товарами, замовленнями, складом.

### HoroshopClient
**Файл**: [app/Services/Horoshop/HoroshopClient.php](../../app/Services/Horoshop/HoroshopClient.php)

**Роль**: Low-level HTTP wrapper для Horoshop API.

**Конфігурація**:
```php
// config/services.php
'horoshop' => [
    'domain' => env('HOROSHOP_DOMAIN'),  // example.horoshop.ua
    'login' => env('HOROSHOP_API_LOGIN'),
    'password' => env('HOROSHOP_API_PASSWORD'),
],
```

**Основний метод**:
```php
$response = $horoshopClient->request('catalog/export', [
    'limit' => 500,
    'offset' => 0,
    'includedParams' => [
        'title', 'price', 'article', 'brand', 'images',
        'display_in_showcase', 'presence', 'quantity', ...
    ],
]);
// Returns: ['status' => 'OK', 'products' => [...]]
```

**Error Handling**:
- Якщо `status = 'EXCEPTION'` → throw `RuntimeException`
- Retry logic: не реалізовано (TODO)

---

### ProductService
**Файл**: [app/Services/Horoshop/ProductService.php](../../app/Services/Horoshop/ProductService.php)

**Роль**: High-level orchestrator для синхронізації.

#### `syncFromHoroshop(int $limit = 200)`
Основний метод синхронізації.

**Алгоритм**:
```php
$offset = 0;
do {
    $response = $this->client->request('catalog/export', [
        'limit' => $limit,
        'offset' => $offset,
        'includedParams' => [...30+ параметрів]
    ]);
    
    $products = $response['products'];
    
    foreach ($products as $item) {
        $this->upsertProductFromHoroshop($item);
    }
    
    $offset += $limit;
} while (!empty($products));
```

**Performance**:
- ~500 товарів за запит
- ~2,800 товарів в БД → ~6 запитів
- Час виконання: ~30-60 секунд

---

#### `upsertProductFromHoroshop(array $item)`
Створює/оновлює Product в БД.

**Логіка**:
```php
// 1. Extract fields з Horoshop response
$article = $item['article'];
$titleUa = $item['title']['ua'] ?? '';
$brand = $item['brand']['value']['ua'] ?? null;
$images = $item['images'] ?? [];
$price = $item['price'] ?? 0;

// 2. Compute derived fields
$searchIndex = mb_strtolower("$titleUa $titleRu $categoryPath");
$inStock = $this->computeInStock($presence, $quantity);

// 3. Update or create
Product::updateOrCreate(
    ['article' => $article],
    [
        'title' => $titleUa,
        'price' => $price,
        'brand' => $brand,
        'images' => $images,
        'raw' => $item,  // Повний JSON відповіді
        'search_index' => $searchIndex,
        'in_stock' => $inStock,
        // ...30+ полів
    ]
);
```

**Важливі поля**:
- `raw` (JSON) — повна відповідь Horoshop (для дебагу та майбутніх фіч)
- `search_index` (TEXT) — lowercase concatenation title+category для Eloquent fallback
- `in_stock` (BOOLEAN) — computed from `presence` + `quantity`
- `popularity` (INT) — з Horoshop (важливо для ранкування)

---

## Product Model

**Файл**: [app/Models/Product.php](../../app/Models/Product.php)

### Схема Таблиці
```sql
CREATE TABLE products (
    id BIGINT PRIMARY KEY,
    article VARCHAR UNIQUE,           -- Унікальний артикул
    parent_article VARCHAR,           -- Для варіантів (розміри/кольори)
    title VARCHAR,
    title_json JSON,                  -- {ua: "...", ru: "..."}
    price INT,
    price_old INT,
    category_path VARCHAR,            -- "Тактичне спорядження/Плитоноски"
    brand VARCHAR,                    -- "АТАКА", "HOFFMANN", "KOMBAT UK"
    slug VARCHAR,
    link VARCHAR,
    images JSON,                      -- ["url1", "url2", ...]
    raw JSON,                         -- Повний JSON з Horoshop
    search_index TEXT,                -- Lowercase для Eloquent search
    
    -- Availability fields
    display_in_showcase BOOLEAN,
    presence VARCHAR,                 -- "instock", "outofstock", "onorder"
    quantity INT,
    in_stock BOOLEAN,                 -- Computed
    
    -- Popularity & recommendations
    popularity INT DEFAULT 0,
    we_recommended BOOLEAN,
    
    -- Product type (AI-generated)
    ai_product_type VARCHAR,          -- "plate_carrier", "helmet", etc.
    
    -- Timestamps
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX(article),
    INDEX(brand),
    INDEX(in_stock),
    INDEX(display_in_showcase),
    FULLTEXT(search_index)
);
```

### Eloquent Casts
```php
protected $casts = [
    'title_json' => 'array',
    'images' => 'array',
    'raw' => 'array',
    'in_stock' => 'boolean',
    'display_in_showcase' => 'boolean',
    'we_recommended' => 'boolean',
];
```

### Relationships
```php
// Many-to-many with ProductTag
public function tags()
{
    return $this->belongsToMany(ProductTag::class);
}
```

---

## Синхронізація

### Manual Sync (CLI)
```bash
php artisan sync:horoshop-products --limit=500
```

### Manual Sync (HTTP)
```bash
curl "https://aintento.laravel.cloud/api/admin/jobs/sync-horoshop?token=SECRET&mode=sync&limit=200"
```

**Response**:
```json
{
    "ok": true,
    "mode": "sync",
    "output": "Синхронізовано 2834 товарів"
}
```

### Automated Sync (Scheduler)
**Файл**: [bootstrap/app.php](../../bootstrap/app.php)

```php
$schedule->job(new SyncHoroshopProductsJob())
    ->dailyAt('03:00')  // Щоденно о 3:00 UTC
    ->onSuccess(fn () => Log::info('Sync completed'))
    ->onFailure(fn () => Log::error('Sync failed'));
```

---

## Індексація в Meilisearch

### MeiliClient
**Файл**: [app/Services/Search/MeiliClient.php](../../app/Services/Search/MeiliClient.php)

**Роль**: Wrapper для Meilisearch SDK.

**Методи**:
```php
// Get products index
$index = $meiliClient->productsIndex();

// Add/update documents
$index->addDocuments($products);

// Search
$results = $index->search('плитоноска', [
    'filter' => 'in_stock = true',
    'limit' => 40,
]);
```

### Індексні Налаштування
```php
// Sortable attributes (для фільтрів)
$index->updateSortableAttributes(['price', 'popularity', 'created_at']);

// Filterable attributes
$index->updateFilterableAttributes(['in_stock', 'brand', 'category_path']);

// Searchable attributes (з вагами)
$index->updateSearchableAttributes([
    'title',           // Найвища вага
    'brand',
    'category_path',
    'search_index',    // Lowest priority
]);
```

### IndexProductsToMeiliJob
**Файл**: [app/Jobs/IndexProductsToMeiliJob.php](../../app/Jobs/IndexProductsToMeiliJob.php)

**Роль**: Batch indexing у фоні.

**Алгоритм**:
```php
$chunk = 500;  // Індексувати по 500 за раз
$offset = 0;

do {
    $products = Product::where('display_in_showcase', true)
        ->where('in_stock', true)
        ->skip($offset)
        ->take($chunk)
        ->get();
    
    if ($products->isEmpty()) break;
    
    // Format for Meilisearch
    $documents = $products->map(fn($p) => [
        'id' => $p->id,
        'title' => $p->title,
        'price' => $p->price,
        'brand' => $p->brand,
        'in_stock' => $p->in_stock,
        'popularity' => $p->popularity,
        // ...інші поля
    ])->toArray();
    
    $index->addDocuments($documents);
    
    $offset += $chunk;
} while (true);
```

**Час виконання**: ~2-3 хвилини для 2,800 товарів.

---

## Background Jobs

### 1. SyncHoroshopProductsJob
**Schedule**: Щоденно 03:00 UTC  
**Тривалість**: ~30-60 секунд  
**Залежності**: HoroshopClient, ProductService

```php
dispatch(new SyncHoroshopProductsJob($limit = 200));
```

---

### 2. IndexProductsToMeiliJob
**Schedule**: Щоденно 03:30 UTC (після sync)  
**Тривалість**: ~2-3 хвилини  
**Залежності**: MeiliClient

```php
dispatch(new IndexProductsToMeiliJob($chunk = 500));
```

---

### 3. RebuildCategoryIndexJob
**Schedule**: Щоденно 03:20 UTC  
**Тривалість**: ~10-20 секунд  
**Залежності**: CategoryIndexService

Створює/оновлює таблиці `categories` та `category_aliases` з `products.category_path`.

---

### 4. SyncBrandsJob
**Schedule**: Щоденно 03:30 UTC (після sync)  
**Тривалість**: ~5 секунд  
**Залежності**: Brand model

Витягує унікальні бренди з `products.brand` і заповнює `brands` таблицю.

```php
dispatch(new SyncBrandsJob());
```

---

## Brands Management

### Brand Model
**Файл**: [app/Models/Brand.php](../../app/Models/Brand.php)

**Схема**:
```sql
CREATE TABLE brands (
    id BIGINT PRIMARY KEY,
    name VARCHAR UNIQUE,           -- "HOFFMANN"
    slug VARCHAR UNIQUE,           -- "hoffmann"
    product_count INT DEFAULT 0,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX(name),
    INDEX(slug)
);
```

**Auto-slug Generation**:
```php
protected static function boot()
{
    parent::boot();
    
    static::creating(function ($brand) {
        $brand->slug = Str::slug($brand->name);
        
        // Handle duplicates: hoffmann-1, hoffmann-2
        $originalSlug = $brand->slug;
        $counter = 1;
        while (static::where('slug', $brand->slug)->exists()) {
            $brand->slug = "{$originalSlug}-{$counter}";
            $counter++;
        }
    });
}
```

---

### BrandDetectionService
**Файл**: [app/Services/Search/BrandDetectionService.php](../../app/Services/Search/BrandDetectionService.php)

**Роль**: Детекція брендів у запитах + 3x boosting.

**Метод**:
```php
$result = $brandDetectionService->detectBrand('hoffmann куртка');
// Returns:
[
    'is_brand' => true,
    'brand' => 'HOFFMANN',
    'enhanced_query' => 'HOFFMANN HOFFMANN HOFFMANN hoffmann куртка'
]
```

**Логіка**:
1. Load brands from `brands` table (cached 24h)
2. Normalize query (lowercase, trim)
3. Check if query starts with brand name
4. If yes → repeat brand 3x for Meilisearch boosting

**Приклади**:
| Query | Detected Brand | Enhanced Query |
|-------|----------------|----------------|
| "hoffmann патчі" | HOFFMANN | "HOFFMANN HOFFMANN HOFFMANN hoffmann патчі" |
| "АТАКА плитоноска" | АТАКА | "АТАКА АТАКА АТАКА атака плитоноска" |
| "зелена куртка" | null | "зелена куртка" |

---

### SyncBrandsCommand
**CLI**:
```bash
php artisan brands:sync        # Sync brands synchronously
php artisan brands:sync --async  # Dispatch to queue
```

**Що робить**:
1. Query distinct brands з `products.brand`
2. Count products per brand
3. Upsert в `brands` таблицю
4. Update `product_count`

**Output**:
```
Starting brands sync...
✓ Created brand: HOFFMANN (134 products)
✓ Updated brand: АТАКА (441 products)
✓ Created brand: KOMBAT UK (530 products)
...
Brands sync complete: 73 brands (45 created, 28 updated)
```

---

## Відомі Проблеми

### 🔴 Horoshop API Rate Limiting
**Проблема**: Немає retry logic якщо API тимчасово недоступний  
**Рішення**: Implement exponential backoff в HoroshopClient  
**Priority**: Medium

---

### ⚠️ Meilisearch Indexing Slow
**Проблема**: 2,800 товарів = 3 хвилини індексації  
**Рішення**: Batch size 500 → розглянути збільшення до 1000  
**Priority**: Low (працює, але можна оптимізувати)

---

### 💡 Brand Sync Should Run After Product Sync
**Проблема**: Якщо brands:sync запускається ПЕРЕД horoshop:sync → неповні дані  
**Рішення**: ✅ FIXED — SyncBrandsJob запускається о 03:30 (після 03:00)  
**Priority**: ✅ Resolved

---

## Code References

### Файли
- [ProductService.php](../../app/Services/Horoshop/ProductService.php) — sync orchestrator
- [HoroshopClient.php](../../app/Services/Horoshop/HoroshopClient.php) — API client
- [Product.php](../../app/Models/Product.php) — Eloquent model
- [Brand.php](../../app/Models/Brand.php) — Brands model
- [BrandDetectionService.php](../../app/Services/Search/BrandDetectionService.php) — brand detection
- [MeiliClient.php](../../app/Services/Search/MeiliClient.php) — Meilisearch wrapper
- [SyncHoroshopProductsJob.php](../../app/Jobs/SyncHoroshopProductsJob.php) — sync job
- [IndexProductsToMeiliJob.php](../../app/Jobs/IndexProductsToMeiliJob.php) — indexing job
- [SyncBrandsJob.php](../../app/Jobs/SyncBrandsJob.php) — brands sync job

---

**Наступний документ**: [Known Issues →](known-issues.md)
