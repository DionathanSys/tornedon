<?php

namespace App\Filament\Clusters\Partners\Resources\Contacts\Components;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

final class ContactComponent
{
    public static function make(): array
    {
        return [
            TextInput::make('email')
                ->label('E-mail')
                ->columnStart(1)
                ->columnSpan([
                    'sm' => 1,
                    'md' => 4,
                    'lg' => 4,
                ])
                ->email()
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Telefone')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 2,
                ])
                ->tel()
                ->maxLength(20),
            TextInput::make('mobile')
                ->label('Celular')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 2,
                ])
                ->tel()
                ->maxLength(20),
            Toggle::make('notify')
                ->label('Recebe Notificações?')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 2,
                ])
                ->inline(false)
                ->default(false),
            Toggle::make('is_active')
                ->label('Ativo')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 2,
                ])
                ->inline(false)
                ->default(true),
        ];
    }
}
