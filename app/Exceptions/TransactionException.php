<?php

namespace App\Exceptions;

class TransactionException extends BusinessException
{
    public function errorCode(): string
    {
        return 'TRANSAKSI_TIDAK_VALID';
    }
}
