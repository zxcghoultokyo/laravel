<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WidgetCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Дозволяємо всім доменам використовувати віджет
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Widget-Token, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
