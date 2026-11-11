<?php

namespace App\Exceptions;

/** Pelanggaran aturan stok (stok tidak cukup, jumlah tidak valid, dst.). */
abstract class StockException extends BusinessException
{
}
