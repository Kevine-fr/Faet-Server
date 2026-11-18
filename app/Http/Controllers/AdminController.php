<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AdminService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use App\Http\Resources\UserResource;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AdminController extends Controller
{
    protected $adminService;

    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
    }

    /**
     * Liste des administrateurs (avec filtre dynamique)
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $status  = $request->get('status'); // ex: active / blocked

            $admins = $this->adminService->getAllAdmins($perPage, $status);

            return UserResource::collection($admins)
                ->additional([
                    'meta' => [
                        'current_page' => $admins->currentPage(),
                        'last_page'    => $admins->lastPage(),
                        'per_page'     => $admins->perPage(),
                        'total'        => $admins->total(),
                    ]
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer les administrateurs.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        try {
            $validated = $request->validate([
                'first_name'       => 'required|string|max:255',
                'last_name'        => 'required|string|max:255',
                'phone'            => ['required', 'string', 'regex:/^(?:\+?\d{1,3})?[0-9]{7,15}$/'],
                'email'            => 'required|email|unique:users,email',
                'password'         => 'required|string|min:8',
            ]);

            // Création de l'admin
            $admin = $this->adminService->createAdmin($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Admin créé avec succès.',
                'admin'   => $admin,
            ], 201);
        } catch (ValidationException $e) {
            // Erreur de validation
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation.',
                'errors' => $e->errors()
            ], 422);
        }

    }


    /**
     * Update the authenticated admin's profile.
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Exception
     * @throws \Throwable
     */
    public function updateMe(Request $request): JsonResponse
    {
        try{
            $user = Auth::user(); // utilisateur connecté

            $validated = $request->validate([
                // Identité
                'first_name' => ['sometimes', 'string', 'max:255'],
                'last_name'  => ['sometimes', 'string', 'max:255'],
                'email'      => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
                'phone'      => ['sometimes', 'string', 'max:20'],
                'profile_picture' => ['sometimes', 'string', 'max:255'],

                // Sécurité
                'password' => ['sometimes', 'string', 'min:8'],

                // Préférences
                'newsletter_enabled'   => ['sometimes', 'boolean'],
                'notification_enabled' => ['sometimes', 'boolean'],

                // Adresse
                'street'       => ['sometimes', 'string', 'max:255'],
                'postal_code'  => ['sometimes', 'string', 'max:20'],
                'city'         => ['sometimes', 'string', 'max:255'],
                'country'      => ['sometimes', 'string', 'max:255'],
            ]);

            $updatedUser = $this->adminService->updateUser($user, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Profil mis à jour avec succès.',
                'data' => new UserResource($user)
            ]);

        }catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du profil.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Search for admins by name or email.
     * @param Request $request
     *
     */
    public function block($id)
    {
        try {
            $user = $this->adminService->blockUser($id);
            return response()->json([
                'message' => 'Utilisateur bloqué avec succès',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reactivate a blocked user by ID.
     *
     */

    public function activate($id)
    {
        try {
            $user = $this->adminService->activateUser($id);
            return response()->json([
                'message' => 'Utilisateur réactivé avec succès',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }


    /**
     * Déconnexion de l'utilisateur (invalidation du token)
     */
    public function logout(Request $request)
    {
        try {
            // Récupère le token envoyé dans le header Authorization
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie'
            ], 200);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified admin.
     */
    public function show($id)
    {
        try {
            $admin = $this->adminService->findById($id);

            return response()->json([
                'success' => true,
                'data'    => new UserResource($admin),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Admin not found',
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the admin',
                'error'   => $e->getMessage(), // à retirer en prod si tu veux cacher les détails
            ], 500);
        }
    }


}