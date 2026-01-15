<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that checks message limits for the current tenant.
 */
class CheckTenantLimitsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if no tenant
        if (!app()->bound('current_tenant')) {
            return $next($request);
        }

        $tenant = app('current_tenant');

        // Check message limit
        if (!$tenant->canSendMessage()) {
            return response()->json([
                'error' => 'Message limit exceeded',
                'usage' => [
                    'used' => $tenant->messages_used,
                    'limit' => $tenant->messages_limit,
                    'percentage' => $tenant->getUsagePercentage(),
                ],
                'message' => 'You have reached your monthly message limit. Please upgrade your plan.',
                'upgrade_url' => config('app.url') . '/billing/upgrade',
            ], 429);
        }

        // Process request
        $response = $next($request);

        // Increment usage counter after successful chat message
        if ($response->isSuccessful() && $this->shouldCountMessage($request)) {
            $tenant->incrementMessageUsage();
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
               !str_contains($path, '/chat/history') &&
               !str_contains($path, '/chat/sessions');
    }
}
