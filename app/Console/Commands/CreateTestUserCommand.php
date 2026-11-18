<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateTestUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create:test-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Créer un utilisateur de test avec un token d\'authentification';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Créer l'utilisateur
            $user = User::create([
                'name' => 'Test Vendor',
                'email' => 'test@vendor.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => 'vendeur'
            ]);

            // Créer le vendeur associé
            $vendor = Vendor::create([
                'user_id' => $user->id,
                'company_name' => 'Test Company',
                'siret' => '12345678901234',
                'website_url' => 'https://test.com',
                'description' => 'Test vendor for API testing',
                'subscription_type' => 'basic',
                'subscription_area' => 'local',
                'is_synchronized' => false,
                'ecommerce_platform' => 'shopify',
                'ecommerce_platform_version' => '2023-01',
                'name_store' => 'Test Store',
                'access_token' => 'test_token',
                'api_version' => '2023-01',
                'last_synced_at' => now(),
                'is_notified' => true,
                'is_notified_new_order' => true,
                'is_notified_order_canceled' => true,
                'is_notified_low_stock' => true,
                'is_notified_app_maintenance' => false,
                'is_notified_app_update' => true,
            ]);

            // Créer un token d'accès personnel
            $token = $user->createToken('test-token')->plainTextToken;

            $this->info('✅ Utilisateur de test créé avec succès !');
            $this->info('📧 Email: test@vendor.com');
            $this->info('🔑 Mot de passe: password');
            $this->info('🔐 Token: ' . $token);
            $this->info('🏢 ID Vendeur: ' . $vendor->id);
            $this->info('');
            $this->info('💡 Pour tester l\'API, utilisez ce token dans l\'en-tête Authorization:');
            $this->info('   Authorization: Bearer ' . $token);

        } catch (\Exception $e) {
            $this->error('❌ Erreur lors de la création de l\'utilisateur de test: ' . $e->getMessage());
        }
    }
}
