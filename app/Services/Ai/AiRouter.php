<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
Ти — AI-роутер для e-commerce чату.
Визнач намір користувача та нормалізуй текст для подальших дій.

Поверни JSON рівно у такому форматі:
{
  \"intent\": \"PRODUCT_SEARCH | ORDER_STATUS | FAQ | SMALL_TALK | FALLBACK\",
  \"normalized_query\": \"ключові слова для пошуку/дії\",
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
Ти — AI нормалізатор пошукових запитів інтернет-магазину.
Користувач пише будь-яким стилем, з помилками, сленгом.

Завдання: повернути 1–5 ключових слів, за якими можна шукати релевантні товари.

Правила:
- НЕ додавай пояснень.
- НЕ додавай лапки.
- Поверни тільки ключові слова/фрази.

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
}
