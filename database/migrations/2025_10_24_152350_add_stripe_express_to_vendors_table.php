<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Ajouter seulement les nouvelles colonnes Stripe Connect Express (vendor_stripe_id existe déjà)
            $table->string('vendor_stripe_id')->nullable();
            // $table->enum('stripe_express_status', ['pending', 'verified', 'rejected', 'restricted'])->default('pending')->after('vendor_stripe_id');
            // $table->json('stripe_express_requirements')->nullable()->after('stripe_express_status');
            // $table->timestamp('stripe_express_created_at')->nullable()->after('stripe_express_requirements');
            // $table->timestamp('stripe_express_verified_at')->nullable()->after('stripe_express_created_at');
        });
    }

    /**
     * Reverse the migratio ns.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Supprimer les colonnes Stripe Connect Express
            $table->dropColumn([
                'stripe_express_status',
                'stripe_express_requirements',
                'stripe_express_created_at',
                'stripe_express_verified_at'
            ]);
        });
    }
};