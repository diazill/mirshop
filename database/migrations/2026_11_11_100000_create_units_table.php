<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->unique();
            // Default untuk satuan produk baru: boleh dijual pecahan (mis. 0,5 kg)?
            $table->boolean('allow_decimal_qty')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('units')->insert(array_map(fn (array $unit) => $unit + [
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['name' => 'Pcs', 'code' => 'PCS', 'allow_decimal_qty' => false],
            ['name' => 'Kg', 'code' => 'KG', 'allow_decimal_qty' => true],
            ['name' => 'Karung', 'code' => 'KRG', 'allow_decimal_qty' => false],
            ['name' => 'Bungkus', 'code' => 'BKS', 'allow_decimal_qty' => false],
            ['name' => 'Sachet', 'code' => 'SCH', 'allow_decimal_qty' => false],
            ['name' => 'Dus', 'code' => 'DUS', 'allow_decimal_qty' => false],
            ['name' => 'Botol', 'code' => 'BTL', 'allow_decimal_qty' => false],
            ['name' => 'Kaleng', 'code' => 'KLG', 'allow_decimal_qty' => false],
            ['name' => 'Liter', 'code' => 'LTR', 'allow_decimal_qty' => true],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
