<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok disimpan dalam satuan dasar produk dan boleh pecahan (mis. 237,5 kg pakan curah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('current_stock', 12, 3)->default(0)->change();
            $table->decimal('min_stock', 12, 3)->nullable()->after('current_stock');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('qty_change', 12, 3)->change();
            $table->decimal('stock_after', 12, 3)->change();
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->decimal('qty', 12, 3)->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('qty_received', 12, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->integer('qty_received')->change();
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->integer('qty')->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('qty_change')->change();
            $table->integer('stock_after')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_stock');
            $table->integer('current_stock')->default(0)->change();
        });
    }
};
