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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('size', 10)->nullable();
            $table->string('color', 50)->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->integer('stock_quantity');
            $table->boolean('is_active')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_id', 'size', 'color']);
            $table->string('external_id')->nullable();
			$table->string('sku')->nullable();
			$table->json('attributes')->nullable();

            // Indexes composites
            $table->index(['product_id', 'is_active'], 'idx_product_variants_product_active');
            $table->index(['is_active', 'color'], 'idx_product_variants_active_color');
            $table->index(['is_active', 'size'], 'idx_product_variants_active_size');
            $table->index(['is_active', 'price'], 'idx_product_variants_active_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('idx_product_variants_product_active');
            $table->dropIndex('idx_product_variants_active_color');
            $table->dropIndex('idx_product_variants_active_size');
            $table->dropIndex('idx_product_variants_active_price');
        });
        
        Schema::dropIfExists('product_variants');
    }
};
