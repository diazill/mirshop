<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * API toko online tahap 2:
 * - slug untuk produk, kategori, dan merek (URL toko online), diisi otomatis untuk data lama.
 * - product_slugs: slug lama produk, agar tautan yang sudah dibagikan tetap terbuka saat slug diganti.
 * - categories.sort_order: urutan kategori di toko online.
 * - store_closures: libur khusus per tanggal (jadwal mingguan memakai store_schedules yang sudah ada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 200)->nullable()->after('title');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->after('name');
            $table->integer('sort_order')->default(0)->after('parent_id');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->after('name');
        });

        $this->backfill('products', 'title', 'produk');
        $this->backfill('categories', 'name', 'kategori');
        $this->backfill('brands', 'name', 'merek');

        Schema::table('products', fn (Blueprint $table) => $table->unique('slug'));
        Schema::table('categories', fn (Blueprint $table) => $table->unique('slug'));
        Schema::table('brands', fn (Blueprint $table) => $table->unique('slug'));

        Schema::create('product_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 200)->unique();
            $table->timestamps();
        });

        Schema::create('store_closures', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('reason', 200)->nullable();
            $table->timestamps();
        });

        // Supabase: tutup akses langsung lewat kunci anon, sama seperti tabel lain.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_slugs ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE store_closures ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_closures');
        Schema::dropIfExists('product_slugs');

        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /** Sama dengan aturan App\Models\Concerns\HasSlug, ditulis ulang agar migrasi tidak bergantung pada model. */
    private function backfill(string $table, string $source, string $fallback): void
    {
        $used = [];

        foreach (DB::table($table)->orderBy('id')->get(['id', $source]) as $row) {
            $base = Str::slug((string) $row->{$source}) ?: $fallback;
            $base = ctype_digit($base) ? "{$fallback}-{$base}" : Str::limit($base, 180, '');
            $slug = $base;

            for ($n = 2; isset($used[$slug]); $n++) {
                $slug = "{$base}-{$n}";
            }

            $used[$slug] = true;
            DB::table($table)->where('id', $row->id)->update(['slug' => $slug]);
        }
    }
};
