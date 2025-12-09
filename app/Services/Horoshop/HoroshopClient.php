<?php

namespace App\Services\Horoshop;

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
     * Викликає функцію Хорошоп API по JSON POST.
     *
     * @param  string  $function  назва функції, напр. "products"
     * @param  array   $payload   параметри
     * @return array              вміст поля "response" з відповіді
     */
    public function call(string $function, array $payload = []): array
    {
        $url = "https://{$this->domain}/api/{$function}/";

        $response = Http::withBasicAuth($this->login, $this->password)
            ->withHeaders([
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
}
