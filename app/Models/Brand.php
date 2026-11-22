<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = ['name', 'slug', 'logo'];

    protected function slugSource(): string
    {
        return 'name';
    }

    protected function slugFallback(): string
    {
        return 'merek';
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
