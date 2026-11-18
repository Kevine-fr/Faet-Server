<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class IsVendor
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        // On s'assure que $user est bien une instance de User
        if (!$user instanceof User || !$user->roles()->where('name', 'vendeur')->exists()) {
            return response()->json([
                'message' => 'Accès refusé : vous devez être connecté en tant que vendeur.',
                'success' => false,
            ], 403);
        }
        return $next($request);
    }
} 