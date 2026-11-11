<?php

namespace App\Exceptions;

class InvalidStockQuantityException extends StockException
{
    public function errorCode(): string
    {
        return 'JUMLAH_TIDAK_VALID';
    }
}
