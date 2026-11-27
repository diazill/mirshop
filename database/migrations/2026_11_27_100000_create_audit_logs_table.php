<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log: siapa melakukan apa pada data penting (login staf, harga/HPP, pengguna, perangkat,
 * token, pengaturan toko). Tidak bisa diubah atau dihapus dari aplikasi; dibersihkan otomatis
 * setelah 2 tahun (model:prune). Perubahan stok dan nota tidak dicatat di sini karena sudah punya
 * riwayatnya sendiri (inventory_movements, transactions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // pelaku; null = sistem/tamu
            $table->string('action', 30);                 // created, updated, deleted, restored, login_success, …
            $table->string('subject_type', 40)->nullable(); // product, user, device, setting, …
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 200)->nullable(); // nama saat kejadian, tetap terbaca walau data dihapus
            $table->json('changes')->nullable();          // {field: [lama, baru]}
            $table->json('context')->nullable();          // alasan gagal login, kode perangkat, jumlah token, …
            $table->string('channel', 10);                // panel, api, sistem
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
