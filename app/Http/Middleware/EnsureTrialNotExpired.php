<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that redirects to billing page if trial has expired.
 * 
 * Users with expired trial can still access:
 * - Billing page (to upgrade)
 * - Profile settings
 * - Logout
 */
class EnsureTrialNotExpired
{
    /**
     * Routes that are always accessible even with expired trial.
     */
    protected array $allowedRoutes = [
        'billing.*',
        'profile.*',
        'logout',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        
        if (!$user) {
            return $next($request);
        }

        $tenant = $user->tenant;
        
        if (!$tenant) {
            return $next($request);
        }

        // Check if trial expired and no active subscription
        if ($tenant->isTrialExpired() && !$tenant->activeSubscription()) {
            // Allow certain routes
            foreach ($this->allowedRoutes as $pattern) {
                if ($request->routeIs($pattern)) {
                    return $next($request);
                }
            }

            // Redirect to billing with warning
            return redirect()->route('billing.index')->with('warning', 
                'Ваш пробний період закінчився. Оберіть план, щоб продовжити користуватись сервісом.'
            );
        }

        return $next($request);
    }
}
