<?php

// config/payment.php

return [
    
    /*
    |--------------------------------------------------------------------------
    | Frais de la plateforme
    |--------------------------------------------------------------------------
    |
    | Pourcentage prélevé par la plateforme sur chaque vente
    |
    */
    'platform_fee_rate' => env('PLATFORM_FEE_RATE', 0.07), // 5% par défaut
    
    /*
    |--------------------------------------------------------------------------
    | Devises par défaut
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('DEFAULT_CURRENCY', 'eur'),

    /*
    |--------------------------------------------------------------------------
    | Configuration PayPal
    |--------------------------------------------------------------------------
    */
    'paypal' => [
        'min_payout_amount' => 1.00, // Montant minimum pour un payout
        'max_payout_amount' => 9999.99, // Montant maximum pour un payout
        'payout_currency' => env('PAYPAL_PAYOUT_CURRENCY', 'EUR'),
        'batch_size' => 15000, // Taille max d'un batch de payouts PayPal
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Limites de paiement
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'min_amount' => 0.50, // Montant minimum en euros
        'max_amount' => 9999.99, // Montant maximum en euros
        'max_orders_per_payment' => 10, // Nombre max de commandes par paiement
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Devises supportées
    |--------------------------------------------------------------------------
    */
    'supported_currencies' => ['EUR', 'USD', 'CAD'],
    
    /*
    |--------------------------------------------------------------------------
    | Statuts de paiement
    |--------------------------------------------------------------------------
    */
    'payment_statuses' => [
        'pending' => 'En attente',
        'processing' => 'En cours',
        'complete' => 'Terminé',
        'failed' => 'Échoué',
        'refunded' => 'Remboursé',
        'cancelled' => 'Annulé',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Types de transaction
    |--------------------------------------------------------------------------
    */
    'transaction_types' => [
        'payment' => 'Paiement',
        'refund' => 'Remboursement', 
        'transfer' => 'Transfert',
        'fee' => 'Frais',
    ],
];