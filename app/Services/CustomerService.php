<?php

namespace App\Services;

use App\Exceptions\CustomerExistsException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Tambah cepat pelanggan dari kasir. Idempotency-Key membuat kiriman ulang (sinyal putus, atau
 * pelanggan yang dibuat offline lalu dikirim saat Sinkron) tidak menggandakan pelanggan.
 */
class CustomerService
{
    /**
     * @param  array{name: string, phone?: ?string, address?: ?string, notes?: ?string}  $data
     * @return array{0: Customer, 1: bool} [pelanggan, replayed]
     */
    public function quickCreate(User $user, string $key, array $data): array
    {
        $attributes = [
            'name' => trim(preg_replace('/\s+/', ' ', $data['name'])),
            'phone' => Customer::normalizePhone($data['phone'] ?? null),
            'address' => blank($data['address'] ?? null) ? null : trim($data['address']),
            'notes' => blank($data['notes'] ?? null) ? null : trim($data['notes']),
        ];
        $hash = hash('sha256', json_encode($attributes));

        if ($existing = $this->replay($user, $key, $hash)) {
            return [$existing, true];
        }

        if ($owner = Customer::findByPhone($attributes['phone'])) {
            throw new CustomerExistsException($owner);
        }

        try {
            $customer = new Customer($attributes + ['is_active' => true]);
            $customer->forceFill(['created_by' => $user->id, 'idempotency_key' => $key, 'request_hash' => $hash])->save();

            return [$customer, false];
        } catch (UniqueConstraintViolationException $exception) {
            // Kiriman bersamaan: kunci yang sama → hasil pemenang; HP yang sama → pelanggan itu.
            if ($existing = $this->replay($user, $key, $hash)) {
                return [$existing, true];
            }

            if ($owner = Customer::findByPhone($attributes['phone'])) {
                throw new CustomerExistsException($owner);
            }

            throw $exception;
        }
    }

    private function replay(User $user, string $key, string $hash): ?Customer
    {
        $existing = Customer::withTrashed()
            ->where('created_by', $user->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing && ! hash_equals((string) $existing->request_hash, $hash)) {
            throw new IdempotencyConflictException();
        }

        return $existing;
    }
}
