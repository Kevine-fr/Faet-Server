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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('cascade');
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('sku', 50)->unique();
            $table->decimal('price', 10, 2)->index()->nullable();
            $table->integer('stock_quantity');
            $table->string('external_id')->nullable();
            $table->json('external_data')->nullable();
            $table->timestamp('last_sync')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active', 'is_blocked'], 'idx_products_category_active_blocked');
            $table->index(['price', 'is_active'], 'idx_products_price_active');
            $table->index(['created_at'], 'idx_products_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // suppression des indexes personnalisés
            $table->dropIndex('idx_products_category_active_blocked');
            $table->dropIndex('idx_products_price_active');
            $table->dropIndex('idx_products_created_at');
        });
        
        Schema::dropIfExists('products');
    }
};
