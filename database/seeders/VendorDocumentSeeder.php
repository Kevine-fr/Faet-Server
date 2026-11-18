<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\VendorDocument;
use Faker\Factory as Faker;
use App\Models\Vendor;


class VendorDocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $faker = Faker::create();

        // Récupère 2000 vendeurs aléatoires (ou les 2000 premiers si tu préfères)
        $vendorIds = Vendor::inRandomOrder()->limit(25)->pluck('id');

        $documentTypes = [
            'Carte d’identité',
            'Kbis',
            'RIB',
            'Justificatif de domicile',
            'Attestation fiscale',
            'Permis de conduire'
        ];

        foreach ($vendorIds as $vendorId) {
            VendorDocument::create([
                'vendor_id'      => $vendorId,
                'document_type'  => $faker->randomElement($documentTypes),
                'document_path'  => 'storage/documents/' . $faker->uuid . '.pdf',
                'status'         => $faker->randomElement(['pending', 'approved', 'rejected']),
            ]);
        }
    }
}
