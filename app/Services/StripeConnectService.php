<?php

namespace App\Services;

use App\Models\Vendor;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;

class StripeConnectService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Créer un compte Stripe Connect Express pour un vendeur
     */
    public function createExpressAccount(Vendor $vendor, string $returnUrl = null, string $refreshUrl = null): array
    {
        try {
            // Vérifier si le vendeur a déjà un compte Stripe
            if ($vendor->vendor_stripe_id) {
                return [
                    'success' => false,
                    'error' => 'Le vendeur a déjà un compte Stripe Express configuré'
                ];
            }

            // Créer le compte Stripe Connect Express
            $account = Account::create([
                'type' => 'express',
                'country' => 'FR', // Par défaut France, peut être modifié
                'email' => $vendor->user->email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'individual', // Peut être modifié selon les besoins
                'metadata' => [
                    'vendor_id' => $vendor->id,
                    'platform' => config('app.name'),
                    'created_via' => 'platform_express_onboarding'
                ]
            ]);

            // Mettre à jour le vendeur avec l'ID du compte Stripe
            $vendor->update([
                'vendor_stripe_id' => $account->id,
                'stripe_express_status' => 'pending',
                'stripe_express_created_at' => now(),
            ]);

            Log::info('Compte Stripe Connect Express créé', [
                'vendor_id' => $vendor->id,
                'stripe_account_id' => $account->id,
                'email' => $vendor->user->email
            ]);

            // Créer le lien d'onboarding si les URLs sont fournies
            $onboardingUrl = null;
            if ($returnUrl && $refreshUrl) {
                $onboardingLink = $this->createOnboardingLink($vendor, $returnUrl, $refreshUrl);
                if ($onboardingLink['success']) {
                    $onboardingUrl = $onboardingLink['onboarding_url'];
                }
            }

            return [
                'success' => true,
                'account_id' => $account->id,
                'vendor_id' => $vendor->id,
                'onboarding_url' => $onboardingUrl
            ];

        } catch (Exception $e) {
            Log::error('Erreur création compte Stripe Connect Express', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Créer un lien d'onboarding Stripe Connect Express
     */
    public function createOnboardingLink(Vendor $vendor, string $returnUrl, string $refreshUrl): array
    {
        try {
            if (!$vendor->vendor_stripe_id) {
                return [
                    'success' => false,
                    'error' => 'Aucun compte Stripe Express associé à ce vendeur'
                ];
            }

            // Créer le lien d'onboarding Express
            $accountLink = AccountLink::create([
                'account' => $vendor->vendor_stripe_id,
                'return_url' => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type' => 'account_onboarding',
                'collect' => 'eventually_due', // Collecter les informations progressivement
            ]);

            Log::info('Lien onboarding Stripe Connect Express créé', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id,
                'link_id' => $accountLink->id
            ]);

            return [
                'success' => true,
                'onboarding_url' => $accountLink->url,
                'expires_at' => $accountLink->expires_at
            ];

        } catch (Exception $e) {
            Log::error('Erreur création lien onboarding Stripe Connect Express', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Créer un lien de connexion pour un compte Stripe Express existant
     */
    public function createLoginLink(Vendor $vendor): array
    {
        try {
            if (!$vendor->vendor_stripe_id) {
                return [
                    'success' => false,
                    'error' => 'Aucun compte Stripe Express associé à ce vendeur'
                ];
            }

            $loginLink = Account::createLoginLink($vendor->vendor_stripe_id);

            Log::info('Lien de connexion Stripe Connect Express créé', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id
            ]);

            return [
                'success' => true,
                'login_url' => $loginLink->url,
                'expires_at' => $loginLink->expires_at
            ];

        } catch (Exception $e) {
            Log::error('Erreur création lien de connexion Stripe Connect Express', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupérer le statut d'un compte Stripe Connect Express
     */
    public function getAccountStatus(Vendor $vendor): array
    {
        try {
            if (!$vendor->vendor_stripe_id) {
                return [
                    'success' => true,
                    'status' => [
                        'id' => null,
                        'charges_enabled' => false,
                        'payouts_enabled' => false,
                        'details_submitted' => false,
                        'requirements' => [],
                        'overall_status' => 'not_configured'
                    ]
                ];
            }

            $account = Account::retrieve($vendor->vendor_stripe_id);

            $status = [
                'id' => $account->id,
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
                'details_submitted' => $account->details_submitted,
                'requirements' => $account->requirements,
                'capabilities' => $account->capabilities,
                'business_profile' => $account->business_profile ?? null,
                'country' => $account->country,
                'email' => $account->email ?? null,
            ];

            // Déterminer le statut global
            if ($account->charges_enabled && $account->payouts_enabled) {
                $status['overall_status'] = 'verified';
            } elseif ($account->details_submitted) {
                $status['overall_status'] = 'pending';
            } else {
                $status['overall_status'] = 'incomplete';
            }

            Log::info('Statut compte Stripe Connect Express récupéré', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id,
                'status' => $status
            ]);
            
            return [
                'success' => true,
                'status' => $status
            ];

        } catch (Exception $e) {
            Log::error('Erreur récupération statut compte Stripe Connect Express', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mettre à jour le statut du vendeur basé sur le compte Stripe Express
     */
    public function updateVendorStatus(Vendor $vendor): array
    {
        try {
            if (!$vendor->vendor_stripe_id) {
                return [
                    'success' => false,
                    'error' => 'Aucun compte Stripe Express associé'
                ];
            }

            $accountStatus = $this->getAccountStatus($vendor);
            
            if (!$accountStatus['success']) {
                return $accountStatus;
            }

            $status = $accountStatus['status'];
            
            // Mettre à jour le statut du vendeur
            $vendor->update([
                'stripe_express_status' => $status['overall_status'],
                'stripe_express_requirements' => $status['requirements'],
                'stripe_express_verified_at' => $status['overall_status'] === 'verified' ? now() : null,
            ]);

            Log::info('Statut Stripe Connect Express vendeur mis à jour', [
                'vendor_id' => $vendor->id,
                'status' => $status['overall_status']
            ]);

            return [
                'success' => true,
                'status' => $status
            ];

        } catch (Exception $e) {
            Log::error('Erreur mise à jour statut vendeur Stripe Connect Express', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Supprimer un compte Stripe Connect Express
     */
    public function deleteAccount(Vendor $vendor): array
    {
        try {
            if (!$vendor->vendor_stripe_id) {
                return [
                    'success' => false,
                    'error' => 'Aucun compte Stripe Express associé'
                ];
            }

            $account = Account::retrieve($vendor->vendor_stripe_id);
            $account->delete();

            // Supprimer l'ID Stripe du vendeur
            $vendor->update([
                'vendor_stripe_id' => null,
                'stripe_express_status' => 'pending',
                'stripe_express_requirements' => null,
                'stripe_express_verified_at' => null,
            ]);

            Log::info('Compte Stripe Connect Express supprimé', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id
            ]);

            return [
                'success' => true,
                'message' => 'Compte Stripe Express supprimé avec succès'
            ];

        } catch (Exception $e) {
            Log::error('Erreur suppression compte Stripe Connect Express', [
                'vendor_id' => $vendor->id,
                'account_id' => $vendor->vendor_stripe_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Traiter les webhooks Stripe Connect Express
     */
    public function handleWebhook(string $eventType, array $data): array
    {
        try {
            switch ($eventType) {
                case 'account.updated':
                    return $this->handleAccountUpdated($data);
                
                case 'account.application.deauthorized':
                    return $this->handleAccountDeauthorized($data);
                
                default:
                    Log::info('Événement webhook Stripe Connect Express non géré', [
                        'event_type' => $eventType
                    ]);
                    return ['success' => true, 'message' => 'Événement non géré'];
            }
        } catch (Exception $e) {
            Log::error('Erreur traitement webhook Stripe Connect Express', [
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Gérer la mise à jour d'un compte Stripe
     */
    private function handleAccountUpdated(array $data): array
    {
        try {
            $accountId = $data['id'];
            $vendor = Vendor::where('vendor_stripe_id', $accountId)->first();

            if (!$vendor) {
                return ['success' => false, 'error' => 'Vendeur non trouvé'];
            }

            // Mettre à jour le statut du vendeur
            return $this->updateVendorStatus($vendor);

        } catch (Exception $e) {
            Log::error('Erreur traitement webhook account.updated', [
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gérer la désautorisation d'un compte Stripe
     */
    private function handleAccountDeauthorized(array $data): array
    {
        try {
            $accountId = $data['id'];
            $vendor = Vendor::where('vendor_stripe_id', $accountId)->first();

            if (!$vendor) {
                return ['success' => false, 'error' => 'Vendeur non trouvé'];
            }

            // Réinitialiser le compte Stripe du vendeur
            $vendor->update([
                'vendor_stripe_id' => null,
                'stripe_express_status' => 'pending',
                'stripe_express_requirements' => null,
                'stripe_express_verified_at' => null,
            ]);

            Log::info('Compte Stripe Connect Express désautorisé', [
                'vendor_id' => $vendor->id,
                'account_id' => $accountId
            ]);

            return ['success' => true, 'message' => 'Compte désautorisé'];

        } catch (Exception $e) {
            Log::error('Erreur traitement webhook account.application.deauthorized', [
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
