# 🐛 Відомі Проблеми та Баги

> **Остання оновлення**: 22.12.2025  
> **Статус**: Active tracking

---

## 📋 Зміст
1. [Критичні Баги](#критичні-баги)
2. [Середній Пріоритет](#середній-пріоритет)
3. [Низький Пріоритет](#низький-пріоритет)
4. [Фічі в Розробці](#фічі-в-розробці)
5. [Fixed Issues](#fixed-issues)

---

## Критичні Баги

### 🔴 AI Reranker Не Поважає Бренд
**ID**: CRITICAL-001  
**Статус**: ⚠️ Частково fixed (код готовий, не закомічено)  
**Виявлено**: 22.12.2025

**Опис**:
Коли користувач шукає конкретний бренд ("hoffmann", "атака"), MeiliProductSearchTool правильно повертає товари цього бренду, але AiRerankTool переставляє результати і ставить товари з вищою `popularity` на перше місце, навіть якщо це інший бренд.

**Приклад**:
```
Запит: "hoffmann"
↓ MeiliProductSearchTool → [9 HOFFMANN патчів]
↓ AiRerankTool → [KOMBAT UK рукавички (popularity=530), ...HOFFMANN патчі]
                  ^ НЕПРАВИЛЬНО!
```

**Root Cause**:
AI промпт в AiRerankTool не містить інструкції про пріоритизацію бренду.

**Фікс** (готовий, не закомічено):
```php
// В AiRerankTool::buildRerankPrompt()
ДУЖЕ ВАЖЛИВО:
- Якщо в запиті є назва бренду (HOFFMANN, АТАКА, KOMBAT UK) → показувати ТІЛЬКИ товари цього бренду
- Бренд МАЄ ПРІОРИТЕТ над popularity
- Якщо всі товари одного бренду → сортуй по релевантності всередині бренду
```

**TODO**:
1. ✅ Код написано
2. ⚠️ Протестувати зміни
3. ❌ Закомітити зміни
4. ❌ Deploy на production

**Workaround**: MeiliProductSearchTool вже фільтрує неправильні бренди, тому в результатах немає сторонніх товарів (працює, але порядок неправильний).

---

### 🔴 Hardcoded FAQ Responses
**ID**: CRITICAL-002  
**Статус**: ❌ Не fixed  
**Виявлено**: 18.12.2025

**Опис**:
FAQ відповіді (доставка, оплата, повернення) захардкожені в коді AgentOrchestrator.

**Файл**: [app/Services/Agent/AgentOrchestrator.php#L382](../../app/Services/Agent/AgentOrchestrator.php#L382)

```php
private function handleFaq(string $message, array $plan, array $context): array
{
    $faqResponses = [
        'доставка' => "Доставка здійснюється Новою Поштою...",
        'оплата' => "Оплата: накладений платіж...",
        'повернення' => "Повернення товару протягом 14 днів...",
    ];
    // ...
}
```

**Проблеми**:
1. Не можна змінити відповіді без редеплою коду
2. Не підтримує багатомовність
3. Не можна додати нові FAQ через UI

**Рішення**:
1. Створити таблицю `faqs` з полями: `key`, `question_ua`, `answer_ua`, `answer_ru`, `is_active`
2. Додати FaqService для управління
3. Додати admin панель для редагування FAQ
4. Fallback на дефолтні відповіді якщо БД порожня

**Priority**: HIGH (блокує контент-менеджмент)

---

## Середній Пріоритет

### ⚠️ Duplicate Accessory Detection Logic
**ID**: MEDIUM-001  
**Статус**: ❌ Не fixed  
**Виявлено**: 22.12.2025

**Опис**:
Accessory detection логіка дублюється в двох місцях:
1. `MeiliProductSearchTool::filterAccessories()`
2. `AccessoryFilterTool::downrankAccessories()`

**Файли**:
- [MeiliProductSearchTool.php#L180](../../app/Services/Agent/Tools/MeiliProductSearchTool.php#L180)
- [AccessoryFilterTool.php#L25](../../app/Services/Agent/Tools/AccessoryFilterTool.php#L25)

**Проблеми**:
- Code smell (DRY violation)
- Змінюємо ключові слова в двох місцях
- Можлива неконсистентність

**Рішення**:
1. Винести logic в окремий `AccessoryDetectionService`
2. Використовувати сервіс в обох tools
3. Accessory keywords в БД або config

**Priority**: MEDIUM (працює, але погана maintainability)

---

### ⚠️ Hardcoded Accessory Keywords
**ID**: MEDIUM-002  
**Статус**: ❌ Не fixed  
**Виявлено**: 22.12.2025

**Опис**:
Ключові слова для детекції аксесуарів захардкожені в коді:
```php
$accessoryKeywords = [
    'камбербанд', 'кап', 'чохол', 'сумка', 'кріплення',
    'адаптер', 'подушки', 'ремінь', 'модуль', 'панел',
    'одноточков', 'двоточков', 'трьохточков', 'слінг',
];
```

**Проблеми**:
1. Не можна додати/видалити keywords без редеплою
2. Немає можливості A/B тестування різних списків
3. Важко трекати які keywords працюють

**Рішення**:
1. Створити таблицю `accessory_keywords`: `keyword`, `is_active`, `weight`, `notes`
2. Кешувати keywords в Redis (TTL 1 година)
3. Fallback на дефолтний список якщо БД порожня

**Priority**: MEDIUM (працює, але не flexible)

---

### ⚠️ Context Detection Regex Too Broad
**ID**: MEDIUM-003  
**Статус**: ⚠️ Частково fixed  
**Виявлено**: 22.12.2025

**Опис**:
Context-aware regex для панелей/ременів може не спрацювати для edge cases.

**Поточний код**:
```php
$contextPatterns = [
    '/^панел/ui',           // "панель", "панелі"
    '/панель для/ui',       // "панель для плитоноски"
    '/^ремінь/ui',          // "ремінь"
    '/ремінь для/ui',       // "ремінь для рюкзака"
];
```

**Проблеми**:
- "купити панель" → не матчиться (немає `^панел`)
- "потрібен ремінь" → не матчиться

**Рішення**:
1. Розширити regex patterns
2. Додати ML-based intent detection замість regex (future)
3. A/B тестування різних patterns

**Priority**: MEDIUM (працює в 80% випадків)

---

### ⚠️ Horoshop API No Retry Logic
**ID**: MEDIUM-004  
**Статус**: ❌ Не fixed  
**Виявлено**: 18.12.2025

**Опис**:
HoroshopClient не має retry logic якщо API тимчасово недоступний.

**Файл**: [app/Services/Horoshop/HoroshopClient.php](../../app/Services/Horoshop/HoroshopClient.php)

**Проблеми**:
- Якщо Horoshop API down → sync job fails
- Немає exponential backoff
- Немає circuit breaker pattern

**Рішення**:
1. Додати retry с exponential backoff (3 спроби: 1s, 2s, 4s)
2. Log кожну спробу
3. Якщо всі спроби failed → send alert

**Priority**: MEDIUM (Horoshop API стабільний, але може бути проблема)

---

## Низький Пріоритет

### 💡 Meilisearch Indexing Could Be Faster
**ID**: LOW-001  
**Статус**: ❌ Не fixed  
**Виявлено**: 22.12.2025

**Опис**:
Індексація 2,800 товарів займає ~3 хвилини (batch size 500).

**Рішення**:
1. Збільшити batch size до 1000
2. Паралельна індексація (multiple workers)
3. Delta indexing (індексувати тільки змінені товари)

**Priority**: LOW (працює, але можна оптимізувати)

---

### 💡 OpenAI Classification Caching
**ID**: LOW-002  
**Статус**: ❌ Не implemented  
**Виявлено**: 22.12.2025

**Опис**:
Однакові запити класифікуються повторно (waste of API calls).

**Приклад**:
```
User 1: "плитоноска" → OpenAI classify → PRODUCT_SEARCH
User 2: "плитоноска" → OpenAI classify → PRODUCT_SEARCH (duplicate)
```

**Рішення**:
```php
$cacheKey = "classify:" . md5($message);
$result = Cache::remember($cacheKey, 3600, function() use ($message) {
    return $this->aiRouter->classify($message);
});
```

**Priority**: LOW (економія ~$0.50/month)

---

### 💡 Category Hints Should Be in Database
**ID**: LOW-003  
**Статус**: ❌ Не fixed  
**Виявлено**: 22.12.2025

**Опис**:
Category hints захардкожені в ProductService.

**Файл**: [app/Services/Horoshop/ProductService.php#L817](../../app/Services/Horoshop/ProductService.php#L817)

```php
$categoryHints = [
    'шолом' => ['шолом', 'шоломи', 'каска', 'helmet'],
    'плитоноска' => ['плитоноска', 'plate carrier'],
    // ...50+ ліній
];
```

**Рішення**:
1. Створити таблицю `category_hints`
2. Migrate існуючі hints
3. Кешувати в Redis

**Priority**: LOW (працює, але hard to maintain)

---

## Фічі в Розробці

### 🚧 Dynamic Product Limit (3-10)
**ID**: FEATURE-001  
**Статус**: ✅ Implemented (потрібно протестувати)  
**Виявлено**: 22.12.2025

**Опис**:
AI reranker тепер сам вирішує скільки товарів повертати (3-10), а не завжди 10.

**Code**:
```php
// AiRerankTool.php
return $reranked; // Динамічна кількість (скільки AI обрав)
```

**Testing TODO**:
1. Тест: "шеврон група крові" → очікується 4 товари (НЕ 10)
2. Тест: "плитоноска" → очікується 5-7 (НЕ 10)
3. Тест: "рукавички" → може бути 10 (якщо є багато варіантів)

---

### 🚧 Brand Boosting in Meilisearch
**ID**: FEATURE-002  
**Статус**: ✅ Implemented and Working  
**Виявлено**: 22.12.2025

**Опис**:
Brand detection з 3x repetition для Meilisearch boosting.

**Testing**:
- ✅ "hoffmann" → 9 HOFFMANN товарів
- ✅ "атака плитоноска" → 5 АТАКА товарів
- ✅ Brand detection service працює правильно

**Performance**: +50-70ms на brand detection (acceptable)

---

## Fixed Issues

### ✅ FIXED: "плитоноска" Shows Accessories
**ID**: FIXED-001  
**Виявлено**: 21.12.2025  
**Зафіксовано**: 22.12.2025  
**Коміт**: [4c32374](https://github.com/stovburtm-web/laravel/commit/4c32374)

**Опис**:
Пошук "плитоноска" показував ремені, камбербанди, панелі замість плитоносок.

**Рішення**:
1. Strict accessory detection в MeiliProductSearchTool
2. Aggressive filtering якщо ≥3 main products
3. Context-aware regex для панелей

**Статус**: ✅ Працює (протестовано)

---

### ✅ FIXED: Brand Detection Service
**ID**: FIXED-002  
**Виявлено**: 22.12.2025  
**Зафіксовано**: 22.12.2025

**Опис**:
BrandDetectionService не завантажувався з brands таблиці.

**Рішення**:
1. Створено SyncBrandsJob
2. Scheduler запускає sync о 03:30
3. Service кешує brands на 24h

**Статус**: ✅ Працює (73 brands in DB)

---

## Tracking Metrics

### Bug Resolution Rate
| Пріоритет | Всього | Fixed | In Progress | Pending |
|-----------|--------|-------|-------------|---------|
| Critical | 2 | 0 | 1 | 1 |
| Medium | 4 | 0 | 1 | 3 |
| Low | 3 | 0 | 0 | 3 |
| **TOTAL** | **9** | **0** | **2** | **7** |

### Recently Fixed
| ID | Title | Fixed Date | Commit |
|----|-------|------------|--------|
| FIXED-001 | Accessory Filtering | 22.12.2025 | 4c32374 |
| FIXED-002 | Brand Detection | 22.12.2025 | - |

---

## Reporting New Issues

### Template
```markdown
**Title**: Short description

**ID**: PRIORITY-XXX

**Status**: ❌ Не fixed / ⚠️ In Progress / ✅ Fixed

**Discovered**: DD.MM.YYYY

**Description**:
What is broken and how it manifests.

**Root Cause**:
Technical explanation of the problem.

**Solution**:
Proposed fix.

**Priority**: CRITICAL / MEDIUM / LOW

**Files Affected**:
- [File.php](path/to/file.php#L123)
```

---

**Наступний документ**: [Hardcoded Values →](hardcoded-values.md)
