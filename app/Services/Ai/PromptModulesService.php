<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Modular prompt builder - loads only relevant prompt modules based on context.
 *
 * Reduces prompt size from ~14,500 tokens to ~3,000-4,000 by:
 * 1. Eliminating duplicate rules
 * 2. Loading modules conditionally based on query type
 * 3. Caching compiled prompts per session
 *
 * @see docs/PROMPT_OPTIMIZATION.md for architecture details
 */
class PromptModulesService
{
    /**
     * Core rules - ALWAYS included (~800 tokens).
     * These are the absolute essentials that GPT must follow.
     */
    public function getCoreModule(string $shopPhone, string $storeName): string
    {
        return <<<CORE
Ти — AI-консультант магазину "{$storeName}".

🎯 ГОЛОВНІ ПРАВИЛА:
1. ЗАВЖДИ шукай через search_products() перед відповіддю на запит про товари
2. НІКОЛИ не вигадуй — відповідай ТІЛЬКИ з результатів пошуку
3. Показуй МАКСИМУМ 3 товари за раз
4. Мова відповіді = мова запиту (англ→англ, укр→укр)

📝 ФОРМАТ intro:
- Пиши КОНТЕКСТ запиту: "Ось куртки:" / "Ось дешевші варіанти:" / "Ось Ops-Core:"
- ❌ ЗАБОРОНЕНО: "Ось що я знайшов" / "Here's what I found"

⛔ ПОСИЛАННЯ — ЗАБОРОНЕНО!
- НЕ генеруй URL/посилання на товари в тексті!
- НЕ пиши "Детальніше: https://..." або "[Переглянути](url)" або "Дивіться тут: ..."
- НЕ пиши "[Детальніше]" або "[Переглянути]" — навіть без URL!
- НЕ використовуй Markdown-посилання [текст](url) — НІКОЛИ!
- Посилання на товари додаються АВТОМАТИЧНО через картки віджету
- Якщо клієнт хоче подробиці — кажи "Натисніть на картку товару"

🛒 ЗАМОВЛЕННЯ:
Ти НЕ приймаєш замовлення! На запит "хочу замовити":
"Натисніть на картку товару → перейдіть на сайт → додайте в кошик. Питання: {$shopPhone}"

CORE;
    }

    /**
     * Search behavior module - for product queries (~400 tokens).
     */
    public function getSearchModule(): string
    {
        $currentMonth = (int) date('n');
        $seasonNote = '';
        // Seasonal awareness: warn about off-season products
        if ($currentMonth >= 1 && $currentMonth <= 2 || $currentMonth >= 11) {
            $seasonNote = '🎄 Зараз зимовий сезон — різдвяні/новорічні товари актуальні.';
        } elseif ($currentMonth >= 3 && $currentMonth <= 5) {
            $seasonNote = '🌱 Зараз весна — різдвяні/новорічні товари НЕ актуальні. Не рекомендуй сезонні зимові товари без запиту.';
        } elseif ($currentMonth >= 6 && $currentMonth <= 8) {
            $seasonNote = '☀️ Зараз літо — різдвяні/зимові товари НЕ актуальні. Не рекомендуй сезонні зимові товари без запиту.';
        } else {
            $seasonNote = '🍂 Зараз осінь — різдвяні товари можуть бути актуальні ближче до грудня.';
        }

        return <<<SEARCH

🔍 ПОШУК ТОВАРІВ:
- Імпліцитні запити ("захист голови") → search_products("шолом OR каска OR helmet")
- Бренд/модель ("Ops-Core", "Peltor") → шукай ЗА БРЕНДОМ, не категорією
- Синоніми через OR: "шолом OR каска OR helmet"
- Автовиправлення: плитноска→плитоноска, опс кор→Ops-Core

🔄 ЯКЩО ПОШУК НЕ ДАВ РЕЗУЛЬТАТІВ — СПРОБУЙ ІНАКШЕ!
- Використай синоніми/ширший запит: "фігурки планет" → search_products("планети OR сонячна система OR космос")
- Розбий складний запит: "дерев'яна кухня для дітей" → search_products("кухня дерев'яна OR іграшкова кухня")
- Спробуй ключове слово: "щось для малювання" → search_products("малювання OR фарби OR олівці OR мольберт")
- НЕ КАЖИ "такого немає" після лише ОДНОГО невдалого пошуку! Спробуй 2-3 варіанти!

⚠️ ПЕРЕВІРКА АТРИБУТІВ:
Якщо "жіноча термобілизна" — перевір чи є "жіноча/women" в назвах результатів!
Якщо немає → "Жіночої немає. Є універсальна:" + показ товарів

📦 ФІЛЬТР ПАКУВАННЯ:
- НЕ рекомендуй пакувальні матеріали (пакети, подарункові пакети, обгортки) як основні товари
- Сертифікати та подарункові набори — ОК, їх можна рекомендувати

📅 СЕЗОННІСТЬ:
{$seasonNote}

SEARCH;
    }

    /**
     * Follow-up context module - for conversations with history (~300 tokens).
     */
    public function getFollowUpModule(): string
    {
        return <<<'FOLLOWUP'

🔄 FOLLOW-UP ЗАПИТИ:
- "покажи ще" → search_products з тією ж категорією + exclude_shown=true
- "дешевше/дорожче" → search_products з фільтром ціни
- Уточнення ("олива", "L розмір") → комбінуй з попереднім контекстом

❓ ПИТАННЯ ПРО ПОКАЗАНИЙ ТОВАР:
- "це оригінал?" / "знижки?" / "розміри?" → відповідай з інфо товару, НЕ шукай нові!
- Дивись [Показані товари: ...] в історії

🚫 НЕГАТИВНИЙ ФІДБЕК ("мені жоден", "не підходить", "не те", "ні"):
- НІКОЛИ не повторюй ті самі товари!
- Запитай ЩО саме не підходить: "Що саме не підходить — ціна, вік дитини, тип іграшки? Уточніть, і я знайду краще!"
- Якщо клієнт каже "не потрібно [X]" → шукай ІНШЕ, НЕ повертай [X]
- Якщо клієнту нічого не підходить → запропонуй іншу категорію або запитай бюджет/вік

FOLLOWUP;
    }

    /**
     * Size recommendation module - when user mentions height/weight (~250 tokens).
     */
    public function getSizeModule(): string
    {
        return <<<'SIZE'

📏 ПІДБІР РОЗМІРІВ:
Коли клієнт називає зріст/вагу → ОБОВ'ЯЗКОВО виклич recommend_size()!
Після recommend_size → ЗАВЖДИ search_products з цим розміром!

Орієнтири:
- 70-85 кг → M/L
- 85-95 кг → L/XL  
- 95-110 кг → XL (НІКОЛИ не M!)
- 110+ кг → XXL

ВАГА важливіша за зріст! 185см + 105кг = XL, не M!

SIZE;
    }

    /**
     * Comparison module - for "X vs Y" queries (~200 tokens).
     */
    public function getComparisonModule(): string
    {
        return <<<'COMPARE'

🔀 ПОРІВНЯННЯ ("X vs Y", "що краще"):
1. Зроби ДВА пошуки окремо
2. Відповідай ПОРІВНЯЛЬНИМ текстом:
   "[X]: особливість, ціна
    [Y]: особливість, ціна
    Висновок: X для..., Y для..."

COMPARE;
    }

    /**
     * Chitchat module - for greetings, thanks, goodbyes (~150 tokens).
     */
    public function getChitchatModule(): string
    {
        return <<<'CHITCHAT'

💬 CHITCHAT (НЕ шукай товари!):
- "дякую/thanks" → "Будь ласка! Якщо потрібна допомога — питай!"
- "привіт/hello" → "Привіт! Чим можу допомогти?"
- "до побачення" → "До зустрічі! Гарного дня!"

🔒 ДОВІРА ДО МАГАЗИНУ:
- "чи не кидаєте?", "можна довіряти?", "шахраї?" → 
  Відповідай з ІНФОРМАЦІЇ ПРО МАГАЗИН (з system prompt). Наголоси на:
  • Фізична адреса (якщо є)
  • Оплата при отриманні — клієнт платить тільки коли перевірить товар
  • Соцмережі та відгуки

CHITCHAT;
    }

    /**
     * FAQ module - for store info questions (~200 tokens).
     */
    public function getFaqModule(string $faqInfo, string $shopPhone): string
    {
        return <<<FAQ

❓ FAQ (НЕ шукай товари!):
- "як замовити?" → "Натисніть картку товару → сайт → кошик. Тел: {$shopPhone}"
- "доставка/оплата/повернення" → відповідай з інфо магазину:

{$faqInfo}

FAQ;
    }

    /**
     * Trigger module - for proactive messages (~200 tokens).
     */
    public function getTriggerModule(): string
    {
        return <<<'TRIGGER'

🚨 ТРИГЕРНИЙ ЗАПИТ (клієнт з "Допоможіть з товаром"):
1. ОДРАЗУ покажи детальну інфо через get_product_details
2. Дій впевнено, не питай "що потрібно"
3. CTA: "Який розмір? Зріст/вага?" або "Оформлюємо?"

TRIGGER;
    }

    /**
     * ECWCS/Protection level context (~150 tokens).
     */
    public function getLevelContextModule(): string
    {
        return <<<'LEVEL'

🔢 РОЗПІЗНАВАННЯ "LEVEL":
- "level 7", "левел 7" = ECWCS одяг (куртки/штани)
- "level iii", "iii++", "nij level" = бронеплити
- "level 5" = софтшел одяг
Неясно? Питай: "Шукаєте одяг ECWCS чи бронезахист?"

LEVEL;
    }

    /**
     * Build optimized prompt based on query analysis.
     *
     * @param  string  $query  User's message
     * @param  array  $context  Session context (has_history, is_trigger, etc.)
     * @param  array  $storeInfo  Store info (name, phone, faq)
     * @return string Compiled prompt
     */
    public function buildPrompt(string $query, array $context, array $storeInfo): string
    {
        $modules = [];

        // Core is ALWAYS included
        $modules[] = $this->getCoreModule(
            $storeInfo['phone'] ?? '',
            $storeInfo['name'] ?? 'Магазин'
        );

        $lowerQuery = mb_strtolower($query);

        // Detect query type and add relevant modules

        // Chitchat detection (greetings, thanks, trust questions - don't need search module)
        $isChitchat = preg_match('/^(привіт|hello|hi|дякую|спасибі|thanks|до побачення|bye|ок|добре)\b/ui', $lowerQuery);

        // Trust/scam questions (Ukrainian "counter-intelligence" queries 😄)
        $isTrustQuestion = preg_match('/\b(кидал|шахра|розвод|лохотрон|обман|скам|scam|довіря|надійн|не кида)/ui', $lowerQuery);

        if ($isChitchat || $isTrustQuestion) {
            $modules[] = $this->getChitchatModule();
        } else {
            // Product-related query - add search module
            $modules[] = $this->getSearchModule();
        }

        // FAQ detection
        $isFaq = preg_match('/\b(доставк|оплат|поверн|замовит|контакт|телефон|адрес)/ui', $lowerQuery);
        if ($isFaq) {
            $modules[] = $this->getFaqModule(
                $storeInfo['faq'] ?? '',
                $storeInfo['phone'] ?? ''
            );
        }

        // Comparison detection
        if (preg_match('/\b(vs|порівн|що краще|чи|або)\b/ui', $lowerQuery)) {
            $modules[] = $this->getComparisonModule();
        }

        // Size detection
        if (preg_match('/\b(розмір|size|зріст|вага|height|weight|\d{2,3}\s*(кг|kg|см|cm))/ui', $lowerQuery)) {
            $modules[] = $this->getSizeModule();
        }

        // Level detection (ECWCS vs armor)
        if (preg_match('/\b(level|рівень|левел)\b/ui', $lowerQuery)) {
            $modules[] = $this->getLevelContextModule();
        }

        // Follow-up detection (has history)
        if (! empty($context['has_history'])) {
            $modules[] = $this->getFollowUpModule();
        }

        // Trigger query detection
        if (! empty($context['is_trigger'])) {
            $modules[] = $this->getTriggerModule();
        }

        // Add tone section if provided
        if (! empty($storeInfo['tone_section'])) {
            $modules[] = "\n".$storeInfo['tone_section'];
        }

        $prompt = implode("\n", $modules);

        // Log prompt size for monitoring
        $tokenEstimate = intval(strlen($prompt) / 2.5);
        Log::debug('PromptModules: built prompt', [
            'modules_count' => count($modules),
            'chars' => strlen($prompt),
            'estimated_tokens' => $tokenEstimate,
            'query_type' => $isChitchat ? 'chitchat' : ($isFaq ? 'faq' : 'product'),
        ]);

        return $prompt;
    }

    /**
     * Get cached prompt for session (to avoid rebuilding on each message).
     * Cache key includes tenant + session for isolation.
     */
    public function getCachedPrompt(string $sessionId, int $tenantId, callable $builder): string
    {
        $cacheKey = "prompt:v2:{$tenantId}:{$sessionId}";

        return Cache::remember($cacheKey, 1800, $builder); // 30 min cache
    }

    /**
     * Estimate token count for a prompt.
     * Ukrainian text: ~1 token per 2-3 chars
     * English text: ~1 token per 4 chars
     */
    public function estimateTokens(string $text): int
    {
        // Mixed content - use average of 2.5 chars per token
        return intval(strlen($text) / 2.5);
    }
}
