<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Login as BaseLogin;

/** Login panel admin dengan username atau email. */
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
}
