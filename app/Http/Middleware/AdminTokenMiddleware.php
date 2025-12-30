<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to protect admin API endpoints with a token.
 * 
 * Token is set via ADMIN_API_TOKEN env variable.
 * Pass token in Authorization header: Bearer <token>
 */
class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.admin.api_token');
        
        if (empty($token)) {
            return response()->json([
                'error' => 'Admin API not configured',
            ], 500);
        }
        
        $providedToken = $request->bearerToken();
        
        if (!$providedToken || !hash_equals($token, $providedToken)) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }
        
        return $next($request);
    }
}
