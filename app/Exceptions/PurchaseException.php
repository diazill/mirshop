<?php

namespace App\Exceptions;

class PurchaseException extends BusinessException
{
    public function errorCode(): string
    {
        return 'PEMBELIAN_TIDAK_VALID';
    }
}
