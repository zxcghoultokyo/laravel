<?php

namespace App\Providers;

use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Реєструємо сервіси в контейнері.
     */
    public function register(): void
    {
        // HoroshopClient — один на весь додаток
        $this->app->singleton(HoroshopClient::class, function ($app) {
            $config = config('services.horoshop');

            return new HoroshopClient(
                $config['domain']   ?? '',
                $config['login']    ?? '',
                $config['password'] ?? '',
            );
        });

        // ProductService
        $this->app->singleton(ProductService::class, function ($app) {
            return new ProductService(
                $app->make(HoroshopClient::class)
            );
        });

        // OrderService
        $this->app->singleton(OrderService::class, function ($app) {
            return new OrderService(
                $app->make(HoroshopClient::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
