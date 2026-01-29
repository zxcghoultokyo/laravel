# Виправлення ai_product_type для аксесуарів на шоломи

## Проблема

При пошуку "шоломи" показуються аксесуари (планки Пікатінні, подушки, кавери) 
замість справжніх шоломів, бо вони мають неправильний `ai_product_type = "helmet"`.

## Рішення

### 1. Оновлено prompt для AI enrichment

Файл: `app/Jobs/AnalyzeProductsWithAiJob.php`

Тепер prompt чітко розрізняє:
- `helmet` - справжні балістичні шоломи
- `helmet_pads` - подушки/накладки
- `helmet_cover` - кавери/чохли  
- `helmet_mount` - планки, адаптери, wing-loc
- `helmet_accessory` - інші аксесуари

### 2. Покращено фільтрацію аксесуарів у пошуку

Файл: `app/Services/Agent/Tools/MeiliProductSearchTool.php`

- Додано перевірку `category_path` (якщо містить "Аксесуар" - це аксесуар)
- Додано `_pads` до списку аксесуарних типів
- Знижено поріг фільтрації з 3 до 2 основних товарів

### 3. Команда для переаналізу неправильних товарів

```bash
# Показати товари з неправильним типом (dry-run)
php artisan products:reanalyze-helmets --tenant=2 --dry-run

# Переаналізувати товари
php artisan products:reanalyze-helmets --tenant=2 --limit=50

# Переіндексувати в Meilisearch
php artisan meili:index --tenant=2
```

## Запуск на Production

### Крок 1: Задеплоїти код
```bash
git add -A && git commit -m "Fix helmet accessory classification" && git push
```

### Крок 2: SSH на production і запустити переаналіз
```bash
# На production сервері
cd /app

# Спочатку dry-run щоб побачити що буде змінено
php artisan products:reanalyze-helmets --tenant=2 --dry-run

# Якщо все ок - запустити переаналіз
php artisan products:reanalyze-helmets --tenant=2 --limit=100

# Переіндексувати в Meilisearch
php artisan meili:index --tenant=2
```

### Крок 3: Перевірити результат
```bash
curl "https://aintento.laravel.cloud/api/diagnostic/search-meili?key=diagnostic_secret_key_2025&q=шолом&tenant_id=2&limit=10"
```

Очікуваний результат:
- Перші результати: справжні шоломи (Ops-Core, Sestan-Busch)
- Аксесуари (планки, подушки) - або відсутні, або внизу списку

## Технічні деталі

### Як визначається аксесуар

1. **За category_path** (найнадійніше):
   - Містить "Аксесуар" → аксесуар
   - Містить "Комплектуюч" → аксесуар

2. **За ai_product_type**:
   - `helmet_pads`, `helmet_cover`, `helmet_mount` → аксесуар
   - `helmet` → основний товар

### Логіка фільтрації

1. Рахуємо скільки "основних" товарів (не аксесуарів)
2. Якщо >= 2 основних → фільтруємо аксесуари
3. Якщо < 2 → сортуємо (основні вгорі, аксесуари внизу)

## Для нових товарів

Нові товари автоматично отримають правильний тип завдяки оновленому prompt.
Переіндексація не потрібна - тип правильний з моменту enrichment.
