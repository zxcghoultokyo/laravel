<?php

namespace App\Services\Store;

use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for loading store context dynamically.
 * Used by AI prompts, accessory filters, and brand detection.
 * 
 * Replaces hardcoded store-specific logic with database-driven config.
 */
class StoreContextService
{
    private const CACHE_KEY = 'store_context';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Get full store context for AI prompts.
     */
    public function getContext(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->loadContext();
        });
    }

    /**
     * Clear cached context (call after settings update).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Load context from database with sensible defaults.
     */
    private function loadContext(): array
    {
        $settings = WidgetSettings::first();
        
        if (!$settings) {
            Log::warning('StoreContextService: No widget_settings found, using defaults');
            return $this->getDefaults();
        }

        return [
            // Store identity
            'store_name' => $settings->store_name ?? 'Магазин',
            'store_context' => $settings->store_context ?? '',
            'store_description' => $settings->store_description ?? '',
            
            // Contact info (from existing fields)
            'store_phone' => $settings->shop_phone ?? null,
            'store_address' => $settings->faq_contacts_text ?? null,
            'store_hours' => $settings->store_hours ?? null,
            'store_about' => $settings->faq_about_text ?? null,
            
            // Customer types for AI context
            'customer_types' => $this->parseJson($settings->customer_types, []),
            
            // Product categories for AI understanding
            'product_categories' => $this->parseJson($settings->product_categories, []),
            
            // Accessory detection rules
            'accessory_keywords' => $this->parseJson($settings->accessory_keywords, []),
            'main_product_keywords' => $this->parseJson($settings->main_product_keywords, []),
            
            // Brand transliterations
            'brand_transliterations' => $this->parseJson($settings->brand_transliterations, []),
            
            // AI behavior flags
            'ai_use_dynamic_prompts' => $settings->ai_use_dynamic_prompts ?? true,
            'ai_strict_category_filter' => $settings->ai_strict_category_filter ?? false,
        ];
    }

    /**
     * Get default context (empty/generic store).
     */
    private function getDefaults(): array
    {
        return [
            'store_name' => 'Магазин',
            'store_context' => '',
            'store_description' => '',
            'store_phone' => null,
            'store_address' => null,
            'store_hours' => null,
            'store_about' => null,
            'customer_types' => [],
            'product_categories' => [],
            'accessory_keywords' => [],
            'main_product_keywords' => [],
            'brand_transliterations' => [],
            'ai_use_dynamic_prompts' => true,
            'ai_strict_category_filter' => false,
        ];
    }

    /**
     * Parse JSON field safely.
     */
    private function parseJson($value, $default = [])
    {
        if (is_array($value)) {
            return $value;
        }
        
        if (is_string($value) && !empty($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $default;
        }
        
        return $default;
    }

    /**
     * Build AI classification prompt based on store context.
     * Returns dynamic prompt for intent classification.
     */
    public function buildClassifyPrompt(string $message): string
    {
        $ctx = $this->getContext();
        
        $storeName = $ctx['store_name'];
        $storeContext = $ctx['store_context'];
        
        // Build customer types section
        $customerTypesText = '';
        if (!empty($ctx['customer_types'])) {
            $customerTypesText = "Клієнти: " . implode(', ', $ctx['customer_types']);
        }
        
        // Build product categories section
        $categoriesText = '';
        if (!empty($ctx['product_categories'])) {
            $categoriesText = "Категорії товарів: " . implode(', ', $ctx['product_categories']);
        }

        return <<<PROMPT
Ти — AI-асистент магазину "{$storeName}"{$storeContext}.

{$customerTypesText}
{$categoriesText}

Визнач намір користувача:
- PRODUCT_SEARCH: пошук товарів ВКЛЮЧАЮЧИ:
  * прямі запити (назви товарів)
  * запити з питаннями (яку обрати, що порадиш, яка краще)
  * ЗАГАЛЬНІ запити (подарунок, що купують, популярне, найкраще, хіт продажів)
  * коли юзер НЕ ЗНАЄ що хоче але хоче товар
- PRODUCT_COMPARISON: ТІЛЬКИ явне порівняння конкретних моделей (порівняй X і Y, чим відрізняється, vs)
- ORDER_STATUS: питання про замовлення (статус, трекінг, моє замовлення)
- FAQ: доставка, оплата, повернення, контакти, графік роботи
- SMALL_TALK: ТІЛЬКИ вітання/подяки БЕЗ запиту товарів (привіт!, дякую, бувай)
- FALLBACK: ТІЛЬКИ якщо взагалі не про магазин (погода, політика, особисте)

ВАЖЛИВО для normalized_query:
- Для загальних запитів (подарунок, популярне, хіт) → 'популярні товари'
- Видали службові слова: допоможи, підібрати, покажи, знайди, хочу, шукаю
- Залиш назву товару + характеристики

ВИЗНАЧИ needs_human: true КОЛИ:
- Запит на великий опт (більше 10 шт), корпоративне замовлення, B2B
- Торг/знижка ('дорого', 'скинете', 'знижка', 'акція')
- Скарга, незадоволення ('погане', 'брак', 'не влаштовує', 'повернення')
- Складне технічне питання яке не стосується товарів
- Юзер прямо просить оператора ('людина', 'менеджер', 'оператор')
- Не можеш впевнено відповісти
В інших випадках needs_human: false.

Поверни JSON:
{
  "intent": "PRODUCT_SEARCH | PRODUCT_COMPARISON | ORDER_STATUS | FAQ | SMALL_TALK | FALLBACK",
  "normalized_query": "очищені ключові слова або null",
  "order_id": null або номер,
  "needs_human": true/false,
  "escalation_reason": "причина ескалації або null"
}

Запит: "{$message}"
PROMPT;
    }

    /**
     * Build AI normalization prompt based on store context.
     */
    public function buildNormalizePrompt(string $message): string
    {
        $ctx = $this->getContext();
        
        // Build brand transliterations section
        $brandExamples = '';
        if (!empty($ctx['brand_transliterations'])) {
            $examples = [];
            foreach ($ctx['brand_transliterations'] as $cyrillic => $latin) {
                $examples[] = "   - {$cyrillic} → {$latin}";
            }
            $brandExamples = "РОЗПІЗНАВАЙ бренди написані кирилицею:\n" . implode("\n", $examples);
        }

        return <<<PROMPT
Ти — асистент пошуку для магазину.

Завдання: витягни з запиту користувача ТІЛЬКИ ключові пошукові терміни для пошуку.

ОБОВ'ЯЗКОВО:
1. ВИПРАВЛЯЙ друкарські помилки
2. {$brandExamples}

ПРИБРАТИ:
- Привітання: привіт, хей, добрий день
- Допоміжні: допоможи, покажи, розкажи, знайди, підбери, порадь
- Питальні: яку обрати, яка краще, що порадиш, який вибрати, а яку, яку
- Займенники: мені, мене, мій, твій
- Прийменники: про, для, в, на, до, із, з

ЗАЛИШИТИ:
- Назви товарів
- Характеристики: колір, розмір, модель, бренд
- Цифри: розміри, класи

ФОРМАТ: тільки чистий пошуковий запит, без лапок

Запит: "{$message}"
PROMPT;
    }

    /**
     * Build AI rerank prompt based on store context.
     */
    public function buildRerankPrompt(array $candidates, string $query, array $filters = [], ?string $detectedBrand = null): string
    {
        $ctx = $this->getContext();
        
        $storeName = $ctx['store_name'];
        $storeContext = $ctx['store_context'];
        
        // Build customer types section
        $customerTypesText = '';
        if (!empty($ctx['customer_types'])) {
            $customerTypesText = "Клієнти: " . implode(', ', $ctx['customer_types']) . ".";
        }
        
        // Build candidates list
        $candidatesList = [];
        foreach (array_slice($candidates, 0, 40) as $c) {
            $categoryPath = $c['category_path'] ?? 'N/A';
            if (is_array($categoryPath)) {
                $categoryPath = implode(' > ', $categoryPath);
            }
            
            $brand = $c['brand'] ?? 'N/A';
            $aiType = $c['ai_product_type'] ?? '';
            
            $candidatesList[] = sprintf(
                "ID %d: %s | Бренд: %s | %s грн | %s | Type: %s | Stock: %s",
                $c['id'],
                $c['title'],
                $brand,
                $c['price'],
                $categoryPath,
                $aiType,
                $c['in_stock'] ? 'Yes' : 'No'
            );
        }
        
        $filterDesc = '';
        if (!empty($filters['budget_max'])) {
            $filterDesc .= "Бюджет до {$filters['budget_max']} грн. ";
        }
        if (!empty($filters['color'])) {
            $filterDesc .= "Колір: {$filters['color']}. ";
        }
        
        // Brand instruction
        $brandInstruction = '';
        if ($detectedBrand) {
            $brandInstruction = <<<BRAND

🔴 КРИТИЧНО ВАЖЛИВО — БРЕНД:
Запит містить бренд "{$detectedBrand}" → показувати ТІЛЬКИ товари бренду "{$detectedBrand}"!
BRAND;
        }
        
        // Accessory detection from config (not hardcoded!)
        $accessoryInstruction = '';
        if (!empty($ctx['accessory_keywords'])) {
            $keywords = implode('", "', $ctx['accessory_keywords']);
            $accessoryInstruction = <<<ACC

АКСЕСУАРИ: Товари з назвою "{$keywords}" — це аксесуари.
- Якщо є 3+ основних товарів — показуй тільки основні
- Аксесуари показуй тільки якщо немає основних товарів
ACC;
        }
        
        $candidateCount = count($candidates);
        $candidateLines = implode("\n", $candidatesList);

        return <<<PROMPT
Ти — AI-експерт магазину "{$storeName}"{$storeContext}.

{$customerTypesText}

Запит користувача: "{$query}"
{$filterDesc}

Кандидати ({$candidateCount} товарів):
{$candidateLines}
{$brandInstruction}
{$accessoryInstruction}

ВАЖЛИВО:
- Використовуй поле "Type" (ai_product_type) для визначення типу товару
- Якщо Type порожній — аналізуй назву та категорію

Завдання:
1. Обери ТІЛЬКИ справді релевантні товари (мінімум 3, максимум 10)
2. ЯКЩО релевантних менше 10 — вибери тільки їх (НЕ заповнюй до 10!)
3. ЯКЩО є 3-4 ідеальних товарів + 6 посередніх → вибери тільки 3-4 ідеальних
4. СПОЧАТКУ основні товари, ПОТІМ аксесуари (якщо дуже релевантні)
5. Якість > кількість. 3 точні варіанти краще ніж 10 різних

Поверни JSON:
{
  "chosen_ids": [id1, id2, ...],
  "reasoning": {"id1": "причина вибору", ...}
}
PROMPT;
    }

    /**
     * Check if a term is an accessory based on config.
     */
    public function isAccessoryTerm(string $term): bool
    {
        $ctx = $this->getContext();
        $keywords = $ctx['accessory_keywords'] ?? [];
        
        if (empty($keywords)) {
            return false;
        }
        
        $termLower = mb_strtolower($term);
        foreach ($keywords as $keyword) {
            if (str_contains($termLower, mb_strtolower($keyword))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if a term is a main product based on config.
     */
    public function isMainProductTerm(string $term): bool
    {
        $ctx = $this->getContext();
        $keywords = $ctx['main_product_keywords'] ?? [];
        
        if (empty($keywords)) {
            return false;
        }
        
        $termLower = mb_strtolower($term);
        foreach ($keywords as $keyword) {
            if (str_contains($termLower, mb_strtolower($keyword))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Transliterate brand from cyrillic to correct spelling.
     */
    public function transliterateBrand(string $text): string
    {
        $ctx = $this->getContext();
        $translits = $ctx['brand_transliterations'] ?? [];
        
        if (empty($translits)) {
            return $text;
        }
        
        $result = $text;
        foreach ($translits as $cyrillic => $latin) {
            $result = str_ireplace($cyrillic, $latin, $result);
        }
        
        return $result;
    }
}
