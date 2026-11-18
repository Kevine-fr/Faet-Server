<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        // 1. Créer le rôle admin s’il n’existe pas
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // 2. Créer l’utilisateur admin
        $adminUser = User::updateOrCreate(
            ['email' => 'glennleo242@gmail.com'],
            [
                'first_name' => 'Glenn',
                'last_name' => 'Leonard',
                'phone' => '+221772266420',
                'email_verified_at' => now(),
                'status' => 'active',
                'password' => Hash::make('Passer123@'), // mot de passe à changer ensuite
            ]
        );

        // 3. Attacher le rôle admin
        $adminUser->roles()->syncWithoutDetaching([$adminRole->id]);

        // Log en console
        $this->command->info('Utilisateur admin créé avec succès !');
    }
}