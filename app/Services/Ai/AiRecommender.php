<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiRecommender
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.openai.api_key');
        $this->model   = config('services.openai.model', 'gpt-4.1-mini');
        $this->baseUrl = rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not set.');
        }
    }

    /**
     * Обирає найрелевантніші товари через OpenAI.
     *
     * @param  string $userMessage  — запит користувача ("чорна сукня S")
     * @param  array  $products     — "сирі" товари з Horoshop (catalog/export)
     * @param  string $language     — 'uk' або 'en' (для тексту відповіді)
     *
     * @return array [
     *   'message'  => текст відповіді для юзера,
     *   'articles' => [ 'SKU1', 'SKU2', ... ],
     * ]
     */
    public function pickProducts(string $userMessage, array $products, string $language = 'uk'): array
    {
        if (empty($products)) {
            return [
                'message'  => 'Я не знайшов жодного товару за цим запитом.',
                'articles' => [],
            ];
        }

        // 1. Беремо максимум 30 товарів, щоб не заливати в модель тисячі рядків
        $candidates = array_slice($products, 0, 30);

        // 2. Готуємо текстовий список товарів для моделі
        $lines = [];

        foreach ($candidates as $index => $product) {
            $idx    = $index + 1;
            $article = $product['article'] ?? ($product['parent_article'] ?? ('p'.$idx));

            $titleUa = $product['title']['ua'] ?? null;
            $titleRu = $product['title']['ru'] ?? null;
            $name    = $titleUa ?: ($titleRu ?: ($product['title'] ?? ''));

            $short = $product['short_description'] ?? null;
            if (is_array($short)) {
                $short = $short['ua'] ?? ($short['ru'] ?? null);
            }

            $price = $product['price'] ?? null;

            $lines[] = sprintf(
                "[%d] article=%s; name=\"%s\"; price=%s; short=\"%s\"",
                $idx,
                $article,
                $name,
                $price !== null ? $price : 'N/A',
                $short !== null ? mb_substr($short, 0, 120) : ''
            );
        }

        $productsText = implode("\n", $lines);

        // 3. Промпт для моделі
        $system = $language === 'uk'
            ? "Ти — AI-консультант інтернет-магазину. Твоє завдання — допомогти користувачу обрати найкращі товари з запропонованого списку. Враховуй запит, контекст, логіку покупок. Поверни СТРОГО JSON без пояснень у форматі: {\"message\": \"текст для користувача\", \"articles\": [\"ART1\", \"ART2\", ...]}."
            : "You are an AI assistant for an online store. Your task is to choose the best products from the list, based on the user's request. Return STRICT JSON only: {\"message\": \"assistant reply\", \"articles\": [\"ART1\", \"ART2\", ...]}.";

        $userPrompt = ($language === 'uk'
                ? "Запит користувача: "
                : "User message: ")
            . $userMessage
            . "\n\n"
            . ($language === 'uk'
                ? "Список товарів-кандидатів:\n"
                : "List of candidate products:\n")
            . $productsText
            . "\n\n"
            . ($language === 'uk'
                ? "Обери до 3 найкращих товарів (article) і склади дружню, але лаконічну відповідь. Не придумуй артикулів, використовуй тільки ті, що вказані в списку."
                : "Choose up to 3 best products (by article) and generate a friendly but concise reply. Do not invent articles, use only those from the list.");

        // 4. Виклик OpenAI Chat Completions
        $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post($this->baseUrl.'/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => $system,
                    ],
                    [
                        'role'    => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                // Просимо JSON-об’єкт у відповіді
                'response_format' => ['type' => 'json_object'],
                'temperature'     => 0.5,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI API error: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            throw new RuntimeException('OpenAI API returned empty content.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI response is not valid JSON: '.$content);
        }

        $message  = $decoded['message']  ?? 'Ось товари, які я можу запропонувати:';
        $articles = $decoded['articles'] ?? [];

        if (! is_array($articles)) {
            $articles = [];
        }

        // Обрізаємо до максимум 5 артикулів на всякий випадок
        $articles = array_values(array_unique(array_slice($articles, 0, 5)));

        return [
            'message'  => $message,
            'articles' => $articles,
        ];
    }
}
