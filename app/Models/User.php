<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    public function auditedFields(): array
    {
        return ['name', 'username', 'email', 'role', 'is_active', 'password'];
    }

    public function maskedAuditFields(): array
    {
        return ['password'];
    }

    public function auditLabel(): string
    {
        return (string) ($this->username ?: $this->name);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasRole('owner', 'admin');
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** Username disimpan huruf kecil tanpa spasi tepi; kosong berarti tidak punya username. */
    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => filled($value) ? mb_strtolower(trim($value)) : null);
    }

    /** Cari user dari isian login: email (bila berbentuk email) atau username, tanpa membedakan huruf besar/kecil. */
    public static function findForLogin(string $login): ?self
    {
        $login = mb_strtolower(trim($login));
        $column = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return static::query()->whereRaw("lower({$column}) = ?", [$login])->first();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
