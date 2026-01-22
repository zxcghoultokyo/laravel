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
    protected bool $isConfigured = false;
    protected string $cacheKeyPrefix;

    /**
     * Create a new HoroshopClient instance.
     * 
     * If credentials are provided, they will be used (tenant-specific mode).
     * Otherwise, falls back to config values (legacy mode).
     *
     * @param string|null $domain   The Horoshop domain (e.g., http://shop123.horoshop.ua)
     * @param string|null $login    The API login
     * @param string|null $password The API password
     */
    public function __construct(?string $domain = null, ?string $login = null, ?string $password = null)
    {
        // If credentials are provided, use them (tenant-specific mode)
        if ($domain && $login && $password) {
            $this->domain = $domain;
            $this->login = $login;
            $this->password = $password;
            // Create unique cache key based on domain to avoid token conflicts between tenants
            $this->cacheKeyPrefix = 'horoshop_api_token_' . md5($domain);
        } else {
            // Fall back to config (legacy mode for global/default shop)
            $this->domain   = (string) config('services.horoshop.domain');
            $this->login    = (string) config('services.horoshop.login');
            $this->password = (string) config('services.horoshop.password');
            $this->cacheKeyPrefix = 'horoshop_api_token';
        }

        // Check if configured
        $this->isConfigured = !empty($this->domain) && !empty($this->login) && !empty($this->password);
    }
    
    /**
     * Check if Horoshop is configured
     */
    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    /**
     * Get the domain this client is connected to
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * Отримати токен авторизації та закешувати його на ~9 хвилин (540 секунд).
     * Uses tenant-specific cache key to avoid conflicts between shops.
     */
    protected function authenticate(): string
    {
        return Cache::remember($this->cacheKeyPrefix, 540, function () {
            $url = $this->domain . '/api/auth/';

            $response = Http::acceptJson()
                ->asJson()
                ->post($url, [
                    'login'    => $this->login,
                    'password' => $this->password,
                ]);

            if (! $response->ok()) {
                throw new RuntimeException('Horoshop auth HTTP error: ' . $response->status() . ' for ' . $this->domain);
            }

            $json   = $response->json();
            $status = $json['status'] ?? null;

            if ($status !== 'OK') {
                $message = $json['response']['message'] ?? 'Unknown auth error';
                throw new RuntimeException('Horoshop auth error for ' . $this->domain . ': ' . $message);
            }

            $token = $json['response']['token'] ?? null;

            if (! $token) {
                throw new RuntimeException('Horoshop auth: token not returned for ' . $this->domain);
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
        if (!$this->isConfigured) {
            throw new RuntimeException('Horoshop is not configured');
        }
        
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
