<?php

use App\Http\Middleware\JwtFromCookie;
use \App\Http\Middleware\SecureHeadersMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Foundation\Configuration\Middleware;
use \App\Http\Middleware\GlobalRateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {
        // ============================================
        // RATE LIMITING GLOBAL (appliqué automatiquement)
        // ============================================

        // $middleware->api(prepend: [
        //     GlobalRateLimiter::class,
        // ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'jwt.auth' => Authenticate::class . ':api',
            'jwt.cookie' => JwtFromCookie::class,
            'secure.headers' => SecureHeadersMiddleware::class,

            // Middlewares de rate limiting
            'auth.throttle' => \App\Http\Middleware\AuthRateLimiter::class,
            // 'sensitive' => \App\Http\Middleware\SensitiveActionThrottle::class,

        ]);

        // ============================================
        // METRIQUES PROMETHEUS
        // ============================================
        // Compte et chronometre les requetes de l'API pour Grafana. En
        // "append" : le middleware s'execute apres les autres et mesure donc
        // la reponse reellement renvoyee (y compris les 401/422/500).
        $middleware->api(append: [
            \App\Http\Middleware\CollectMetrics::class,
        ]);

                // ============================================
        // TRUST PROXIES (Important pour Cloudflare/Nginx)
        // ============================================
        $middleware->trustProxies(at: ['*']);
    })
    // …
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
