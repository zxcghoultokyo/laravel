<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContractorAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('contractor_authenticated')) {
            return redirect()->route('contractor.login');
        }

        return $next($request);
    }
}
