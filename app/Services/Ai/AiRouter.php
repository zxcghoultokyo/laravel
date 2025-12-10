<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiRouter
{
    protected string $model;

    public function __construct()
    {
        $this->model = config('services.openai.model', 'gpt-4.1-mini');
    }

    /**
     * Основна маршрутизація намірів
     */
    public function classify(string $message): array
    {
        $prompt = "
Ти — AI роутер для e-commerce магазину тактичного спорядження.
Визнач намір користувача та нормалізуй текст для подальшого пошуку товарів.

Поверни JSON:
{
  \"intent\": \"PRODUCT_SEARCH | ORDER_STATUS | FAQ | SMALL_TALK | FALLBACK\",
  \"normalized_query\": \"ключові слова для пошуку\",
  \"order_id\": null | number
}

Текст: \"$message\"
";

        $response = Http::withToken(config('services.openai.key'))
            ->post("https://api.openai.com/v1/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.2,
            ])
            ->json();

        return json_decode($response['choices'][0]['message']['content'], true);
    }

    /**
     * 🔥 Нове! AI-нормалізація тексту для пошуку товарів.
     */
    public function normalizeSearchQuery(string $message): string
    {
        $prompt = "
Ти — AI нор-малізатор пошукових запитів.
Користувач пише будь-яким стилем, з помилками, сленгом.

Твоє завдання: повернути 1-3 ключові слова, за якими можна шукати товар у магазині.

Приклади:

«потрібна плитоноска» → «плитоноска»
«плєтоноска» → «плитоноска»
«бронік» → «плитоноска»
«хочу шось під аптечку» → «підсумок аптечка»
«магазин для глока» → «магазин глок»

❗ Поверни ТІЛЬКИ слова для пошуку, без зайвого тексту.

Текст: \"$message\"
";

        $response = Http::withToken(config('services.openai.key'))
            ->post("https://api.openai.com/v1/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.3,
            ])
            ->json();

        return trim($response['choices'][0]['message']['content']);
    }
}
