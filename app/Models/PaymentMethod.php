<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    protected $fillable = [
        'vendor_id',
        'type',
        'name',
        'encrypted_config',
        'encryption_iv',
        'is_active',
        'is_default'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'encrypted_config',
        'encryption_iv'
    ];

    /**
     * Relation avec le vendeur
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Scope pour les méthodes actives
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour les méthodes par défaut
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope par type de paiement
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Vérifier si la méthode est de type carte
     */
    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    /**
     * Vérifier si la méthode est de type PayPal
     */
    public function isPayPal(): bool
    {
        return $this->type === 'paypal';
    }

    /**
     * Vérifier si la méthode est de type Stripe
     */
    public function isStripe(): bool
    {
        return $this->type === 'stripe';
    }

    /**
     * Obtenir le nom formaté du type
     */
    public function getTypeNameAttribute(): string
    {
        return match($this->type) {
            'card' => 'Carte bancaire',
            'paypal' => 'PayPal',
            'stripe' => 'Stripe',
            default => 'Inconnu'
        };
    }
}
