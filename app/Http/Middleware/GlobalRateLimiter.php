<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GlobalRateLimiter
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        /**
         * ───────────────────────────────────────────────
         * 1️⃣  Contrôle de requêtes concurrentes (anti-bot)
         * ───────────────────────────────────────────────
         */
        $concurrentKey = "concurrent:{$ip}";
        $maxConcurrent = config('app.rate_limiting.global.concurrent_requests', 10);

        // Crée la clé si elle n'existe pas et incrémente
        $current = Cache::increment($concurrentKey);
        if ($current === 1) {
            Cache::put($concurrentKey, $current, now()->addSeconds(5)); // TTL
        }

        if ($current > $maxConcurrent) {
            Log::warning('Concurrent limit exceeded', [
                'ip' => $ip,
                'count' => $current,
                'path' => $request->path()
            ]);

            $this->decrementConcurrent($concurrentKey);
            return $this->buildResponse(
                'Trop de requêtes simultanées — ralentissez.',
                429,
                $maxConcurrent,
                2
            );
        }

        /**
         * ───────────────────────────────────────────────
         * 2️⃣  Contrôle global par IP (multi-niveau)
         * ───────────────────────────────────────────────
         */
        $key = "global:{$ip}";
        $limits = [
            ['max' => config('app.rate_limiting.global.per_second', 20), 'decay' => 1],
            ['max' => config('app.rate_limiting.global.per_minute', 100), 'decay' => 60],
            ['max' => config('app.rate_limiting.global.per_hour', 1000), 'decay' => 3600],
        ];

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($key . ':' . $limit['decay'], $limit['max'])) {
                $retryAfter = RateLimiter::availableIn($key . ':' . $limit['decay']);
                $this->decrementConcurrent($concurrentKey);

                Log::info('Rate limit exceeded', [
                    'ip' => $ip,
                    'window' => $limit['decay'],
                    'retry_after' => $retryAfter,
                ]);

                return $this->buildResponse(
                    "Trop de requêtes — réessayez dans {$retryAfter}s.",
                    429,
                    $limit['max'],
                    $retryAfter
                );
            }

            RateLimiter::hit($key . ':' . $limit['decay'], $limit['decay']);
        }

        /**
         * ───────────────────────────────────────────────
         * 3️⃣  Exécuter la requête réelle
         * ───────────────────────────────────────────────
         */
        try {
            $response = $next($request);
        } finally {
            $this->decrementConcurrent($concurrentKey);
        }

        // Ajouter des headers informatifs
        $response->headers->add([
            'X-RateLimit-Limit' => $limits[1]['max'],
            'X-RateLimit-Remaining' => RateLimiter::remaining($key . ':60', $limits[1]['max']),
        ]);

        return $response;
    }

    /**
     * Décrémente le compteur concurrent de manière sûre
     */
    protected function decrementConcurrent(string $key): void
    {
        try {
            $current = Cache::get($key, 0);
            if ($current > 0) {
                Cache::decrement($key);
            }
            if (Cache::get($key, 0) <= 0) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la décrémentation du compteur', [
                'key' => $key,
                'message' => $e->getMessage()
            ]);
        }
    }

    protected function buildResponse(string $message, int $status, int $limit, int $retryAfter): Response
    {
        return response()->json([
            'message' => $message,
            'error' => 'rate_limit_exceeded',
            'limit' => $limit,
            'retry_after' => $retryAfter
        ], $status, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => 0
        ]);
    }
}