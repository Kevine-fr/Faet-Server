<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                  ->constrained()
                  ->onDelete('cascade'); // supprime les images si le produit est supprimé
            $table->string('path');            // chemin / URL de l'image
            $table->string('alt')->nullable(); // texte alternatif
            $table->boolean('is_primary')->default(false); // image principale
            $table->integer('position')->nullable()->index(); // ordre d'affichage
            $table->timestamps();

            $table->index(['product_id', 'is_primary'], 'idx_product_images_product_id_is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex('idx_product_images_product_id_is_primary');
        });

        Schema::dropIfExists('product_images');
    }
};
