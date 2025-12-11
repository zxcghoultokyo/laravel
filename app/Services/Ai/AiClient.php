<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiClient
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $defaultModel;

    public function __construct()
    {
        // ключ беремо з config/services.php або напряму з env
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));

        if (! $this->apiKey) {
            throw new RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY in .env or services.openai.api_key.');
        }

        $this->baseUrl      = config('services.openai.base_url', env('OPENAI_BASE_URL', 'https://api.openai.com/v1'));
        $this->defaultModel = config('services.openai.chat_model', env('OPENAI_CHAT_MODEL', 'gpt-4.1-mini'));
    }

    /**
     * Викликає OpenAI Chat Completions і очікує JSON-обʼєкт у відповіді.
     *
     * @param string $system  system prompt (інструкція)
     * @param array  $userPayload довільний payload, який ми кодуємо як JSON і передаємо в повідомленні користувача
     * @param array  $options опції: ['model' => '...', 'temperature' => 0.2, ...]
     *
     * @return array розпарсений JSON з відповіді моделі (якщо не вдалося – порожній масив)
     */
    public function chatJson(string $system, array $userPayload, array $options = []): array
    {
        $model       = $options['model'] ?? $this->defaultModel;
        $temperature = $options['temperature'] ?? 0.3;

        $messages = [
            [
                'role'    => 'system',
                'content' => $system,
            ],
            [
                'role'    => 'user',
                'content' => json_encode(
                    $userPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ],
        ];

        $body = [
            'model'           => $model,
            'messages'        => $messages,
            'temperature'     => $temperature,
            'response_format' => ['type' => 'json_object'],
        ];

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $body);

        if (! $response->successful()) {
            Log::error('OpenAI chatJson request failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);

            return [];
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            Log::warning('OpenAI chatJson: empty content', [
                'raw' => $response->json(),
            ]);

            return [];
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::warning('OpenAI chatJson: invalid JSON content', [
                'content' => $content,
                'error'   => json_last_error_msg(),
            ]);

            return [];
        }

        return $decoded;
    }

    /**
     * Якщо буде треба звичайна текстова відповідь (не JSON) — можна юзати це.
     */
    public function chatText(string $system, string $userMessage, array $options = []): ?string
    {
        $model       = $options['model'] ?? $this->defaultModel;
        $temperature = $options['temperature'] ?? 0.5;

        $messages = [
            [
                'role'    => 'system',
                'content' => $system,
            ],
            [
                'role'    => 'user',
                'content' => $userMessage,
            ],
        ];

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ];

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $body);

        if (! $response->successful()) {
            Log::error('OpenAI chatText request failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : null;
    }
}
