<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HoroshopClient
{
    /**
     * Базові налаштування для доступу до Хорошоп.
     *
     * Очікуємо, що в .env є:
     * HOROSHOP_DOMAIN=https://yourshop.com
     * HOROSHOP_LOGIN=api_login
     * HOROSHOP_PASSWORD=api_password
     */

    protected string $domain;
    protected string $login;
    protected string $password;

    // ключ для кешу токена
    protected string $tokenCacheKey = 'horoshop_api_token';

    // TTL токена в секундах (у Хорошопа 600 сек, беремо трохи менше, щоб оновлювати завчасно)
    protected int $tokenTtl = 540; // 9 хвилин

    public function __construct()
    {
        $this->domain   = rtrim(config('services.horoshop.domain'), '/');
        $this->login    = config('services.horoshop.login');
        $this->password = config('services.horoshop.password');

        if (empty($this->domain) || empty($this->login) || empty($this->password)) {
            throw new RuntimeException('Horoshop config is missing. Check services.horoshop and .env variables.');
        }
    }

    /**
     * Отримуємо токен авторизації з Хорошопа.
     * Токен кешується на ~9 хвилин.
     */
    public function getToken(): string
    {
        return Cache::remember($this->tokenCacheKey, $this->tokenTtl, function () {
            $url = sprintf('%s/api/auth/', $this->domain);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, [
                'login'    => $this->login,
                'password' => $this->password,
            ]);

            if (! $response->ok()) {
                throw new RuntimeException(
                    sprintf('Horoshop auth HTTP error %s', $response->status())
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException('Horoshop auth: invalid JSON response');
            }

            if (($data['status'] ?? null) !== 'OK') {
                $message = $data['response']['message'] ?? 'Unknown auth error';
                throw new RuntimeException("Horoshop auth error: {$message}");
            }

            $token = $data['response']['token'] ?? null;

            if (! $token || ! is_string($token)) {
                throw new RuntimeException('Horoshop auth: token is missing in response');
            }

            return $token;
        });
    }

    /**
     * Базовий JSON POST до будь-якої функції Хорошоп API.
     *
     * @param  string  $function  Наприклад: 'catalog/export', 'orders/get'
     * @param  array   $payload   Тіло запиту (параметри). token додамо автоматично, якщо його немає.
     * @return array              Розпарсена JSON-відповідь
     */
    public function postJson(string $function, array $payload): array
    {
        // гарантуємо, що токен є в payload
        if (! isset($payload['token'])) {
            $payload['token'] = $this->getToken();
        }

        $url = sprintf(
            '%s/api/%s/',
            $this->domain,
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

        if (! is_array($data)) {
            throw new RuntimeException("Horoshop: invalid JSON response for {$function}");
        }

        // status може бути OK або EMPTY – обидва варіанти не є фатальною помилкою
        $status = $data['status'] ?? null;

        if ($status && ! in_array($status, ['OK', 'EMPTY'], true)) {
            $message = $data['response']['message'] ?? '';
            throw new RuntimeException("Horoshop API error [{$status}]: {$message}");
        }

        return $data;
    }
}
