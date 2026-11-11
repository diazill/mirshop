<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('title');
            $table->text('blurb')->nullable();
            $table->integer('price');
            $table->integer('cost_price');
            $table->integer('weight_gram');
            $table->string('image_url')->nullable();
            $table->boolean('is_best_product')->default(false);
            $table->integer('current_stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
