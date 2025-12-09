<?php

namespace App\Providers;

use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use App\Services\Ai\AiRouter;
use App\Services\Ai\AiRecommender;
use App\Services\FaqService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Horoshop client
        $this->app->singleton(HoroshopClient::class, function ($app) {
            $config = config('services.horoshop');

            return new HoroshopClient(
                $config['domain']   ?? '',
                $config['login']    ?? '',
                $config['password'] ?? '',
            );
        });

        // Products
        $this->app->singleton(ProductService::class, function ($app) {
            return new ProductService(
                $app->make(HoroshopClient::class)
            );
        });

        // Orders
        $this->app->singleton(OrderService::class, function ($app) {
            return new OrderService(
                $app->make(HoroshopClient::class)
            );
        });

        // FAQ (якщо він у тебе сервісом)
        $this->app->singleton(FaqService::class, function ($app) {
            return new FaqService();
        });

        // AI Router
        $this->app->singleton(AiRouter::class, function ($app) {
            return new AiRouter();
        });

        // AI Recommender (припускаю, що він уже є)
        $this->app->singleton(AiRecommender::class, function ($app) {
            return new AiRecommender();
        });
    }

    public function boot(): void
    {
        //
    }
}
