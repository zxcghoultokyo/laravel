<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',      // 👈 ДОДАЛИ ОЦЕ
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Синхронізація з Horoshop: щоденно о 3:00 UTC
        $schedule->job(new \App\Jobs\SyncHoroshopProductsJob())
            ->dailyAt('03:00')
            ->onSuccess(fn () => \Log::info('SyncHoroshopProductsJob completed'))
            ->onFailure(fn () => \Log::error('SyncHoroshopProductsJob failed'));

        // Побудова сценаріїв для рекомендацій: щоденно о 4:00
        $schedule->job(new \App\Jobs\GenerateCategoryScenariosJob())
            ->dailyAt('04:00')
            ->onSuccess(fn () => \Log::info('GenerateCategoryScenariosJob completed'))
            ->onFailure(fn () => \Log::error('GenerateCategoryScenariosJob failed'));

        // Генерація скриптів категорій: щотижня о 5:00 у понеділок
        $schedule->job(new \App\Jobs\GenerateCategoryScriptsJob())
            ->weeklyOn(1, '05:00')
            ->onSuccess(fn () => \Log::info('GenerateCategoryScriptsJob completed'))
            ->onFailure(fn () => \Log::error('GenerateCategoryScriptsJob failed'));

        // Перебудова індексу категорій: щоденно о 3:20
        $schedule->job(new \App\Jobs\RebuildCategoryIndexJob())
            ->dailyAt('03:20')
            ->onSuccess(fn () => \Log::info('RebuildCategoryIndexJob completed'))
            ->onFailure(fn () => \Log::error('RebuildCategoryIndexJob failed'));

        // Переіндексація Meilisearch (чанки): щоденно о 3:30 (після sync)
        $schedule->job(new \App\Jobs\IndexProductsToMeiliJob(500))
            ->dailyAt('03:30')
            ->onSuccess(fn () => \Log::info('IndexProductsToMeiliJob completed'))
            ->onFailure(fn () => \Log::error('IndexProductsToMeiliJob failed'));
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Додаємо CORS для віджета
        $middleware->alias([
            'widget.cors' => \App\Http\Middleware\WidgetCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
