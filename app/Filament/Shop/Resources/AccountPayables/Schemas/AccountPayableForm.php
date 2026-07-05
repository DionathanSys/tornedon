<?php

namespace App\Filament\Shop\Resources\AccountPayables\Schemas;

use App\Enum\AccountPayable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\FinancialCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class AccountPayableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
            ->components([
                Section::make('Lançamento')
                    ->columns(['md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        Toggle::make('is_manual_counterparty')
                            ->label('Parceiro Avulso?')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Toggle $component, ?bool $state, ?object $record): void {
                                if (! $record) {
                                    return;
                                }

                                $component->state($record->supplier_id === null && filled($record->manual_counterparty_name));
                            })
                            ->afterStateUpdated(function (bool $state, Set $set): void {
                                if ($state) {
                                    $set('supplier_id', null);

                                    return;
                                }

                                $set('manual_counterparty_name', null);
                            })
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        SelectPartner::make('supplier_id', 'all')
                            ->label('Fornecedor')
                            ->columnSpan(['md' => 4, 'lg' => 6])
                            ->required(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false))
                            ->hidden(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false)),
                        TextInput::make('manual_counterparty_name')
                            ->label('Nome da Contraparte')
                            ->columnSpan(['md' => 4, 'lg' => 6])
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false))
                            ->hidden(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false)),
                        DatePicker::make('due_date')
                            ->label('Vencimento')
                            ->default(now())
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Money::make('due_amount')
                            ->label('Valor')
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        TextInput::make('installment_count')
                            ->label('Parcelas')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->default(1)
                            ->required()
                            ->visibleOn('create')
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        Select::make('status')
                            ->label('Status')
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->disabled()
                            ->visibleOn('edit')
                            ->columnSpan(['md' => 2, 'lg' => 2]),
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                    ]),
                Section::make('Complemento')
                    ->columns(['md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        Select::make('financial_category_id')
                            ->label('Categoria Financeira')
                            ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'payable'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->visibleOn('create')
                            ->columnSpan(['md' => 4, 'lg' => 4]),
                        TextInput::make('document_number')
                            ->label('Documento')
                            ->maxLength(50)
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        TextInput::make('description')
                            ->label('Descrição')
                            ->maxLength(255)
                            ->columnSpan(['md' => 4, 'lg' => 5]),
                        DatePicker::make('paid_date')
                            ->label('Pago em')
                            ->displayFormat('d/m/Y')
                            ->disabled()
                            ->visibleOn('edit')
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                        Money::make('paid_amount')
                            ->label('Valor pago')
                            ->disabled()
                            ->visibleOn('edit')
                            ->columnSpan(['md' => 2, 'lg' => 3]),
                    ]),
                Hidden::make('company_id'),
            ]);
    }
}
