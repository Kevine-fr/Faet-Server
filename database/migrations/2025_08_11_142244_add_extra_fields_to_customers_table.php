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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('rue')->nullable()->after('gender_preference');
            $table->string('code_postal', 10)->nullable()->after('rue');
            $table->string('ville')->nullable()->after('code_postal');
            $table->string('numero_carte', 20)->nullable()->after('ville');
            $table->string('expiration', 5)->nullable()->after('numero_carte'); // format MM/AA
            $table->string('cvc', 4)->nullable()->after('expiration');
            $table->string('titulaire')->nullable()->after('cvc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'rue',
                'code_postal',
                'ville',
                'numero_carte',
                'expiration',
                'cvc',
                'titulaire',
            ]);
        });
    }
};
