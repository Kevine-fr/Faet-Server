<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomVerifyEmail;
use App\Notifications\ForgotPassword;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthService
{
    /**
     * Récupère un client par email, ou lance une ValidationException.
     */
    public function getClientByEmail(string $email): User
    {
        $user = User::where('email', $email)->first();

        if (is_null($user)) {
            throw ValidationException::withMessages(['email' => ['Aucun compte ne correspond à cet email.']]);
        }
        return $user;
    }

    /**
     * Valide les données et crée un nouvel utilisateur.
     *
     * @param Request $request
     * @return User
     * @throws ValidationException
     */
    public function validateClientRegister(Request $request): User
    {
        $data = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'birthdate' => 'nullable|date',
            'phone' => 'required|string|unique:users,phone',
            'gender_preference' => ['required', Rule::in(['homme', 'femme', 'autre'])],
            'newsletter_enabled' => 'required|boolean',
            'notification_enabled' => 'required|boolean',
        ]);

        return DB::transaction(function () use ($data) {
            // Création de l'utilisateur
            $user = User::create([
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
                'newsletter_enabled' => $data['newsletter_enabled'],
                'notification_enabled' => $data['notification_enabled'],
            ]);

            // Création du client lié
            $user->customer()->create([
                'birthdate' => $data['birthdate'] ?? null,
                'gender_preference' => $data['gender_preference'],
            ]);

            // Affectation du rôle client
            $clientRole = Role::where('name', 'client')->first();
            if ($clientRole) {
                $user->roles()->attach($clientRole);
            }

            // Génération du code de vérification
            $code = strtoupper(Str::random(6));
            $expiresAt = Carbon::now()->addMinutes(5);

            $user->email_verification_code = $code;
            $user->email_verification_expires_at = $expiresAt;
            $user->save();

            // Envoi du code de vérification par email
            $user->notify(new CustomVerifyEmail());

            return $user;
        });
    }

    /**
     * Vérifie le code d'email, active le compte, et renvoie l'utilisateur.
     *
     * @param Request $request
     * @return User
     * @throws ValidationException
     */
    public function verifyCode(Request $request): User
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
            'code'  => 'required|string|size:6',
        ]);

        $user = $this->getClientByEmail($data['email']);

        if (
            ! $user ||
            $user->email_verification_code !== $data['code'] ||
            Carbon::now()->greaterThan($user->email_verification_expires_at)
        ) {
            throw ValidationException::withMessages([
                'code' => ['Le code est invalide ou a expiré.'],
            ]);
        }

        $user->update([
            'email_verified_at'             => Carbon::now(),
            'email_verification_code'       => null,
            'email_verification_expires_at' => null,
        ]);

        return $user;
    }

    /**
     * Renvoie un nouveau code de vérification.
     * Accepte soit un Request (flux GitHub), soit un User (flux local).
     *
     * @param Request|User $requestOrUser
     * @return void
     * @throws ValidationException
     */
    public function resendVerifiCode(Request|User $requestOrUser): void
    {
        if ($requestOrUser instanceof Request) {
            $data = $requestOrUser->validate([
                'email' => 'required|email|exists:users,email',
            ]);
            $user = $this->getClientByEmail($data['email']);

            if (!is_null($user->email_verified_at)) {
                throw ValidationException::withMessages(['email' => ['Adresse e-mail déjà confirmée.']]);
            }
        } else {
            // cas User fourni directement (route locale)
            $user = $requestOrUser;
            if (!is_null($user->email_verified_at)) {
                // Silencieux : rien à renvoyer (ou lever une ValidationException si tu préfères)
                throw ValidationException::withMessages(['email' => ['Adresse e-mail déjà confirmée.']]);
            }
        }

        // Générer un nouveau code
        $code = strtoupper(Str::random(6));
        $user->update([
            'email_verification_code'       => $code,
            'email_verification_expires_at' => Carbon::now()->addMinutes(5),
        ]);

        // Envoi du code
        $user->notify(new CustomVerifyEmail());
    }

    /**
     * Processus de connexion client : vérifications et token.
     *
     * @throws ValidationException
     */
    public function loginClient(Request $request): array
    {
        // Validation
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        // Existence
        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => ['Email ou mot de passe incorrect.']]);
        }

        // Mot de passe
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Email ou mot de passe incorrect.']]);
        }

        // Compte actif ?
        if ($user->status === 'blocked') {
            throw ValidationException::withMessages(['email' => ['Votre compte est bloqué. Veuillez contacter le support.']]);
        }

        // Email confirmé ?
        if (is_null($user->email_verified_at)) {
            $this->resendVerifiCode($request);
            throw ValidationException::withMessages(['email' => ['Adresse e-mail non confirmée. Un nouveau code vous a été envoyé par mail.']]);
        }

        // Génération du token
        $clientType = $request->header('X-Client-Type', 'web');
        [$responseData, $cookie] = $this->generateClientToken($user, $clientType);

        return ['response' => $responseData, 'cookie' => $cookie];
    }

    /**
     * Déconnecte le client : invalide le token si présent et supprime le cookie pour le web.
     *
     * @param Request $request
     * @return HttpCookie|null  // Cookie à retourner pour le client web, null pour mobile
     */
    public function logoutClient(Request $request)
    {
        // Récupère le token : d'abord l'Authorization Bearer, sinon dans le cookie 'access_token'
        $token = $request->bearerToken() ?: $request->cookie('access_token');
        $userId = null;

        if ($token) {
            try {
                try {
                    $payload = JWTAuth::setToken($token)->getPayload();
                    $userId = $payload->get('sub') ?? $payload->get('uid') ?? null;
                } catch (Exception $e) {
                    Log::info('logout: impossible de lire payload token', ['msg' => $e->getMessage()]);
                }

                // invalide le token (si blacklisting activé dans jwt.php)
                JWTAuth::setToken($token)->invalidate(true);
                Log::info('logout: token invalidé', ['user_id' => $userId]);
            } catch (TokenExpiredException $e) {
                Log::info('logout: token expiré', ['user_id' => $userId, 'msg' => $e->getMessage()]);
            } catch (TokenInvalidException $e) {
                Log::warning('logout: token invalide', ['user_id' => $userId, 'msg' => $e->getMessage()]);
            } catch (JWTException $e) {
                Log::error('logout: erreur JWT', ['user_id' => $userId, 'msg' => $e->getMessage()]);
            } catch (Exception $e) {
                Log::error('logout: erreur inattendue', ['msg' => $e->getMessage()]);
            }
        } else {
            Log::info('logout: aucun token fourni', ['ip' => $request->ip()]);
        }

        // Gérer la suppression du cookie pour le client web
        $clientType = $request->header('X-Client-Type', 'web');

        if (strtolower($clientType) === 'web') {
            // Cookie::forget crée un cookie expiré pour effacer le cookie côté client
            $expiredCookie = Cookie::forget('access_token');
            return $expiredCookie;
        }

        // Pour mobile (ou autres clients), pas de cookie à renvoyer
        return null;
    }

    /**
     * Demande de réinitialisation de mot de passe :
     * création/MAJ du token, envoi de la notification.
     *
     * @throws ValidationException
     */
    public function clientForgotPasswordRequestService(Request $request): void
    {
        // Validation
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        // Vérifier que l'utilisateur existe
        $user = $this->getClientByEmail($data['email']);
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Aucun compte n’est associé à cette adresse e-mail.'],
            ]);
        }

        // Générer un token JWT temporaire
        $token = JWTAuth::fromUser($user);

        // Stocker le token en base
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => $token,
                'created_at' => Carbon::now(),
                'expired_at' => Carbon::now()->addHour(),
            ]
        );

        // Construire l'URL de réinitialisation
        $clientWebUrl = config('app.client_web_url');
        $resetUrl = sprintf(
            '%s/auth/réinitilisation-de-mot-de-passe-oublié?token=%s&email=%s',
            rtrim($clientWebUrl, '/'),
            urlencode($token),
            urlencode($user->email)
        );

        // Envoyer la notification
        $user->notify(new ForgotPassword($resetUrl));
    }

    /**
     * Traitement de la réinitialisation du mot de passe via le token.
     *
     * @throws ValidationException
     */
    public function clientResetForgotPasswordService(Request $request): void
    {
        // Validation
        $data = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Rechercher le record de reset
        $record = DB::table('password_reset_tokens')
            ->where('token', $data['token'])
            ->first();

        if (!$record || Carbon::now()->greaterThan(Carbon::parse($record->expired_at))) {
            throw ValidationException::withMessages([
                'token' => ['Le lien de réinitialisation est invalide ou expiré.'],
            ]);
        }

        // Récupérer l'utilisateur
        $user = User::where('email', $record->email)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Utilisateur introuvable pour ce token.'],
            ]);
        }

        // Mettre à jour le mot de passe
        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        // Supprimer le token
        DB::table('password_reset_tokens')->where('email', $record->email)->delete();
    }

    /**
     * Changement de mot de passe pour un utilisateur authentifié
     *
     * @param Request $request
     * @param User $user Utilisateur authentifié récupéré depuis Auth::user()
     * @throws ValidationException
     */
    public function clientChangePasswordService(Request $request, User $user): void
    {
        // Validation des données
        $data = $request->validate([
            'actuel_password' => 'required|string',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/'
            ],
        ], [
            'actuel_password.required' => 'Le mot de passe actuel est obligatoire.',
            'password.regex' => 'Le mot de passe doit contenir au moins : 1 majuscule, 1 minuscule, 1 chiffre et 1 caractère spécial.',
        ]);

        // Vérifier que l'utilisateur n'est pas connecté via Google (pas de mot de passe local)
        if ($user->provider === 'google') {
            throw ValidationException::withMessages([
                'actuel_password' => ['Impossible de changer le mot de passe pour un compte Google sans mot de passe local.'],
            ]);
        }

        // Vérifier le mot de passe actuel
        if (!Hash::check($data['actuel_password'], $user->password)) {
            throw ValidationException::withMessages([
                'actuel_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        // Vérifier que le nouveau mot de passe est différent de l'actuel
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Le nouveau mot de passe doit être différent du mot de passe actuel.'],
            ]);
        }

        // Mettre à jour le mot de passe
        $user->update([
            'password' => Hash::make($data['password']),
        ]);
    }

    /**
     * Mise à jour du profil utilisateur et client associé
     *
     * @param Request $request
     * @param User $user Utilisateur authentifié récupéré depuis Auth::user()
     * @return User
     * @throws ValidationException
     */
    public function clientUpdateProfileService(Request $request, User $user): User
    {
        // Validation des données
        $data = $request->validate([
            // Données utilisateur
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user->id)
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('users', 'phone')->ignore($user->id)
            ],
            'phone_fixed' => 'nullable|string|max:20',
            'street' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:255',
            'newsletter_enabled' => 'nullable|boolean',
            'notification_enabled' => 'nullable|boolean',

            // Données client
            'birthdate' => 'nullable|date',
            'gender_preference' => ['nullable', Rule::in(['homme', 'femme', 'autre'])],

            // Adresse
            'rue' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:255',
            'code_postal' => 'nullable|string|max:10',

            // Informations de paiement
            'titulaire' => 'nullable|string|max:255',
            'numero_carte' => 'nullable|string|max:20',
            'expiration' => 'nullable|string|max:5',
            'cvc' => 'nullable|string|max:4',
        ]);

        return DB::transaction(function () use ($data, $user) {
            // Mise à jour des données utilisateur
            $user->update([
                'first_name' => $data['first_name'] ?? $user->first_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? $user->phone,
                'phone_fixed' => $data['phone_fixed'] ?? $user->phone_fixed,
                'street' => $data['street'] ?? $user->street,
                'postal_code' => $data['postal_code'] ?? $user->postal_code,
                'city' => $data['city'] ?? $user->city,
                'newsletter_enabled' => $data['newsletter_enabled'] ?? $user->newsletter_enabled,
                'notification_enabled' => $data['notification_enabled'] ?? $user->notification_enabled,
            ]);

            // Mise à jour ou création des données client
            $customerData = [];

            if (isset($data['birthdate'])) {
                $customerData['birthdate'] = $data['birthdate'];
            }

            if (isset($data['gender_preference'])) {
                $customerData['gender_preference'] = $data['gender_preference'];
            }

            if (isset($data['rue'])) {
                $customerData['rue'] = $data['rue'];
            }

            if (isset($data['ville'])) {
                $customerData['ville'] = $data['ville'];
            }

            if (isset($data['code_postal'])) {
                $customerData['code_postal'] = $data['code_postal'];
            }

            if (isset($data['titulaire'])) {
                $customerData['titulaire'] = $data['titulaire'];
            }

            if (isset($data['numero_carte'])) {
                $customerData['numero_carte'] = $data['numero_carte'];
            }

            if (isset($data['expiration'])) {
                $customerData['expiration'] = $data['expiration'];
            }

            if (isset($data['cvc'])) {
                $customerData['cvc'] = $data['cvc'];
            }

            // Mise à jour ou création du profil client
            if (!empty($customerData)) {
                if ($user->customer) {
                    $user->customer->update($customerData);
                } else {
                    $user->customer()->create($customerData);
                }
            }

            // Recharger l'utilisateur avec ses relations
            return $user->fresh(['customer', 'roles']);
        });
    }

    /**
     * Génère un JWT adapté au type de client et prépare la réponse.
     *
     * @param User   $user
     * @param string $clientType 'web' ou 'mobile'
     * @return array [responseData, Cookie|null]
     */
    public function generateClientToken(User $user, string $clientType): array
    {
        // configurer la durée de vie du token
        $ttlMinutes = strtolower($clientType) === 'mobile' ? 5256000 : 180;
        JWTAuth::factory()->setTTL($ttlMinutes);

        // Générer le token
        $token = JWTAuth::fromUser($user);

        // Préparer la réponse selon le client
        if (strtolower($clientType) === 'web') {          
            $cookie = cookie(
                'access_token',
                $token,
                $ttlMinutes,
                '/',
                null,
                true,
                true,
                false,
                'none'
            );

            Log::info("cookie", array($cookie));

            return [[
                'success' => true,
                'error'   => false,
            ], $cookie];
        }

        // Pour le mobile
        $responseData = [
            'success'      => true,
            'error'        => false,
            'access_token' => $token,
            'expires_in'   => $ttlMinutes * 60,
        ];

        return [$responseData, null];
    }

    /**
     * Gère la connexion via Google (Socialite) : liaison ou création d'utilisateur.
     *
     * @param  mixed   $socialUser  L'objet renvoyé par Socialite (->getId(), ->getEmail(), ->getRaw(), ...)
     * @param  \Illuminate\Http\Request $request
     * @return array  ['user' => User, 'token' => string, 'is_mobile' => bool]
     *
     * @throws \Illuminate\Validation\ValidationException on business errors
     * @throws \Exception on DB errors
     */
    public function handleGoogleUser($socialUser, Request $request): array
    {
        $isNew = false;
        $googleId = $socialUser->getId();
        $email = $socialUser->getEmail();
        $raw = $socialUser->getRaw() ?? [];
        $emailVerified = $raw['email_verified'] ?? null;

        // Récupérer prénom/nom depuis raw ou getName()
        $firstName = $raw['given_name'] ?? null;
        $lastName  = $raw['family_name'] ?? null;
        $fullName  = $socialUser->getName() ?? ($raw['name'] ?? null);
        if (!$firstName && $fullName) {
            $parts = preg_split('/\s+/', trim($fullName));
            $firstName = $parts[0] ?? null;
            array_shift($parts);
            $lastName = trim(implode(' ', $parts)) ?: null;
        }

        // 1) Cherche d'abord par google_id
        $user = User::where('google_id', $googleId)->first();

        // 2) Sinon, tenter par email (liaison compte existant)
        if (!$user && $email) {
            $user = User::where('email', $email)->first();

            if ($user) {
                if ($user->google_id && $user->google_id != $googleId) {
                    throw ValidationException::withMessages([
                        'email' => ['Cet email est déjà utilisé avec une autre méthode de connexion.'],
                    ]);
                }

                // Traiter provider === null comme compte "local" (email+mdp)
                // Considérer local si provider est null ou explicitement 'local'/'email'
                $isLocalAccount = is_null($user->provider) || in_array($user->provider, ['local', 'email', 'password']);

                if ($isLocalAccount) {
                    // Compte créé via email+mot de passe => refuser la connexion sociale
                    throw ValidationException::withMessages([
                        'email' => ['Cet email est déjà utilisé avec une autre méthode de connexion.'],
                    ]);
                }

                // Si provider existe et est différent de google (ex: 'facebook') => refuser aussi
                if (!empty($user->provider) && $user->provider != 'google') {
                    throw ValidationException::withMessages([
                        'email' => ['Cet email est déjà associé à une autre méthode de connexion.'],
                    ]);
                }

                if (empty($user->google_id)) {
                    if ($emailVerified) {
                        $user->google_id = $googleId;
                        $user->provider   = 'google';

                        // Mettre à jour les noms si disponibles et vides en DB
                        if (isset($user->first_name) && empty($user->first_name) && $firstName) {
                            $user->first_name = $firstName;
                        }
                        if (isset($user->last_name) && empty($user->last_name) && $lastName) {
                            $user->last_name = $lastName;
                        }

                        $user->save();
                    } else {
                        throw ValidationException::withMessages([
                            'email' => ['Votre compte Google n\'est pas vérifié. Veuillez vérifier votre compte Google.'],
                        ]);
                    }
                }
            }
        }

        // 3) Toujours pas d'utilisateur -> création
        if (!$user) {
            DB::beginTransaction();
            try {
                $user = User::create([
                    'email'            => $socialUser->getEmail(),
                    'password'         => bcrypt(Str::random(16)),
                    'email_verified_at'=> $emailVerified ? now() : null,
                    'provider'         => 'google',
                    'google_id'        => $socialUser->getId(),
                    'first_name'        => $firstName ?? null,
                    'last_name'         => $lastName ?? null,
                ]);

                if (method_exists($user, 'customer')) {
                    $user->customer()->create();
                }

                $clientRole = Role::where('name', 'client')->first();
                if ($clientRole) {
                    $user->roles()->attach($clientRole);
                }

                DB::commit();
                $isNew = true;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }

        // Génération du token
        $token = JWTAuth::fromUser($user);

        // Déterminer si le client est mobile
        $isMobile = $request->query('platform') === 'mobile' || $request->wantsJson();

        return [
            'user'      => $user,
            'token'     => $token,
            'is_mobile' => $isMobile,
            'is_new'    => $isNew,
        ];
    }

    /**
     * Connexion Admin.
     *
     * @param array $credentials
     * @return array
     * @throws ValidationException
     */
    public function alogin(array $credentials)
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email ou mot de passe invalide.'],
            ]);
        }

        // Vérifie si l'utilisateur a le rôle 'admin'
        if (!$user->hasRole('admin')) {
            throw ValidationException::withMessages([
                'role' => ['Seuls les administrateurs peuvent se connecter.'],
            ]);
        }

        // Vérifie que l'utilisateur est actif
        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => ['Votre compte est inactif ou bloqué.'],
            ]);
        }

        $token = Auth::guard('api')->login($user);

        return [
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => Auth::guard('api')->factory()->getTTL() * 60,
            'status'       => 200
        ];
    }

    // -------------------------------------------------------------------------
    // -----------------   FONCTIONS LOCALES AJOUTÉES EN BAS   -----------------
    // -------------------------------------------------------------------------

    /**
     * Inscription d'un vendeur (local).
     */
    public function validateVendorRegister(Request $request): User
    {
        $data = $request->validate([
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:8|confirmed',
            'first_name' => 'required|string',
            'last_name'  => 'required|string',
        ]);

        $user = User::create([
            'email'           => $data['email'],
            'password'        => Hash::make($data['password']),
            'first_name'      => $data['first_name'],
            'last_name'       => $data['last_name'],
            'phone'           => null,
            'profile_picture' => null,
        ]);

        // Créer profil vendeur + rôle vendeur
        $user->vendor()->create([]);
        $user->roles()->attach(2);

        // Code de vérification
        $code = strtoupper(Str::random(6));
        $expiresAt = Carbon::now()->addMinutes(5);
        $user->email_verification_code = $code;
        $user->email_verification_expires_at = $expiresAt;
        $user->save();

        $user->notify(new CustomVerifyEmail());

        return $user;
    }

    /**
     * Génère un token JWT pour un vendeur et renvoie les infos (local).
     */
    public function generateVendorToken(User $user): array
    {
        $ttlMinutes = 420 ;
        JWTAuth::factory()->setTTL($ttlMinutes);
        $token = JWTAuth::fromUser($user);

        return [
            'access_token' => $token,
            'expires_in'   => $ttlMinutes * 60,
        ];
    }
}