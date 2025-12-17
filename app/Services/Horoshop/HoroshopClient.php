<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class HoroshopClient
{
    protected string $domain;
    protected string $login;
    protected string $password;

    public function __construct()
    {
        $this->domain   = (string) config('services.horoshop.domain');
        $this->login    = (string) config('services.horoshop.login');
        $this->password = (string) config('services.horoshop.password');

        // ✅ Важливо: не валимо composer/artisan під час деплою/інсталяції
        if (! $this->domain) {
            if (app()->runningInConsole()) {
                // просто лишаємо client "неактивним" — реальні API виклики все одно впадуть,
                // але composer install і package:discover пройдуть
                return;
            }

            throw new RuntimeException('Horoshop domain is not configured (services.horoshop.domain)');
        }
    }

    /**
     * Отримати токен авторизації та закешувати його на ~9 хвилин (540 секунд).
     */
    protected function authenticate(): string
    {
        return Cache::remember('horoshop_api_token', 540, function () {
            $url = $this->domain . '/api/auth/';

            $response = Http::acceptJson()
                ->asJson()
                ->post($url, [
                    'login'    => $this->login,
                    'password' => $this->password,
                ]);

            if (! $response->ok()) {
                throw new RuntimeException('Horoshop auth HTTP error: ' . $response->status());
            }

            $json   = $response->json();
            $status = $json['status'] ?? null;

            if ($status !== 'OK') {
                $message = $json['response']['message'] ?? 'Unknown auth error';
                throw new RuntimeException('Horoshop auth error: ' . $message);
            }

            $token = $json['response']['token'] ?? null;

            if (! $token) {
                throw new RuntimeException('Horoshop auth: token not returned');
            }

            return $token;
        });
    }

    /**
     * Основний метод виклику будь-якої API-функції Horoshop.
     *
     * @param  string  $function  Наприклад: 'catalog/export', 'orders/get'
     * @param  array   $payload   Параметри без token (ми додамо самі)
     * @return array              Поле "response" від Horoshop або []
     *
     * @throws \RuntimeException  При статусах ERROR / EXCEPTION / AUTHORIZATION_ERROR тощо
     */
    public function request(string $function, array $payload = []): array
    {
        $token = $this->authenticate();
        $url   = $this->domain . '/api/' . trim($function, '/') . '/';

        $response = Http::acceptJson()
            ->asJson()
            ->post($url, array_merge(['token' => $token], $payload));

        if (! $response->ok()) {
            throw new RuntimeException(
                sprintf('Horoshop HTTP error %d for %s', $response->status(), $function)
            );
        }

        $json   = $response->json();
        $status = $json['status'] ?? 'UNKNOWN';

        if ($status === 'OK') {
            return $json['response'] ?? [];
        }

        if ($status === 'EMPTY') {
            // Для деяких функцій це просто означає "нічого не знайшли"
            return [];
        }

        $message = $json['response']['message'] ?? 'Unknown API error';

        throw new RuntimeException(
            sprintf('Horoshop API error [%s]: %s', $status, $message)
        );
    }

    /**
     * Backwards-compat: якщо десь в коді ще викликається ->call().
     */
    public function call(string $function, array $payload = []): array
    {
        return $this->request($function, $payload);
    }

    /**
     * Backwards-compat: якщо десь викликається getToken().
     */
    public function getToken(): string
    {
        return $this->authenticate();
    }
}
