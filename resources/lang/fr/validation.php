<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'email' => 'Le champ :attribute doit être une adresse email valide.',
    'unique' => 'Cet :attribute est déjà utilisé.',
    'exists'    => 'Le :attribute n\'existe pas dans nos enregistrements.',

    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],

    'size' => [
        'string' => 'Le champ :attribute doit contenir exactement :size caractères.',  
    ],
    
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'in' => 'Le champ :attribute sélectionné est invalide.',

    'attributes' => [
        'email' => 'adresse email',
        'password' => 'mot de passe',
        'first_name' => 'prénom',
        'last_name' => 'nom de famille',
        'birthdate' => 'date de naissance',
        'phone' => 'numéro de téléphone',
        'gender_preference' => 'préférence de genre',
        'newsletter_enabled' => 'abonnement à la newsletter',
        'notification_enabled' => 'activation des notifications',
    ],
];
