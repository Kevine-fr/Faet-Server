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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->enum('type', ['card', 'paypal', 'stripe'])->comment('Type de méthode de paiement');
            $table->string('name')->comment('Nom de la méthode de paiement');
            $table->text('encrypted_config')->comment('Configuration chiffrée (JSON)');
            $table->text('encryption_iv')->comment('Vecteur d\'initialisation pour le chiffrement');
            $table->boolean('is_active')->default(true)->comment('Méthode active ou non');
            $table->boolean('is_default')->default(false)->comment('Méthode par défaut');
            $table->timestamps();
            
            // Index pour optimiser les requêtes
            $table->index(['vendor_id', 'type']);
            $table->index(['vendor_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
