<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kebutuhan kasir (aplikasi HP):
 * - customers: pelanggan toko (POS-05). price_type dan credit_limit disiapkan untuk harga langganan
 *   dan bon/piutang yang ditunda; belum dipakai logika apa pun.
 * - transactions: pelanggan pada nota. customer_name disalin saat nota dibuat supaya struk lama
 *   tetap benar walau nama pelanggan diubah. customer_dropped = nota offline menyebut pelanggan
 *   yang sudah nonaktif/terhapus, disimpan tanpa pelanggan dan ditandai untuk ditinjau.
 * - settings: pengaturan toko (kepala struk, zona waktu), pasangan key/value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            // Ditunda: harga langganan dan bon. Jangan dipakai sebelum fiturnya dibuat.
            $table->string('price_type', 20)->nullable();
            $table->unsignedBigInteger('credit_limit')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('updated_at');
            $table->unique(['created_by', 'idempotency_key']);
        });

        // Nomor HP unik di antara pelanggan yang belum dihapus (yang dihapus boleh dipakai lagi).
        DB::statement('CREATE UNIQUE INDEX customers_phone_unique ON customers (phone) WHERE phone IS NOT NULL AND deleted_at IS NULL');

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained('customers')->nullOnDelete();
            $table->string('customer_name', 100)->nullable()->after('customer_id');
            $table->boolean('customer_dropped')->default(false)->after('late_sync');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Supabase: tutup akses langsung lewat kunci anon (data pribadi pelanggan). Laravel terhubung
        // sebagai pemilik tabel sehingga tidak terpengaruh. Sama seperti tabel lain di proyek ini.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE settings ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['customer_name', 'customer_dropped']);
        });

        Schema::dropIfExists('customers');
    }
};
