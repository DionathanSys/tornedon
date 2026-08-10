<?php

namespace App\Filament\Clusters\Financial\Resources\ChartAccounts\Schemas;

use App\Enum\Financial\AccountingNature;
use App\Enum\Financial\ChartAccountType;
use App\Models\ChartAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChartAccountForm
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
                Section::make('Conta do Plano')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Código')
                            ->maxLength(255)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 3, 'lg' => 6]),
                        Select::make('parent_id')
                            ->label('Conta Pai')
                            ->options(fn (): array => ChartAccount::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->placeholder('Raiz')
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(ChartAccountType::toSelectArray())
                            ->required()
                            ->native(false)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('nature')
                            ->label('Natureza')
                            ->options(AccountingNature::toSelectArray())
                            ->native(false)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('sort_order')
                            ->label('Ordem')
                            ->numeric()
                            ->default(0)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Toggle::make('is_postable')
                            ->label('Permite lançamento?')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
