<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Pengaturan toko sederhana: satu baris per key. Baca/tulis lewat App\Support\StoreProfile. */
class Setting extends Model
{
    use Auditable;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public function auditedFields(): array
    {
        return ['value'];
    }

    public function auditLabel(): string
    {
        return (string) $this->key;
    }
}
