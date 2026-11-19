<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BarcodeTakenException;
use App\Exceptions\ProductDeletedException;
use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use App\Services\PricingService;
use App\Support\ProductUnitPayload;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Gudang', 'Kelola barang dari aplikasi HP: barcode, stok, foto. Online.', weight: 4)]
class ProductUnitBarcodeController extends Controller
{
    /**
     * Pasang atau lepas barcode satuan produk.
     *
     * `barcode: null` melepas barcode. Barcode unik di semua satuan; bila sudah dipakai satuan lain,
     * jawaban 409 `BARCODE_SUDAH_DIPAKAI` dengan `details` berisi pemiliknya. Hasil sama dengan scan barcode.
     * Peran: owner, admin, gudang.
     */
    public function update(Request $request, ProductUnit $productUnit, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            /** 4–64 karakter: huruf, angka, "-" dan ".". null = lepas barcode. */
            'barcode' => ['present', 'nullable', 'string', 'min:4', 'max:64', 'regex:/^[A-Za-z0-9.\-]+$/'],
        ], [
            'barcode.present' => 'Field barcode wajib dikirim (isi null untuk melepas).',
            'barcode.min' => 'Barcode minimal 4 karakter.',
            'barcode.max' => 'Barcode maksimal 64 karakter.',
            'barcode.regex' => 'Barcode hanya boleh huruf, angka, tanda minus, dan titik.',
        ]);

        $barcode = isset($data['barcode']) ? trim($data['barcode']) : null;

        $product = $productUnit->product()->withTrashed()->first();
        if ($product?->trashed()) {
            throw new ProductDeletedException($product->title);
        }

        $this->assertBarcodeFree($barcode, $productUnit);

        try {
            $productUnit->update(['barcode' => $barcode]);
        } catch (UniqueConstraintViolationException $exception) {
            // Satuan lain memasang barcode yang sama tepat bersamaan.
            $this->assertBarcodeFree($barcode, $productUnit);

            throw $exception;
        }

        return response()->json(['data' => ProductUnitPayload::make($productUnit->refresh(), $pricing)]);
    }

    private function assertBarcodeFree(?string $barcode, ProductUnit $self): void
    {
        if ($barcode === null) {
            return;
        }

        $owner = ProductUnit::query()->where('barcode', $barcode)->whereKeyNot($self->id)->first();

        if ($owner) {
            throw new BarcodeTakenException($barcode, $owner);
        }
    }
}
