<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alur pembelian: draft → received (stok masuk) / cancelled.
 * Item menyimpan snapshot satuan agar perubahan konversi produk tidak mengubah histori.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('reference_no')->index();
            $table->string('supplier_invoice_no')->nullable()->after('status');
            $table->integer('total_cost')->default(0)->change();
            $table->text('notes')->nullable()->after('supplier_id');

            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('created_by');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('received_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->renameColumn('qty_received', 'qty');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
            $table->string('unit_name')->nullable()->after('product_unit_id');
            $table->decimal('conversion_qty', 12, 3)->default(1)->after('unit_name');
            $table->decimal('base_qty', 12, 3)->default(0)->after('qty');
            $table->integer('subtotal')->default(0)->after('buy_price');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'conversion_qty', 'base_qty', 'subtotal']);
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->renameColumn('qty', 'qty_received');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'supplier_invoice_no', 'notes', 'received_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
