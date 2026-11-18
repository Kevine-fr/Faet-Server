<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AudienceSeeder::class,
            RoleSeeder::class,
            AdminSeeder::class,
            UserSeeder::class,
            VendorSeeder::class,
            // CustomerSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            // OrderSeeder::class,
            OrdersAdminSeeder::class,
            ProductVariantSeeder::class,
            TransactionsSeeder::class,
            ProductAudienceSeeder::class,   

            ProductImageSeeder::class,
            CategoryAudienceSeeder::class,
        ]);
    }
}
