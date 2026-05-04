# Diagnostic API Reference

Production URL: `https://aintento.laravel.cloud/api/diagnostic`

**Secret Key**: `?key=<DIAGNOSTIC_KEY>`

---

## 📊 Database Statistics

```bash
GET /api/diagnostic/db-stats?key=<DIAGNOSTIC_KEY>
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
GET /api/diagnostic/search-db?key=<DIAGNOSTIC_KEY>&q=рукавички&limit=20
```

Пошук напряму в MySQL по title, search_index, category_path.

---

## 🔍 Search in Meilisearch

```bash
GET /api/diagnostic/search-meili?key=<DIAGNOSTIC_KEY>&q=рукавички&limit=20
GET /api/diagnostic/search-meili?key=<DIAGNOSTIC_KEY>&q=рукавички&filter=category_path CONTAINS "Рукавиці"
```

Пошук в Meilisearch з можливістю фільтра.

---

## 📈 Meilisearch Statistics

```bash
GET /api/diagnostic/meili-stats?key=<DIAGNOSTIC_KEY>
```

Returns:
- `documents` - кількість документів
- `is_indexing` - чи йде індексація
- `filterable_attributes` - поля для фільтрації
- `searchable_attributes` - поля для пошуку

---

## 📦 Product Details

```bash
GET /api/diagnostic/product/{id}?key=<DIAGNOSTIC_KEY>
```

Повна інформація про товар + siblings (варіанти).

---

## 🎨 Product Variants by Parent

```bash
GET /api/diagnostic/variants/{parentArticle}?key=<DIAGNOSTIC_KEY>
```

Всі варіанти товару згруповані по кольору.

---

## 📁 Products in Category

```bash
GET /api/diagnostic/category-products?key=<DIAGNOSTIC_KEY>&path=Рукавиці&limit=50
```

Товари в категорії (пошук по category_path LIKE).

---

## 🤖 Test Chat Search (without AI)

```bash
GET /api/diagnostic/test-chat?key=<DIAGNOSTIC_KEY>&q=рукавички тактичні
```

Тестує MeiliProductSearchTool напряму без OpenAI.

---

## 🔄 Sync Sample (Raw Data)

```bash
GET /api/diagnostic/sync-sample?key=<DIAGNOSTIC_KEY>
GET /api/diagnostic/sync-sample?key=<DIAGNOSTIC_KEY>&article=kb-atg-olgr-s
```

Перевіряє що прийшло з Horoshop API (raw поле).
Shows: `raw_color`, `raw_Kolir`, `raw_Rozmir`, `raw_mod_title`

---

## 🧪 Quick Debug Scenarios

### 1. Чому "рукавички тактичні" показує мало товарів?

```bash
# 1. Скільки їх в БД?
curl "https://aintento.laravel.cloud/api/diagnostic/search-db?key=<DIAGNOSTIC_KEY>&q=рукавич"

# 2. Скільки в Meili?
curl "https://aintento.laravel.cloud/api/diagnostic/search-meili?key=<DIAGNOSTIC_KEY>&q=рукавички"

# 3. Що повертає MeiliProductSearchTool?
curl "https://aintento.laravel.cloud/api/diagnostic/test-chat?key=<DIAGNOSTIC_KEY>&q=рукавички%20тактичні"

# 4. Чи є категорія Рукавиці?
curl "https://aintento.laravel.cloud/api/diagnostic/category-products?key=<DIAGNOSTIC_KEY>&path=Рукавиц"
```

### 2. Перевірити color_variants

```bash
# Знайти товар з варіантами
curl "https://aintento.laravel.cloud/api/diagnostic/search-db?key=<DIAGNOSTIC_KEY>&q=KOMBAT" | jq '.products[0].id'

# Отримати всі варіанти
curl "https://aintento.laravel.cloud/api/diagnostic/product/123?key=<DIAGNOSTIC_KEY>"
```

### 3. Перевірити чи Kolir витягується

```bash
curl "https://aintento.laravel.cloud/api/diagnostic/sync-sample?key=<DIAGNOSTIC_KEY>&article=kb-atg-olgr-s"
```

### 4. Перевірити статистику після синхронізації

```bash
curl "https://aintento.laravel.cloud/api/diagnostic/db-stats?key=<DIAGNOSTIC_KEY>" | jq '{total: .total_products, with_color: .with_color, colors: .unique_colors}'
```

---

## 📝 Notes

- All endpoints require `?key=<DIAGNOSTIC_KEY>`
- Rate limits: none (but don't abuse)
- Results are JSON
- Use `| jq` for pretty output
