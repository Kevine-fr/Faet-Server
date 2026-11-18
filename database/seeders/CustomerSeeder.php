<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\User;
use App\Models\VendorSubscription;
use App\Models\Role;
use App\Models\Vendor;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Récupérer le premier vendor disponible
        $vendor = Vendor::first();
        
        if (!$vendor) {
            $this->command->error('Aucun vendor trouvé. Veuillez d\'abord créer un vendor.');
            return;
        }
        
        $vendorId = $vendor->id;
        $this->command->info('Utilisation du vendor ID: ' . $vendorId . ' (' . ($vendor->company_name ?: 'Sans nom') . ')');

        // Données des customers
        $customers = [
            [
                'first_name' => 'Jean',
                'last_name' => 'Dupont',
                'email' => 'jean.dupont.customer@example.com',
                'phone' => '0123456789',
                'birthdate' => '1990-01-15',
                'gender_preference' => 'homme'
            ],
            [
                'first_name' => 'Marie',
                'last_name' => 'Martin',
                'email' => 'marie.martin.customer@example.com',
                'phone' => '0987654321',
                'birthdate' => '1985-03-22',
                'gender_preference' => 'femme'
            ],
            [
                'first_name' => 'Pierre',
                'last_name' => 'Bernard',
                'email' => 'pierre.bernard.customer@example.com',
                'phone' => '0555666777',
                'birthdate' => '1992-07-10',
                'gender_preference' => 'homme'
            ],
            [
                'first_name' => 'Sophie',
                'last_name' => 'Petit',
                'email' => 'sophie.petit.customer@example.com',
                'phone' => '0444333222',
                'birthdate' => '1988-11-05',
                'gender_preference' => 'femme'
            ],
            [
                'first_name' => 'Lucas',
                'last_name' => 'Moreau',
                'email' => 'lucas.moreau.customer@example.com',
                'phone' => '0333222111',
                'birthdate' => '1995-09-18',
                'gender_preference' => 'homme'
            ],
            [
                'first_name' => 'Emma',
                'last_name' => 'Leroy',
                'email' => 'emma.leroy.customer@example.com',
                'phone' => '0222333444',
                'birthdate' => '1993-04-12',
                'gender_preference' => 'femme'
            ],
            [
                'first_name' => 'Thomas',
                'last_name' => 'Roux',
                'email' => 'thomas.roux.customer@example.com',
                'phone' => '0111222333',
                'birthdate' => '1987-08-25',
                'gender_preference' => 'homme'
            ],
            [
                'first_name' => 'Julie',
                'last_name' => 'Simon',
                'email' => 'julie.simon.customer@example.com',
                'phone' => '0999888777',
                'birthdate' => '1991-12-03',
                'gender_preference' => 'femme'
            ]
        ];

        $createdCount = 0;

        foreach ($customers as $customerData) {
            // Créer l'utilisateur d'abord
            $user = User::firstOrCreate(
                ['email' => $customerData['email']],
                [
                    'first_name' => $customerData['first_name'],
                    'last_name' => $customerData['last_name'],
                    'phone' => $customerData['phone'],
                    'password' => bcrypt('password123'),
                    'status' => 'active'
                ]
            );

            // Créer le customer associé
            $customer = Customer::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'birthdate' => $customerData['birthdate'],
                    'gender_preference' => $customerData['gender_preference']
                ]
            );

            // Créer l'abonnement au vendeur
            $subscription = VendorSubscription::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'vendor_id' => $vendorId
                ]
            );

            $createdCount++;
        }

        $this->command->info("{$createdCount} customers créés avec succès !");
        $this->command->info("Customers de test: [email]@example.com / password123");
    }
}
