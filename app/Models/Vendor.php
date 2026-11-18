<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;

class Vendor extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'user_id',
        'siret',
        'website_url',
        'description',
        'rue',
        'code_postal',
        'ville',
        'subscription_type',
        'subscription_area',
        'is_synchronized',
        'ecommerce_platform',
        'ecommerce_platform_version',
        'name_store',
        'access_token',
        'api_version',
        'last_synced_at',
        'is_notified',
        'is_notified_new_order',
        'is_notified_order_canceled',
        'is_notified_low_stock',
        'is_notified_app_maintenance',
        'is_notified_app_update',
        'vendor_stripe_id',
        'stripe_express_status',
        'stripe_express_requirements',
        'stripe_express_created_at',
        'stripe_express_verified_at',
        'preferred_payment_method',
        'store_location',
        'phone_number_store',
        'landline_phone_store',
    ];

    /**
     * Les attributs qui doivent être cachés lors de la sérialisation
     */
    protected $hidden = [
        'access_token',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'stripe_express_requirements' => 'array',
        'stripe_express_created_at' => 'datetime',
        'stripe_express_verified_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_synchronized' => 'boolean',
        'is_notified' => 'boolean',
        'is_notified_new_order' => 'boolean',
        'is_notified_order_canceled' => 'boolean',
        'is_notified_low_stock' => 'boolean',
        'is_notified_app_maintenance' => 'boolean',
        'is_notified_app_update' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function promoCodes()
    {
        return $this->hasMany(PromoCode::class);
    }

    public function shippingOptions()
    {
        return $this->hasMany(ShippingOption::class);
    }

    public function documents()
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function visitStatistics()
    {
        return $this->hasMany(VisitStatistic::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(VendorSubscription::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * On chiffre l'access_token après la synchronisation des produits
     */
    public function encryptAccessToken(): void
    {
        if ($this->access_token && !$this->isAccessTokenEncrypted()) {
            try {
                $encrypted = encrypt($this->access_token);
                $this->access_token = 'FaetSecure' . $encrypted;
                $this->save();

                Log::info('Access token chiffré avec succès', [
                    'vendor_id' => $this->id
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur chiffrement access_token', [
                    'vendor_id' => $this->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Vérifie si l'access_token est déjà chiffré
     */
    private function isAccessTokenEncrypted(): bool
    {
        // Le token commence par "FaetSecure"
        return str_starts_with($this->access_token, 'FaetSecure');
    }

    /**
     * Pour déchiffrer l'access_token pour les appels API
     *
     */
    public function getDecryptedAccessToken(): ?string
    {
        if (!$this->access_token) {
            return null;
        }

        try {
            if ($this->isAccessTokenEncrypted()) {
                // On enleve le préfixe "FaetSecure" avant de déchiffrer
                $encryptedToken = substr($this->access_token, 10); // 10 = longueur de "FaetSecure"
                return decrypt($encryptedToken);
            }
            return $this->access_token; // Déjà en texte brut
        } catch (\Exception $e) {
            Log::error('Erreur déchiffrement access_token', [
                'vendor_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Vérifier si le vendeur a un compte Stripe Express configuré
     */
    public function hasStripeExpressAccount(): bool
    {
        return !is_null($this->vendor_stripe_id);
    }

    /**
     * Vérifier si le compte Stripe Express est vérifié
     */
    public function isStripeExpressVerified(): bool
    {
        return $this->stripe_express_status === 'verified';
    }

    /**
     * Vérifier si le compte Stripe Express peut recevoir des paiements
     */
    public function canReceivePayments(): bool
    {
        return $this->hasStripeExpressAccount() && $this->isStripeExpressVerified();
    }

    /**
     * Obtenir le statut Stripe Express formaté
     */
    public function getStripeExpressStatusFormatted(): string
    {
        return match($this->stripe_express_status) {
            'pending' => 'En attente de vérification',
            'verified' => 'Vérifié et actif',
            'rejected' => 'Rejeté',
            'restricted' => 'Restreint',
            default => 'Non configuré'
        };
    }
}