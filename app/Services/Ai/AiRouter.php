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
}
