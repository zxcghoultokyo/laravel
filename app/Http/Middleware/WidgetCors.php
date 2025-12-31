<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WidgetCors
{
    public function handle(Request $request, Closure $next)
    {
        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        // Get the requesting origin
        $origin = $request->header('Origin');
        
        // Allow specific origin or wildcard for simple requests
        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } else {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Widget-Token, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
