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
        Schema::table('promotions', function (Blueprint $table) {
            $table->foreignId('title_promotion_id')
                  ->nullable()
                  ->after('vendor_id')
                  ->constrained('title_promotions')
                  ->onDelete('set null');
            
            // Ajouter un index pour améliorer les performances des requêtes
            $table->index('title_promotion_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropForeign(['title_promotion_id']);
            $table->dropIndex(['title_promotion_id']);
            $table->dropColumn('title_promotion_id');
        });
    }
};
