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
        Schema::table('invoices', function (Blueprint $table) {
            // supprime la colonne pdf_path si elle existe
            if (Schema::hasColumn('invoices', 'pdf_path')) {
                $table->dropColumn('pdf_path');
            }

            // ajoute les champs Cloudinary / taille fichier
            $table->string('cloudinary_public_id')->nullable()->after('invoice_number');
            $table->string('cloudinary_url')->nullable()->after('cloudinary_public_id');
            $table->unsignedBigInteger('file_size')->nullable()->after('cloudinary_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // supprime les nouveaux champs
            $table->dropColumn(['cloudinary_public_id', 'cloudinary_url', 'file_size']);

            // restaure pdf_path (nullable) après invoice_number
            $table->string('pdf_path')->nullable()->after('invoice_number');
        });
    }
};
