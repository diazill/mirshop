<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengamanan API aplikasi HP:
 * - token login terikat ke perangkat (device_id) agar kode perangkat tidak bisa dipalsukan;
 * - device_users mencatat siapa saja yang pernah login di perangkat itu (sah sebagai kasir nota offline);
 * - penanda tinjau baru: harga di bawah HPP dan nota yang terlambat disinkronkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('tokenable_id')
                ->constrained('devices')->cascadeOnDelete();
        });

        Schema::create('device_users', function (Blueprint $table) {
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_login_at');

            $table->primary(['device_id', 'user_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('below_cost')->default(false)->after('stock_conflict');
            $table->boolean('late_sync')->default(false)->after('below_cost');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['below_cost', 'late_sync']);
        });

        Schema::dropIfExists('device_users');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
    }
};
