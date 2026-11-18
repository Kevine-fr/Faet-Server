<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthRateLimiter
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = strtolower($request->input('email', 'unknown'));
        $ip = $request->ip();
        $key = "auth:{$email}|{$ip}";

        $maxAttempts = config('app.rate_limiting.auth.max_attempts', 5);
        $decay = config('app.rate_limiting.auth.decay_minutes', 1) * 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            Log::warning('Auth rate limit exceeded', [
                'email' => $email,
                'ip' => $ip,
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'message' => "Trop de tentatives. Réessayez dans {$retryAfter}s.",
                'error' => 'auth_rate_limit_exceeded',
                'retry_after' => $retryAfter
            ], 429);
        }

        RateLimiter::hit($key, $decay);

        $response = $next($request);

        // Si connexion réussie → reset le compteur
        if ($response->status() === 200) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}