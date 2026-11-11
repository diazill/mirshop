<?php

namespace App\Enums;

enum MovementType: string
{
    case Purchase = 'PURCHASE';         // barang masuk dari pemasok
    case Sale = 'SALE';                 // penjualan kasir / online
    case SaleCancel = 'SALE_CANCEL';    // pembatalan penjualan (stok kembali)
    case SaleReturn = 'SALE_RETURN';    // retur dari pelanggan (stok kembali)
    case PurchaseReturn = 'PURCHASE_RETURN'; // retur ke pemasok
    case PurchaseCancel = 'PURCHASE_CANCEL'; // pembatalan penerimaan pembelian (stok ditarik)
    case Adjustment = 'ADJUSTMENT';     // koreksi manual, alasan wajib
    case Opname = 'OPNAME';             // penyesuaian hasil hitung fisik

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Pembelian',
            self::Sale => 'Penjualan',
            self::SaleCancel => 'Batal jual',
            self::SaleReturn => 'Retur jual',
            self::PurchaseReturn => 'Retur beli',
            self::PurchaseCancel => 'Batal beli',
            self::Adjustment => 'Koreksi',
            self::Opname => 'Opname',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Purchase, self::SaleCancel, self::SaleReturn => 'success',
            self::Sale, self::PurchaseReturn, self::PurchaseCancel => 'info',
            self::Adjustment => 'warning',
            self::Opname => 'gray',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
