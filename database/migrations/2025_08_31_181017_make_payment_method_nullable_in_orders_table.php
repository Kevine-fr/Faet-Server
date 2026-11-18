<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sauvegarder les données existantes
        $orders = DB::table('orders')->select('id', 'payment_method')->get();
        
        // Supprimer la colonne existante
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
        
        // Recréer la colonne nullable
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_method', ['paypal', 'cb', 'stripe'])->nullable()->after('status');
        });
        
        // Restaurer les données
        foreach ($orders as $order) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update(['payment_method' => $order->payment_method]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Mettre à jour tous les NULL en 'stripe' par défaut avant de rendre NOT NULL
        DB::table('orders')->whereNull('payment_method')->update(['payment_method' => 'stripe']);
        
        // Sauvegarder les données existantes
        $orders = DB::table('orders')->select('id', 'payment_method')->get();
        
        // Supprimer la colonne
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
        
        // Recréer la colonne NOT NULL
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_method', ['paypal', 'cb', 'stripe'])->after('status');
        });
        
        // Restaurer les données
        foreach ($orders as $order) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update(['payment_method' => $order->payment_method]);
        }
    }
};