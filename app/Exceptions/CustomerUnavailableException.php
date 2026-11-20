<?php

namespace App\Exceptions;

/** Nota online menyebut pelanggan yang tidak ada, nonaktif, atau sudah dihapus. */
class CustomerUnavailableException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Pelanggan tidak ditemukan atau sudah nonaktif. Pilih pelanggan lain atau jual sebagai pembeli umum.');
    }

    public function errorCode(): string
    {
        return 'PELANGGAN_TIDAK_AKTIF';
    }
}
