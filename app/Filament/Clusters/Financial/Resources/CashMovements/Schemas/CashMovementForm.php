<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Schemas;

use App\Enum\Financial\CashMovementDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Services\Partner\PartnerService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CashMovementForm
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
                Section::make('Movimento Financeiro')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_manual_counterparty')
                            ->label('Parceiro Avulso?')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Toggle $component, ?bool $state, $record): void {
                                if (! $record) {
                                    return;
                                }

                                $component->state($record->counterparty_partner_id === null && filled($record->manual_counterparty_name));
                            })
                            ->afterStateUpdated(function (bool $state, Set $set): void {
                                if ($state) {
                                    $set('counterparty_partner_id', null);
                                    return;
                                }

                                $set('manual_counterparty_name', null);
                            })
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('financial_account_id')
                            ->label('Conta Financeira')
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('financial_category_id')
                            ->label('Categoria Financeira')
                            ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'cash_movement'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('direction')
                            ->label('Direcao')
                            ->options(CashMovementDirection::toSelectArray())
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        DatePicker::make('transaction_date')
                            ->label('Data')
                            ->default(now())
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Money::make('amount')
                            ->label('Valor')
                            ->prefix('R$')
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('counterparty_partner_id')
                            ->label('Parceiro Contraparte')
                            ->searchable()
                            ->preload()
                            ->getSearchResultsUsing(fn (string $search): array => app(PartnerService::class)
                                ->searchForSelect($search, Filament::getTenant()->id, 'all'))
                            ->getOptionLabelUsing(fn ($value): ?string => $value
                                ? app(PartnerService::class)->getLabelForSelect((int) $value)
                                : null)
                            ->options(fn (): array => app(PartnerService::class)
                                ->searchForSelect('', Filament::getTenant()->id, 'all', 50))
                            ->native(false)
                            ->hidden(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false))
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('manual_counterparty_name')
                            ->label('Nome da Contraparte')
                            ->maxLength(255)
                            ->hidden(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false))
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('counterparty_financial_account_id')
                            ->label('Conta Contraparte')
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('description')
                            ->label('DescriDescriçãocao')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 3, 'lg' => 6]),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull(),
                        Hidden::make('company_id'),
                        Hidden::make('transfer_group_id'),
                    ]),
            ]);
    }
}
