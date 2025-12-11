<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Product;

class AiRouter
{
    protected string $model;
    protected string $baseUrl;
    protected ?string $apiKey;

    public function __construct()
    {
        $config        = config('services.openai', []);
        $this->model   = $config['model'] ?? 'gpt-4.1-mini';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->apiKey  = $config['api_key'] ?? null;
    }

    /**
     * Основна маршрутизація намірів
     */
    public function classify(string $message): array
    {
        // 🔙 Фолбек якщо щось пішло не так
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
Ти — AI роутер для e-commerce магазину тактичного спорядження.
Визнач намір користувача та нормалізуй текст для подальшого пошуку товарів.

Поверни JSON рівно у такому форматі:
{
  \"intent\": \"PRODUCT_SEARCH | ORDER_STATUS | FAQ | SMALL_TALK | FALLBACK\",
  \"normalized_query\": \"ключові слова для пошуку\",
  \"order_id\": null або номер замовлення (якщо є у тексті)
}

Текст: \"{$message}\"
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

            // merge, щоб були всі очікувані ключі
            return array_merge($fallback, $decoded);
        } catch (\Throwable $e) {
            Log::error('AiRouter::classify exception: ' . $e->getMessage(), ['exception' => $e]);
            return $fallback;
        }
    }

    /**
     * AI-нормалізація тексту для пошуку товарів.
     */
    public function normalizeSearchQuery(string $message): string
    {
        $fallback = $message;

        if (empty($this->apiKey)) {
            Log::warning('AiRouter::normalizeSearchQuery called without OPENAI_API_KEY');
            return $fallback;
        }

        $prompt = "
Ти — AI нормалізатор пошукових запитів.
Користувач пише будь-яким стилем, з помилками, сленгом.

Твоє завдання: повернути 1–3 ключові слова, за якими можна шукати товар у магазині.

Приклади:
«потрібна плитоноска» → «плитоноска»
«плєтоноска» → «плитоноска»
«бронік» → «плитоноска»
«хочу шось під аптечку» → «підсумок аптечка»
«магазин для глока» → «магазин глок»

❗ Поверни ТІЛЬКИ слова для пошуку, без пояснень, без лапок.

Текст: \"{$message}\"
";

        try {
            $response = Http::withToken($this->apiKey)
                ->post($this->baseUrl . '/chat/completions', [
                    'model'       => $this->model,
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.3,
                ]);

            $data = $response->json();

            if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
                Log::error('AiRouter::normalizeSearchQuery invalid OpenAI response', ['data' => $data]);
                return $fallback;
            }

            return trim((string) $data['choices'][0]['message']['content']);
        } catch (\Throwable $e) {
            Log::error('AiRouter::normalizeSearchQuery exception: ' . $e->getMessage(), ['exception' => $e]);
            return $fallback;
        }
    }
    public function buildProductIndexData(Product $product): array
    {
        $title    = mb_strtolower($product->title ?? '');
        $category = mb_strtolower($product->category_path ?? '');
        $index    = mb_strtolower($product->search_index ?? '');
        $haystack = $title . ' ' . $index . ' ' . $category;
    
        $productType = null;
    
        if (str_contains($category, 'шолом')) {
            $productType = 'helmet';
        } elseif (str_contains($category, 'плитоноски')) {
            $productType = 'plate_carrier';
        } elseif (str_contains($category, 'бронезахист')) {
            $productType = 'armor_plate';
        } elseif (str_contains($category, 'футболк')) {
            $productType = 'tshirt';
        }
    
        return [
            'product_type' => $productType,
            'ai_category'  => $productType ? 'tactical_gear' : null,
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
     *
     * Повертає масив:
     *  - product_types        => []   // типи товарів, як їх бачить AI (футболка, каска, плита...)
     *  - must_have_keywords   => []   // обов'язкові абревіатури/матеріали/стандарти (UHMWPE, FR...)
     *  - fallback_types       => []   // ширші категорії на випадок, якщо нічого не знайшли
     */
    public function parseProductSearchIntent(string $message): array
    {
        if (empty($this->apiKey)) {
            Log::warning('AiRouter::parseProductSearchIntent called without OPENAI_API_KEY');
            return [];
        }

        $systemPrompt = <<<PROMPT
Ти — AI-модуль, який розбирає пошукові запити по тактичному спорядженню / одягу.
Твоє завдання — повернути ЧИСТИЙ JSON з полями:

- "product_types": масив коротких назв типів товарів так, як їх написав користувач.
  Приклади: ["каска", "плита", "футболка", "розгрузка", "plate carrier"].
- "must_have_keywords": масив важливих слів/скорочень/позначень, які ОБОВ'ЯЗКОВО мають бути в товарі,
  якщо користувач явно це просить.
  Приклади: ["uhmwpe", "fr", "niii", "level 4", "molle"].
- "fallback_types": масив ширших категорій, які можна показати, якщо точних товарів немає.
  Приклади: ["шоломи", "бронеплити", "одяг"].

Важливе:
- НЕ вигадуй нові абревіатури.
- Якщо не впевнений у чомусь — краще залиш масив порожнім.
- Використовуй і українські/російські слова, і англійські абревіатури так, як у запиті.
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
        /**
     * Високорівнева маршрутизація повідомлення чату.
     * Повертає JSON-структуру, з якою працює ChatService.
     */
    public function routeChatMessage(string $message, array $context = []): array
{
    $fallback = [
        'intent'       => 'unknown',
        'action'       => 'ASK_CLARIFICATION',
        'confidence'   => 0.0,
        'category_key' => null,
        'message'      => "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙂",
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

    $systemPrompt = <<<PROMPT
Ти — AI-оркестратор для чату інтернет-магазину тактичного спорядження та такмеду.

Твоє завдання — на основі повідомлення користувача визначити:
- інтенцію (intent),
- дію (action),
- категорію товарів (category_key), якщо це пошук,
- допоміжні слоти (slots) — бюджет, номер замовлення тощо,
- текст-відповідь message, який одразу можна показати користувачу.

Можливі intent:
- "product_search" — користувач хоче підібрати/подивитись товар.
- "order_status"  — користувач питає про статус/доставку свого замовлення.
- "shop_info"     — питання про магазин: доставка, оплата, повернення, гарантія, наявність.
- "smalltalk"     — привітання, подяка, просто розмова ("привіт", "як справи" тощо).
- "abuse"         — мат, образи, токсична поведінка.
- "unknown"       — нічого з вище описаного впевнено не підходить.

Можливі action:
- "SHOW_PRODUCTS"      — можна відразу показувати список товарів.
- "ASK_CLARIFICATION"  — треба поставити уточнююче питання.
- "NONE"               — просто відповісти текстом (наприклад, smalltalk, shop_info).

category_key використовується ТІЛЬКИ для intent = "product_search".
Дозволені значення category_key:
- "tourniquets"          — турнікети.
- "ifak_kits"            — аптечки IFAK / тактичні аптечки.
- "helmets"              — шоломи / каски.
- "plate_carriers"       — плитоноски / розгрузки під плити.
- "plates"               — бронеплити / плити SAPI.
- "cold_weather_jackets" — зимові/теплі куртки, парки, софтшели.
- "tactical_medicine"    — загалом тактична медицина (якщо важко вибрати точнішу категорію).

slots — вільна структура, але ми очікуємо принаймні:
- "budget_min": мінімальний бюджет у гривнях (float) або null.
- "budget_max": максимальний бюджет у гривнях (float) або null.
- "order_number": рядок з номером замовлення або null.

Важливі правила:
- Якщо користувач ПРЯМО називає категорію ("турнікети", "шоломи", "аптечка ifak") —
  intent = "product_search", action = "SHOW_PRODUCTS", category_key = відповідна категорія, confidence >= 0.7.
- Якщо запит розмитий ("порадь щось щоб не замерзнути") —
  intent = "product_search", але action = "ASK_CLARIFICATION" і message має містити уточнююче запитання.
- Якщо є фрази типу "де моє замовлення", "статус доставки", "коли прийде посилка" —
  intent = "order_status". Якщо знайшов номер замовлення в тексті — поклади його у slots.order_number.
- Якщо питання про умови, оплату, доставку, повернення —
  intent = "shop_info" і у message дай коротку структуровану відповідь.
- Якщо це просто "привіт", "дякую" тощо — intent = "smalltalk".
- Якщо багато матюків/образ — intent = "abuse", але message повинен бути м'яким, ввічливим.

Ти ПОВИНЕН повернути ЧИСТИЙ JSON без пояснень, без markdown.

Структура JSON:
{
  "intent": "product_search" | "order_status" | "shop_info" | "smalltalk" | "abuse" | "unknown",
  "action": "SHOW_PRODUCTS" | "ASK_CLARIFICATION" | "NONE",
  "confidence": 0.0-1.0,
  "category_key": string|null,
  "message": "текст відповіді, який можна одразу показати користувачу",
  "slots": {
    "budget_min": float|null,
    "budget_max": float|null,
    "order_number": string|null
  }
}
PROMPT;

    try {
        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $message],
                ],
                'temperature'     => 0.2,
                'response_format' => [
                    'type'        => 'json_schema',
                    'json_schema' => [
                        'name'   => 'chat_routing',
                        'strict' => true,
                        'schema' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'properties'           => [
                                'intent' => [
                                    'type' => 'string',
                                    'enum' => [
                                        'product_search',
                                        'order_status',
                                        'shop_info',
                                        'smalltalk',
                                        'abuse',
                                        'unknown',
                                    ],
                                ],
                                'action' => [
                                    'type' => 'string',
                                    'enum' => [
                                        'SHOW_PRODUCTS',
                                        'ASK_CLARIFICATION',
                                        'NONE',
                                    ],
                                ],
                                'confidence' => [
                                    'type'    => 'number',
                                    'minimum' => 0,
                                    'maximum' => 1,
                                ],
                                'category_key' => [
                                    'type' => ['string', 'null'],
                                ],
                                'message' => [
                                    'type' => 'string',
                                ],
                                'slots' => [
                                    'type'                 => 'object',
                                    'additionalProperties' => false,
                                    'properties'           => [
                                        'budget_min' => ['type' => ['number', 'null']],
                                        'budget_max' => ['type' => ['number', 'null']],
                                        'order_number' => ['type' => ['string', 'null']],
                                    ],
                                    'required' => ['budget_min', 'budget_max', 'order_number'],
                                ],
                            ],
                            'required' => [
                                'intent',
                                'action',
                                'confidence',
                                'category_key',
                                'message',
                                'slots',
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('AiRouter::routeChatMessage HTTP error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return $fallback;
        }

        $content = $response->json('choices.0.message.content');
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            Log::warning('AiRouter::routeChatMessage got non-JSON content', [
                'content' => $content,
            ]);
            return $fallback;
        }

        return array_merge($fallback, $decoded);
    } catch (\Throwable $e) {
        Log::error('AiRouter::routeChatMessage exception: ' . $e->getMessage(), [
            'exception' => $e,
        ]);
        return $fallback;
    }
}

    }

