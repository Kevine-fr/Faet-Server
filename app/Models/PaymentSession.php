<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSession extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'payment_method',
        'total_amount',
        'orders_count',
        'encrypted_data',
        'status',
        'external_session_id',
        'external_payment_id',
        'expires_at',
        'processed_at',
        'error_message'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifier si la session est expirée
    */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Marquer comme traitée
    */

    public function markAsProcessed(string $paymentIntentId): void
    {
        $this->update([
            'status' => 'completed',
            'external_payment_id' => $paymentIntentId,
            'processed_at' => now()
        ]);
    }

    /**
     * Marquer comme échouée
    */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'processed_at' => now()
        ]);
    }

    /**
     * Scopes pour les requêtes communes
    */
    public function scopeActive($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '>', now());
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
