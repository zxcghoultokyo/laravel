<?php

namespace App\Services\Search;

use App\Services\FaqService;
use Illuminate\Support\Facades\Log;

/**
 * Query Preprocessor Service
 *
 * Processes user queries BEFORE sending to GPT:
 * 1. FAQ detection - returns FAQ answer without GPT call
 * 2. Brand normalization - "опс кор" → "Ops-Core шолом"
 * 3. Multi-word slang expansion - "тк кат" → "турнікет CAT"
 * 4. Greeting/thanks detection - "дякую" → polite farewell
 *
 * This prevents GPT misunderstanding slang and making unnecessary calls.
 */
class QueryPreprocessorService
{
    protected BrandDetectionService $brandDetection;

    protected SlangDictionaryService $slangDictionary;

    protected FaqService $faqService;

    /**
     * Multi-word slang phrases that need special handling.
     * Maps phrase → [canonical term, product hint for GPT]
     */
    protected const MULTI_WORD_SLANG = [
        // Tourniquets
        'тк кат' => ['турнікет CAT', 'tourniquet'],
        'tk cat' => ['турнікет CAT', 'tourniquet'],
        'тк cat' => ['турнікет CAT', 'tourniquet'],
        'ткат' => ['турнікет CAT', 'tourniquet'],
        'кат турнікет' => ['турнікет CAT', 'tourniquet'],
        'cat турнікет' => ['турнікет CAT', 'tourniquet'],
        'соф т' => ['турнікет SOF-T', 'tourniquet'],
        'sof t' => ['турнікет SOF-T', 'tourniquet'],

        // Helmets with brand
        'опс кор' => ['шолом Ops-Core', 'helmet'],
        'ops core' => ['шолом Ops-Core', 'helmet'],
        'опс-кор' => ['шолом Ops-Core', 'helmet'],
        'опскор' => ['шолом Ops-Core', 'helmet'],
        'сестан буш' => ['шолом SESTAN BUSCH', 'helmet'],
        'sestan busch' => ['шолом SESTAN BUSCH', 'helmet'],

        // Plate carriers
        'пц' => ['плитоноска', 'plate_carrier'],
        'пє' => ['плитоноска', 'plate_carrier'],
        'плитонос' => ['плитоноска', 'plate_carrier'],

        // Headsets
        'пелтор комтак' => ['навушники Peltor ComTac', 'headset'],
        'peltor comtac' => ['навушники Peltor ComTac', 'headset'],
        'комтаки' => ['навушники Peltor ComTac', 'headset'],

        // Medical
        'ізраїль бандаж' => ['ізраїльський бандаж', 'bandage'],
        'ізраїльський' => ['ізраїльський бандаж', 'bandage'],
        'окклюзійка' => ['оклюзійний пластир', 'chest_seal'],

        // Clothing
        'термуха' => ['термобілизна', 'thermal_underwear'],
        'терма' => ['термобілизна', 'thermal_underwear'],
    ];

    /**
     * Greeting/farewell phrases that should get polite response without search.
     */
    protected const GREETING_PHRASES = [
        // Thanks
        'дякую' => 'Будь ласка! Якщо виникнуть питання — звертайтесь! 🙂',
        'дякуємо' => 'Будь ласка! Раді допомогти! 🙂',
        'спасибі' => 'Будь ласка! Звертайтесь, якщо щось потрібно! 🙂',
        'спасибо' => 'Будь ласка! Якщо є питання — пишіть! 🙂',
        'thx' => 'You\'re welcome! Let me know if you need anything else! 🙂',
        'thanks' => 'You\'re welcome! Feel free to ask if you have more questions! 🙂',
        'thank you' => 'You\'re welcome! Happy to help! 🙂',

        // Greetings
        'привіт' => 'Привіт! 👋 Чим можу допомогти? Шукаєте щось конкретне?',
        'вітаю' => 'Вітаю! 👋 Що шукаємо сьогодні?',
        'добрий день' => 'Добрий день! 👋 Чим можу допомогти?',
        'доброго дня' => 'Доброго дня! 👋 Що вас цікавить?',
        'добрий вечір' => 'Добрий вечір! 👋 Чим можу допомогти?',
        'hello' => 'Hello! 👋 What can I help you find today?',
        'hi' => 'Hi! 👋 Looking for something specific?',
        'hey' => 'Hey! 👋 How can I help?',

        // Farewells
        'до побачення' => 'До побачення! Гарного дня! 🙂',
        'бувай' => 'Бувай! Гарного дня! 🙂',
        'бувайте' => 'Бувайте! Вдалих покупок! 🙂',
        'па-па' => 'Па-па! 👋',
        'пока' => 'Бувай! 👋',
        'bye' => 'Bye! Have a great day! 🙂',
        'goodbye' => 'Goodbye! Take care! 🙂',

        // Confirmations (just acknowledge, don't search)
        'ок' => 'Добре! Якщо щось ще потрібно — пишіть! 🙂',
        'окей' => 'Окей! Звертайтесь, якщо є питання! 🙂',
        'зрозуміло' => 'Чудово! Якщо є ще питання — з радістю допоможу! 🙂',
        'ясно' => 'Добре! Якщо щось потрібно — я тут! 🙂',
        'добре' => 'Чудово! Якщо знадобиться допомога — пишіть! 🙂',
        'гаразд' => 'Гаразд! Звертайтесь! 🙂',
    ];

    /**
     * Comparison question patterns - answer with explanation, not products.
     */
    protected const COMPARISON_PATTERNS = [
        'велкро чи молле' => 'Velcro (липучка) — швидке кріплення/зняття підсумків, легко переставляти. MOLLE (стропи) — надійніше тримає навантаження, але повільніше переставляти. Для легкого спорядження краще Velcro, для важкого бойового — MOLLE. Що вас більше цікавить?',
        'molle чи velcro' => 'MOLLE — надійні стропи для важкого навантаження. Velcro — швидка липучка для легкого спорядження. Для бойових завдань краще MOLLE, для тренувань/швидкого доступу — Velcro. Підібрати варіанти?',
        'молле чи велкро' => 'Молле — стропова система, надійно тримає вагу. Велкро — на липучці, швидко знімати. Молле для бою, Велкро для швидкості. Що шукаєте?',
        'що краще' => null, // Generic - let GPT handle with context
        'яка різниця' => null,
        'чим відрізняється' => null,
    ];

    public function __construct()
    {
        $this->brandDetection = app(BrandDetectionService::class);
        $this->slangDictionary = app(SlangDictionaryService::class);
        $this->faqService = app(FaqService::class);
    }

    /**
     * Preprocess query before GPT.
     *
     * @param  string  $query  User's original query
     * @return array {
     *               'query': string,           // Normalized query for GPT
     *               'intercepted': bool,       // True if we have direct answer (skip GPT)
     *               'response': ?string,       // Direct response if intercepted
     *               'response_type': ?string,  // 'faq', 'greeting', 'comparison'
     *               'detected_slang': ?array,  // Info about detected slang
     *               'detected_brand': ?string, // Detected brand
     *               }
     */
    public function preprocess(string $query): array
    {
        $original = $query;
        $queryLower = mb_strtolower(trim($query));

        $result = [
            'query' => $query,
            'original' => $original,
            'intercepted' => false,
            'response' => null,
            'response_type' => null,
            'detected_slang' => null,
            'detected_brand' => null,
        ];

        // 1. Check for greeting/farewell (exact or starts with)
        $greetingResponse = $this->checkGreeting($queryLower);
        if ($greetingResponse) {
            Log::info('QueryPreprocessor: greeting intercepted', [
                'query' => $query,
                'response' => $greetingResponse,
            ]);

            return array_merge($result, [
                'intercepted' => true,
                'response' => $greetingResponse,
                'response_type' => 'greeting',
            ]);
        }

        // 1.5. Check for "call manager" / "connect me" patterns — intercept BEFORE GPT
        $managerResponse = $this->checkManagerRequest($queryLower);
        if ($managerResponse) {
            Log::info('QueryPreprocessor: manager request intercepted', [
                'query' => $query,
            ]);

            return array_merge($result, [
                'intercepted' => true,
                'response' => $managerResponse,
                'response_type' => 'manager_request',
            ]);
        }

        // 2. Check for FAQ match
        $faqResult = $this->faqService->match($queryLower);
        if ($faqResult) {
            Log::info('QueryPreprocessor: FAQ intercepted', [
                'query' => $query,
                'question' => $faqResult['question_stub'] ?? null,
            ]);

            return array_merge($result, [
                'intercepted' => true,
                'response' => $faqResult['answer'],
                'response_type' => 'faq',
            ]);
        }

        // 3. Check for comparison questions with direct answer
        $comparisonResponse = $this->checkComparison($queryLower);
        if ($comparisonResponse) {
            Log::info('QueryPreprocessor: comparison intercepted', [
                'query' => $query,
                'response' => $comparisonResponse,
            ]);

            return array_merge($result, [
                'intercepted' => true,
                'response' => $comparisonResponse,
                'response_type' => 'comparison',
            ]);
        }

        // 4. Expand multi-word slang (before brand detection)
        $expandedQuery = $this->expandMultiWordSlang($query);
        if ($expandedQuery !== $query) {
            $result['detected_slang'] = [
                'original' => $query,
                'expanded' => $expandedQuery,
            ];
            $query = $expandedQuery;
        }

        // 5. Normalize brands in query
        $normalizedQuery = $this->brandDetection->normalizeBrandsInQuery($query);
        if ($normalizedQuery !== $query) {
            $result['detected_brand'] = $this->brandDetection->detectBrand($normalizedQuery)['brand'] ?? null;
            $query = $normalizedQuery;
        }

        $result['query'] = $query;

        if ($query !== $original) {
            Log::info('QueryPreprocessor: query normalized', [
                'original' => $original,
                'normalized' => $query,
                'slang' => $result['detected_slang'],
                'brand' => $result['detected_brand'],
            ]);
        }

        return $result;
    }

    /**
     * Check if query is a greeting/farewell.
     * Returns null if query has continuation that looks like a product request.
     */
    protected function checkGreeting(string $queryLower): ?string
    {
        // Keywords that indicate user wants something MORE than just greeting
        $continuationKeywords = [
            'покажи', 'шукаю', 'потрібно', 'потрібен', 'потрібна', 'хочу',
            'дешевше', 'дорожче', 'ще', 'інші', 'інше', 'аналог',
            'доставк', 'оплат', 'замов', 'розмір', 'колір', 'ціна',
            'є ', 'маєте', 'можна', 'як ', 'що ', 'скільки', 'коли',
            'чекаю', 'чекати', 'очікую',
        ];

        // Check if query has continuation keywords - if so, don't intercept
        foreach ($continuationKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                return null; // Has continuation - let GPT handle it
            }
        }

        // Exact match (no continuation)
        if (isset(self::GREETING_PHRASES[$queryLower])) {
            return self::GREETING_PHRASES[$queryLower];
        }

        // Check if it's a short greeting with punctuation/emoji only
        // "дякую!", "дякую ))", "привіт!" but NOT "дякую за футболку"
        foreach (self::GREETING_PHRASES as $phrase => $response) {
            // Exact match with punctuation/emoji
            $pattern = '/^'.preg_quote($phrase, '/').'[!?\.\)\s😊🙂👋]*$/u';
            if (preg_match($pattern, $queryLower)) {
                return $response;
            }
        }

        // Short message that's likely just a confirmation/thanks (max 12 chars, no spaces after core)
        if (mb_strlen($queryLower) <= 12) {
            foreach (['дяк', 'спс', 'thx', 'ty'] as $shortForm) {
                if (str_contains($queryLower, $shortForm)) {
                    return 'Будь ласка! Звертайтесь, якщо щось ще потрібно! 🙂';
                }
            }
        }

        return null;
    }

    /**
     * Check if user is asking for a manager/human operator.
     * Intercepts before GPT to prevent hallucinated contact info.
     */
    protected function checkManagerRequest(string $queryLower): ?string
    {
        $managerPatterns = [
            '/\b(поклич|покличте|визов|викличте?|з.?єднай)\b.*менеджер/ui',
            '/\bменеджер\b/ui',
            '/\b(хочу|можна|дайте)\b.*(менеджер|оператор|людин|живу людину)/ui',
            '/\bз.?єднай(те)?\b.*(менеджер|оператор|людин)/ui',
            '/\b(живий|живу|реальн)\b.*(оператор|людин|консультант)/ui',
            '/\b(оператор|консультант)\b/ui',
            '/\bcall\s*(manager|operator|human)\b/ui',
            '/\bspeak\s*(to|with)\s*(a\s*)?(manager|human|person)\b/ui',
        ];

        foreach ($managerPatterns as $pattern) {
            if (preg_match($pattern, $queryLower)) {
                return 'Я — AI-консультант і не можу з\'єднати з менеджером напряму. Але з радістю допоможу підібрати товар! А якщо потрібен живий менеджер — зверніться через сайт магазину. Чим можу допомогти? 🙂';
            }
        }

        return null;
    }

    /**
     * Check if query is a comparison question with known answer.
     */
    protected function checkComparison(string $queryLower): ?string
    {
        foreach (self::COMPARISON_PATTERNS as $pattern => $response) {
            if ($response !== null && str_contains($queryLower, $pattern)) {
                return $response;
            }
        }

        return null;
    }

    /**
     * Expand multi-word slang phrases to canonical form.
     */
    protected function expandMultiWordSlang(string $query): string
    {
        $queryLower = mb_strtolower($query);

        // Sort by length descending to match longer phrases first
        $sorted = self::MULTI_WORD_SLANG;
        uksort($sorted, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($sorted as $slang => [$canonical, $type]) {
            if (str_contains($queryLower, $slang)) {
                // Replace slang with canonical, preserving other parts of query
                $pattern = '/'.preg_quote($slang, '/').'/iu';
                $query = preg_replace($pattern, $canonical, $query);
                $queryLower = mb_strtolower($query);

                Log::debug('QueryPreprocessor: slang expanded', [
                    'slang' => $slang,
                    'canonical' => $canonical,
                    'type' => $type,
                ]);
            }
        }

        return $query;
    }

    /**
     * Get product type hint for detected slang.
     */
    public function getProductTypeHint(string $query): ?string
    {
        $queryLower = mb_strtolower($query);

        foreach (self::MULTI_WORD_SLANG as $slang => [$canonical, $type]) {
            if (str_contains($queryLower, $slang)) {
                return $type;
            }
        }

        return null;
    }
}
