<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',      // 👈 ДОДАЛИ ОЦЕ
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Admin routes (Livewire)
            \Illuminate\Support\Facades\Route::middleware(['web'])
                ->group(base_path('routes/admin.php'));

            // Contractor routes (separate auth)
            \Illuminate\Support\Facades\Route::middleware(['web'])
                ->group(base_path('routes/contractor.php'));
        },
    )
    ->withSchedule(function (Schedule $schedule) {
        // Синхронізація з Horoshop: щоденно о 3:00 UTC
        $schedule->job(new \App\Jobs\SyncHoroshopProductsJob)
            ->dailyAt('03:00')
            ->onSuccess(fn () => \Log::info('SyncHoroshopProductsJob completed'))
            ->onFailure(fn () => \Log::error('SyncHoroshopProductsJob failed'));

        // Побудова сценаріїв для рекомендацій: щоденно о 4:00
        $schedule->job(new \App\Jobs\GenerateCategoryScenariosJob)
            ->dailyAt('04:00')
            ->onSuccess(fn () => \Log::info('GenerateCategoryScenariosJob completed'))
            ->onFailure(fn () => \Log::error('GenerateCategoryScenariosJob failed'));

        // Генерація скриптів категорій: щотижня о 5:00 у понеділок
        $schedule->job(new \App\Jobs\GenerateCategoryScriptsJob)
            ->weeklyOn(1, '05:00')
            ->onSuccess(fn () => \Log::info('GenerateCategoryScriptsJob completed'))
            ->onFailure(fn () => \Log::error('GenerateCategoryScriptsJob failed'));

        // Перебудова індексу категорій: щоденно о 3:20
        $schedule->job(new \App\Jobs\RebuildCategoryIndexJob)
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
            'admin.token' => \App\Http\Middleware\AdminTokenMiddleware::class,
            'tenant' => \App\Http\Middleware\ResolveTenantMiddleware::class,
            'tenant.limits' => \App\Http\Middleware\CheckTenantLimitsMiddleware::class,
            'super-admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'trial.active' => \App\Http\Middleware\EnsureTrialNotExpired::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle CSRF token expiration (419) - redirect to login instead of showing error page
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            // For API requests - return JSON error
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'session_expired',
                    'message' => 'Сесія закінчилась. Будь ласка, оновіть сторінку.',
                ], 419);
            }

            // For web requests - redirect to login with message
            return redirect()->route('login')
                ->with('warning', 'Сесія закінчилась. Будь ласка, увійдіть знову.');
        });

        // Handle throttle (rate limit) exceptions with user-friendly message
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'type' => 'text',
                    'text' => 'Забагато запитів. Зачекайте кілька секунд і спробуйте ще раз 🙏',
                    'data' => null,
                    'session_id' => $request->input('session_id'),
                    'meta' => [
                        'error' => true,
                        'rate_limited' => true,
                        'retry_after' => $e->getHeaders()['Retry-After'] ?? 60,
                    ],
                ], 429);
            }
        });
    })
    ->create();
