<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TitlePromotion;
use Carbon\Carbon;
use Faker\Factory as Faker;

class TitlePromotionSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $startDate = Carbon::today();

        for ($i = 1; $i <= 65; $i++) {
            $title = "Promotion " . $i;
            $description = $faker->sentence(6);

            // Chaque promo dure entre 3 et 7 jours
            $duration = rand(3, 7);

            $endDate = (clone $startDate)->addDays($duration);

            TitlePromotion::create([
                'title' => $title,
                'description' => $description,
                'sort_order' => $i,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ]);

            // La prochaine promo commence 1 jour après la précédente
            $startDate->addDays(1);
        }
    }
}