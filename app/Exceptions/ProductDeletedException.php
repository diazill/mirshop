<?php

namespace App\Exceptions;

class ProductDeletedException extends BusinessException
{
    public function __construct(string $title)
    {
        parent::__construct("Produk {$title} sudah dihapus.");
    }

    public function errorCode(): string
    {
        return 'PRODUK_DIHAPUS';
    }
}
