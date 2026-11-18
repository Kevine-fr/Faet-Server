<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class VendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        $vendeurIds = User::whereHas('roles', fn($q) => $q->where('name', 'vendeur'))
            ->inRandomOrder()
            ->take(25)
            ->pluck('id');

        foreach ($vendeurIds as $userId) {
            $vendor = Vendor::firstWhere('user_id', $userId);

            if ($vendor) {
                // Si le vendor existe mais que name_store est null, on met à jour uniquement name_store
                if (is_null($vendor->name_store)) {
                    $vendor->update([
                        'name_store' => $faker->company,
                    ]);
                }
            } else {
                // Crée seulement si absent
                Vendor::create([
                    'user_id'           => $userId,
                    'name_store'        => $faker->company,
                    'siret'             => $faker->optional()->numerify('##############'),
                    'website_url'       => $faker->optional()->url,
                    'description'       => $faker->optional()->paragraph,
                    'subscription_type' => $faker->randomElement(['ville', 'region', 'pays']),
                    'subscription_area' => $faker->city,
                    'is_synchronized'   => $faker->boolean(10),
                ]);
            }
        }
    }
}