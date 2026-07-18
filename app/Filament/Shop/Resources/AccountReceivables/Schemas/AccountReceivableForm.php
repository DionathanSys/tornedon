<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Schemas;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\AccountReceivable;
use App\Models\CardPaymentProfile;
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

class AccountReceivableForm
{
    public static function configure(Schema $schema, bool $useSections = true): Schema
    {
        $launchComponents = [
            Toggle::make('is_manual_counterparty')
                ->label('Parceiro Avulso?')
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(function (Toggle $component, ?bool $state, ?AccountReceivable $record): void {
                    if (! $record) {
                        return;
                    }

                    $component->state($record->customer_id === null && filled($record->manual_counterparty_name));
                })
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        $set('customer_id', null);

                        return;
                    }

                    $set('manual_counterparty_name', null);
                })
                ->columnSpan(['md' => 2, 'lg' => 3]),
            SelectPartner::make('customer_id', 'all')
                ->label('Cliente')
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
            ($useSections ? Money::make('due_amount') : TextInput::make('due_amount')
                ->inputMode('decimal')
                ->formatStateUsing(fn ($state): ?string => filled($state) ? number_format((float) $state, 2, ',', '.') : null)
                ->dehydrateStateUsing(fn ($state): float => self::normalizeMoney($state)))
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
                ->live()
                ->columnSpan(['md' => 2, 'lg' => 3]),
            Select::make('card_payment_profile_id')
                ->label('Perfil de Cartão')
                ->options(fn (): array => CardPaymentProfile::optionsForCompany(Filament::getTenant()->id))
                ->searchable()
                ->preload()
                ->native(false)
                ->visible(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->required(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->columnSpan(['md' => 2, 'lg' => 3]),
            DatePicker::make('payment_date')
                ->label('Data da venda no cartão')
                ->default(now())
                ->displayFormat('d/m/Y')
                ->visible(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->required(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->columnSpan(['md' => 2, 'lg' => 3]),
        ];

        $complementComponents = [
            Select::make('financial_category_id')
                ->label('Categoria Financeira')
                ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'receivable'))
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
                ->label('Recebido em')
                ->displayFormat('d/m/Y')
                ->disabled()
                ->visibleOn('edit')
                ->columnSpan(['md' => 2, 'lg' => 3]),
            ($useSections ? Money::make('paid_amount') : TextInput::make('paid_amount')
                ->inputMode('decimal')
                ->formatStateUsing(fn ($state): ?string => filled($state) ? number_format((float) $state, 2, ',', '.') : null))
                ->label('Valor recebido')
                ->disabled()
                ->visibleOn('edit')
                ->columnSpan(['md' => 2, 'lg' => 3]),
        ];

        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
            ->components($useSections ? [
                Section::make('Lançamento')
                    ->columns(['md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema($launchComponents),
                Section::make('Complemento')
                    ->columns(['md' => 4, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema($complementComponents),
                Hidden::make('company_id'),
            ] : [
                ...$launchComponents,
                ...$complementComponents,
                Hidden::make('company_id'),
            ]);
    }

    private static function normalizeMoney(mixed $state): float
    {
        $normalized = trim((string) $state);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }
}
