<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клієнт для API Хорошопа.
 * Працює через auth -> token і JSON POST-запити.
 */
class HoroshopClient
{
    // ... конструктор та getToken вже є

    /**
     * Базовий JSON POST до Хорошопу.
     *
     * @param  string  $function  напр. 'catalog/export', 'orders/get'
     * @param  array   $payload   тіло запиту (token+параметри)
     * @return array
     */
    public function postJson(string $function, array $payload): array
    {
        // гарантуємо, що токен є в payload
        if (!isset($payload['token'])) {
            $payload['token'] = $this->getToken();
        }

        $url = sprintf(
            '%s/api/%s/',
            rtrim($this->domain, '/'),
            trim($function, '/')
        );

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        if (! $response->ok()) {
            throw new RuntimeException(
                sprintf('Horoshop HTTP error %s for %s', $response->status(), $function)
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('Horoshop: invalid JSON response');
        }

        // status може бути OK або EMPTY – це не HTTP-помилка
        $status = $data['status'] ?? null;

        if ($status && !in_array($status, ['OK', 'EMPTY'], true)) {
            $message = $data['response']['message'] ?? '';
            throw new RuntimeException("Horoshop API error [{$status}]: {$message}");
        }

        return $data;
    }
}
