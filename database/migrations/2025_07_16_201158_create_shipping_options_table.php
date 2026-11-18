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
        Schema::create('shipping_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->string('name', 100)->index();
            $table->decimal('price', 10, 2)->index();
            $table->decimal('free_shipping_threshold', 10, 2)->nullable();
            $table->integer('delivery_time_min');
            $table->integer('delivery_time_max');
            $table->decimal('weight_min', 10, 2)->nullable();
            $table->decimal('weight_max', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_options');
    }
};
