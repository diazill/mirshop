<?php

namespace App\Exceptions;

/** Penyimpanan file (Supabase Storage) menolak atau tidak bisa dihubungi. */
class UploadFailedException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Foto gagal disimpan ke server. Coba lagi.');
    }

    public function errorCode(): string
    {
        return 'UPLOAD_GAGAL';
    }

    public function status(): int
    {
        return 502;
    }
}
