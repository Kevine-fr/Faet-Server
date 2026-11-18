<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
            // user_id sera assigné manuellement pour éviter doublons ou collisions
            'company_name' => $this->faker->company(),
            'siret' => $this->faker->optional()->numerify('##############'), // 14 chiffres
            'website_url' => $this->faker->optional()->url(),
            'description' => $this->faker->optional()->paragraph(),
            'subscription_type' => $this->faker->optional()->randomElement(['ville', 'region', 'pays']),
            'subscription_area' => $this->faker->city(),
            'is_synchronized' => $this->faker->boolean(30),
            'ecommerce_platform' => $this->faker->optional()->randomElement(['shopify', 'woocommerce', 'prestashop', 'magento']),
            'ecommerce_platform_version' => $this->faker->optional()->randomElement(['2024-01', '2023-10', '2023-07']),
            'name_store' => $this->faker->optional()->company(),
            'access_token' => $this->faker->optional()->uuid(),
            'api_version' => $this->faker->optional()->randomElement(['2024-01', '2023-10', '2023-07']),
            'last_synced_at' => $this->faker->optional()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
