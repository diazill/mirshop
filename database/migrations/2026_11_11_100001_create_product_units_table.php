<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            // 1 satuan ini = conversion_qty satuan dasar (mis. 1 Karung = 50 Kg).
            $table->decimal('conversion_qty', 12, 3)->default(1);
            $table->boolean('is_base')->default(false);
            $table->string('barcode')->nullable()->unique();
            $table->boolean('allow_decimal_qty')->default(false);
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        // Setiap produk lama mendapat satuan dasar "Pcs" agar data tetap konsisten.
        // Admin perlu meninjau dan mengganti satuan dasar produk curah menjadi Kg.
        $pcsId = DB::table('units')->where('code', 'PCS')->value('id');
        $now = now();

        DB::table('products')->orderBy('id')->select('id')->chunk(500, function ($products) use ($pcsId, $now) {
            DB::table('product_units')->insert($products->map(fn ($product) => [
                'product_id' => $product->id,
                'unit_id' => $pcsId,
                'conversion_qty' => 1,
                'is_base' => true,
                'allow_decimal_qty' => false,
                'is_sellable' => true,
                'is_purchasable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
