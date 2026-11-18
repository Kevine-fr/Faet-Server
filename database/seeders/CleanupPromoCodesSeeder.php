<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CleanupPromoCodesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sauvegarde recommandée avant exécution (exécuter une dump avant)
        $this->command->info('Nettoyage des tables promo: attach_promo_codes, promo_code_product, promo_codes');

        // Utilise TRUNCATE ... CASCADE + restart identity pour PG
        DB::statement('TRUNCATE TABLE attach_promo_codes, promo_code_product, promo_codes RESTART IDENTITY CASCADE');

        $this->command->info('Tables vidées.');
    }
}
