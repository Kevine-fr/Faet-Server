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
        Schema::table('product_searches', function (Blueprint $table) {
            // Ajouter l'index unique sur product_id
            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_searches', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
        });
    }
};
