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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string("order_number")->unique()->index();
            $table->date("seller_pickup_at")->nullable();
            $table->date("actual_pickup_at")->nullable();
            $table->date("cancelled_at")->nullable();
            $table->enum("cancelled_by", ["customer" , "vendor"])->nullable();
            $table->text("cancelletion_reason")->nullable();
            $table->boolean("cancelletion_reunded")->default(false);
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['pending_validation', 'in_preparation', 'to_be_collected', 'collected', 'canceled', 'abandoned', 'refunded'])->index();
            $table->enum('payment_method', ['paypal', 'cb', 'stripe']);
            $table->enum('payment_status', ['pending', 'complete', 'refunded', 'failed']);
            $table->decimal('total_amount', 10, 2);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};