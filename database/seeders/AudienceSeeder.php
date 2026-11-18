<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AudienceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
     public function run(): void
    {
        DB::table('audiences')->insert([
            ['name' => 'Femme',   'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Homme', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Enfants',   'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
