<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    /**
     * Override the default email form field.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email address or Username')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Determine credentials based on input.
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login_type = filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $login_type => $data['email'],
            'password'  => $data['password'],
        ];
    }
}
