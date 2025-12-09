<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HoroshopClient
{
    public function __construct(
        protected string $domain,
        protected string $login,
        protected string $password,
    ) {}

    /**
     * Загальний виклик будь-якої функції API Хорошоп.
     *
     * - автоматично додає token (крім auth)
     * - використовує JSON POST
     */
    public function call(string $function, array $payload = []): array
    {
        // 1. якщо це НЕ auth — додаємо token
        if ($function !== 'auth') {
            $token = $this->getToken();
            // token має бути в payload разом з іншими параметрами
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
     * Отримати (або оновити) token для API.
     *
     * Token живе 600 секунд — кешуємо десь на 550.
     */
    protected function getToken(): string
    {
        // 1. пробуємо взяти з кешу
        $cached = Cache::get('horoshop_api_token');
        if ($cached) {
            return $cached;
        }

        // 2. якщо немає — авторизуємось
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

        // 3. зберігаємо в кеш приблизно на 550 секунд
        Cache::put('horoshop_api_token', $token, now()->addSeconds(550));

        return $token;
    }
}
