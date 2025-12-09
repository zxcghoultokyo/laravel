<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AiRouter
{
    protected string $model;

    public function __construct()
    {
        // Можеш винести в config/services.php, якщо захочеш.
        $this->model = config('services.openai.router_model', 'gpt-4o-mini');
    }

    /**
     * Основний метод класифікації.
     *
     * Повертає масив:
     * [
     *   'intent'           => 'ORDER_STATUS'|'PRODUCT_SEARCH'|'FAQ'|'SMALL_TALK'|'UNKNOWN'|'FALLBACK',
     *   'order_id'         => 7|null,
     *   'normalized_query' => '...',
     *   'confidence'       => 0.0–1.0,
     * ]
     */
    public function classify(string $message): array
    {
        $apiKey = config('services.openai.key');

        // Якщо немає ключа — одразу fallback
        if (empty($apiKey)) {
            return [
                'intent'           => 'FALLBACK',
                'order_id'         => null,
                'normalized_query' => $message,
                'confidence'       => 0.0,
                'reason'           => 'no_openai_key',
            ];
        }

        $systemPrompt = <<<PROMPT
Ти - маршрутизатор запитів для інтернет-магазину.

Твоє завдання:
1. Визначити, що хоче користувач.
2. Повернути STRICT JSON БЕЗ жодного пояснення, тільки JSON.

Можливі intent:
- "ORDER_STATUS"  — користувач запитує про статус замовлення, наприклад: "де моє замовлення 7", "статус заказу #15", "що там по заказу", "що з order 123".
- "PRODUCT_SEARCH" — користувач хоче підібрати або знайти товар, наприклад: "потрібна плитоноска", "хочу теплу куртку", "обери мені кросівки".
- "FAQ" — питання про умови магазину: оплата, доставка, повернення, гарантія, графік роботи, контакти.
- "SMALL_TALK" — привітання, болтовня, подяки тощо: "привіт", "як справи", "дякую".
- "UNKNOWN" — якщо не можеш впевнено визначити намір.

Формат відповіді:
{
  "intent": "ORDER_STATUS" | "PRODUCT_SEARCH" | "FAQ" | "SMALL_TALK" | "UNKNOWN",
  "order_id": 123 | null,
  "normalized_query": "коротко перефразований запит користувача",
  "confidence": 0.0–1.0
}

Правила:
- Якщо intent = "ORDER_STATUS", але в тексті немає номера замовлення — "order_id" = null.
- Якщо intent не "ORDER_STATUS" — завжди "order_id" = null.
- "normalized_query" має бути коротким і зрозумілим для backend-пошуку.
- Тільки один об'єкт JSON, без коментарів і тексту поза дужками.
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'    => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $message],
                    ],
                    'temperature' => 0.0,
                ]);

            if (!$response->successful()) {
                return [
                    'intent'           => 'FALLBACK',
                    'order_id'         => null,
                    'normalized_query' => $message,
                    'confidence'       => 0.0,
                    'reason'           => 'openai_http_error_' . $response->status(),
                ];
            }

            $data = $response->json();

            $content = $data['choices'][0]['message']['content'] ?? '{}';

            // іноді модель може обгорнути JSON у ```json ... ```
            $content = Str::of($content)
                ->replace('```json', '')
                ->replace('```', '')
                ->trim()
                ->toString();

            $parsed = json_decode($content, true);

            if (!is_array($parsed)) {
                return [
                    'intent'           => 'FALLBACK',
                    'order_id'         => null,
                    'normalized_query' => $message,
                    'confidence'       => 0.0,
                    'reason'           => 'json_parse_error',
                ];
            }

            return [
                'intent'           => strtoupper($parsed['intent'] ?? 'UNKNOWN'),
                'order_id'         => isset($parsed['order_id']) ? (int) $parsed['order_id'] : null,
                'normalized_query' => $parsed['normalized_query'] ?? $message,
                'confidence'       => (float) ($parsed['confidence'] ?? 0.0),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'intent'           => 'FALLBACK',
                'order_id'         => null,
                'normalized_query' => $message,
                'confidence'       => 0.0,
                'reason'           => 'exception',
            ];
        }
    }
}
