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
        Schema::table('vendors', function (Blueprint $table) {
            // Colonne principale pour activer/désactiver toutes les notifications
            $table->boolean('is_notified')->default(true)->after('last_synced_at');
            
            // Préférences spécifiques pour chaque type de notification
            $table->boolean('is_notified_new_order')->default(true)->after('is_notified');
            $table->boolean('is_notified_order_canceled')->default(true)->after('is_notified_new_order');
            $table->boolean('is_notified_low_stock')->default(true)->after('is_notified_order_canceled');
            $table->boolean('is_notified_app_maintenance')->default(false)->after('is_notified_low_stock');
            $table->boolean('is_notified_app_update')->default(true)->after('is_notified_app_maintenance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'is_notified',
                'is_notified_new_order',
                'is_notified_order_canceled',
                'is_notified_low_stock',
                'is_notified_app_maintenance',
                'is_notified_app_update'
            ]);
        });
    }
};
