<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        // ============================================
        // Protection pour les API : masquer les erreurs en production
        // ============================================
        if ($request->is('api/*')) {

            // En production, ne jamais exposer les détails techniques
            if (config('app.env') === 'production' || config('app.debug') === false) {

                // Erreurs de validation (422) : les laisser passer
                if ($exception instanceof \Illuminate\Validation\ValidationException) {
                    return parent::render($request, $exception);
                }

                // Erreurs d'authentification (401)
                if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json([
                        'message' => 'Non authentifié'
                    ], 401);
                }

                // Erreurs d'autorisation (403)
                if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return response()->json([
                        'message' => 'Action non autorisée'
                    ], 403);
                }

                // Erreur 404 (Route/Model not found)
                if ($exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                    $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'message' => 'Ressource introuvable'
                    ], 404);
                }

                // Rate limiting (429)
                if ($exception instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                    return response()->json([
                        'message' => 'Trop de requêtes. Veuillez ralentir.'
                    ], 429);
                }

                // Toutes les autres erreurs : message générique
                return response()->json([
                    'message' => 'Une erreur est survenue',
                    'error' => 'internal_server_error'
                ], 500);
            }
        }

        return parent::render($request, $exception);
    }
}