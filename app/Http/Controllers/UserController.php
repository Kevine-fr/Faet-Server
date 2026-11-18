<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{

    public function setLocation(Request $request)
    {
        try {
            $data = $request->validate([
                'rue' => 'required|string',
                'ville' => 'required|string',
                'code_postal' => 'required|string',
            ]);

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => "Utilisateur non authentifié.",
                    'success' => false,
                ], 401);
            }

            DB::transaction(function () use ($user, $data) {
                $locationPayload = [
                    'rue' => $data['rue'],
                    'code_postal' => $data['code_postal'],
                    'ville' => $data['ville'],
                ];

                // Si l'utilisateur a déjà une relation customer, on met à jour, sinon on crée
                if ($user->customer) {
                    $user->customer->update($locationPayload);
                } else {
                    $user->customer()->create($locationPayload);
                }
            });

            
            return response()->json([
                'message' => 'Localisation enregistrée avec succès.',
                'success' => true,
                'error' => false,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            // log si besoin: \Log::error($e);
            return response()->json([
                'message' => "Une erreur est survenue lors de l'enregistrement de la localisation. Veuillez réessayer plus tard.",
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
