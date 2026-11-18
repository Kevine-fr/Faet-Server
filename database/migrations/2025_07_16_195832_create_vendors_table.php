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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->string('name_store')->nullable();
            $table->string('siret', 14)->nullable();
            $table->string('website_url')->unique()->nullable();
            $table->string('ecommerce_platform')->nullable();
            $table->enum('store_location', ['internal', 'external'])->nullable();
            $table->string('ecommerce_platform_version')->nullable();
            $table->text('access_token')->nullable();
            $table->string('api_version')->nullable();
            $table->text('description')->nullable();
            $table->enum('subscription_type', ['ville', 'region', 'pays'])->nullable();
            $table->string('subscription_area')->nullable();
            $table->boolean('is_synchronized')->default(false);
            $table->enum('preferred_payment_method', ['stripe', 'paypal'])
                  ->default('stripe')
                  ->comment('Méthode de paiement préférée du vendeur');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
