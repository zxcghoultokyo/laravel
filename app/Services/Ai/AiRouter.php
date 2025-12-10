<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
}
