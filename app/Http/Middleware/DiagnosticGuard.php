<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the /api/diagnostic/* endpoints with three layers:
 *  1) Optional IP allowlist (DIAGNOSTIC_ALLOWED_IPS=1.2.3.4,5.6.7.8). If set,
 *     requests from any other IP get 404 (so scanners can't fingerprint).
 *  2) Shared secret via ?key= or Authorization: Bearer <key>. Must match
 *     services.diagnostic.secret_key and MUST not be the legacy default.
 *  3) Rate limit per IP (configurable, defaults to 30 req/min). Hitting the
 *     limit returns 429.
 *
 * If the secret is still set to the legacy default value, the guard blocks
 * every request to force rotation in prod.
 */
class DiagnosticGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.diagnostic.secret_key', '');

        // 1) Refuse to run when the secret is not configured. The shared key
        //    MUST be provided explicitly via DIAGNOSTIC_SECRET_KEY env var.
        if ($secret === '') {
            Log::warning('Diagnostic endpoint blocked: secret not rotated', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'diagnostic disabled'], 503);
        }

        // 2) Optional IP allowlist. When set, everything else gets 404.
        $allowed = array_filter(array_map('trim', explode(',', (string) config('services.diagnostic.allowed_ips', ''))));
        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            return response()->json(['error' => 'not found'], 404);
        }

        // 3) Secret check via query param or Authorization header.
        $provided = (string) ($request->query('key', '')
            ?: $request->header('X-Diagnostic-Key', '')
            ?: str_replace('Bearer ', '', (string) $request->header('Authorization', '')));

        if (! hash_equals($secret, $provided)) {
            Log::warning('Diagnostic endpoint: bad key', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        // 4) Rate limit per IP.
        $limit = (int) config('services.diagnostic.rate_limit_per_minute', 30);
        $key = 'diagnostic:'.sha1($request->ip().'|'.$provided);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'error' => 'rate limited',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
