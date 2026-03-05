<?php

namespace App\Services\Rozetka;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RozetkaClient
{
    protected const BASE_URL = 'https://api-seller.rozetka.com.ua';

    protected string $username;

    protected string $password;

    protected bool $isConfigured = false;

    protected string $cacheKeyPrefix;

    public function __construct(?string $username = null, ?string $password = null)
    {
        if ($username && $password) {
            $this->username = $username;
            $this->password = $password;
            $this->cacheKeyPrefix = 'rozetka_token_'.md5($username);
        } else {
            $this->username = (string) config('services.rozetka.username');
            $this->password = (string) config('services.rozetka.password');
            $this->cacheKeyPrefix = 'rozetka_token';
        }

        $this->isConfigured = ! empty($this->username) && ! empty($this->password);
    }

    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    protected function authenticate(): string
    {
        return Cache::remember($this->cacheKeyPrefix, 3600, function () {
            $response = Http::acceptJson()
                ->asJson()
                ->post(self::BASE_URL.'/sites', [
                    'username' => $this->username,
                    'password' => base64_encode($this->password),
                ]);

            if (! $response->ok()) {
                throw new RuntimeException('Rozetka auth HTTP error: '.$response->status());
            }

            $json = $response->json();

            if (! ($json['success'] ?? false)) {
                $message = $json['errors']['message'] ?? 'Unknown auth error';
                throw new RuntimeException('Rozetka auth error: '.$message);
            }

            $token = $json['content']['access_token'] ?? null;

            if (! $token) {
                throw new RuntimeException('Rozetka auth: access_token not returned');
            }

            Log::info('Rozetka: authenticated successfully', [
                'market_id' => $json['content']['market']['id'] ?? null,
                'market_title' => $json['content']['market']['title'] ?? null,
            ]);

            return $token;
        });
    }

    /**
     * Make an authenticated GET request.
     */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        $token = $this->authenticate();

        $response = Http::withHeaders(array_merge([
            'Authorization' => 'Bearer '.$token,
        ], $headers))->acceptJson()->get(self::BASE_URL.$path, $query);

        if (! $response->ok()) {
            // Token might be expired
            if ($response->status() === 401) {
                Cache::forget($this->cacheKeyPrefix);
                $token = $this->authenticate();

                $response = Http::withHeaders(array_merge([
                    'Authorization' => 'Bearer '.$token,
                ], $headers))->acceptJson()->get(self::BASE_URL.$path, $query);
            }

            if (! $response->ok()) {
                throw new RuntimeException("Rozetka API GET {$path} error: ".$response->status());
            }
        }

        return $response->json();
    }

    /**
     * Make an authenticated POST request.
     */
    public function post(string $path, array $data = []): array
    {
        $token = $this->authenticate();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->acceptJson()->asJson()->post(self::BASE_URL.$path, $data);

        if (! $response->ok()) {
            if ($response->status() === 401) {
                Cache::forget($this->cacheKeyPrefix);
                $token = $this->authenticate();

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->acceptJson()->asJson()->post(self::BASE_URL.$path, $data);
            }

            if (! $response->ok()) {
                throw new RuntimeException("Rozetka API POST {$path} error: ".$response->status());
            }
        }

        return $response->json();
    }
}
