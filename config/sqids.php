<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alphabet personnalisé pour Sqids
    |--------------------------------------------------------------------------
    |
    | Vous pouvez personnaliser l'alphabet utilisé pour générer les Sqids.
    | Par défaut, on utilise un alphabet complet avec lettres et chiffres.
    |
    */
    'alphabet' => env('SQIDS_ALPHABET', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'),

    /*
    |--------------------------------------------------------------------------
    | Longueur minimale des Sqids
    |--------------------------------------------------------------------------
    |
    | La longueur minimale des Sqids générés. Plus cette valeur est élevée,
    | plus les Sqids seront longs mais aussi plus uniques.
    |
    */
    'min_length' => env('SQIDS_MIN_LENGTH', 20),
];

