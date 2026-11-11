<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'allow_decimal_qty', 'is_active'];

    protected $casts = [
        'allow_decimal_qty' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
