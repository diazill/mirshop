<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi stok yang bisa diaudit: siapa, kenapa, dari dokumen apa, dan dalam satuan apa.
 * Semua kolom jumlah "base" dalam satuan dasar produk; qty/unit_name adalah snapshot input pengguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('reference_id')->nullable()->change();
            $table->string('reference_type', 50)->nullable()->after('movement_type');

            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
            $table->string('unit_name')->nullable()->after('product_unit_id');
            $table->decimal('conversion_qty', 12, 3)->default(1)->after('unit_name');
            $table->decimal('qty', 12, 3)->nullable()->after('conversion_qty');

            $table->decimal('stock_before', 12, 3)->default(0)->after('qty_change');

            $table->string('reason', 50)->nullable()->after('stock_after');
            $table->text('notes')->nullable()->after('reason');
            $table->foreignId('user_id')->nullable()->after('notes')
                ->constrained('users')->nullOnDelete();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'created_at']);
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['reference_type', 'unit_name', 'conversion_qty', 'qty', 'stock_before', 'reason', 'notes']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('reference_id')->nullable()->change();
        });
    }
};
