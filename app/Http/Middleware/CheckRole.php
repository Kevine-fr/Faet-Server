<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['error' => 'Utilisateur non authentifié'], 401);
        }

        // On découpe les rôles possibles : "admin|vendeur" devient ["admin", "vendeur"]
        $roleList = explode('|', $roles);

        // On vérifie si l'utilisateur a au moins un des rôles
        $hasRole = $user->roles()->whereIn('name', $roleList)->exists();

        if (!$hasRole) {
            return response()->json(['error' => 'Accès interdit. Rôle requis : ' . implode(' ou ', $roleList)], 403);
        }

        return $next($request);
    }
}
