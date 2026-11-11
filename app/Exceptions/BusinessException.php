<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pelanggaran aturan bisnis. Untuk request API dirender sebagai JSON 422
 * dengan format error seragam: { code, message, details }.
 */
abstract class BusinessException extends RuntimeException
{
    abstract public function errorCode(): string;

    public function details(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json([
            'code' => $this->errorCode(),
            'message' => $this->getMessage(),
            'details' => $this->details(),
        ], 422);
    }
}
