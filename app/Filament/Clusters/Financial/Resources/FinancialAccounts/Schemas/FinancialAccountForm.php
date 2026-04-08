<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialAccounts\Schemas;

use App\Enum\Financial\FinancialAccountType;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class FinancialAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 8,
            ])
            ->components([
                Section::make('Conta Financeira')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(FinancialAccountType::toSelectArray())
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('institution_name')
                            ->label('Instituição')
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('branch')
                            ->label('Agência')
                            ->maxLength(20)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('account_number')
                            ->label('Conta')
                            ->maxLength(50)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('pix_key')
                            ->label('Chave PIX')
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Money::make('opening_balance')
                            ->label('Saldo Inicial')
                            ->formatStateUsing(fn ($state) => 'R$ ' . number_format($state, 2, ',', '.'))
                            ->default(0)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        DatePicker::make('opened_at')
                            ->label('Data de Abertura')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Checkbox::make('is_active')
                            ->label('Ativa')
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
