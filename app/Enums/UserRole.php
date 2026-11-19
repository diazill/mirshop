<?php

namespace App\Enums;

/** Satu-satunya daftar nilai peran. Middleware `role:` dan pengecekan hasRole() memakai nilai ini. */
enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Cashier = 'kasir';
    case Warehouse = 'gudang';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Cashier => 'Kasir',
            self::Warehouse => 'Staf gudang',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $role) => [$role->value => $role->label()])->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
