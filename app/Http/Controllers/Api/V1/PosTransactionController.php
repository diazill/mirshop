<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API penjualan untuk Mode Kasir aplikasi HP.
 * POST wajib membawa header Idempotency-Key (UUID per transaksi) agar pengiriman ulang
 * saat sinyal putus tidak mencatat penjualan dua kali.
 */
class PosTransactionController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (blank($key) || strlen($key) > 100) {
            return response()->json([
                'code' => 'IDEMPOTENCY_KEY_WAJIB',
                'message' => 'Header Idempotency-Key wajib diisi (maks. 100 karakter), satu nilai unik per transaksi.',
            ], 400);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.price_type' => ['nullable', Rule::in(TransactionService::CASHIER_PRICE_TYPES)],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_amount' => ['nullable', 'integer', 'min:0', 'max:' . TransactionService::MAX_AMOUNT],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $transaction = $this->transactions->createSale(
            cashier: $request->user(),
            lines: $data['items'],
            method: PaymentMethod::from($data['payment_method']),
            paidAmount: $data['paid_amount'] ?? null,
            idempotencyKey: $key,
            notes: $data['notes'] ?? null,
        );

        return response()->json(
            ['data' => $this->payload($transaction->load('transactionItems.product'))],
            $transaction->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $page = Transaction::query()
            ->withCount('transactionItems')
            // Kasir hanya melihat penjualannya sendiri; admin/owner melihat semua.
            ->when(! $user->hasRole('owner', 'admin'), fn ($q) => $q->where('user_id', $user->id))
            // Tanggal kejadian, bukan tanggal sinkron: nota offline masuk ke hari terjadinya.
            ->whereDate('occurred_at', $filters['date'] ?? now()->toDateString())
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50)
            ->through(fn (Transaction $t): array => $this->summary($t));

        return response()->json($page);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeView($request, $transaction);

        return response()->json(['data' => $this->payload($transaction->load('transactionItems.product'))]);
    }

    public function cancel(Request $request, Transaction $transaction): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $transaction = $this->transactions->cancel($transaction, $data['reason'], $request->user());

        return response()->json(['data' => $this->payload($transaction->load('transactionItems.product'))]);
    }

    private function authorizeView(Request $request, Transaction $transaction): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('owner', 'admin') || $transaction->user_id === $user->id,
            404,
        );
    }

    private function summary(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'invoice_no' => $t->invoice_no,
            'status' => $t->status->value,
            'total_amount' => $t->total_amount,
            'payment_method' => $t->payment_method->value,
            'items_count' => $t->transaction_items_count ?? $t->transactionItems->count(),
            'cashier_name' => $t->cashier_name,
            'source' => $t->source,
            'needs_review' => $t->needs_review,
            'occurred_at' => $t->occurred_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function payload(Transaction $t): array
    {
        return $this->summary($t) + [
            'paid_amount' => $t->paid_amount,
            'change_amount' => $t->change_amount,
            'notes' => $t->notes,
            'cancelled_at' => $t->cancelled_at?->toIso8601String(),
            'cancel_reason' => $t->cancel_reason,
            'items' => $t->transactionItems->map(fn (TransactionItem $item): array => [
                'product_id' => $item->product_id,
                'product_unit_id' => $item->product_unit_id,
                'title' => $item->product?->title,
                'unit_name' => $item->unit_name,
                'qty' => $item->qty,
                'base_qty' => $item->base_qty,
                'price_type' => $item->price_type,
                'price' => $item->price_at_time,
                'subtotal' => $item->subtotal,
            ])->values(),
        ];
    }
}
