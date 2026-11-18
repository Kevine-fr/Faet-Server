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
        Schema::table('orders', function (Blueprint $table) {
            // id de code promo (nullable pour ne pas casser les enregistrements existants)
            $table->unsignedBigInteger('promo_code_id')->nullable()->after('id');

            // sous-total et montant de la remise (decimal pour valeurs monétaires)
            $table->decimal('subtotal', 10, 2)->default(0.00)->after('promo_code_id');
            $table->decimal('discount_amount', 10, 2)->default(0.00)->after('subtotal');

            // index + contrainte FK (optionnel mais utile si vous avez une table promo_codes)
            $table->index('promo_code_id');
            $table->foreign('promo_code_id')
                  ->references('id')
                  ->on('promo_codes')
                  ->onDelete('set null')
                  ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // retirer contrainte FK en utilisant le tableau de colonnes (compatible)
            $table->dropForeign(['promo_code_id']);
            $table->dropIndex(['promo_code_id']);

            // puis supprimer les colonnes
            $table->dropColumn(['promo_code_id', 'subtotal', 'discount_amount']);
        });
    }
};
