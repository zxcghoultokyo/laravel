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
use App\Services\Support\FaqContentIngestService;

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

        // FAQ Content Ingest Service
        $this->app->singleton(FaqContentIngestService::class, function ($app) {
            return new FaqContentIngestService();
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

        // CrossSell Service
        $this->app->singleton(\App\Services\CrossSell\CrossSellService::class, function ($app) {
            return new \App\Services\CrossSell\CrossSellService(
                $app->make(AiRouter::class)
            );
        });

        // === NEW SERVICES ===

        // Session Context Service (unified session handling)
        $this->app->singleton(\App\Services\Session\SessionContextService::class, function ($app) {
            return new \App\Services\Session\SessionContextService();
        });

        // Color Service (dynamic color detection from DB)
        $this->app->singleton(\App\Services\Search\ColorService::class, function ($app) {
            return new \App\Services\Search\ColorService();
        });

        // === AGENT HANDLERS ===

        // FAQ Handler
        $this->app->singleton(\App\Services\Agent\Handlers\FaqHandler::class, function ($app) {
            return new \App\Services\Agent\Handlers\FaqHandler(
                $app->make(\App\Services\Ai\AiRouter::class)
            );
        });

        // SmallTalk Handler
        $this->app->singleton(\App\Services\Agent\Handlers\SmallTalkHandler::class, function ($app) {
            return new \App\Services\Agent\Handlers\SmallTalkHandler(
                $app->make(\App\Services\Ai\AiRouter::class)
            );
        });

        // OrderStatus Handler
        $this->app->singleton(\App\Services\Agent\Handlers\OrderStatusHandler::class, function ($app) {
            return new \App\Services\Agent\Handlers\OrderStatusHandler(
                $app->make(\App\Services\Horoshop\OrderSearchService::class),
                $app->make(\App\Services\Horoshop\DeliveryTrackingService::class)
            );
        });

        // Narrative Builder
        $this->app->singleton(\App\Services\Agent\Handlers\NarrativeBuilder::class, function ($app) {
            return new \App\Services\Agent\Handlers\NarrativeBuilder(
                $app->make(\App\Services\Ai\AiRouter::class),
                $app->make(\App\Services\Search\ColorService::class)
            );
        });

        // === AGENT TOOLS ===

        // Agent Orchestrator Tools
        $this->app->singleton(\App\Services\Agent\Tools\MeiliProductSearchTool::class, function ($app) {
            return new \App\Services\Agent\Tools\MeiliProductSearchTool(
                $app->make(\App\Services\Search\MeiliClient::class),
                $app->make(\App\Services\Search\BrandDetectionService::class),
                $app->make(\App\Services\Search\ColorService::class),
                $app->make(\App\Services\Search\QueryExpander::class)
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
                $app->make(\App\Services\Horoshop\HoroshopDataService::class),
                $app->make(\App\Services\Session\SessionContextService::class),
                $app->make(\App\Services\Search\ColorService::class),
                $app->make(\App\Services\Agent\Handlers\FaqHandler::class),
                $app->make(\App\Services\Agent\Handlers\SmallTalkHandler::class),
                $app->make(\App\Services\Agent\Handlers\OrderStatusHandler::class),
                $app->make(\App\Services\Agent\Handlers\NarrativeBuilder::class)
            );
        });

        // Escalation Service for human operator handover
        $this->app->singleton(\App\Services\Escalation\EscalationService::class, function ($app) {
            return new \App\Services\Escalation\EscalationService();
        });
    }

    public function boot(): void
    {
        //
    }
}
