<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Pages\Auth\Login
{
    /**
     * Replace the email field with a plain username text input.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('نام کاربری')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Pass username+password credentials to Laravel's auth driver.
     * EloquentUserProvider::retrieveByCredentials() will query WHERE username = ?
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    /**
     * Point the validation error at data.username (not data.email).
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
