<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_type'); // PURCHASE / SALE / ADJUSTMENT
            $table->integer('reference_id')->nullable();
            $table->integer('qty_change'); // + or -
            $table->integer('stock_after');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
