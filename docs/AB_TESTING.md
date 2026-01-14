# A/B Testing System for Search Quality

## Overview

Система A/B тестування для порівняння якості пошуку з AI features (semantic search, slang expansion, AI reranking) vs без них.

## Імплементація

### Основні компоненти

```
ABTestingService           - Керування експериментами та варіантами
ab_test_events table      - Зберігання подій для аналізу
MeiliProductSearchTool    - Інтеграція з пошуком (conditional features)
```

### Архітектура

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   User      │────▶│  ABTestingService│────▶│   Variant       │
│ (session)   │     │  getVariant()    │     │   (control/     │
└─────────────┘     └──────────────────┘     │    treatment)   │
                           │                 └─────────────────┘
                           ▼
                    ┌──────────────────┐
                    │   Feature Flags   │
                    │ - semantic_search │
                    │ - slang_expansion │
                    │ - ai_reranking    │
                    └──────────────────┘
                           │
                           ▼
                    ┌──────────────────┐
                    │  Event Tracking   │
                    │ - search_performed│
                    │ - product_click   │
                    │ - add_to_cart     │
                    └──────────────────┘
```

## Активний експеримент

### `search_ai_features`

Порівнює пошук з AI features vs базовий keyword search:

| Variant | Features | Опис |
|---------|----------|------|
| `control` | keyword only | Базовий Meilisearch без AI |
| `treatment` | keyword + AI | Semantic search fallback, slang expansion, AI reranking |

### Feature Flags

```php
'control' => [
    'semantic_search' => false,    // Не використовувати embeddings fallback
    'slang_expansion' => false,    // Не розширювати сленг
    'ai_reranking' => false,       // Не ререйтити результати через AI
],
'treatment' => [
    'semantic_search' => true,     // Semantic search при < 3 результатах
    'slang_expansion' => true,     // Розширювати сленг запити
    'ai_reranking' => true,        // AI reranking для кращої релевантності
]
```

## Варіант призначення

Варіант призначається детерміновано по `session_id`:

```php
// Той самий юзер завжди отримує той самий варіант
$hash = crc32($sessionId);
$variantIndex = $hash % count($variants); // 0 або 1
```

50/50 розподіл забезпечує статистичну валідність.

## Метрики

### Основні KPIs

| Метрика | Формула | Мета |
|---------|---------|------|
| Zero Results Rate | searches_with_zero / total_searches | Менше = краще |
| Click-Through Rate | clicks / total_searches | Більше = краще |
| Add-to-Cart Rate | add_to_carts / total_searches | Більше = краще |
| Query Refinement Rate | refinements / total_searches | Менше = краще |
| Avg Results Count | sum(results) / total_searches | Оптимум ~10-20 |

### Event Types

```php
// Пошук виконано
$this->abTesting->track($sessionId, 'search_performed', [
    'query' => $query,
    'results_count' => count($products),
    'used_semantic' => $usedSemantic,
]);

// Клік на товар
$this->abTesting->track($sessionId, 'product_click', [
    'product_id' => $productId,
]);

// Додано в кошик
$this->abTesting->track($sessionId, 'add_to_cart', [
    'product_id' => $productId,
    'price' => $price,
]);
```

## API Endpoints

### Статистика A/B тесту

```bash
GET /api/diagnostic/ab-test-stats?key=diagnostic_secret_key_2025

# Response:
{
  "experiment": "search_ai_features",
  "variants": {
    "control": {
      "sessions": 156,
      "searches": 423,
      "zero_results": 45,
      "zero_results_rate": 10.6,
      "clicks": 89,
      "ctr": 21.0,
      "add_to_carts": 23,
      "add_to_cart_rate": 5.4
    },
    "treatment": {
      "sessions": 148,
      "searches": 398,
      "zero_results": 12,
      "zero_results_rate": 3.0,  // -72% vs control!
      "clicks": 124,
      "ctr": 31.2,               // +48% vs control!
      "add_to_carts": 35,
      "add_to_cart_rate": 8.8    // +63% vs control!
    }
  },
  "comparison": {
    "zero_results_improvement": -71.7,
    "ctr_improvement": 48.6,
    "add_to_cart_improvement": 63.0
  }
}
```

### Перевірка варіанту сесії

```bash
GET /api/diagnostic/ab-test-variant?key=diagnostic_secret_key_2025&session_id=abc123

# Response:
{
  "session_id": "abc123",
  "experiment": "search_ai_features",
  "variant": "treatment",
  "features": {
    "semantic_search": true,
    "slang_expansion": true,
    "ai_reranking": true
  }
}
```

### Форсування варіанту (для тестування)

```bash
POST /api/diagnostic/ab-test-force?key=diagnostic_secret_key_2025
Content-Type: application/json

{
  "session_id": "test123",
  "variant": "control"
}
```

### Скидання даних експерименту

```bash
POST /api/diagnostic/ab-test-reset?key=diagnostic_secret_key_2025
# Очищає всі events для поточного експерименту
```

## Інтеграція в код

### MeiliProductSearchTool

```php
class MeiliProductSearchTool
{
    private ?ABTestingService $abTesting = null;
    private ?string $currentSessionId = null;
    
    public function setSessionId(string $sessionId): void
    {
        $this->currentSessionId = $sessionId;
    }
    
    public function search(array $params): array
    {
        // Отримати features для варіанту
        $features = $this->getABFeatures();
        
        // Keyword search
        $results = $this->meilisearch->search($query);
        
        // Semantic fallback тільки якщо enabled
        if (count($results) < 3 && ($features['semantic_search'] ?? true)) {
            $semanticResults = $this->semanticSearch->search($query, 5);
            $results = array_merge($results, $semanticResults);
        }
        
        // Track для A/B
        $this->trackSearchForAB($query, $results, $usedSemantic);
        
        return $results;
    }
}
```

### ChatController → AgentOrchestrator → Tool

```php
// ChatController
$response = $orchestrator->handleMessage($message, [
    'session_id' => $sessionId,
]);

// AgentOrchestrator
public function handleMessage(string $message, array $context): array
{
    if (isset($context['session_id'])) {
        $this->searchTool->setSessionId($context['session_id']);
    }
    // ...
}
```

## Статистична значущість

### Мінімальний sample size

- **1000+ сесій на варіант** для надійних висновків
- **7+ днів** щоб врахувати денні/тижневі патерни

### Chi-Square Test

```php
// Приклад розрахунку значущості
$controlConversions = 45;
$controlTotal = 500;
$treatmentConversions = 62;
$treatmentTotal = 500;

// p-value < 0.05 = статистично значущо
// Використовуйте онлайн калькулятор або бібліотеку
```

## Workflow

```
1. START      - Експеримент активний, збираємо дані
2. COLLECT    - Чекаємо 1000+ сесій на варіант
3. ANALYZE    - Рахуємо метрики, перевіряємо significance
4. DECIDE     - Winner очевидний → деплоїмо
5. ROLLOUT    - Treatment стає default для всіх
```

## Приклад аналізу

Після 2 тижнів збору даних:

| Метрика | Control | Treatment | Δ |
|---------|---------|-----------|---|
| Sessions | 1,234 | 1,189 | - |
| Searches | 3,456 | 3,298 | - |
| Zero Results | 345 (10.0%) | 99 (3.0%) | **-70%** ✅ |
| Clicks | 691 (20.0%) | 989 (30.0%) | **+50%** ✅ |
| Add to Cart | 173 (5.0%) | 264 (8.0%) | **+60%** ✅ |

**Висновок:** Treatment (AI features) значно покращує всі метрики. Рекомендовано зробити AI features default.

## Розширення

### Додавання нового експерименту

```php
// ABTestingService.php
private const EXPERIMENTS = [
    'search_ai_features' => [/* ... */],
    
    // Новий експеримент
    'new_model_comparison' => [
        'variants' => ['gpt4', 'gpt4mini'],
        'config' => [
            'gpt4' => ['model' => 'gpt-4.1'],
            'gpt4mini' => ['model' => 'gpt-4.1-mini'],
        ],
    ],
];
```

### Додавання нової метрики

```php
// Track custom event
$abTesting->track($sessionId, 'custom_event', [
    'custom_field' => $value,
]);

// Analyze in getStats()
$customRate = $this->calculateRate($events, 'custom_event');
```

## Troubleshooting

### Варіант не призначається

```bash
# Перевірити чи session_id передається
curl "https://example.com/api/diagnostic/ab-test-variant?key=...&session_id=test"
```

### Events не записуються

```bash
# Перевірити таблицю
php artisan tinker
>>> \DB::table('ab_test_events')->count()
```

### Неочікувані результати

1. Перевірте sample size (мінімум 1000/варіант)
2. Перевірте 50/50 розподіл
3. Перевірте що features реально вмикаються/вимикаються
