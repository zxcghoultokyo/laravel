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
    public function __construct(
        protected string $domain,
        protected string $login,
        protected string $password,
    ) {}

    /**
     * Виклик будь-якої функції API (крім auth).
     * Автоматично додає token.
     */
    public function call(string $function, array $payload = []): array
    {
        if ($function !== 'auth') {
            $token = $this->getToken();
            $payload = array_merge(['token' => $token], $payload);
        }

        $url = "https://{$this->domain}/api/{$function}/";

        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])
            ->post($url, $payload);

        $data = $response->json();

        if (! $response->successful()) {
            throw new RuntimeException(
                'HTTP error from Horoshop: '.$response->status().' '.json_encode($data)
            );
        }

        $status = $data['status'] ?? null;

        if ($status !== 'OK') {
            $message = $data['response']['message'] ?? 'Unknown error';
            throw new RuntimeException("Horoshop API error [{$status}]: {$message}");
        }

        return $data['response'] ?? [];
    }

    /**
     * Отримати/оновити token через /api/auth/.
     */
    protected function getToken(): string
    {
        if ($cached = Cache::get('horoshop_api_token')) {
            return $cached;
        }

        $url = "https://{$this->domain}/api/auth/";

        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])
            ->post($url, [
                'login'    => $this->login,
                'password' => $this->password,
            ]);

        $data = $response->json();

        if (! $response->successful() || ($data['status'] ?? null) !== 'OK') {
            $message = $data['response']['message'] ?? 'Auth failed';
            throw new RuntimeException("Horoshop auth error: {$message}");
        }

        $token = $data['response']['token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Horoshop auth error: token not returned');
        }

        // Токен живе 600 сек — кешуємо десь на 550
        Cache::put('horoshop_api_token', $token, now()->addSeconds(550));

        return $token;
    }
}
