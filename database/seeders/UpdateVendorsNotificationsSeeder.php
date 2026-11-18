<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateVendorsNotificationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Mettre à jour tous les vendeurs existants avec les nouvelles colonnes de notifications
        DB::table('vendors')->update([
            'is_notified' => true,
            'is_notified_new_order' => true,
            'is_notified_order_canceled' => true,
            'is_notified_low_stock' => true,
            'is_notified_app_maintenance' => false,
            'is_notified_app_update' => true,
        ]);

        $this->command->info('Préférences de notifications mises à jour pour tous les vendeurs existants.');
    }
}
