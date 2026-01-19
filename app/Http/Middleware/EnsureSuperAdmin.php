<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure user is Super Admin.
 * Returns 404 for non-super-admins to hide admin routes existence.
 */
class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->isSuperAdmin()) {
            // Return 404 to hide admin route existence from regular users
            abort(404);
        }

        return $next($request);
    }
}
