<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;

class PromoCode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'code',
        'name',
        'description',
        'type',
        'value',
        'min_order_amount',
        'max_uses',
        'used_count',
        'status',
        'valid_from',
        'valid_until',
        'is_active'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Relation avec le vendeur
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Relation many-to-many avec les produits via la table attach_promo_codes
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'attach_promo_codes', 'promo_code_id', 'product_id')
                    ->withPivot('final_price')
                    ->withTimestamps();
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'promo_code_id');
    }

    /**
     * Vérifier si le code promo est valide
     */
    public function isValid(): bool
    {
        if (!$this->is_active || $this->status !== 'active') {
            return false;
        }

        $now = Carbon::now();

        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Calculer la réduction
     */
    public function calculateDiscount(float $orderAmount): float
    {
        if ($this->min_order_amount && $orderAmount < $this->min_order_amount) {
            return 0;
        }

        if ($this->type === 'percentage') {
            return ($orderAmount * $this->value) / 100;
        }

        return min($this->value, $orderAmount);
    }

    /**
     * Mettre à jour le statut automatiquement
     */
    public function updateStatus(): void
    {
        $now = Carbon::now();

        // Si le code est désactivé manuellement, ne pas changer le statut
        if (!$this->is_active) {
            return;
        }

        // Recalculer le statut en fonction des dates
        if ($this->valid_until && $now->gt($this->valid_until)) {
            $this->update(['status' => 'expired']);
        } elseif ($this->valid_from && $now->lt($this->valid_from)) {
            $this->update(['status' => 'upcoming']);
        } else {
            // Si on est dans la période valide, le statut est "active"
            $this->update(['status' => 'active']);
        }
    }

    /**
     * Recalculer le statut lors de la réactivation
     */
    public function recalculateStatusOnReactivation(): void
    {
        $now = Carbon::now();

        // Recalculer le statut en fonction des dates actuelles
        if ($this->valid_until && $now->gt($this->valid_until)) {
            $this->update(['status' => 'expired']);
        } elseif ($this->valid_from && $now->lt($this->valid_from)) {
            $this->update(['status' => 'upcoming']);
        } else {
            // Si on est dans la période valide, le statut est "active"
            $this->update(['status' => 'active']);
        }
    }

    /**
     * Forcer le statut à inactive
     */
    public function forceInactive(): void
    {
        $this->update([
            'is_active' => false,
            'status' => 'inactive'
        ]);
    }

    /**
     * Incrémenter le compteur d'utilisation
     */
    public function incrementUsage(): void
    {
        $this->increment('used_count');
    }

    /**
     * Scope pour les codes actifs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('status', 'active');
    }

    /**
     * Scope pour les codes d'un vendeur
     */
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}
