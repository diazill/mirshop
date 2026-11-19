<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur Kelola barang (aplikasi HP, peran gudang):
 * - product_units: siapa dan kapan barcode terakhir diubah.
 * - inventory_movements: kunci idempotensi agar opname/koreksi yang dikirim ulang
 *   (sinyal gudang putus) tidak mengubah stok dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->foreignId('barcode_updated_by')->nullable()->after('barcode')->constrained('users')->nullOnDelete();
            $table->timestamp('barcode_updated_at')->nullable()->after('barcode_updated_by');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('user_id');
            $table->string('request_hash', 64)->nullable()->after('idempotency_key');

            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });

        Schema::table('product_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('barcode_updated_by');
            $table->dropColumn('barcode_updated_at');
        });
    }
};
