<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->string('price_type', 20)->default('eceran'); // eceran / grosir / langganan
            // Harga berlaku mulai pembelian sejumlah ini (dalam satuan tersebut).
            $table->decimal('min_qty', 12, 3)->default(1);
            $table->integer('price');
            $table->timestamps();

            $table->unique(['product_unit_id', 'price_type', 'min_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
