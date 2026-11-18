<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtFromCookie
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = null;
        $source = null;

        // 1) Si header Authorization présent -> on l'utilise (priorité)
        $authHeader = $request->header('Authorization');
        if ($authHeader) {
            // support "Bearer <token>" ou token brut
            if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = trim($authHeader);
            }
            $source = 'header';
        }
        // 2) Sinon, si cookie access_token présent -> on l'utilise
        elseif ($request->cookies->has('access_token')) {
            $token = $request->cookie('access_token');
            if ($token) {
                // on met l'en-tête pour que d'autres middleware / libs s'attendent au header
                $request->headers->set('Authorization', 'Bearer ' . $token);
                $source = 'cookie';
            }
        }

        if ($token) {
            try {
                $user = JWTAuth::setToken($token)->toUser();

                if ($user) {
                    auth()->setUser($user);
                }
            } catch (TokenExpiredException $e) {
                // Token expiré : laisser en invité.
                Log::info('JWT token expired in JwtFromCookie middleware.', [
                    'source' => $source,
                    'message' => $e->getMessage(),
                    'ip' => $request->ip(),
                    'route' => $request->path(),
                ]);
            } catch (JWTException $e) {
                // Token invalide ou autre problème JWT : laisser en invité.
                Log::warning('Invalid JWT in JwtFromCookie middleware.', [
                    'source' => $source,
                    'message' => $e->getMessage(),
                    'ip' => $request->ip(),
                    'route' => $request->path(),
                ]);
            } catch (\Throwable $e) {
                // Sécurité : capturer toute autre erreur pour empêcher un 500.
                Log::error('Unexpected error in JwtFromCookie middleware.', [
                    'source' => $source,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $next($request);
    }
}
