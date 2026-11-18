<?php
return [
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key' => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),
    'secure' => env('CLOUDINARY_SECURE', true),
    
    // Configuration pour les factures
    'folders' => [
        'invoices' => 'invoices',
        'documents' => 'documents'
    ],
    
    'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET', ''),
];

