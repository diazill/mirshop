<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * - Peran lama dari form Users (`cashier`, `warehouse`) disamakan dengan nilai yang dipakai API
 *   (`kasir`, `gudang`).
 * - Username disimpan huruf kecil agar login tidak membedakan huruf besar/kecil.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'cashier')->update(['role' => 'kasir']);
        DB::table('users')->where('role', 'warehouse')->update(['role' => 'gudang']);

        DB::table('users')->where('username', '')->update(['username' => null]);
        DB::table('users')->whereNotNull('username')->update(['username' => DB::raw('lower(trim(username))')]);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'kasir')->update(['role' => 'cashier']);
        DB::table('users')->where('role', 'gudang')->update(['role' => 'warehouse']);
    }
};
