<?php

namespace App\Exceptions;

use App\Models\Customer;

/** Nomor HP sudah dipakai pelanggan lain. details = pelanggan itu, supaya kasir bisa langsung memakainya. */
class CustomerExistsException extends BusinessException
{
    public function __construct(public readonly Customer $existing)
    {
        parent::__construct("Nomor HP {$existing->phone} sudah terdaftar atas nama {$existing->name}.");
    }

    public function errorCode(): string
    {
        return 'PELANGGAN_SUDAH_ADA';
    }

    public function status(): int
    {
        return 409;
    }

    public function details(): array
    {
        return $this->existing->toApi();
    }
}
