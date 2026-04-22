<?php

namespace App\Http\Middleware;

use App\Services\Usage\UsageTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that checks message limits for the current tenant.
 */
class CheckTenantLimitsMiddleware
{
    public function __construct(
        protected UsageTrackingService $usageService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if no tenant
        if (! app()->bound('current_tenant')) {
            return $next($request);
        }

        $tenant = app('current_tenant');

        // Block if trial expired and no active paid subscription.
        if (method_exists($tenant, 'canUseWidget') && ! $tenant->canUseWidget()) {
            return response()->json([
                'type' => 'error',
                'error' => 'subscription_required',
                'text' => 'Пробний період завершено. Оберіть тарифний план, щоб продовжити користуватися чатом.',
                'upgrade_url' => config('app.url').'/billing',
            ], 402);
        }

        // Check message limit
        if ($this->usageService->hasReachedLimit($tenant)) {
            $stats = $this->usageService->getUsageStats($tenant);

            return response()->json([
                'type' => 'error',
                'error' => 'limit_exceeded',
                'text' => 'Вибачте, ліміт повідомлень на цей місяць вичерпано. Зверніться до власника магазину.',
                'usage' => $stats['messages'],
                'upgrade_url' => config('app.url').'/billing',
            ], 429);
        }

        // Add usage warning header if near limit
        $response = $next($request);

        if ($this->usageService->isNearLimit($tenant)) {
            $remaining = $this->usageService->getRemainingMessages($tenant);
            $response->headers->set('X-Usage-Warning', "Near limit: {$remaining} messages remaining");
        }

        // Increment usage counter after successful chat message
        if ($response->isSuccessful() && $this->shouldCountMessage($request)) {
            $this->usageService->incrementMessages($tenant);
        }

        return $response;
    }

    /**
     * Check if this request should count as a message.
     */
    protected function shouldCountMessage(Request $request): bool
    {
        // Only count POST requests to chat endpoints
        if ($request->method() !== 'POST') {
            return false;
        }

        $path = $request->path();

        return str_contains($path, '/chat') &&
               ! str_contains($path, '/chat/history') &&
               ! str_contains($path, '/chat/sessions');
    }
}
