<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi penjualan kasir: kasir sebagai user, status lunas/batal, pembayaran,
 * kunci idempotensi untuk aplikasi HP, dan snapshot satuan + harga per item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('channel', 20)->default('pos')->after('invoice_no');
            $table->string('status', 20)->default('paid')->after('channel')->index();
            $table->foreignId('user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('payment_method', 20)->change();
            $table->integer('paid_amount')->default(0)->after('payment_method');
            $table->integer('change_amount')->default(0)->after('paid_amount');
            $table->text('notes')->nullable()->after('change_amount');

            $table->string('idempotency_key', 100)->nullable()->after('notes');
            $table->string('request_hash', 64)->nullable()->after('idempotency_key');

            $table->timestamp('cancelled_at')->nullable()->after('request_hash');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');

            $table->unique(['user_id', 'idempotency_key']);
            $table->index('created_at');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
            $table->string('unit_name')->nullable()->after('product_unit_id');
            $table->decimal('conversion_qty', 12, 3)->default(1)->after('unit_name');
            $table->decimal('base_qty', 12, 3)->default(0)->after('qty');
            $table->string('price_type', 20)->default('eceran')->after('base_qty');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'conversion_qty', 'base_qty', 'price_type']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'channel', 'status', 'paid_amount', 'change_amount', 'notes',
                'idempotency_key', 'request_hash', 'cancelled_at', 'cancel_reason',
            ]);
        });
    }
};
