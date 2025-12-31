# Diagnostic API Reference

Production URL: `https://aimbot.laravel.cloud/api/diagnostic`

**Secret Key**: `?key=diagnostic_secret_key_2025`

---

## 📊 Database Statistics

```bash
GET /api/diagnostic/db-stats?key=diagnostic_secret_key_2025
```

Returns:
- `total_products` - всього товарів
- `in_stock` - в наявності
- `with_color` - з кольором
- `with_size` - з розміром
- `unique_colors` - унікальні кольори

---

## 🔍 Search in Database (Eloquent)

```bash
GET /api/diagnostic/search-db?key=diagnostic_secret_key_2025&q=рукавички&limit=20
```

Пошук напряму в MySQL по title, search_index, category_path.

---

## 🔍 Search in Meilisearch

```bash
GET /api/diagnostic/search-meili?key=diagnostic_secret_key_2025&q=рукавички&limit=20
GET /api/diagnostic/search-meili?key=diagnostic_secret_key_2025&q=рукавички&filter=category_path CONTAINS "Рукавиці"
```

Пошук в Meilisearch з можливістю фільтра.

---

## 📈 Meilisearch Statistics

```bash
GET /api/diagnostic/meili-stats?key=diagnostic_secret_key_2025
```

Returns:
- `documents` - кількість документів
- `is_indexing` - чи йде індексація
- `filterable_attributes` - поля для фільтрації
- `searchable_attributes` - поля для пошуку

---

## 📦 Product Details

```bash
GET /api/diagnostic/product/{id}?key=diagnostic_secret_key_2025
```

Повна інформація про товар + siblings (варіанти).

---

## 🎨 Product Variants by Parent

```bash
GET /api/diagnostic/variants/{parentArticle}?key=diagnostic_secret_key_2025
```

Всі варіанти товару згруповані по кольору.

---

## 📁 Products in Category

```bash
GET /api/diagnostic/category-products?key=diagnostic_secret_key_2025&path=Рукавиці&limit=50
```

Товари в категорії (пошук по category_path LIKE).

---

## 🤖 Test Chat Search (without AI)

```bash
GET /api/diagnostic/test-chat?key=diagnostic_secret_key_2025&q=рукавички тактичні
```

Тестує MeiliProductSearchTool напряму без OpenAI.

---

## 🔄 Sync Sample (Raw Data)

```bash
GET /api/diagnostic/sync-sample?key=diagnostic_secret_key_2025
GET /api/diagnostic/sync-sample?key=diagnostic_secret_key_2025&article=kb-atg-olgr-s
```

Перевіряє що прийшло з Horoshop API (raw поле).
Shows: `raw_color`, `raw_Kolir`, `raw_Rozmir`, `raw_mod_title`

---

## 🧪 Quick Debug Scenarios

### 1. Чому "рукавички тактичні" показує мало товарів?

```bash
# 1. Скільки їх в БД?
curl "https://aimbot.laravel.cloud/api/diagnostic/search-db?key=diagnostic_secret_key_2025&q=рукавич"

# 2. Скільки в Meili?
curl "https://aimbot.laravel.cloud/api/diagnostic/search-meili?key=diagnostic_secret_key_2025&q=рукавички"

# 3. Що повертає MeiliProductSearchTool?
curl "https://aimbot.laravel.cloud/api/diagnostic/test-chat?key=diagnostic_secret_key_2025&q=рукавички%20тактичні"

# 4. Чи є категорія Рукавиці?
curl "https://aimbot.laravel.cloud/api/diagnostic/category-products?key=diagnostic_secret_key_2025&path=Рукавиц"
```

### 2. Перевірити color_variants

```bash
# Знайти товар з варіантами
curl "https://aimbot.laravel.cloud/api/diagnostic/search-db?key=diagnostic_secret_key_2025&q=KOMBAT" | jq '.products[0].id'

# Отримати всі варіанти
curl "https://aimbot.laravel.cloud/api/diagnostic/product/123?key=diagnostic_secret_key_2025"
```

### 3. Перевірити чи Kolir витягується

```bash
curl "https://aimbot.laravel.cloud/api/diagnostic/sync-sample?key=diagnostic_secret_key_2025&article=kb-atg-olgr-s"
```

### 4. Перевірити статистику після синхронізації

```bash
curl "https://aimbot.laravel.cloud/api/diagnostic/db-stats?key=diagnostic_secret_key_2025" | jq '{total: .total_products, with_color: .with_color, colors: .unique_colors}'
```

---

## 📝 Notes

- All endpoints require `?key=diagnostic_secret_key_2025`
- Rate limits: none (but don't abuse)
- Results are JSON
- Use `| jq` for pretty output
