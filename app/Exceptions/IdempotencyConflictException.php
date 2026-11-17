<?php

namespace App\Exceptions;

/** Idempotency-Key yang sama dipakai ulang untuk isi permintaan yang berbeda. */
class IdempotencyConflictException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key ini sudah dipakai untuk transaksi lain. Buat kunci baru untuk transaksi baru.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_BENTROK';
    }

    public function status(): int
    {
        return 409;
    }
}
