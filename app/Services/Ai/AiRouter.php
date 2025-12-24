<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;
use App\Models\Brand;

class AiRouter
{
    protected string $model;
    protected string $baseUrl;
    protected ?string $apiKey;

    public function __construct()
    {
        $config        = config('services.openai', []);
        $this->model   = $config['model'] ?? 'gpt-5.1';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->apiKey  = $config['key'] ?? null;
    }

    /**
     * Основна маршрутизація намірів
     */
    public function classify(string $message): array
    {
        $fallback = [
            'intent'           => 'PRODUCT_SEARCH',
            'normalized_query' => $message,
            'order_id'         => null,
        ];

        if (empty($this->apiKey)) {
            Log::warning('AiRouter::classify called without OPENAI_API_KEY');
            return $fallback;
        }

        $prompt = "
Ти — AI-асистент магазину Contractor (тактичне військове спорядження).

Контекст магазину:
- Продаємо: бронежилети, шоломи, плитоноски, бронеплити, тактичний одяг, взуття, рюкзаки, підсумки, рукавиці, окуляри
- Клієнти: військові ЗСУ, правоохоронці, добровольці, цивільні патріоти
- Особливість: професійне екіпірування для екстремальних умов

Визнач намір користувача:
- PRODUCT_SEARCH: пошук спорядження (плитоноска, броня, тактична куртка, берці)
- ORDER_STATUS: питання про замовлення (статус, трекінг)
- FAQ: доставка, оплата, повернення, контакти
- SMALL_TALK: вітання, подяки
- FALLBACK: незрозумілий запит

Поверни JSON:
{
  \"intent\": \"PRODUCT_SEARCH | ORDER_STATUS | FAQ | SMALL_TALK | FALLBACK\",
  \"normalized_query\": \"ключові слова для пошуку\",
  \"order_id\": null або номер
}

Запит: \"{$message}\"
";

        try {
            $response = Http::withToken($this->apiKey)
                ->post($this->baseUrl . '/chat/completions', [
                    'model'       => $this->model,
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                ]);

            $data = $response->json();

            if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
                Log::error('AiRouter::classify invalid OpenAI response', ['data' => $data]);
                return $fallback;
            }

            $content = $data['choices'][0]['message']['content'] ?? null;
            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                Log::error('AiRouter::classify JSON decode failed', ['content' => $content]);
                return $fallback;
            }

            return array_merge($fallback, $decoded);
        } catch (\Throwable $e) {
            Log::error('AiRouter::classify exception: ' . $e->getMessage(), ['exception' => $e]);
            return $fallback;
        }
    }

    /**
    * AI-нормалізація тексту для пошуку товарів.
     * Використовує AI для витягування суті запиту без хардкодених правил.
     */
    public function normalizeSearchQuery(string $message): string
    {
        $fallback = $message;

        if (empty($this->apiKey)) {
            Log::warning('AiRouter::normalizeSearchQuery called without OPENAI_API_KEY, using fallback');
            return $this->extractKeywordsFromMessage($message);
        }

        $prompt = "
Ти — асистент пошуку для магазину військового спорядження.

Завдання: витягни з запиту користувача ТІЛЬКИ ключові пошукові терміни для Meilisearch.

Правила:
- Прибери службові фрази (привіт, допоможи, розкажи, покажи, про)
- Залиш ВСІ інформативні слова: назви товарів, моделі, цифри, кольори, бренди
- НЕ додавай нічого від себе, НЕ розширюй синонімами
- Повертай ТІЛЬКИ очищений запит, без лапок, без пояснень

Приклади:
'Привіт, допоможи підібрати плитоноску в пікселі' → 'плитоноску пікселі'
'розкажи про плитоноску схід 24' → 'плитоноску схід 24'
'покажи мультикам берці 43 розмір' → 'мультикам берці 43'
'шолом ballistic crye' → 'шолом ballistic crye'

Запит: \"{$message}\"
";

        try {
            $response = Http::timeout(3)->withToken($this->apiKey)
                ->post($this->baseUrl . '/chat/completions', [
                    'model'       => $this->model,
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 50,
                ]);

            $data = $response->json();

            if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
                Log::warning('AiRouter::normalizeSearchQuery invalid response, using fallback');
                return $this->extractKeywordsFromMessage($message);
            }

            $cleaned = trim((string) $data['choices'][0]['message']['content']);
            
            // Sanity check: якщо AI повернув порожнє або дуже довге — fallback
            if (empty($cleaned) || mb_strlen($cleaned) > mb_strlen($message) * 1.5) {
                Log::warning('AiRouter::normalizeSearchQuery suspicious output, using fallback', ['cleaned' => $cleaned]);
                return $this->extractKeywordsFromMessage($message);
            }

            Log::info('AiRouter::normalizeSearchQuery AI normalized', ['original' => $message, 'cleaned' => $cleaned]);
            return $cleaned;
            
        } catch (\Throwable $e) {
            Log::warning('AiRouter::normalizeSearchQuery exception: ' . $e->getMessage());
            return $this->extractKeywordsFromMessage($message);
        }
    }

    /**
     * Fallback витягування ключових слів (коли AI недоступний).
     * Прибирає тільки базові стоп-слова, зберігає все інше.
     */
    protected function extractKeywordsFromMessage(string $message): string
    {
        $lowerMsg = mb_strtolower($message);
        
        // Мінімальний список стоп-слів для fallback (коли AI не працює)
        $stopWords = [
            'привіт', 'привет', 'hi', 'hello',
            'допоможи', 'помоги',
            'підібрати', 'подобрать',
            'показати', 'показать',
            'розкажи', 'скажи',
            'про', 'about',
        ];
        
        // Розбиваємо на слова та фільтруємо
        $words = preg_split('/\s+/u', trim($lowerMsg));
        $filtered = [];
        
        foreach ($words as $word) {
            if (empty(trim($word))) {
                continue;
            }
            
            $cleaned = preg_replace('/[^\p{L}\p{N}\-]/u', '', $word);
            if (empty($cleaned)) {
                continue;
            }
            
            // Пропускаємо базові стоп-слова
            if (in_array($cleaned, $stopWords)) {
                continue;
            }
            
            // Залишаємо токени: містять цифри АБО довжина >= 2
            if (preg_match('/\d/', $cleaned) || mb_strlen($cleaned) >= 2) {
                $filtered[] = $cleaned;
            }
        }
        
        return implode(' ', $filtered);
    }



    /**
     * Detect if the message contains any known brand word.
     */
    protected function containsBrandWord(string $message): bool
    {
        $msg = mb_strtolower($message);
        $brands = $this->getBrandNames();

        foreach ($brands as $brand) {
            $b = mb_strtolower($brand);
            // Match as a word boundary or substring for short brands
            if (preg_match('/\b' . preg_quote($b, '/') . '\b/u', $msg) || str_contains($msg, $b)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cached list of brand names from DB (fallback to common known brands).
     */
    protected function getBrandNames(): array
    {
        return Cache::remember('ai_router_brand_names', now()->addHours(6), function () {
            try {
                $names = Brand::query()->pluck('name')->filter()->values()->all();
                if (!empty($names)) {
                    return $names;
                }
            } catch (\Throwable $e) {
                Log::warning('AiRouter::getBrandNames failed to load from DB', ['error' => $e->getMessage()]);
            }
            // Fallback list of common brands seen in the catalog
            return ['hoffmann', 'атака', 'ataka', 'mil-tec', 'miltec', 'avenger', 'condor', '5.11', '511'];
        });
    }

    public function buildProductIndexData(Product $product): array
    {
        // Ця функція має бути без “ніші”.
        // Якщо хочеш — наступним кроком зробимо нормальну класифікацію через CategoryAlias/ProductSynonym таблиці.
        return [
            'product_type' => null,
            'ai_category'  => null,
            'materials'    => null,
            'standards'    => null,
            'slang'        => null,
            'keywords'     => null,
            'usage'        => null,
            'embedding'    => null,
        ];
    }

    /**
     * Розбір запиту користувача в структурований intent для пошуку товарів.
     * Повертає:
     *  - product_types        => []
     *  - must_have_keywords   => []
     *  - fallback_types       => []
     */
    public function parseProductSearchIntent(string $message): array
    {
        if (empty($this->apiKey)) {
            Log::warning('AiRouter::parseProductSearchIntent called without OPENAI_API_KEY');
            return [];
        }

        $systemPrompt = <<<PROMPT
Ти — AI-модуль, який розбирає пошукові запити інтернет-магазину.
Поверни ЧИСТИЙ JSON:

- "product_types": масив типів товарів так, як це звучить у запиті (коротко).
- "must_have_keywords": важливі слова/скорочення/ознаки, які користувач прямо вимагає.
- "fallback_types": ширші категорії, якщо точного типу нема.

Правила:
- Не вигадуй нових фактів/абревіатур.
- Якщо не впевнений — залиш порожній масив.
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $message],
                ],
                'temperature' => 0.1,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name'   => 'product_search_intent',
                        'strict' => true,
                        'schema' => [
                            'type'       => 'object',
                            'properties' => [
                                'product_types' => [
                                    'type'  => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'must_have_keywords' => [
                                    'type'  => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'fallback_types' => [
                                    'type'  => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                            'required' => ['product_types', 'must_have_keywords', 'fallback_types'],
                        ],
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('AiRouter::parseProductSearchIntent HTTP error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return [];
            }

            $content = $response->json('choices.0.message.content');
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                Log::warning('AiRouter::parseProductSearchIntent got non-JSON content', [
                    'content' => $content,
                ]);
                return [];
            }

            return [
                'product_types'      => $decoded['product_types']      ?? [],
                'must_have_keywords' => $decoded['must_have_keywords'] ?? [],
                'fallback_types'     => $decoded['fallback_types']     ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('AiRouter::parseProductSearchIntent exception: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return [];
        }
    }

    protected function getSessionHistory(?string $sessionId): array
    {
        if (! $sessionId) {
            return [];
        }

        $key = "chat_history:{$sessionId}";
        $history = Cache::get($key, []);

        return is_array($history) ? $history : [];
    }

    protected function pushSessionExchange(?string $sessionId, string $userMessage, string $assistantMessage): void
    {
        if (! $sessionId) {
            return;
        }

        $key = "chat_history:{$sessionId}";
        $history = $this->getSessionHistory($sessionId);

        $history[] = ['role' => 'user', 'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $assistantMessage];

        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        Cache::put($key, $history, now()->addHours(6));
    }

    public function routeChatMessage(string $message, array $context = []): array
    {
        $fallback = [
            'intent'       => 'unknown',
            'action'       => 'ASK_CLARIFICATION',
            'confidence'   => 0.0,
            'category_key' => null,
            'message'      => "Я трохи не зрозумів запит. Спробуй сформулювати ще раз 🙂",
            'slots'        => [
                'budget_min'   => null,
                'budget_max'   => null,
                'order_number' => null,
            ],
        ];

        if (empty($this->apiKey)) {
            Log::warning('AiRouter::routeChatMessage called without OPENAI_API_KEY');
            return $fallback;
        }

        $sessionId = $context['session_id'] ?? null;
        $history   = $this->getSessionHistory($sessionId);

        $systemPrompt = <<<PROMPT
Ти — AI-оркестратор для e-commerce чату.

Ти маєш повернути ЧИСТИЙ JSON:
{
  "intent": "product_search" | "order_status" | "shop_info" | "smalltalk" | "abuse" | "unknown",
  "action": "SHOW_PRODUCTS" | "ASK_CLARIFICATION" | "NONE",
  "confidence": 0.0-1.0,
  "category_key": string|null,
  "message": "готовий текст відповіді користувачу",
  "slots": {
    "budget_min": float|null,
    "budget_max": float|null,
    "order_number": string|null
  }
}

Правила:
- Якщо це пошук товару — intent=product_search.
- Якщо статус замовлення/доставка — intent=order_status.
- Якщо доставка/оплата/повернення/умови — intent=shop_info.
- Якщо привітання/подяка — intent=smalltalk.
- Якщо токсично — intent=abuse, але відповідь ввічлива.

category_key поки може бути null (ми не прив’язуємось до ніші).
PROMPT;

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            foreach ($history as $item) {
                if (!isset($item['role'], $item['content'])) continue;
                $messages[] = ['role' => $item['role'], 'content' => $item['content']];
            }

            $messages[] = ['role' => 'user', 'content' => $message];

            $response = Http::withToken($this->apiKey)
                ->post($this->baseUrl . '/chat/completions', [
                    'model'    => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name'   => 'chat_routing',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'intent' => ['type' => 'string'],
                                    'action' => ['type' => 'string'],
                                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                                    'category_key' => ['type' => ['string', 'null']],
                                    'message' => ['type' => 'string'],
                                    'slots' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'properties' => [
                                            'budget_min' => ['type' => ['number', 'null']],
                                            'budget_max' => ['type' => ['number', 'null']],
                                            'order_number' => ['type' => ['string', 'null']],
                                        ],
                                        'required' => ['budget_min', 'budget_max', 'order_number'],
                                    ],
                                ],
                                'required' => ['intent', 'action', 'confidence', 'category_key', 'message', 'slots'],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('AiRouter::routeChatMessage HTTP error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return $fallback;
            }

            $content = $response->json('choices.0.message.content');
            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                Log::warning('AiRouter::routeChatMessage got non-JSON content', [
                    'content' => $content,
                ]);
                return $fallback;
            }

            $merged = array_merge($fallback, $decoded);

            $this->pushSessionExchange(
                $sessionId,
                $message,
                $merged['message'] ?? $fallback['message']
            );

            return $merged;
        } catch (\Throwable $e) {
            Log::error('AiRouter::routeChatMessage exception: ' . $e->getMessage(), ['exception' => $e]);
            return $fallback;
        }
    }
        /**
     * Реранк кандидатів з Meili з урахуванням сесійної історії.
     * Повертає:
     *  - chosen_ids: [int]   // які показати (у правильному порядку)
     *  - refined_query: ?string // якщо треба зробити повторний пошук
     *  - reason_short: string   // коротко для логів
     */
    public function rerankProductCandidates(
        string $userQuery,
        array $candidates,
        ?string $sessionId = null,
        int $limit = 10
    ): array {
        if (empty($this->apiKey)) {
            Log::warning('AiRouter::rerankProductCandidates called without OPENAI_API_KEY');
            return [
                'chosen_ids' => array_slice(array_map(fn($x) => (int)($x['id'] ?? 0), $candidates), 0, $limit),
                'refined_query' => null,
                'reason_short' => 'no_api_key_fallback',
            ];
        }

        $history = $this->getSessionHistory($sessionId);
        $historyText = '';
        if (!empty($history)) {
            // беремо останні 10 елементів (5 реплік)
            $tail = array_slice($history, -10);
            foreach ($tail as $h) {
                $role = $h['role'] ?? '';
                $content = $h['content'] ?? '';
                if ($role && $content) {
                    $historyText .= strtoupper($role) . ": " . $content . "\n";
                }
            }
        }

        // обрізаємо кандидатів до розумної кількості, щоб не роздувати промпт
        $candidates = array_slice($candidates, 0, 60);

        $systemPrompt = <<<PROMPT
Ти — модуль rerank для e-commerce пошуку.
Ти отримуєш:
- запит користувача
- коротку історію чату (контекст)
- список кандидатів (результати Meili)

Твоя задача:
1) Вибрати найрелевантніші товари під поточний запит + контекст історії.
2) Прибрати дублікати (однаковий товар у різних варіантах) — залиш 1 кращий.
3) Якщо кандидати нерелевантні (наприклад, це аксесуари замість основного товару) — НЕ вибирай їх.
4) Якщо після відбору виходить менше 3 адекватних товарів — запропонуй refined_query (1 коротка фраза) щоб повторити пошук ширше/точніше.
   refined_query має бути нейтральний і універсальний (без хардкоду під нішу).

Поверни ЧИСТИЙ JSON.
PROMPT;

        $userPayload = [
            'query' => $userQuery,
            'limit' => $limit,
            'chat_history' => $historyText,
            'candidates' => array_map(function ($c) {
                return [
                    'id' => (int)($c['id'] ?? 0),
                    'title' => (string)($c['title'] ?? ''),
                    'category_path' => (string)($c['category_path'] ?? ''),
                    'price' => (float)($c['price'] ?? 0),
                    'in_stock' => (int)($c['in_stock'] ?? 0),
                    'display_in_showcase' => (int)($c['display_in_showcase'] ?? 0),
                    'product_type' => (string)($c['product_type'] ?? ''),
                    'ai_product_type' => (string)($c['ai_product_type'] ?? ''),
                ];
            }, $candidates),
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
                ],
                'temperature' => 0.2,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'rerank_products',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'chosen_ids' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'integer'],
                                ],
                                'refined_query' => [
                                    'type' => ['string', 'null'],
                                ],
                                'reason_short' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => ['chosen_ids', 'refined_query', 'reason_short'],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::warning('AiRouter::rerankProductCandidates HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'chosen_ids' => array_slice(array_map(fn($x) => (int)($x['id'] ?? 0), $candidates), 0, $limit),
                    'refined_query' => null,
                    'reason_short' => 'http_error_fallback',
                ];
            }

            $content = $response->json('choices.0.message.content');
            $decoded = json_decode($content, true);

            if (!is_array($decoded)) {
                Log::warning('AiRouter::rerankProductCandidates non-JSON content', ['content' => $content]);
                return [
                    'chosen_ids' => array_slice(array_map(fn($x) => (int)($x['id'] ?? 0), $candidates), 0, $limit),
                    'refined_query' => null,
                    'reason_short' => 'json_decode_fallback',
                ];
            }

            // safety: чистимо chosen_ids
            $ids = array_values(array_filter($decoded['chosen_ids'] ?? [], fn($v) => is_int($v) || ctype_digit((string)$v)));
            $ids = array_map('intval', $ids);
            $ids = array_slice($ids, 0, $limit);

            return [
                'chosen_ids' => $ids,
                'refined_query' => $decoded['refined_query'] ?? null,
                'reason_short' => (string)($decoded['reason_short'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::error('AiRouter::rerankProductCandidates ex
ception: ' . $e->getMessage(), ['exception' => $e]);
            return [
                'chosen_ids' => array_slice(array_map(fn($x) => (int)($x['id'] ?? 0), $candidates), 0, $limit),
                'refined_query' => null,
                'reason_short' => 'exception_fallback',
            ];
        }
    }

    /**
     * Оцінка релевантності товарів по контексту запиту.
     * 
     * Отримує масив товарів (50+), контекст сесії, запит — AI вирішує які найрелевантніші.
     * Повертає топ-10 з оцінками релевантності (0.0-1.0).
     */
    public function rankProductsByRelevance(
        array $products,
        string $originalQuery,
        ?string $categoryKey,
        array $sessionContext = [],
        array $negativeTerms = []
    ): array {
        if (empty($this->apiKey)) {
            Log::warning('AiRouter::rankProductsByRelevance called without OPENAI_API_KEY');
            return [];
        }

        if (empty($products)) {
            return [];
        }

        // Беремо максимум 50 товарів для оцінки (щоб не перевантажити токени)
        $toRank = array_slice($products, 0, 50);

        // Будуємо опис товарів для AI
        $productsText = '';
        foreach ($toRank as $idx => $p) {
            $title = $p['title'] ?? 'N/A';
            $category = $p['category_path'] ?? '';
            $price = $p['price'] ?? 'N/A';
            $description = $p['description'] ?? '';
            
            $productsText .= "[$idx] Title: {$title} | Category: {$category} | Price: {$price} | Desc: {$description}\n";
        }

        // Контекст сесії для AI
        $lastCategory = $sessionContext['last_category_key'] ?? null;
        $lastIntent = $sessionContext['last_intent'] ?? null;
        
        // Будуємо список негативів
        $negativesText = '';
        if (!empty($negativeTerms)) {
            $negativesText = "Товари, що містять ці слова у назві/категорії — НЕрелевантні:\n" .
                implode(', ', array_map('mb_strtolower', $negativeTerms)) . "\n\n";
        }

        $systemPrompt = <<<PROMPT
Ти — експерт по тактичному спорядженню.
Твоє завдання — оцінити релевантність кожного товару до запиту користувача.

ВАЖЛИВО - Типи товарів по категоріям:
- helmets: основне — сам шолом/каска (балистичні/тактичні). Аксесуари — кріплення, рейки, адаптери для GoPro/навушників, мати — relevance 0.1
- plate_carriers: основне — плитоноска/розгрузка з плитами. Аксесуари — панелі, підсумки, чохли, камербанд, клапани — relevance 0.1
- plates: основне — саме бронеплити. Аксесуари — кейси, сумки для плит — relevance 0.1
- ifak_kits: основне — аптечка/медичний набір. Аксесуари — окремі перев'язки, кровоспинні засоби — relevance 0.2
- tourniquets: основне — турнікет. Аксесуари — кейси, тренувальні манекени — relevance 0.2

{$negativesText}Поверни JSON масив об'єктів:
[
  {"index": 0, "relevance": 0.95, "reason": "коротко чому релевантний"},
  {"index": 5, "relevance": 0.80, "reason": "..."},
  ...
]

Сортуй по релевантності (спадаючий порядок).
Включай тільки товари з relevance >= 0.5.
Максимум 10 товарів.

Правила оцінки:
- Основні товари (не аксесуари) — мінімум 0.6 relevance
- Якщо юзер просить конкретний розмір/клас/колір — враховуй це при оцінці
- Якщо є контекст сесії (юзер просив дещо раніше) — вважай це подовженням запиту, не новим пошуком
- Популярні базові варіанти (без специфічних фільтрів) — вищі оцінки за спеціалізовані

Поверни ТІЛЬКИ JSON, без пояснень.
PROMPT;

        try {
            $userPrompt = <<<PROMPT
Запит: "{$originalQuery}"
Категорія: {$categoryKey}
Контекст: {$lastIntent} (категорія раніше: {$lastCategory})

Товари для оцінки:
{$productsText}
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'temperature' => 0.3,
            ]);

            if (! $response->successful()) {
                Log::warning('AiRouter::rankProductsByRelevance HTTP error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return [];
            }

            $content = $response->json('choices.0.message.content');
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                Log::warning('AiRouter::rankProductsByRelevance got non-JSON content', [
                    'content' => $content,
                ]);
                return [];
            }

            // Побудуємо масив з індексами товарів в оригінальному порядку релевантності
            $ranked = [];
            foreach ($decoded as $item) {
                $idx = $item['index'] ?? null;
                if ($idx !== null && isset($toRank[$idx])) {
                    $ranked[] = array_merge($toRank[$idx], [
                        'ai_relevance' => $item['relevance'] ?? 0.0,
                        'ai_reason'    => $item['reason'] ?? '',
                    ]);
                }
            }

            return array_slice($ranked, 0, 10);
        } catch (\Throwable $e) {
            Log::error('AiRouter::rankProductsByRelevance exception: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Generic OpenAI call helper for tools
     */
    public function callOpenAI(string $prompt, float $temperature = 0.3, int $maxTokens = 1000): string
    {
        if (empty($this->apiKey)) {
            Log::warning('AiRouter::callOpenAI called without OPENAI_API_KEY');
            throw new \RuntimeException('OpenAI key not configured');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post($this->baseUrl . '/chat/completions', [
                'model'       => $this->model,
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_completion_tokens'  => $maxTokens,
            ]);

        $data = $response->json();

        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            Log::error('AiRouter::callOpenAI invalid response', ['data' => $data]);
            throw new \RuntimeException('Invalid OpenAI response: ' . json_encode($data));
        }

        return trim((string) $data['choices'][0]['message']['content']);
    }
}
