<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dukungan aplikasi HP offline-first:
 * - devices: HP kasir/gudang terdaftar, kodenya jadi awalan nomor nota offline.
 * - transactions: waktu kejadian (occurred_at), asal nota, penanda untuk ditinjau owner.
 * - sync_deletions: jejak data katalog yang dihapus agar HP ikut menghapusnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('last_synced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('invoice_no')->index();
            $table->string('source', 20)->default('online')->after('channel');
            $table->foreignId('device_id')->nullable()->after('user_id')->constrained('devices')->nullOnDelete();
            $table->foreignId('synced_by')->nullable()->after('device_id')->constrained('users')->nullOnDelete();

            $table->boolean('price_mismatch')->default(false)->after('request_hash');
            $table->boolean('stock_conflict')->default(false)->after('price_mismatch');
            $table->boolean('needs_review')->default(false)->after('stock_conflict')->index();
            $table->timestamp('reviewed_at')->nullable()->after('needs_review');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            // Harga resmi saat sinkron, untuk dibandingkan dengan harga yang dipakai kasir offline.
            $table->integer('official_price')->nullable()->after('price_at_time');
        });

        Schema::create('sync_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 50);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('deleted_at');

            $table->index(['table_name', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_deletions');

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('official_price');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('synced_by');
            $table->dropConstrainedForeignId('device_id');
            $table->dropIndex(['needs_review']);
            $table->dropIndex(['occurred_at']);
            $table->dropColumn(['occurred_at', 'source', 'price_mismatch', 'stock_conflict', 'needs_review', 'reviewed_at']);
        });

        Schema::dropIfExists('devices');
    }
};
