<?php

namespace App\Providers;

use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\ProductService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Реєструємо сервіси в контейнері.
     */
    public function register(): void
    {
        // HoroshopClient — один на весь додаток (singleton)
        $this->app->singleton(HoroshopClient::class, function ($app) {
            $config = config('services.horoshop');

            return new HoroshopClient(
                $config['domain']   ?? '',
                $config['login']    ?? '',
                $config['password'] ?? '',
            );
        });

        // ProductService теж singleton, збирається з HoroshopClient
        $this->app->singleton(ProductService::class, function ($app) {
            return new ProductService(
                $app->make(HoroshopClient::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
