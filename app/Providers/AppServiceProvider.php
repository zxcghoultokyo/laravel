<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Horoshop\HoroshopService;
use App\Services\Ai\AiRouter;
use App\Services\Horoshop\HoroshopDataService;
use App\Services\Horoshop\DeliveryTrackingService;
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
        // MeiliClient
        $this->app->singleton(\App\Services\Search\MeiliClient::class, function ($app) {
            return new \App\Services\Search\MeiliClient();
        });
        
        // BrandDetectionService
        $this->app->singleton(\App\Services\Search\BrandDetectionService::class, function ($app) {
            return new \App\Services\Search\BrandDetectionService();
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
            $app->make(HoroshopClient::class), // 1) client
            $app->make(AiRouter::class),       // 2) router
            );
        });

        // OrderService
        $this->app->singleton(OrderService::class, function ($app) {
            return new OrderService(
                $app->make(HoroshopClient::class),
            );
        });

        // OrderSearchService
        $this->app->singleton(OrderSearchService::class, function ($app) {
            return new OrderSearchService(
                $app->make(HoroshopClient::class),
                $app->make(OrderService::class),
                            $app->make(DeliveryTrackingService::class),
            );
        });

        // FAQ
        $this->app->singleton(FaqService::class, function ($app) {
            return new FaqService();
        });

        // HoroshopDataService
        $this->app->singleton(HoroshopDataService::class, function ($app) {
            return new HoroshopDataService(
                $app->make(HoroshopClient::class),
            );
        });

        // DeliveryTrackingService
        $this->app->singleton(DeliveryTrackingService::class, function ($app) {
            return new DeliveryTrackingService(
                $app->make(HoroshopDataService::class),
            );
        });

        // AI Router
        $this->app->singleton(AiRouter::class, function ($app) {
            return new AiRouter();
        });

        // AI Recommender (якщо використовується)
        $this->app->singleton(AiRecommender::class, function ($app) {
            return new AiRecommender();
        });

        // Agent Orchestrator Tools
        $this->app->singleton(\App\Services\Agent\Tools\MeiliProductSearchTool::class, function ($app) {
            return new \App\Services\Agent\Tools\MeiliProductSearchTool(
                $app->make(\App\Services\Search\MeiliClient::class),
                $app->make(\App\Services\Search\BrandDetectionService::class)
            );
        });

        $this->app->singleton(\App\Services\Agent\Tools\ProductDetailsTool::class, function ($app) {
            return new \App\Services\Agent\Tools\ProductDetailsTool();
        });

        $this->app->singleton(\App\Services\Agent\Tools\DeduperTool::class, function ($app) {
            return new \App\Services\Agent\Tools\DeduperTool();
        });

        $this->app->singleton(\App\Services\Agent\Tools\AccessoryFilterTool::class, function ($app) {
            return new \App\Services\Agent\Tools\AccessoryFilterTool();
        });

        $this->app->singleton(\App\Services\Agent\Tools\AiRerankTool::class, function ($app) {
            return new \App\Services\Agent\Tools\AiRerankTool(
                $app->make(\App\Services\Ai\AiRouter::class)
            );
        });

        // Agent Orchestrator
        $this->app->singleton(\App\Services\Agent\AgentOrchestrator::class, function ($app) {
            return new \App\Services\Agent\AgentOrchestrator(
                $app->make(\App\Services\Ai\AiRouter::class),
                $app->make(\App\Services\Agent\Tools\MeiliProductSearchTool::class),
                $app->make(\App\Services\Agent\Tools\ProductDetailsTool::class),
                $app->make(\App\Services\Agent\Tools\DeduperTool::class),
                $app->make(\App\Services\Agent\Tools\AccessoryFilterTool::class),
                $app->make(\App\Services\Agent\Tools\AiRerankTool::class),
                $app->make(\App\Services\Horoshop\OrderSearchService::class),
                $app->make(\App\Services\Horoshop\DeliveryTrackingService::class),
                $app->make(\App\Services\Horoshop\HoroshopDataService::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
