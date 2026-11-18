<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\UserResource;
use App\Http\Resources\AdminRessource;
use Laravel\Socialite\Facades\Socialite;

// Imports supplémentaires nécessaires pour tes fonctions locales (ajoutées en bas)
use App\Models\User;
use App\Notifications\ForgotPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Inscription d'un nouveau client
     *
     * Traite la demande d'inscription d'un client, valide les données,
     * crée l'utilisateur et envoie un email de vérification.
     *
     * @param Request $request Données d'inscription du client
     * @return \Illuminate\Http\JsonResponse Réponse avec statut de l'inscription
     */
    public function clientRegister(Request $request)
    {
        try {
            //validation, création de l'utilisateur et envoie du mail de vérification
            $user = $this->authService->validateClientRegister($request);

            return response()->json([
                'success'  => true,
                'data' => $user,
                'message' => 'Inscription réussie. Un code de vérification a été envoyé à votre adresse e-mail.'
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vérification du code d'email
     *
     * Vérifie le code de vérification envoyé par email et active le compte utilisateur.
     * Génère un token d'authentification si la vérification est réussie.
     *
     * @param Request $request Contient le code de vérification
     * @return \Illuminate\Http\JsonResponse Réponse avec token d'authentification si succès
     */
    public function verifyEmailCode(Request $request)
    {
        try {
            //validation et verification du code
            $user = $this->authService->verifyCode($request);

            //Génération du token et reponse
            [$responseData, $cookie] = $this->authService->generateClientToken(
                $user,
                $request->header('X-Client-Type', 'web')
            );

            $message = 'Adresse e-mail vérifiée avec succès.';
            return $cookie ?
                response()->json([
                    'message' => $message,
                    'data' => $responseData,
                ])->withCookie($cookie) :
                response()->json([
                    'message' => $message,
                    'data' => $responseData,
                ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Renvoyer le code de vérification (version GitHub)
     */
    public function clientResendVerifyEmailCode(Request $request)
    {
        try {
            $this->authService->resendVerifiCode($request);
            return response()->json([
                'message' => 'Un nouveau code de vérification a été envoyé à votre adresse e-mail.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données reçues sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Connexion d'un client
     *
     * Authentifie un client avec ses identifiants et génère un token d'accès.
     *
     * @param Request $request Données de connexion (email, mot de passe)
     * @return \Illuminate\Http\JsonResponse Réponse avec token d'authentification
     */
    public function clientLogin(Request $request)
    {
        try {
            ['response' => $data, 'cookie' => $cookie] = $this->authService->loginClient($request);
            return $cookie
                ? response()->json($data)->withCookie($cookie)
                : response()->json($data);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
            ], 500);
        }
    }

    public function clientLogout(Request $request)
    {
        try {
            // On attend que logoutClient retourne soit un Cookie (pour web) soit null (pour mobile)
            $cookie = $this->authService->logoutClient($request);

            return $cookie
                ? response()->json(['success' => true, 'message' => 'Déconnexion effectuée.'])->withCookie($cookie)
                : response()->json(['success' => true, 'message' => 'Déconnexion effectuée.']);
        } catch (Exception $e) {
            // Tu peux logger $e->getMessage() si tu veux
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la déconnexion.'
            ], 500);
        }
    }

    /**
     * Demande de réinitialisation de mot de passe
     *
     * Traite la demande de réinitialisation de mot de passe d'un client
     * et envoie un lien de réinitialisation par email.
     *
     * @param Request $request Contient l'email du client
     * @return \Illuminate\Http\JsonResponse Confirmation d'envoi du lien
     */
    public function clientForgotPasswordRequest(Request $request)
    {
        try {
            $this->authService->clientForgotPasswordRequestService($request);

            return response()->json([
                'message' => 'Un lien de réinitialisation vient de vous être envoyé par e-mail.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Réinitialisation du mot de passe oublié
     *
     * Traite la demande de réinitialisation de mot de passe avec le token reçu
     * et met à jour le mot de passe de l'utilisateur.
     *
     * @param Request $request Contient le token et le nouveau mot de passe
     * @return \Illuminate\Http\JsonResponse Confirmation de la réinitialisation
     */
    public function clientResetForgotPassword(Request $request)
    {
        try {
            $this->authService->clientResetForgotPasswordService($request);
            return response()->json([
                'message' => 'Votre mot de passe a bien été réinitialisé.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Changement de mot de passe pour un utilisateur authentifié
     *
     * Permet à un utilisateur connecté de changer son mot de passe
     * en fournissant son mot de passe actuel et le nouveau.
     *
     * @param Request $request Contient le mot de passe actuel et le nouveau
     * @return \Illuminate\Http\JsonResponse Confirmation du changement
     */
    public function clientChangePassword(Request $request)
    {
        try {
            $this->authService->clientChangePasswordService($request, Auth::user());

            return response()->json([
                'message' => 'Votre mot de passe a été modifié avec succès.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mise à jour du profil utilisateur
     *
     * Permet à un utilisateur authentifié de mettre à jour ses informations
     * personnelles et les données de son profil client associé.
     *
     * @param Request $request Données de mise à jour du profil
     * @return \Illuminate\Http\JsonResponse Confirmation de la mise à jour
     */
    public function clienUpdateProfile(Request $request)
    {
        try {
            $updatedUser = $this->authService->clientUpdateProfileService($request, Auth::user());

            return response()->json([
                'message' => 'Votre profil a été mis à jour avec succès.',
                'success' => true,
                'error' => false,
                'data' => new UserResource($updatedUser)
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Redirection vers l'URL de connexion Google
     *
     * Génère l'URL de connexion Google et redirige l'utilisateur vers cette URL.
     *
     * @return \Illuminate\Http\JsonResponse URL de connexion Google
     */
    public function clientRedirectToGoogle(Request $request)
    {
        // Recueillir le chemin demandÉ par le front
        $returnTo = $request->query('return_to', '/');


        // Valider : n'accepter que des chemins relatifs (ex: '/profile', '/products/12')
        if (!is_string($returnTo) || !str_starts_with($returnTo, '/')) {
            $returnTo = '/';
        }

        // Générer un state unique et stocker le mapping state->return_to en cache
        $state = Str::random(40);
        Cache::put('oauth_state:' . $state, ['return_to' => $returnTo], now()->addMinutes(5));

        $aa = Cache::get('oauth_state:' . $state);

        // Construire l'URL Google et y attacher le state (socialite stateless peut gérer l'état,
        // mais on l'ajoute explicitement pour être sûr)
        $googleRedirect = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        $separator = str_contains($googleRedirect, '?') ? '&' : '?';
        $googleRedirect .= $separator . 'state=' . urlencode($state);

        return redirect()->away($googleRedirect);
    }

    /**
     * Callback OAuth Google :
     * - récupère l'utilisateur Socialite
     * - délègue la logique de liaison/création au service AuthService
     * - renvoie soit JSON (mobile), soit redirection vers le front avec cookie (web)
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function clientHandleGoogleCallback(Request $request)
    {
        try {
            $state = $request->input('state');
            // récupérer le mapping (usage unique)
            $cacheKey = 'oauth_state:' . $state;
            $stateData = Cache::pull($cacheKey); // pull = read + delete

            // si absent -> on peut rediriger vers le front root (sécurité)
            $returnTo = '/';
            if (is_array($stateData) && !empty($stateData['return_to'])) {
                $rt = $stateData['return_to'];
                // sécurité: n'accepter que les chemins relatifs
                $returnTo = (is_string($rt) && str_starts_with($rt, '/')) ? $rt : '/';
                Log::info('Google auth callback state data', ['return_to' => $returnTo]);
            }

            $socialUser = Socialite::driver('google')->stateless()->user();
            $result = $this->authService->handleGoogleUser($socialUser, $request);

            $user = $result['user'];
            $token = $result['token'];
            $isMobile = $result['is_mobile'];

            if ($isMobile) {
                return response()->json([
                    'message' => 'Connexion réussie',
                    'success' => true,
                    'error' => false,
                    'token' => $token,
                    'first_time' => $result['is_new'] ?? false,
                ], 200);
            }

            // web: mettre en cookie
            $cookie = cookie('access_token', $token, 60, '/', null, true, true, false, 'none');

            $exchangeCode = Str::random(40);

            $frontendUrl = config('app.client_web_url');
            $redirectUrl = $frontendUrl . $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'code=' . urlencode($exchangeCode);

            // si nouvel utilisateur : ajouter cookie lisible pendant 2 minutes
            if(!empty($result['is_new'])){
                $firstTimeCookie = cookie('first_time', '1', 2, '/', '.byfaet.fr', true, false, false, 'none');
                return redirect()->away($redirectUrl)->withCookie($cookie)->withCookie($firstTimeCookie);
            }

            return redirect()->away($redirectUrl)->withCookie($cookie);

        } catch (ValidationException $e) {
            // Erreur métier (ex: conflit de méthode d'auth) -> rediriger vers front avec code d'erreur générique
            Log::info('Google auth validation error', ['errors' => $e->errors()]);
            $frontendUrl = rtrim(config('app.client_web_url'), '/');
            $errorCode = 'provider_account_conflict';
            $redirectUrl = $frontendUrl . '/auth/error?code=' . urlencode($errorCode);
            return redirect()->away($redirectUrl);
        } catch (\Exception $e) {
            // Erreur serveur -> logger et rediriger vers frontend erreur générique
            Log::error('Google auth exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $frontendUrl = rtrim(config('app.client_web_url'), '/');
            $errorCode = 'server_error';
            $redirectUrl = $frontendUrl . '/auth/error?code=' . urlencode($errorCode);
            return redirect()->away($redirectUrl);
        }
    }

    public function getAuthClient(Request $request)
    {
        $userId = Auth::id();
        $user = $userId ? User::with(['customer', 'roles', 'vendor'])->find($userId) : null;
        return new UserResource($user);
    }

    public function clientDeleteAccount(Request $request) {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
            }

            // Charger le customer lié (s'il existe)
            $customer = $user->customer;

            if ($customer) {
                // Soft-delete du customer (utilise SoftDeletes dans le model Customer)
                $customer->delete();
            }

            $user->orders()->delete();
            $user->favorites()->delete();
            // Soft delete du User
            $user->delete();

            // Déconnexion via ton service centralisé
            $cookie = $this->authService->logoutClient($request);

            return $cookie
            ? response()->json([
                'success' => true,
                'message' => 'Compte supprimé avec succès.'
            ])->withCookie($cookie)
            : response()->json([
                'success' => true,
                'message' => 'Compte supprimé avec succès.'
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la suppression du compte.',
                'error' => $e
            ], 500);
        }
    }

    /**
     * Permet à l'admin de se connecter
     */
    public function AdminLogin(Request  $request)
    {
        try {
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required'],
            ]);

            $result = $this->authService->alogin($credentials);

            return response()->json($result);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => '422',
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => '500',
                'message' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Get the authenticated User (version GitHub)
     */
    public function me(Request $request)
    {
        $user = Auth::guard('api')->user();

        Log::info("tes");
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Utilisateur non authentifié.'
            ], 401);
        }

        $user = $user ? User::with(['roles', 'vendor'])->find($user->id) : null;
        return response()->json([
            'status' => 'success',
            'message' => 'Utilisateur connecté.',
            'data' => new UserResource($user)
        ], 200);
    }

    // -------------------------------------------------------------------------
    // -----------------   FONCTIONS LOCALES AJOUTÉES EN BAS   -----------------
    // -------------------------------------------------------------------------

    /**
     * Renvoyer le code de vérification (ta version locale, autre route)
     */
    public function resendVerifiCode(Request $request)
    {
        try {
            $data = $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $user = User::where('email', $data['email'])->first();

            if (is_null($user->email_verified_at)) {
                $this->authService->resendVerifiCode($user);
                return response()->json([
                    'message' => 'Un nouveau code de vérification a été envoyé à votre adresse e-mail.',
                    'success' => true,
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Votre adresse e-mail est déjà vérifiée.',
                    'success' => false,
                    'error' => true
                ], 400);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Réinitialisation du mot de passe (ta version locale par token)
     */
    public function resetPassword(Request $request)
    {
        try {
            $data = $request->validate([
                'token' => 'required|string',
                'email' => 'required|email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // Vérifier que le token existe et n'est pas expiré
            $resetToken = DB::table('password_reset_tokens')
                ->where('email', $data['email'])
                ->where('token', $data['token'])
                ->where('expired_at', '>', now())
                ->first();

            if (!$resetToken) {
                return response()->json([
                    'message' => 'Le lien de réinitialisation est invalide ou a expiré.',
                    'success' => false,
                    'error' => true
                ], 400);
            }

            // Mettre à jour le mot de passe
            $user = User::where('email', $data['email'])->first();
            if (!$user) {
                return response()->json([
                    'message' => 'Utilisateur non trouvé.',
                    'success' => false,
                    'error' => true
                ], 404);
            }

            $user->password = Hash::make($data['password']);
            $user->save();

            // Supprimer le token utilisé
            DB::table('password_reset_tokens')
                ->where('email', $data['email'])
                ->delete();

            return response()->json([
                'message' => 'Votre mot de passe a été réinitialisé avec succès.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la réinitialisation du mot de passe.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vérification de la validité d'un token de réinitialisation (local)
     */
    public function verifyResetToken(Request $request)
    {
        try {
            $data = $request->validate([
                'token' => 'required|string',
                'email' => 'required|email',
            ]);

            $resetToken = DB::table('password_reset_tokens')
                ->where('email', $data['email'])
                ->where('token', $data['token'])
                ->where('expired_at', '>', now())
                ->first();

            if (!$resetToken) {
                return response()->json([
                    'message' => 'Le lien de réinitialisation est invalide ou a expiré.',
                    'success' => false,
                    'error' => true
                ], 400);
            }

            return response()->json([
                'message' => 'Token valide.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Changement de mot de passe (local, sur /changePassword)
     */
    public function changePassword(Request $request)
    {
        try {
            $user = $request->user();

            $data = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            // Vérifier le mot de passe actuel
            if (!Hash::check($data['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Le mot de passe actuel est incorrect.',
                    'success' => false,
                    'error' => true
                ], 422);
            }

            // Mettre à jour le mot de passe
            $user->password = Hash::make($data['new_password']);
            $user->save();

            return response()->json([
                'message' => 'Votre mot de passe a été modifié avec succès.',
                'success' => true,
                'error' => false
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors du changement de mot de passe.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Inscription d'un vendeur (local)
     */
    public function vendorRegister(Request $request)
    {
        try {
            $user = $this->authService->validateVendorRegister($request);
            return response()->json([
                'success'  => true,
                'data' => $user,
                'message' => 'Inscription vendeur réussie.'
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Connexion d'un vendeur (local)
     */
    public function vendorLogin(Request $request)
    {
        try {
            $data = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:8',
            ]);

            $user = User::where('email', $data['email'])->first();

            // Vérifier d'abord l'existence de l'utilisateur
            if (!$user) {
                return response()->json([
                    'message' => 'Email ou mot de passe incorrect.',
                    'success' => false,
                    'error' => true
                ], 401);
            }

            // Vérifier que c'est un vendeur
            if (!$user->vendor) {
                return response()->json([
                    'message' => 'Email ou mot de passe incorrect, ou vous n\'êtes pas vendeur.',
                    'success' => false,
                    'error' => true
                ], 401);
            }

            // Vérification du rôle 'vendeur'
            if (!$user->roles()->where('name', 'vendeur')->exists()) {
                return response()->json([
                    'message' => 'Accès refusé : vous n\'êtes pas vendeur.',
                    'success' => false,
                    'error' => true
                ], 403);
            }

            // Vérifier le mot de passe AVANT de vérifier l'email
            if (!Hash::check($data['password'], $user->password)) {
                return response()->json([
                    'message' => 'Email ou mot de passe incorrect.',
                    'success' => false,
                    'error' => true
                ], 401);
            }

            // Maintenant vérifier si l'email est vérifié
            if (is_null($user->email_verified_at)) {
                $this->authService->resendVerifiCode($user);
                return response()->json([
                    'message' => 'Votre adresse e-mail n\'est pas encore confirmée. Un nouveau code de vérification vous a été envoyé.',
                    'success' => false,
                    'error' => true
                ], 403);
            }

            // Vérifier que le compte est actif
            if ($user->status !== 'active') {
                return response()->json([
                    'message' => 'Votre compte est désactivé. Veuillez contacter le support.',
                    'success' => false,
                    'error' => true
                ], 403);
            }

            // Génération du token vendeur
            $clientType = $request->header('X-Client-Type', 'web');
            $tokenData = $this->authService->generateVendorToken($user, $clientType);

            $message = 'Connexion vendeur réussie.';
            $userData = [
                'first_name'   => $user->first_name,
                'last_name'    => $user->last_name,
                'email'        => $user->email,
                'access_token' => $tokenData['access_token'] ?? null,
                'expires_in'   => $tokenData['expires_in'] ?? null,
            ];

            return response()->json([
                'message' => $message,
                'data' => $userData,
                'success' => true,
                'error' => false
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue. Veuillez réessayer plus tard.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère les informations d'un utilisateur par son ID (local)
     */
    public function getUser($id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json([
                    'message' => 'Utilisateur non trouvé.',
                    'success' => false,
                ], 404);
            }
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'status' => $user->status,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la récupération des informations.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Met à jour le profil de l'utilisateur connecté (local)
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            // Validation des données
            $validatedData = $request->validate([
                'first_name' => 'sometimes|string|max:255',
                'last_name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20',
                'street' => 'sometimes|nullable|string|max:255',
                'postal_code' => 'sometimes|nullable|string|max:10',
                'city' => 'sometimes|nullable|string|max:255',
                'country' => 'sometimes|nullable|string|max:2',
                'current_password' => 'required_with:new_password|string',
                'new_password' => 'sometimes|string|min:8|confirmed',
            ]);

            // Nettoyer les champs vides
            foreach (['street', 'postal_code', 'city', 'country'] as $field) {
                if (isset($validatedData[$field]) && (empty($validatedData[$field]) || $validatedData[$field] === '')) {
                    $validatedData[$field] = null;
                }
            }

            // Vérifier le mot de passe actuel si un nouveau mot de passe est fourni
            if (isset($validatedData['new_password'])) {
                if (!Hash::check($validatedData['current_password'], $user->password)) {
                    return response()->json([
                        'message' => 'Le mot de passe actuel est incorrect.',
                        'success' => false,
                    ], 422);
                }

                // Mettre à jour le mot de passe
                $user->password = Hash::make($validatedData['new_password']);

                // Supprimer les champs de mot de passe des données validées
                unset($validatedData['current_password'], $validatedData['new_password']);
            }

            // Mettre à jour les autres champs
            $user->fill($validatedData);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profil mis à jour avec succès.',
                'data' => [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'street' => $user->street,
                    'postal_code' => $user->postal_code,
                    'city' => $user->city,
                    'country' => $user->country,
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données saisies sont invalides. Veuillez vérifier et réessayer.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour du profil.',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
