<?php
/**
 * SOLUTION 1 : Rate Limiting Global via RouteServiceProvider
 * Fichier : app/Providers/RouteServiceProvider.php
 */

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Le namespace pour les controllers de l'application
     */
    public const HOME = '/home';

    /**
     * Configuration du rate limiting
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configuration des rate limiters
     */
    protected function configureRateLimiting()
    {

        RateLimiter::for('payment-api', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
                Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        // ============================================
        // RATE LIMITER GLOBAL : 20 requêtes par seconde
        // ============================================
        // RateLimiter::for('api', function (Request $request) {
        //     return Limit::perSecond(20)
        //         ->by($request->ip())
        //         ->response(function (Request $request, array $headers) {
        //             return response()->json([
        //                 'message' => 'Trop de requêtes. Ralentissez.',
        //                 'error' => 'rate_limit_exceeded'
        //             ], 429, $headers);
        //         });
        // });

        // ============================================
        // RATE LIMITER STRICT POUR LE LOGIN : 5 par minute
        // ============================================
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip() . '|' . $request->input('email'))
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Trop de tentatives de connexion. Réessayez dans 1 minute.',
                        'error' => 'too_many_login_attempts'
                    ], 429, $headers);
                });
        });

        // ============================================
        // RATE LIMITER POUR LES ACTIONS SENSIBLES : 10 par minute
        // ============================================
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Action limitée. Attendez avant de réessayer.',
                        'error' => 'action_rate_limit'
                    ], 429, $headers);
                });
        });

        // ============================================
        // RATE LIMITER POUR LA RECHERCHE : 60 par minute
        // ============================================
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip());
        });
    }
}
