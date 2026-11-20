<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pelanggan untuk Mode Kasir. Pelanggan yang dibuat di sini juga muncul di panel admin.
 * Pelanggan yang dibuat saat offline dikirim lewat POST /customers (Idempotency-Key = id lokal HP)
 * saat Sinkron, sebelum nota offline yang memakainya.
 */
#[Group('Pelanggan', 'Cari dan tambah pelanggan dari kasir', weight: 3)]
class CustomerController extends Controller
{
    /**
     * Cari pelanggan.
     *
     * `q` mencocokkan nama (tanpa beda huruf besar/kecil) atau nomor HP (format apa saja: 0812…, +62 812…).
     * Hanya pelanggan aktif, urut nama. Tanpa `q` = semua pelanggan aktif.
     * Peran: owner, admin, kasir.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));
        $digits = preg_replace('/\D/', '', $q);
        $phone = strlen($digits) >= 3 ? Customer::normalizePhone($digits) : null;

        $page = Customer::query()
            ->active()
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q, $phone) {
                $w->whereRaw("lower(name) like ? escape '!'", ['%' . $this->escapeLike(mb_strtolower($q)) . '%']);

                if ($phone !== null) {
                    // Hanya angka, tidak perlu di-escape.
                    $w->orWhere('phone', 'like', "%{$phone}%");
                }
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->through(fn (Customer $c): array => $c->toApi());

        return response()->json($page);
    }

    /**
     * Tambah cepat pelanggan.
     *
     * `name` wajib, `phone` opsional tapi unik. Kirim ulang dengan `Idempotency-Key` yang sama
     * mengembalikan pelanggan yang sama (200, `replayed: true`). Nomor HP sudah dipakai → 409
     * `PELANGGAN_SUDAH_ADA`, `details` berisi pelanggan itu (pakai `details.id`).
     * Peran: owner, admin, kasir.
     */
    #[HeaderParameter('Idempotency-Key', 'UUID unik per pelanggan baru, dibuat aplikasi (untuk pelanggan offline: id lokalnya). Wajib.', required: true, example: '3c9e1f20-6b7a-4d2e-9f8c-1a5b7d3e9c04')]
    public function store(Request $request, CustomerService $customers): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 100) {
            return response()->json([
                'code' => 'IDEMPOTENCY_KEY_WAJIB',
                'message' => 'Header Idempotency-Key wajib diisi (maks. 100 karakter), satu nilai unik per pelanggan baru.',
            ], 400);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'phone' => ['nullable', 'string', 'max:25', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! Customer::isValidPhone((string) $value)) {
                    $fail('Nomor HP tidak valid. Contoh: 0812 3456 7890.');
                }
            }],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'Nama pelanggan wajib diisi.',
            'name.regex' => 'Nama pelanggan wajib diisi.',
        ]);

        [$customer, $replayed] = $customers->quickCreate($request->user(), $key, $data);

        return response()->json(['data' => $customer->toApi(), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    /**
     * Detail pelanggan + 10 nota terakhir.
     *
     * Kasir hanya melihat nota yang dia buat sendiri (sama seperti daftar penjualan); owner/admin semua.
     * Pelanggan nonaktif tetap bisa dibuka (`is_active: false`); yang sudah dihapus → 404.
     * Peran: owner, admin, kasir.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $user = $request->user();

        $recent = $customer->transactions()
            ->when(! $user->hasRole('owner', 'admin'), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'invoice_no', 'status', 'total_amount', 'payment_method', 'occurred_at'])
            ->map(fn (Transaction $t): array => [
                'id' => $t->id,
                'invoice_no' => $t->invoice_no,
                'status' => $t->status->value,
                'total_amount' => $t->total_amount,
                'payment_method' => $t->payment_method->value,
                'occurred_at' => $t->occurred_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $customer->toApi() + [
            'notes' => $customer->notes,
            'created_at' => $customer->created_at?->toIso8601String(),
            'recent_transactions' => $recent,
        ]]);
    }

    private function escapeLike(string $value): string
    {
        // "!" sebagai karakter escape: sama perilakunya di Postgres dan SQLite (tes).
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
