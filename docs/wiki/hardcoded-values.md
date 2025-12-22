# 🔨 Hardcoded Values — Що Треба Винести

> **Остання оновлення**: 22.12.2025  
> **Мета**: Зробити систему configurable без редеплоїв

---

## 📋 Зміст
1. [FAQ Responses](#faq-responses)
2. [Accessory Keywords](#accessory-keywords)
3. [Category Hints](#category-hints)
4. [Context Detection Patterns](#context-detection-patterns)
5. [AI Prompts](#ai-prompts)
6. [Scenario Templates](#scenario-templates)

---

## FAQ Responses

### Поточний Стан
**Файл**: [app/Services/Agent/AgentOrchestrator.php#L382](../../app/Services/Agent/AgentOrchestrator.php#L382)

```php
private function handleFaq(string $message, array $plan, array $context): array
{
    $faqResponses = [
        'доставка' => "Доставка здійснюється Новою Поштою по всій Україні. Термін доставки 1-3 дні. Вартість згідно тарифів перевізника.",
        'оплата' => "Оплата: накладений платіж, оплата на карту, готівка при самовивозі.",
        'повернення' => "Повернення товару протягом 14 днів згідно Закону про захист прав споживачів.",
    ];
    
    // Simple keyword matching
    foreach ($faqResponses as $keyword => $response) {
        if (str_contains(mb_strtolower($message), $keyword)) {
            return ['message' => $response, 'products' => [], 'meta' => ['intent' => 'faq']];
        }
    }
    
    return ['message' => "Вибачте, не знайшов відповідь на це питання...", ...];
}
```

---

### Проблеми
1. ❌ Не можна змінити відповіді без редеплою коду
2. ❌ Тільки 3 FAQ (доставка, оплата, повернення)
3. ❌ Немає багатомовності (тільки UA)
4. ❌ Примітивний matching (тільки keyword in message)
5. ❌ Немає категоризації FAQ

---

### Рішення: FAQ Service + Database

#### Migration
```php
Schema::create('faqs', function (Blueprint $table) {
    $table->id();
    $table->string('category')->index();  // 'delivery', 'payment', 'returns', 'general'
    $table->string('slug')->unique();     // 'shipping-ukraine'
    $table->json('keywords');             // ['доставка', 'доставити', 'shipping']
    $table->json('question');             // {ua: "Як здійснюється доставка?", en: "How..."}
    $table->json('answer');               // {ua: "Доставка Новою Поштою...", en: "..."}
    $table->integer('priority')->default(0);  // Для сортування
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->index('category');
    $table->index('is_active');
});
```

#### FaqService
```php
namespace App\Services;

class FaqService
{
    public function findAnswer(string $query, string $locale = 'ua'): ?array
    {
        $query = mb_strtolower($query);
        
        $faq = Faq::where('is_active', true)
            ->get()
            ->first(function($faq) use ($query) {
                $keywords = $faq->keywords ?? [];
                foreach ($keywords as $keyword) {
                    if (str_contains($query, mb_strtolower($keyword))) {
                        return true;
                    }
                }
                return false;
            });
        
        if (!$faq) {
            return null;
        }
        
        return [
            'question' => $faq->question[$locale] ?? $faq->question['ua'],
            'answer' => $faq->answer[$locale] ?? $faq->answer['ua'],
            'category' => $faq->category,
        ];
    }
    
    public function getAllFaqs(string $category = null): Collection
    {
        $query = Faq::where('is_active', true);
        
        if ($category) {
            $query->where('category', $category);
        }
        
        return $query->orderBy('priority', 'desc')->get();
    }
}
```

#### Updated AgentOrchestrator
```php
private function handleFaq(string $message, array $plan, array $context): array
{
    $result = $this->faqService->findAnswer($message);
    
    if ($result) {
        return [
            'message' => $result['answer'],
            'products' => [],
            'meta' => [
                'intent' => 'faq',
                'category' => $result['category'],
                'question' => $result['question'],
            ],
        ];
    }
    
    // Fallback to AI if no FAQ found
    return $this->handleFaqWithAi($message);
}
```

---

### Seeder (Initial Data)
```php
class FaqSeeder extends Seeder
{
    public function run()
    {
        $faqs = [
            [
                'category' => 'delivery',
                'slug' => 'shipping-ukraine',
                'keywords' => ['доставка', 'доставити', 'shipping', 'нова пошта'],
                'question' => [
                    'ua' => 'Як здійснюється доставка?',
                    'en' => 'How is shipping handled?',
                ],
                'answer' => [
                    'ua' => 'Доставка здійснюється Новою Поштою по всій Україні. Термін доставки 1-3 дні. Вартість згідно тарифів перевізника.',
                    'en' => 'Shipping via Nova Poshta across Ukraine. Delivery time 1-3 days. Cost according to carrier tariffs.',
                ],
                'priority' => 100,
            ],
            [
                'category' => 'payment',
                'slug' => 'payment-methods',
                'keywords' => ['оплата', 'оплатити', 'payment', 'платіж'],
                'question' => [
                    'ua' => 'Які способи оплати?',
                ],
                'answer' => [
                    'ua' => 'Оплата: накладений платіж (НП), оплата на карту ПриватБанку, готівка при самовивозі з офісу.',
                ],
                'priority' => 90,
            ],
            // ... more FAQs
        ];
        
        foreach ($faqs as $faqData) {
            Faq::create($faqData);
        }
    }
}
```

---

## Accessory Keywords

### Поточний Стан
**Файл**: [app/Services/Agent/Tools/MeiliProductSearchTool.php#L180](../../app/Services/Agent/Tools/MeiliProductSearchTool.php#L180)

```php
private function isAccessory(array $product, string $query): bool
{
    $title = mb_strtolower($product['title'] ?? '');
    
    // Strict accessory keywords
    $strictKeywords = [
        'камбербанд', 'кап', 'чохол', 'сумка', 'кріплення',
        'адаптер', 'подушки', 'ремінь', 'модуль', 'панел',
        'одноточков', 'двоточков', 'трьохточков', 'слінг',
        'нашивка', 'шеврон', 'ліхтарик', 'ліхтар',
        'навушник', 'гарнітур', 'кавер', 'стропа',
    ];
    
    foreach ($strictKeywords as $keyword) {
        if (str_contains($title, $keyword)) {
            return true;
        }
    }
    
    return false;
}
```

**Дублюється в**: [AccessoryFilterTool.php#L25](../../app/Services/Agent/Tools/AccessoryFilterTool.php#L25)

---

### Проблеми
1. ❌ Hardcoded список в двох файлах (DRY violation)
2. ❌ Не можна додати/видалити keywords без редеплою
3. ❌ Немає ваги/пріоритету (все однаково важливе)
4. ❌ Немає можливості A/B тестування

---

### Рішення: Accessory Keywords Service

#### Migration
```php
Schema::create('accessory_keywords', function (Blueprint $table) {
    $table->id();
    $table->string('keyword')->unique();
    $table->integer('weight')->default(1);  // 1-10 (10 = завжди аксесуар)
    $table->string('category')->nullable(); // Для групування
    $table->text('notes')->nullable();      // Чому додали цей keyword
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->index('keyword');
    $table->index('is_active');
});
```

#### Service
```php
namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AccessoryDetectionService
{
    public function isAccessory(string $title, int $threshold = 5): bool
    {
        $title = mb_strtolower($title);
        $keywords = $this->getKeywords();
        
        foreach ($keywords as $keyword) {
            if (str_contains($title, $keyword['keyword'])) {
                if ($keyword['weight'] >= $threshold) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    private function getKeywords(): array
    {
        return Cache::remember('accessory_keywords', 3600, function() {
            return AccessoryKeyword::where('is_active', true)
                ->select('keyword', 'weight')
                ->get()
                ->toArray();
        });
    }
    
    public function clearCache(): void
    {
        Cache::forget('accessory_keywords');
    }
}
```

#### Seeder
```php
class AccessoryKeywordSeeder extends Seeder
{
    public function run()
    {
        $keywords = [
            ['keyword' => 'камбербанд', 'weight' => 10, 'category' => 'plate_carrier_accessories'],
            ['keyword' => 'кап', 'weight' => 10, 'category' => 'plate_carrier_accessories'],
            ['keyword' => 'чохол', 'weight' => 9, 'category' => 'covers'],
            ['keyword' => 'сумка', 'weight' => 8, 'category' => 'bags'],
            ['keyword' => 'ремінь', 'weight' => 7, 'category' => 'straps', 'notes' => 'Може бути основним товаром якщо запит "ремінь для..."'],
            // ...більше keywords
        ];
        
        foreach ($keywords as $data) {
            AccessoryKeyword::create($data);
        }
    }
}
```

---

## Category Hints

### Поточний Стан
**Файл**: [app/Services/Horoshop/ProductService.php#L817](../../app/Services/Horoshop/ProductService.php#L817)

```php
private function categoryBonus(string $categoryPath, string $needle): float
{
    $categoryNorm = mb_strtolower($categoryPath);
    $bonus = 0.0;
    
    $categoryHints = [
        'шолом' => ['шолом', 'шоломи', 'каска', 'каски', 'helmet'],
        'плитоноска' => ['плитоноска', 'плитоноски', 'plate carrier'],
        'куртка' => ['куртка', 'куртки', 'jacket'],
        'рукавиці' => ['рукавиці', 'рукавички', 'gloves'],
        // ...50+ ліній
    ];
    
    foreach ($categoryHints as $catKey => $synonyms) {
        if (str_contains($categoryNorm, $catKey)) {
            foreach ($synonyms as $syn) {
                if (str_contains(mb_strtolower($needle), $syn)) {
                    $bonus += 5.0;
                    break;
                }
            }
        }
    }
    
    return $bonus;
}
```

---

### Проблеми
1. ❌ 50+ ліній hardcoded hints
2. ❌ Не можна додати нові категорії без редеплою
3. ❌ Немає багатомовності (UA + EN mixed)
4. ❌ Складно підтримувати

---

### Рішення: Category Synonyms Table

#### Migration
```php
Schema::create('category_synonyms', function (Blueprint $table) {
    $table->id();
    $table->string('canonical');      // "шолом"
    $table->string('synonym');        // "helmet"
    $table->string('locale')->default('ua');
    $table->float('boost')->default(5.0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->index(['canonical', 'is_active']);
    $table->index('synonym');
});
```

#### Service
```php
class CategorySynonymService
{
    public function getCategoryBonus(string $categoryPath, string $query): float
    {
        $categoryPath = mb_strtolower($categoryPath);
        $query = mb_strtolower($query);
        
        $synonyms = $this->getSynonyms();
        $bonus = 0.0;
        
        foreach ($synonyms as $syn) {
            if (str_contains($categoryPath, $syn['canonical'])) {
                if (str_contains($query, $syn['synonym'])) {
                    $bonus += $syn['boost'];
                    break;
                }
            }
        }
        
        return $bonus;
    }
    
    private function getSynonyms(): array
    {
        return Cache::remember('category_synonyms', 3600, function() {
            return CategorySynonym::where('is_active', true)
                ->get()
                ->toArray();
        });
    }
}
```

---

## Context Detection Patterns

### Поточний Стан
**Файл**: [app/Services/Agent/Tools/MeiliProductSearchTool.php#L200](../../app/Services/Agent/Tools/MeiliProductSearchTool.php#L200)

```php
private function isContextualAccessoryQuery(string $query): bool
{
    $query = mb_strtolower($query);
    
    $contextPatterns = [
        '/^панел/ui',           // "панель", "панелі"
        '/панель для/ui',       // "панель для плитоноски"
        '/^ремінь/ui',          // "ремінь"
        '/ремінь для/ui',       // "ремінь для рюкзака"
        '/^кріплення/ui',       // "кріплення"
        '/кріплення для/ui',    // "кріплення для шолома"
    ];
    
    foreach ($contextPatterns as $pattern) {
        if (preg_match($pattern, $query)) {
            return true;
        }
    }
    
    return false;
}
```

---

### Проблеми
1. ❌ Hardcoded regex patterns
2. ❌ Складно тестувати різні варіанти
3. ❌ Немає A/B testing можливості

---

### Рішення: Context Patterns Table

#### Migration
```php
Schema::create('context_patterns', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('pattern');        // Regex pattern
    $table->string('description');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->index('is_active');
});
```

#### Seeder
```php
$patterns = [
    ['name' => 'panel_start', 'pattern' => '/^панел/ui', 'description' => 'Query starts with "панел"'],
    ['name' => 'panel_for', 'pattern' => '/панель для/ui', 'description' => '"панель для..."'],
    ['name' => 'strap_start', 'pattern' => '/^ремінь/ui', 'description' => 'Query starts with "ремінь"'],
    // ...
];
```

---

## AI Prompts

### Поточний Стан
AI промпти hardcoded в методах:
- `AiRouter::classify()` — intent classification prompt
- `AiRouter::normalizeSearchQuery()` — normalization prompt
- `AiRerankTool::buildRerankPrompt()` — reranking prompt

---

### Проблеми
1. ❌ Не можна A/B тестувати різні промпти
2. ❌ Складно відтрекати які промпти працюють краще
3. ❌ Немає версіонування

---

### Рішення: Prompt Templates Table

#### Migration
```php
Schema::create('ai_prompts', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();   // 'intent_classification'
    $table->integer('version')->default(1);
    $table->text('prompt');
    $table->json('variables')->nullable();  // Placeholders: {message}, {candidates}
    $table->boolean('is_active')->default(true);
    $table->float('success_rate')->nullable();  // Metrics
    $table->timestamps();
    
    $table->index(['name', 'is_active']);
});
```

#### Service
```php
class PromptService
{
    public function getPrompt(string $name, array $vars = []): string
    {
        $prompt = AiPrompt::where('name', $name)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
        
        if (!$prompt) {
            throw new \Exception("Prompt '{$name}' not found");
        }
        
        $template = $prompt->prompt;
        
        foreach ($vars as $key => $value) {
            $template = str_replace("{{$key}}", $value, $template);
        }
        
        return $template;
    }
}
```

---

## Priority Matrix

| Hardcoded Item | Priority | Effort | Impact | Status |
|----------------|----------|--------|--------|--------|
| FAQ Responses | 🔴 HIGH | Medium | High | ❌ TODO |
| Accessory Keywords | 🟡 MEDIUM | Low | Medium | ❌ TODO |
| Category Hints | 🟢 LOW | Medium | Low | ❌ TODO |
| Context Patterns | 🟢 LOW | Low | Low | ❌ TODO |
| AI Prompts | 🟡 MEDIUM | High | High | ❌ TODO |

---

## Migration Plan

### Phase 1: Critical (Sprint 1)
1. ✅ Create `faqs` table + FaqService
2. ✅ Seed initial FAQ data
3. ✅ Update AgentOrchestrator to use FaqService
4. ✅ Add admin panel for FAQ management

### Phase 2: Important (Sprint 2)
1. ✅ Create `accessory_keywords` table
2. ✅ Create AccessoryDetectionService
3. ✅ Refactor MeiliProductSearchTool + AccessoryFilterTool
4. ✅ Seed keywords from existing code

### Phase 3: Nice to Have (Sprint 3)
1. ✅ Create `category_synonyms` table
2. ✅ Migrate hints from ProductService
3. ✅ Create `context_patterns` table
4. ✅ Create `ai_prompts` table

---

**Наступний документ**: [Roadmap →](roadmap.md)
