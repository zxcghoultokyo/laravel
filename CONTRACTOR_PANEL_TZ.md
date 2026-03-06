# ТЗ-Промпт: Contractor Panel (Розетка + Хорошоп)

> Промпт для Claude Opus 4.6 — побудова Contractor Panel на окремому Laravel 12 додатку

---

## 🎯 Загальна мета

Побудувати **Contractor Panel** — веб-панель для управління товарами між двома платформами:
- **Хорошоп** (horoshop.ua) — основне джерело товарів (інтернет-магазин)
- **Розетка** (rozetka.com.ua) — маркетплейс, куди вивантажуються товари

Панель дозволяє:
1. Синхронізувати каталог товарів з Хорошоп API
2. Синхронізувати товари з Розетка Seller API
3. Зв'язувати товари між платформами (по артикулу)
4. Редагувати товари перед відправкою на Розетку
5. Призначати категорії та атрибути Розетки
6. Пушити зміни на Розетку через API

---

## 🏗️ Стек технологій

- **PHP 8.3** + **Laravel 12**
- **Livewire 4** (full-page components як роути)
- **Alpine.js 3** (клієнтська інтерактивність)
- **Tailwind CSS 3**
- **MySQL** (production) / **SQLite** (тести)
- **Laravel Queue** (фонові джоби синхронізації)
- **Cache** (прогрес синхронізації через Cache::put/get)

---

## 🔐 Аутентифікація

Простий session-based логін без Eloquent User моделі:

```
POST /contractor/login → перевіряє username + password через Hash::check()
```

- Credentials зберігаються в `.env`:
  - `CONTRACTOR_USERNAME` (default: 'contractor')
  - `CONTRACTOR_PASSWORD_HASH` (bcrypt хеш)
- В `config/services.php`:
  ```php
  'contractor' => [
      'username' => env('CONTRACTOR_USERNAME', 'contractor'),
      'password_hash' => env('CONTRACTOR_PASSWORD_HASH'),
  ],
  ```
- Session keys: `contractor_authenticated` (bool), `contractor_username` (string)
- Middleware `ContractorAuth` — перевіряє `session('contractor_authenticated')`, редіректить на login якщо false
- `AuthController`: showLogin, login (validate + Hash::check), logout (forget session)

---

## 🗺️ Роути

```
# Публічні (middleware: web)
GET  /contractor/login              → AuthController@showLogin
POST /contractor/login              → AuthController@login
POST /contractor/logout             → AuthController@logout

# Захищені (middleware: web + ContractorAuth)
GET  /contractor/rozetka            → RozetkaProductList (Livewire full-page)
GET  /contractor/horoshop           → HoroshopProductList (Livewire full-page)
```

Layout: `contractor/layout.blade.php` — навігація з двома посиланнями (Розетка 🛒, Хорошоп 🛍️), підсвітка активної сторінки, логаут.

---

## 📊 База даних

### rozetka_products (основна таблиця Розетки)

| Колонка | Тип | Опис |
|---------|-----|------|
| id | bigint PK | |
| tenant_id | bigint FK | ID тенанта |
| rozetka_item_id | bigint unique nullable | ID товару на Розетці |
| price_offer_id | bigint nullable | ID пропозиції (offer) |
| is_duplicate | boolean false | Чи дублікат по артикулу |
| primary_offer_id | varchar nullable | ID основного offer якщо дублікат |
| article | varchar indexed | Артикул |
| parent_article | varchar nullable | Батьківський артикул |
| title | varchar | Назва |
| description | text nullable | Опис |
| description_ua | text nullable | Опис українською |
| price | decimal(12,2) | Ціна |
| price_old | decimal(12,2) nullable | Стара ціна |
| rozetka_category_id | bigint nullable | FK до категорії Розетки |
| rozetka_category_name | varchar nullable | Назва категорії |
| in_stock | boolean false | В наявності |
| quantity | smallint unsigned 0 | Кількість |
| upload_status | smallint nullable | 0=новий, 1=модерація, 2=активний, 3=вимкнений, 4=архів, 9=модерація✗ |
| upload_status_title | varchar(100) nullable | Текст статусу |
| moderation_status | tinyint unsigned 0 | Статус модерації |
| rz_status | smallint nullable | Статус на Розетці |
| rz_sell_status | smallint nullable | Статус продажу |
| available | smallint nullable | Доступність |
| available_title | varchar(100) nullable | Текст доступності |
| blocked_reasons | json nullable | Причини блокування (array) |
| change_status | smallint nullable | Статус змін |
| producer_name | varchar(255) nullable | Виробник |
| url | varchar(500) nullable | URL на Розетці |
| status | varchar 'active' | Внутрішній статус |
| export_status | varchar(20) nullable | 'draft'\|'ready' для нових товарів |
| local_product_id | bigint nullable FK | Зв'язок з базою товарів |
| group_id | bigint 0 | Група товарів |
| photos | json nullable | Масив фото |
| raw | json nullable | Повна відповідь API |
| edited_fields | json nullable | Відредаговані поля |
| has_local_changes | boolean false | Є локальні зміни |
| synced_at | timestamp nullable | Час останньої синхронізації |

**Unique:** (tenant_id, price_offer_id)
**Index:** (tenant_id, article)

### horoshop_products (каталог Хорошоп)

| Колонка | Тип | Опис |
|---------|-----|------|
| id | bigint PK | |
| tenant_id | bigint FK | |
| article | varchar(500) | Артикул |
| parent_article | varchar(500) nullable | Батьківський артикул |
| title | varchar(500) nullable | Назва |
| title_json | json nullable | Багатомовна назва |
| price | decimal(12,2) 0 | |
| price_old | decimal(12,2) nullable | |
| brand | varchar(255) nullable | |
| color | varchar(255) nullable | |
| size | varchar(255) nullable | |
| category_path | varchar(500) nullable | Шлях категорії з Хорошоп |
| in_stock | boolean false | |
| quantity | int unsigned 0 | |
| presence | varchar(255) nullable | Текст наявності |
| display_in_showcase | boolean true | |
| description_ua/ru | text nullable | |
| short_description_ua/ru | text nullable | |
| images | json nullable | Масив зображень |
| gallery_common | json nullable | |
| characteristics | json nullable | Характеристики |
| seo_title/keywords/description | json nullable | SEO (багатомовне) |
| slug | varchar(500) nullable | |
| link | varchar(1000) nullable | URL на сайті |
| popularity | int unsigned 0 | |
| we_recommended | boolean false | |
| icons | json nullable | |
| mod_title | varchar(500) nullable | |
| raw | json nullable | Повний payload з API |
| rozetka_product_id | bigint nullable indexed | FK до rozetka_products.id |
| synced_at | timestamp nullable | |

**Unique:** (tenant_id, article)
**Index:** (tenant_id, parent_article), (brand), (category_path)

### rozetka_categories (глобальні, без tenant_id)

| Колонка | Тип |
|---------|-----|
| rozetka_id | bigint unique | 
| title_ua/ru | varchar |
| parent_rozetka_id | bigint nullable FK (self) |
| level | tinyint unsigned 1 |
| mpath | varchar nullable (materialized path) |
| full_path | varchar nullable ("Батько > Дитина > Внук") |
| is_vendor_required | boolean false |

### rozetka_category_attributes (глобальні)

| Колонка | Тип |
|---------|-----|
| rozetka_category_id | bigint indexed |
| attribute_id | bigint |
| name | varchar |
| attr_type | varchar(50) — ComboBox, List, ListValues, TextInput, Decimal, Integer, CheckBox, CheckBoxGroupValues, TextArea, MultiText |
| filter_type | varchar(50) — 'main' (обов'язковий) або 'disable' |
| unit | varchar nullable (мм, кг, тощо) |
| is_global | boolean false |
| values | json nullable — [{id, name}, ...] для dropdown-типів |

### rozetka_product_attribute_values

| Колонка | Тип |
|---------|-----|
| rozetka_product_id | bigint FK |
| attribute_id | bigint |
| attribute_name | varchar |
| value_id | bigint nullable (для dropdown вибору) |
| value_text | text nullable (для текстових полів) |

**Unique:** (rozetka_product_id, attribute_id)

### rozetka_category_mappings (з tenant_id)

| Колонка | Тип |
|---------|-----|
| tenant_id | bigint FK |
| local_category_id | bigint nullable |
| local_category_name | varchar |
| local_category_source | varchar 'hprofit' |
| rozetka_category_id | bigint |
| rozetka_category_name | varchar |
| rozetka_category_path | varchar nullable |
| is_confirmed | boolean false |
| matched_by | varchar 'manual' — manual\|auto\|ai |

---

## 🔄 Зв'язки між моделями

```
RozetkaProduct:
  → belongsTo Tenant
  → belongsTo RozetkaCategory (via rozetka_category_id → rozetka_id)
  → hasMany RozetkaProductAttributeValue
  → belongsTo Product (local_product_id) — зв'язок з основною базою
  → hasOne HoroshopProduct (horoshop.rozetka_product_id → rozetka.id)

HoroshopProduct:
  → belongsTo Tenant
  → belongsTo RozetkaProduct (rozetka_product_id)

RozetkaCategory:
  → hasMany self (children via parent_rozetka_id)
  → hasMany RozetkaCategoryAttribute
```

---

## 🔌 Зовнішні API

### Хорошоп API (horoshop.ua)

**Базовий URL:** `https://{domain}/api/`

**Авторизація:** POST `/auth` з login+password → отримуєш token, кешується на 50 хвилин.

**Credentials тенанта:** зберігаються в `tenants.platform_credentials` (encrypted JSON):
```json
{
  "horoshop_domain": "attack.kiev.ua",
  "horoshop_login": "...",
  "horoshop_password": "..."
}
```

**Ендпоінт для синхронізації:** POST `/catalog/export`
```json
{
  "limit": 500,
  "offset": 0,
  "includedParams": ["title", "article", "parent_article", "price", "images", "presence", "quantity", "color", "brand", "description", "characteristics", "slug", "link", ...]
}
```
Повертає `{ "products": [...] }` — масив товарів з вкладеними структурами (title.ua, brand.value.ua, тощо).

### Розетка Seller API

**Базовий URL:** `https://api-seller.rozetka.com.ua`

**Авторизація:** POST `/sites` з base64(password) → `access_token`, кешується 1 годину.

**Credentials:** `.env` — `ROZETKA_USERNAME`, `ROZETKA_PASSWORD`

**Ендпоінти:**
| Метод | URL | Опис |
|-------|-----|------|
| GET | `/goods/all?page=N` | Список всіх товарів (100 на сторінку) |
| PUT | `/items-create/mass-update-basic-data` | Оновлення товару |
| GET | `/items-create/categories?page=N` | Список категорій |
| GET | `/items-create/attributes?category_id=X` | Атрибути категорії |
| GET | `/items-create/values?category_id=X&attr_id=Y` | Значення атрибута |
| GET | `/v1/market-categories/category-options?category_id=X` | Legacy атрибути |

---

## ⚙️ Сервіси (бізнес-логіка)

### HoroshopCatalogService

**`syncFullCatalog(Tenant $tenant, int $limit = 500)`**
1. Пагінує `/catalog/export` по 500 товарів
2. Для кожного товару — `upsertHoroshopProduct()`:
   - Розпарсити вкладені структури (title.ua, brand.value.ua, description.value.ua, тощо)
   - Витягти color з характеристик (Kolir, Колір, Color)
   - Витягти size з характеристик (Rozmir, Розмір) або з title по regex
   - Перевірити stock: quantity > 0 АБО presence містить "в наявності"
   - Зберегти повний raw payload
3. Оновлює прогрес в кеші: `horoshop_catalog_sync_progress_{tenantId}`
4. Після завершення — `matchWithRozetka()`: зв'язує по артикулу
5. Повертає `{total, created, updated, matched}`

### RozetkaProductService

**`syncProducts(int $tenantId, ?callable $onProgress)`**
1. Пагінує `/goods/all` по 100
2. Upsert кожного товару з масою полів (статуси, блокування, фото, тощо)
3. Зв'язує з локальними товарами по артикулу
4. Після завершення — `markDuplicates()`: знаходить дублі по артикулу, залишає один primary

**`pushToRozetka(RozetkaProduct $product, bool $autoApprove)`**
1. Будує payload (name, description, price, photos, params)
2. PUT на `/items-create/mass-update-basic-data`
3. Скидає `has_local_changes`, `edited_fields`

### RozetkaCategoryService
- `syncCategories()` — завантажити всі категорії Розетки
- `searchCategories($query)` — пошук по назві/шляху

### RozetkaAttributeService
- `syncCategoryAttributes($categoryId)` — завантажити атрибути + їх значення
- `getAttributesForCategory($categoryId)` — отримати з кешу або синхронізувати

---

## 🖥️ UI компоненти

### Сторінка Розетки (`/contractor/rozetka`)

**Stats bar:** Total, по upload_status (кольорові бейджі, клікабельні як фільтри), in_stock, blocked, matched/unmatched, duplicates.

**Фільтри:** Пошук (артикул/назва), наявність (dropdown), upload_status (клік по бейджу), match status (клік по бейджу).

**Список товарів:** Фото, назва+артикул+match dot, upload status badge, категорія, ціна, stock, expand arrow.

**Розгорнута картка товару (inline, не модальне вікно):**
- Ліва панель (1/3): фото + галерея, статус-інфо (CRM таблиця), помилки, зміни
- Права панель (2/3):
  - Кнопки: Push to Rozetka, Push+AutoModerate, Discard changes
  - Редаговані поля: title, description, price, price_old, quantity, producer (з порівнянням з Хорошоп)
  - Вибір категорії (autocomplete пошук)
  - Характеристики категорії (dropdown/text/number/checkbox залежно від attr_type)
  - Згорнутий блок "Хорошоп дані"

**Синхронізація:** Кнопка → dispatch SyncRozetkaProductsJob → poll кешу кожні 3 сек → прогрес бар із %

### Сторінка Хорошоп (`/contractor/horoshop`)

**Stats bar:** Total, in_stock, matched/unmatched з Розеткою (клікабельні фільтри).

**Фільтри:** Пошук, наявність, match status.

**Список:** Фото, назва+артикул+brand, Rozetka match badge, категорія, ціна, stock.

**Розгорнутий вид:** Match info, фото-галерея, деталі (артикул, brand, color, size, ціна, категорія, synced_at), характеристики, опис.

**Синхронізація:** Кнопка → dispatch SyncHoroshopCatalogJob → poll кешу → прогрес бар (animate-pulse, без %)

---

## 🔄 Потоки синхронізації

### Синхронізація Розетки
```
UI кнопка → syncProducts() → dispatch SyncRozetkaProductsJob
→ Cache::put("rozetka_sync_status_{tenantId}", {status: 'running', ...})
→ RozetkaProductService::syncProducts() (paginated /goods/all)
→ onProgress callback оновлює кеш з % і кількістю
→ Після завершення: markDuplicates()
→ Cache status → 'done'

UI poll кожні 3с → checkSyncStatus() → читає кеш → показує прогрес
```

### Синхронізація Хорошоп
```
UI кнопка → syncCatalog() → dispatch SyncHoroshopCatalogJob
→ Cache::put("horoshop_catalog_sync_status_{tenantId}", {status: 'running'})
→ HoroshopCatalogService::syncFullCatalog() (paginated /catalog/export)
→ Кожну ітерацію оновлює horoshop_catalog_sync_progress_{tenantId}
→ Після завершення: matchWithRozetka()
→ Cache status → 'completed' з result

UI poll кожні 3с → checkSyncStatus() → читає кеш
```

---

## ⚠️ Відомі проблеми та TODO

1. **`tenantId = 2` захардкожений** — треба зробити динамічним (з сесії контрактора або з tenant context)
2. **Прогрес бар Хорошоп** — показує тільки animate-pulse без %, бо `syncFullCatalog` не повертає total наперед. Треба або зробити preflight запит для підрахунку, або показувати "Синхронізовано X товарів..."
3. **Картка товару** — користувач хоче модальне вікно (popup) замість inline expand. Треба Alpine.js modal overlay
4. **"Push to Rozetka"** — користувач каже що це НЕ правильний шлях, має бути XML-фід генерація замість API push per product. Але поки API push працює
5. **Зв'язування товарів** — автоматичне по артикулу працює, але немає UI для ручного зв'язування (коли артикули не збігаються)
6. **Export tab** — код існує в RozetkaProductList (renderExportTab, prepareForExport, markReady, markDraft) але не підключений до UI після розділення на окремі сторінки. Може стати третьою сторінкою
7. **Категорії Розетки глобальні** — без tenant_id, що ок якщо всі використовують один Розетка акаунт

---

## 📁 Структура файлів

```
app/
  Http/
    Controllers/Contractor/AuthController.php
    Middleware/ContractorAuth.php
  Livewire/Contractor/
    RozetkaProductList.php
    HoroshopProductList.php
  Models/
    RozetkaProduct.php
    HoroshopProduct.php
    RozetkaCategory.php
    RozetkaCategoryAttribute.php
    RozetkaProductAttributeValue.php
    RozetkaCategoryMapping.php
  Services/
    Horoshop/
      HoroshopCatalogService.php
      HoroshopClient.php          # HTTP клієнт з авторизацією
    Rozetka/
      RozetkaProductService.php
      RozetkaCategoryService.php
      RozetkaAttributeService.php
      RozetkaClient.php           # HTTP клієнт з авторизацією
  Jobs/
    SyncRozetkaProductsJob.php
    SyncHoroshopCatalogJob.php

resources/views/
  contractor/
    layout.blade.php
    login.blade.php
  livewire/contractor/
    rozetka-product-list.blade.php
    horoshop-product-list.blade.php
    partials/
      rozetka-product-card.blade.php

routes/contractor.php
config/services.php              # contractor, rozetka credentials

tests/Feature/
  ContractorAuthTest.php         # 7 тестів
  ContractorHoroshopPageTest.php # 4 тести
```

---

## 🎨 Дизайн-система

- **Розетка кольори:** emerald-600 (акцент), сірі відтінки
- **Хорошоп кольори:** purple-600 (акцент)
- **Upload status кольори:**
  - 0 (Новий) → blue
  - 1 (Модерація) → yellow
  - 2 (Активний) → emerald/green
  - 3 (Вимкнений) → gray
  - 4 (Архів) → indigo
  - 9 (Модерація✗) → red
- **Match статуси:** purple (matched), amber (unmatched)
- **Шрифт:** Inter
- **Max width:** max-w-7xl
- **Border radius:** rounded-lg/md
- **Shadows:** shadow-sm
