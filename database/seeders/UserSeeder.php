<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        DB::disableQueryLog(); // performance
        $total = 1000; // 50000 utilisateurs
        $batchSize = 500; // 1000 batches de 5000
        $roleIds = Role::pluck('id', 'name'); // récupère les rôles

       for ($i = 0; $i < $total / $batchSize; $i++) {
    $users = [];

    foreach (User::factory()->count($batchSize)->make() as $user) {
        if (!User::where('email', $user->email)->exists()) {
            $user->save();

            // Récupère les rôles
            $randomRoles = $roleIds->random(rand(1, 2))->values()->all();
            $user->roles()->attach($randomRoles);

            $users[] = $user; // Pour mémoire, mais pas forcément utile ici
        }
    }
}

    }
}
