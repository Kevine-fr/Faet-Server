<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'vendor_id',
        'customer_id',
        'admin_id',
        'title',
        'message',
        'type',
        'priority',
        'metadata',
        'is_read',
        'read_at',
        'is_sent',
        'sent_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_read' => 'boolean',
        'is_sent' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec le vendeur
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Relation avec le client
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relation avec l'administrateur
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Scope pour les notifications non lues
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope pour les notifications d'un vendeur
     */
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Scope pour les notifications d'un utilisateur
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope pour les notifications d'un type spécifique
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Marquer la notification comme lue
     */
    public function markAsRead(): bool
    {
        return $this->update([
            'is_read' => true,
            'read_at' => now()
        ]);
    }

    /**
     * Marquer la notification comme envoyée
     */
    public function markAsSent(): bool
    {
        return $this->update([
            'is_sent' => true,
            'sent_at' => now()
        ]);
    }

    /**
     * Obtenir le texte de priorité formaté
     */
    public function getPriorityTextAttribute(): string
    {
        return match($this->priority) {
            'low' => 'Faible',
            'medium' => 'Moyenne',
            'high' => 'Élevée',
            'urgent' => 'Urgente',
            default => 'Moyenne'
        };
    }

    /**
     * Obtenir la classe CSS de priorité
     */
    public function getPriorityClassAttribute(): string
    {
        return match($this->priority) {
            'low' => 'priority-low',
            'medium' => 'priority-medium',
            'high' => 'priority-high',
            'urgent' => 'priority-urgent',
            default => 'priority-medium'
        };
    }

    /**
     * Obtenir l'icône selon le type
     */
    public function getTypeIconAttribute(): string
    {
        return match($this->type) {
            'order' => 'fa-shopping-cart',
            'order_canceled' => 'fa-times-circle',
            'low_stock' => 'fa-exclamation-triangle',
            'maintenance' => 'fa-tools',
            'update' => 'fa-arrow-up',
            'system' => 'fa-cog',
            'admin_message' => 'fa-user-shield',
            default => 'fa-bell'
        };
    }

    /**
     * Obtenir le texte du type formaté
     */
    public function getTypeTextAttribute(): string
    {
        return match($this->type) {
            'order' => 'Commande',
            'order_canceled' => 'Commande annulée',
            'low_stock' => 'Stock faible',
            'maintenance' => 'Maintenance',
            'update' => 'Mise à jour',
            'system' => 'Système',
            'admin_message' => 'Message administrateur',
            default => 'Notification'
        };
    }

    /**
     * Vérifier si la notification est récente (moins de 24h)
     */
    public function getIsRecentAttribute(): bool
    {
        return $this->created_at->isAfter(now()->subDay());
    }

    /**
     * Obtenir le temps écoulé depuis la création
     */
    public function getTimeAgoAttribute(): string
    {
        $diff = $this->created_at->diffForHumans();
        return $diff;
    }
}
