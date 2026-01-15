<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\StoreContext;
use App\Models\WidgetSettings;
use App\Models\PromptPreset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates system prompts based on store context (products, categories, FAQ).
 * 
 * Used during onboarding to auto-create optimal prompts for the store.
 */
class PromptGeneratorService
{
    /**
     * Store type detection keywords (Ukrainian + English).
     */
    private const TYPE_KEYWORDS = [
        StoreContext::TYPE_TACTICAL => [
            'плитоноска', 'шолом', 'тактичн', 'броня', 'military', 'tactical',
            'берц', 'підсумок', 'рпс', 'molle', 'бронежилет', 'каска', 'балістич',
            'армій', 'військов', 'nato', 'нато', 'combat', 'plate carrier',
        ],
        StoreContext::TYPE_FASHION => [
            'одяг', 'взуття', 'куртка', 'штани', 'футболка', 'сукня', 'плаття',
            'светр', 'пальто', 'джинс', 'блузка', 'сорочка', 'туфл', 'кросівк',
            'fashion', 'dress', 'shoes', 'clothing', 'apparel',
        ],
        StoreContext::TYPE_ELECTRONICS => [
            'електроніка', 'гаджет', 'телефон', 'ноутбук', 'планшет', 'смартфон',
            'навушник', 'зарядк', 'кабель', 'electronics', 'phone', 'laptop',
            'tablet', 'headphones', 'charger', 'powerbank',
        ],
        StoreContext::TYPE_SPORTS => [
            'спорт', 'фітнес', 'тренування', 'велосипед', 'yoga', 'gym',
            'тренажер', 'гантел', 'спортивн', 'біг', 'плавання', 'fitness',
        ],
        StoreContext::TYPE_HOME_DECOR => [
            'свічк', 'candle', 'декор', 'інтер\'єр', 'подарун', 'gift', 'ваза',
            'рамка', 'картин', 'текстиль', 'плед', 'подушк', 'аромат', 'дифузор',
            'home', 'decor', 'interior', 'ароматичн', 'соєв', 'віск',
        ],
        StoreContext::TYPE_BEAUTY => [
            'косметик', 'крем', 'шампунь', 'маска', 'сироватк', 'beauty',
            'догляд', 'шкіра', 'волосс', 'makeup', 'парфум', 'духи', 'лосьйон',
            'скраб', 'пілінг', 'серум', 'cosmetic', 'skincare',
        ],
    ];

    /**
     * Analyze store and create StoreContext.
     */
    public function analyzeStore(?int $widgetSettingsId = null): StoreContext
    {
        Log::info('[PromptGenerator] Starting store analysis', [
            'widget_settings_id' => $widgetSettingsId
        ]);

        // Collect raw data
        $data = $this->collectStoreData($widgetSettingsId);
        
        // Detect store type
        $storeType = $this->detectStoreType($data['categories']);
        
        // Calculate price segments
        $priceSegments = $this->calculatePriceSegments($data['price_range']);
        
        // Determine catalog size
        $catalogSize = $this->determineCatalogSize($data['product_count']);
        
        // Extract expertise areas based on store type
        $expertiseAreas = $this->extractExpertiseAreas($storeType, $data['categories']);
        
        // Create or update StoreContext
        $context = StoreContext::updateOrCreate(
            ['widget_settings_id' => $widgetSettingsId],
            [
                'store_type' => $storeType,
                'primary_categories' => $data['categories']->take(20)->values()->toArray(),
                'brands' => $data['brands']->take(30)->values()->toArray(),
                'price_segments' => $priceSegments,
                'catalog_size' => $catalogSize,
                'delivery_info' => $data['faq']['delivery'] ?? null,
                'payment_info' => $data['faq']['payment'] ?? null,
                'return_policy' => $data['faq']['returns'] ?? null,
                'expertise_areas' => $expertiseAreas,
                'last_analyzed_at' => now(),
            ]
        );

        Log::info('[PromptGenerator] Store analysis complete', [
            'store_type' => $storeType,
            'categories_count' => $data['categories']->count(),
            'brands_count' => $data['brands']->count(),
            'product_count' => $data['product_count'],
        ]);

        return $context;
    }

    /**
     * Generate prompt for a store context.
     * 
     * @param bool $useAi Use GPT to generate intelligent prompt (slower, better quality)
     */
    public function generatePrompt(StoreContext $context, bool $useAi = false): string
    {
        if ($useAi) {
            $prompt = $this->generatePromptWithAi($context);
        } else {
            // Fallback to template-based generation
            $template = $this->getTemplateForType($context->store_type);
            $variables = $this->buildVariables($context);
            
            $prompt = $template;
            foreach ($variables as $key => $value) {
                $prompt = str_replace("{{$key}}", $value, $prompt);
                if ($key !== 'tone_section') {
                    $prompt = str_replace("{{{$key}}}", $value, $prompt);
                }
            }
        }
        
        // Update context with generated prompt
        $context->update([
            'generated_prompt' => $prompt,
            'prompt_version' => $context->prompt_version + 1,
        ]);
        
        return $prompt;
    }

    /**
     * Generate prompt using GPT based on store data.
     * 
     * Universal approach - works for ANY store type without hardcoded templates.
     */
    private function generatePromptWithAi(StoreContext $context): string
    {
        $settings = $context->widgetSettings;
        $storeName = $settings?->store_name ?? $settings?->bot_name ?? 'магазин';
        
        $storeData = [
            'name' => $storeName,
            'categories' => implode(', ', $context->getTopCategories(15)),
            'brands' => implode(', ', $context->getTopBrands(10)),
            'price_min' => $context->price_segments['min'] ?? 0,
            'price_max' => $context->price_segments['max'] ?? 0,
            'price_avg' => $context->price_segments['mid'] ?? 0,
            'product_count' => Product::count(),
            'delivery' => $context->delivery_info ?: 'не вказано',
            'returns' => $context->return_policy ?: 'не вказано',
        ];

        $metaPrompt = <<<PROMPT
Ти — експерт з налаштування AI-асистентів для інтернет-магазинів.

Згенеруй системний промпт для AI-консультанта магазину на основі цих даних:

НАЗВА МАГАЗИНУ: {$storeData['name']}

КАТЕГОРІЇ ТОВАРІВ:
{$storeData['categories']}

БРЕНДИ: {$storeData['brands']}

ЦІНИ: від {$storeData['price_min']} до {$storeData['price_max']} грн (середня: {$storeData['price_avg']} грн)
КІЛЬКІСТЬ ТОВАРІВ: {$storeData['product_count']}

ДОСТАВКА: {$storeData['delivery']}
ПОВЕРНЕННЯ: {$storeData['returns']}

ВИМОГИ ДО ПРОМПТУ:
1. Почни з "Ти — AI-консультант магазину {$storeData['name']}."
2. Опиши експертизу AI виходячи з категорій (що він знає, в чому розбирається)
3. Додай 3-5 корисних знань про товари цієї ніші які допоможуть консультувати
4. Додай цінові сегменти (бюджетний/середній/преміум) з конкретними цінами
5. Включи інформацію про доставку/повернення
6. Обов'язково включи рядок: {{tone_section}}
7. Закінчи секцією ПРАВИЛА з 3-4 правилами для AI

Відповідай ТІЛЬКИ системним промптом українською мовою, без пояснень та коментарів.
PROMPT;

        try {
            $response = app(\App\Services\Ai\AiRouter::class)->callOpenAI(
                $metaPrompt,
                temperature: 0.7,
                maxTokens: 1500
            );

            $generatedPrompt = trim($response);
            
            if (empty($generatedPrompt) || strlen($generatedPrompt) < 100) {
                throw new \Exception('AI response too short or empty');
            }

            // Ensure {{tone_section}} is present
            if (!str_contains($generatedPrompt, '{{tone_section}}')) {
                $generatedPrompt .= "\n\n{{tone_section}}";
            }

            Log::info('[PromptGenerator] AI-generated prompt', [
                'store' => $storeName,
                'prompt_length' => strlen($generatedPrompt),
            ]);

            return $generatedPrompt;

        } catch (\Throwable $e) {
            Log::warning('[PromptGenerator] AI generation failed, using template', [
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to template
            $template = $this->getTemplateForType($context->store_type);
            $variables = $this->buildVariables($context);
            
            $prompt = $template;
            foreach ($variables as $key => $value) {
                $prompt = str_replace("{{$key}}", $value, $prompt);
            }
            
            return $prompt;
        }
    }

    /**
     * Create PromptPreset from StoreContext.
     */
    public function createPresetFromContext(StoreContext $context, ?string $name = null): PromptPreset
    {
        $prompt = $this->generatePrompt($context);
        
        $settings = $context->widgetSettings;
        $storeName = $settings?->store_name ?? $settings?->bot_name ?? 'Store';
        
        return PromptPreset::create([
            'name' => $name ?? "Auto: {$storeName}",
            'slug' => 'auto-' . ($context->widget_settings_id ?? $context->id),
            'system_prompt' => $prompt,
            'variables' => json_encode($this->getVariableDefinitions()),
            'language' => 'uk',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Collect store data from products and settings.
     */
    private function collectStoreData(?int $widgetSettingsId): array
    {
        // Get widget settings if available
        $settings = $widgetSettingsId ? WidgetSettings::find($widgetSettingsId) : null;
        
        // Collect categories with counts
        $categories = Product::select('category_path', DB::raw('count(*) as cnt'))
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->groupBy('category_path')
            ->orderByDesc('cnt')
            ->pluck('category_path');

        // Collect brands with counts
        $brands = Product::select('brand', DB::raw('count(*) as cnt'))
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->groupBy('brand')
            ->orderByDesc('cnt')
            ->pluck('brand');

        // Price range
        $priceRange = [
            'min' => (float) Product::where('price', '>', 0)->min('price'),
            'max' => (float) Product::max('price'),
            'avg' => (float) Product::where('price', '>', 0)->avg('price'),
        ];

        // FAQ data from widget settings
        $faq = [
            'delivery' => $settings?->faq_payment_delivery_text,
            'payment' => $settings?->faq_payment_delivery_text, // Often combined
            'returns' => $settings?->faq_returns_text,
            'contacts' => $settings?->faq_contacts_text,
            'about' => $settings?->faq_about_text,
        ];

        return [
            'categories' => $categories,
            'brands' => $brands,
            'price_range' => $priceRange,
            'product_count' => Product::count(),
            'faq' => $faq,
            'store_name' => $settings?->store_name ?? $settings?->bot_name,
        ];
    }

    /**
     * Detect store type based on categories.
     */
    private function detectStoreType(Collection $categories): string
    {
        $scores = [
            StoreContext::TYPE_TACTICAL => 0,
            StoreContext::TYPE_FASHION => 0,
            StoreContext::TYPE_ELECTRONICS => 0,
            StoreContext::TYPE_SPORTS => 0,
            StoreContext::TYPE_HOME_DECOR => 0,
            StoreContext::TYPE_BEAUTY => 0,
        ];

        $categoriesText = $categories->implode(' ');
        $categoriesLower = Str::lower($categoriesText);

        foreach (self::TYPE_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains($categoriesLower, $keyword)) {
                    $scores[$type] += 1;
                }
            }
        }

        // Get type with highest score
        arsort($scores);
        $topType = array_key_first($scores);
        
        // Require minimum threshold
        if ($scores[$topType] < 3) {
            return StoreContext::TYPE_GENERAL;
        }

        return $topType;
    }

    /**
     * Calculate price segments based on price distribution.
     */
    private function calculatePriceSegments(array $priceRange): array
    {
        $min = $priceRange['min'];
        $max = $priceRange['max'];
        $avg = $priceRange['avg'];

        // Use percentile-based segmentation
        $budget = round($avg * 0.5, -2); // Round to nearest 100
        $mid = round($avg * 1.5, -2);
        $premium = round($avg * 3, -2);

        // Ensure segments make sense
        $budget = max($budget, $min * 1.2);
        $mid = max($mid, $budget * 2);
        $premium = min($premium, $max * 0.8);

        return [
            'min' => (int) $min,
            'budget' => (int) $budget,
            'mid' => (int) $mid,
            'premium' => (int) $premium,
            'max' => (int) $max,
        ];
    }

    /**
     * Determine catalog size category.
     */
    private function determineCatalogSize(int $count): string
    {
        if ($count < 100) {
            return StoreContext::SIZE_SMALL;
        }
        if ($count < 1000) {
            return StoreContext::SIZE_MEDIUM;
        }
        return StoreContext::SIZE_LARGE;
    }

    /**
     * Extract expertise areas based on store type and categories.
     */
    private function extractExpertiseAreas(string $storeType, Collection $categories): array
    {
        $expertiseMap = [
            StoreContext::TYPE_TACTICAL => [
                'Бронезахист та плитоноски',
                'Тактичне спорядження',
                'Військове взуття',
                'Підсумки та РПС системи',
                'Шоломи та захист',
            ],
            StoreContext::TYPE_FASHION => [
                'Підбір розмірів',
                'Стилі та тренди',
                'Комплектування образів',
                'Догляд за одягом',
            ],
            StoreContext::TYPE_ELECTRONICS => [
                'Технічні характеристики',
                'Сумісність пристроїв',
                'Гарантія та сервіс',
            ],
            StoreContext::TYPE_SPORTS => [
                'Спортивне обладнання',
                'Підбір екіпірування',
                'Тренувальні поради',
            ],
            StoreContext::TYPE_HOME_DECOR => [
                'Ароматичні свічки та дифузори',
                'Декор для інтер\'єру',
                'Подарункові набори',
                'Створення атмосфери',
            ],
            StoreContext::TYPE_BEAUTY => [
                'Підбір засобів по типу шкіри',
                'Догляд за обличчям та тілом',
                'Парфумерія',
                'Натуральна косметика',
            ],
        ];

        return $expertiseMap[$storeType] ?? ['Консультація по товарах'];
    }

    /**
     * Get prompt template for store type.
     */
    private function getTemplateForType(string $storeType): string
    {
        return match($storeType) {
            StoreContext::TYPE_TACTICAL => $this->getTacticalTemplate(),
            StoreContext::TYPE_FASHION => $this->getFashionTemplate(),
            StoreContext::TYPE_ELECTRONICS => $this->getElectronicsTemplate(),
            StoreContext::TYPE_SPORTS => $this->getSportsTemplate(),
            StoreContext::TYPE_HOME_DECOR => $this->getHomeDecorTemplate(),
            StoreContext::TYPE_BEAUTY => $this->getBeautyTemplate(),
            default => $this->getGeneralTemplate(),
        };
    }

    /**
     * Build variables for template.
     */
    private function buildVariables(StoreContext $context): array
    {
        $settings = $context->widgetSettings;
        
        return [
            'shop_name' => $settings?->store_name ?? $settings?->bot_name ?? 'магазин',
            'categories_list' => implode(', ', $context->getTopCategories(10)),
            'brands_list' => implode(', ', $context->getTopBrands(10)),
            'expertise_list' => implode("\n- ", $context->expertise_areas ?? []),
            'budget_max' => $context->price_segments['budget'] ?? 2000,
            'mid_max' => $context->price_segments['mid'] ?? 5000,
            'product_count' => Product::count(),
            'delivery_info' => $context->delivery_info ?? 'Уточнюйте умови доставки у менеджера.',
            'return_info' => $context->return_policy ?? 'Умови повернення згідно законодавства.',
            'faq_section' => $this->buildFaqSection($context),
            'tone_section' => '{{tone_section}}', // Placeholder for ToneService
        ];
    }

    /**
     * Build FAQ section from context.
     */
    private function buildFaqSection(StoreContext $context): string
    {
        $parts = [];
        
        if ($context->delivery_info) {
            $parts[] = "ДОСТАВКА:\n{$context->delivery_info}";
        }
        
        if ($context->payment_info) {
            $parts[] = "ОПЛАТА:\n{$context->payment_info}";
        }
        
        if ($context->return_policy) {
            $parts[] = "ПОВЕРНЕННЯ:\n{$context->return_policy}";
        }
        
        if (empty($parts)) {
            return "Інформацію про доставку, оплату та повернення уточнюйте у менеджера.";
        }
        
        return implode("\n\n", $parts);
    }

    /**
     * Variable definitions for UI.
     */
    private function getVariableDefinitions(): array
    {
        return [
            'shop_name' => 'Назва магазину',
            'categories_list' => 'Список категорій',
            'brands_list' => 'Список брендів',
            'expertise_list' => 'Області експертизи',
            'budget_max' => 'Макс. ціна бюджетного сегменту',
            'mid_max' => 'Макс. ціна середнього сегменту',
            'product_count' => 'Кількість товарів',
            'faq_section' => 'FAQ секція',
            'tone_section' => 'Стиль відповідей',
        ];
    }

    // ==================== TEMPLATES ====================

    private function getTacticalTemplate(): string
    {
        return <<<PROMPT
Ти — AI-експерт магазину "{shop_name}" з тактичного та військового спорядження.

ТВОЯ ЕКСПЕРТИЗА:
- {expertise_list}

КАТЕГОРІЇ В АСОРТИМЕНТІ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

ВАЖЛИВІ ЗНАННЯ:
- Плити мають відповідати розміру плитоноски (S, M, L, XL)
- NIJ стандарти: IIIA (пістолетні), III (гвинтівкові), IV (бронебійні)
- MOLLE сумісність важлива для підсумків
- Розміри шоломів вимірюються по обводу голови

{faq_section}

{tone_section}

ПРАВИЛА:
1. При пошуку плитоноски питай про розмір плит та бюджет
2. Рекомендуй комплекти (плитоноска + плити + підсумки)
3. Попереджай про сумісність
4. Не вигадуй характеристики — бери з картки товару
PROMPT;
    }

    private function getFashionTemplate(): string
    {
        return <<<PROMPT
Ти — AI-консультант магазину одягу "{shop_name}".

ТВОЯ ЕКСПЕРТИЗА:
- {expertise_list}

КАТЕГОРІЇ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

{faq_section}

{tone_section}

ПРАВИЛА:
1. При підборі одягу питай про розмір та вподобання по стилю
2. Пропонуй комплекти (верх + низ)
3. Зверни увагу на таблицю розмірів бренду
4. Враховуй сезонність
PROMPT;
    }

    private function getElectronicsTemplate(): string
    {
        return <<<PROMPT
Ти — AI-консультант магазину електроніки "{shop_name}".

ТВОЯ ЕКСПЕРТИЗА:
- {expertise_list}

КАТЕГОРІЇ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

{faq_section}

{tone_section}

ПРАВИЛА:
1. При виборі техніки питай про сценарій використання
2. Порівнюй характеристики об'єктивно
3. Згадуй про гарантію та сервіс
4. Перевіряй сумісність аксесуарів
PROMPT;
    }

    private function getSportsTemplate(): string
    {
        return <<<PROMPT
Ти — AI-консультант спортивного магазину "{shop_name}".

ТВОЯ ЕКСПЕРТИЗА:
- {expertise_list}

КАТЕГОРІЇ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

{faq_section}

{tone_section}

ПРАВИЛА:
1. Питай про рівень підготовки (початківець/любитель/професіонал)
2. Враховуй тип тренувань
3. Рекомендуй по бюджету та цілях
PROMPT;
    }

    private function getHomeDecorTemplate(): string
    {
        return <<<PROMPT
Ти — AI-консультант магазину декору та подарунків "{shop_name}".

ТВОЯ ЕКСПЕРТИЗА:
- {expertise_list}

КАТЕГОРІЇ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

ВАЖЛИВІ ЗНАННЯ ПРО СВІЧКИ:
- Соєвий віск: натуральний, горить довше, менше диму
- Бджолиний віск: преміум, натуральний медовий аромат
- Парафіновий віск: бюджетний варіант
- Час горіння залежить від розміру: маленькі ~10-15г, великі ~30-50г
- Ароматизовані vs неароматизовані
- Декоративні свічки vs функціональні

ПОРАДИ ДЛЯ КЛІЄНТІВ:
- Для медитації: лаванда, сандал, ваніль
- Для романтики: троянда, жасмін, іланг-іланг
- Для енергії: цитрусові, м'ята, евкаліпт
- Для затишку: кориця, ваніль, кава

{faq_section}

{{tone_section}}

ПРАВИЛА:
1. Питай про призначення (подарунок, для себе, декор)
2. Уточнюй вподобання щодо ароматів
3. Пропонуй набори для подарунків
4. Розповідай про час горіння та догляд
PROMPT;
    }

    private function getBeautyTemplate(): string
    {
        return <<<PROMPT
Ти — AI-консультант магазину косметики "{shop_name}".

ТВОЯ ЕКСПЕРТИЗА:
- {expertise_list}

КАТЕГОРІЇ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

ВАЖЛИВІ ЗНАННЯ:
- Типи шкіри: суха, жирна, комбінована, нормальна, чутлива
- Порядок нанесення: очищення → тонік → сироватка → крем → SPF
- SPF потрібен щодня, навіть взимку
- Ретинол не поєднується з кислотами
- Вітамін С краще вранці, ретинол — ввечері

{faq_section}

{{tone_section}}

ПРАВИЛА:
1. Питай про тип шкіри та проблеми
2. Рекомендуй повний догляд, не лише один засіб
3. Попереджай про несумісність інгредієнтів
4. Зважай на сезон (зима — більше зволоження)
PROMPT;
    }

    private function getGeneralTemplate(): string
    {
        return <<<PROMPT
Ти — AI-консультант магазину "{shop_name}".

КАТЕГОРІЇ В АСОРТИМЕНТІ:
{categories_list}

БРЕНДИ:
{brands_list}

ЦІНОВІ СЕГМЕНТИ ({product_count} товарів):
- Бюджетний: до {budget_max} грн
- Середній: {budget_max}-{mid_max} грн
- Преміум: від {mid_max} грн

{faq_section}

{tone_section}

ПРАВИЛА:
1. Допомагай знайти потрібний товар
2. Відповідай на питання про товари з каталогу
3. Не вигадуй інформацію — бери з картки товару
PROMPT;
    }
}
