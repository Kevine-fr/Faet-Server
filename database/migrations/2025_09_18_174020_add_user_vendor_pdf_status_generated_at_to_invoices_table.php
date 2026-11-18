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
            // foreign keys
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');

            // other fields
            $table->string('pdf_path')->nullable()->after('invoice_number');
            $table->string('status', 50)->default('generated')->after('pdf_path');
            $table->timestamp('generated_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['pdf_path', 'status', 'generated_at']);

            // drop foreign keys then columns
            $table->dropForeign(['user_id']);
            $table->dropForeign(['vendor_id']);
            $table->dropColumn(['user_id', 'vendor_id']);
        });
    }
};
