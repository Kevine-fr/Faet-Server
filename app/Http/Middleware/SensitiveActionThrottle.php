<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class SensitiveActionThrottle
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->id ?? $request->ip();
        $key = "sensitive:{$userId}";
        $max = 10;
        $decay = 60;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json([
                'message' => 'Trop d’actions sensibles. Réessayez plus tard.',
                'retry_after' => RateLimiter::availableIn($key)
            ], 429);
        }

        RateLimiter::hit($key, $decay);
        return $next($request);
    }
}