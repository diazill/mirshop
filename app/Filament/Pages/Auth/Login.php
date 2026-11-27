<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\AuditLogger;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Login panel admin dengan username atau email. Setiap percobaan dicatat di audit log. */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Username atau email')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    public function authenticate(): ?LoginResponse
    {
        $login = (string) ($this->data['email'] ?? '');
        $user = $login === '' ? null : User::findForLogin($login);

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            // Password salah, akun tidak ada/nonaktif, atau peran tanpa akses panel (kasir/gudang).
            AuditLogger::record(
                action: 'login_failed',
                subjectType: 'user',
                subjectId: $user?->id,
                subjectLabel: $login,
                context: ['reason' => $this->failureReason($user)],
            );

            throw $exception;
        }

        if ($response === null) {
            // Filament menolak karena terlalu banyak percobaan (rate limit).
            AuditLogger::record(action: 'login_blocked', subjectType: 'user', subjectId: $user?->id, subjectLabel: $login);

            return null;
        }

        /** @var User $authenticated */
        $authenticated = auth()->user();
        AuditLogger::record(
            action: 'login_success',
            subjectType: 'user',
            subjectId: $authenticated->id,
            subjectLabel: $authenticated->username ?: $authenticated->name,
            actor: $authenticated,
        );

        return $response;
    }

    /**
     * Cari user sekali (tanpa membedakan huruf besar/kecil) lalu login berdasarkan id,
     * agar username "Budi" dan "budi" dianggap sama di Postgres.
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $user = User::findForLogin((string) $data['email']);

        return [
            // Tidak ditemukan: id yang tidak mungkin ada, Filament menampilkan pesan gagal standar.
            'id' => $user?->getKey() ?? 0,
            'password' => $data['password'],
        ];
    }

    private function failureReason(?User $user): string
    {
        return match (true) {
            $user === null => 'akun_tidak_ditemukan',
            ! Hash::check((string) ($this->data['password'] ?? ''), $user->password) => 'password_salah',
            ! $user->is_active => 'akun_nonaktif',
            ! $user->hasRole('owner', 'admin') => 'tanpa_akses_panel',
            default => 'lainnya',
        };
    }
}
