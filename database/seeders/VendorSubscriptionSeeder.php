<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\VendorSubscription;
use App\Models\User;

class VendorSubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customerUsers = User::whereHas('customer')->get();

        $subscriptions = [
            [
                'user_id' => $customerUsers->get(0)->id ?? 6, // Jean Dupont
                'vendor_id' => 5,
                'subscription_date' => now()->subDays(30)
            ],
            [
                'user_id' => $customerUsers->get(1)->id ?? 7, // Marie Martin
                'vendor_id' => 5,
                'subscription_date' => now()->subDays(15)
            ],
            [
                'user_id' => $customerUsers->get(2)->id ?? 8, // Pierre Bernard
                'vendor_id' => 5,
                'subscription_date' => now()->subDays(7)
            ],
            [
                'user_id' => $customerUsers->get(3)->id ?? 9, // Sophie Petit
                'vendor_id' => 5,
                'subscription_date' => now()->subDays(3)
            ],
            [
                'user_id' => $customerUsers->get(4)->id ?? 10, // Lucas Moreau
                'vendor_id' => 5,
                'subscription_date' => now()->subDays(1)
            ]
        ];

        foreach ($subscriptions as $subscriptionData) {
            VendorSubscription::create([
                'user_id' => $subscriptionData['user_id'],
                'vendor_id' => $subscriptionData['vendor_id']
            ]);
        }

        $this->command->info('Vendor Subscriptions créés avec succès !');
    }
}
