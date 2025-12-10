<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use App\Services\Horoshop\HoroshopService;
use App\Services\Ai\AiRouter;
use App\Services\Ai\AiRecommender;
use App\Services\FaqService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Horoshop HTTP client
        $this->app->singleton(HoroshopClient::class, function ($app) {
            $config = config('services.horoshop');

            return new HoroshopClient(
                $config['domain']   ?? '',
                $config['login']    ?? '',
                $config['password'] ?? '',
            );
        });

        // HoroshopService (обгортка над клієнтом, якщо він у тебе є)
        $this->app->singleton(HoroshopService::class, function ($app) {
            return new HoroshopService(
                $app->make(HoroshopClient::class),
            );
        });

        // 🔥 ProductService — тепер приймає AiRouter, а НЕ HoroshopClient
        $this->app->singleton(ProductService::class, function ($app) {
            return new ProductService(
                $app->make(AiRouter::class),
            );
        });

        // OrderService
        $this->app->singleton(OrderService::class, function ($app) {
            return new OrderService(
                $app->make(HoroshopClient::class),
            );
        });

        // FAQ
        $this->app->singleton(FaqService::class, function ($app) {
            return new FaqService();
        });

        // AI Router
        $this->app->singleton(AiRouter::class, function ($app) {
            return new AiRouter();
        });

        // AI Recommender (якщо використовується)
        $this->app->singleton(AiRecommender::class, function ($app) {
            return new AiRecommender();
        });
    }

    public function boot(): void
    {
        //
    }
}
