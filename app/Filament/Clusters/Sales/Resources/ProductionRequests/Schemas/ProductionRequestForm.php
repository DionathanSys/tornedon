<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Models\CardPaymentProfile;
use App\Models\CompanyPreference;
use App\Models\FinancialCategory;
use App\Models\ProductionRequest;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductionRequestForm
{
    public static function configure(Schema $schema, bool $includeOrderData = true, bool $useSections = true): Schema
    {
        $orderFields = [
            Toggle::make('is_manual_counterparty')
                ->label('Parceiro Avulso?')
                ->disabledOn('edit')
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(function (Toggle $component, ?bool $state, ?ProductionRequest $record): void {
                    if (! $record) {
                        return;
                    }

                    $component->state(blank($record->customer_id) && filled($record->manual_counterparty_name));
                })
                ->columnSpan(['md' => 2, 'lg' => 3]),
            SelectPartner::make('customer_id', 'customer')
                ->label('Cliente')
                ->columnSpan(['md' => 4, 'lg' => 6])
                ->required(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false))
                ->hidden(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false)),
            TextInput::make('manual_counterparty_name')
                ->label('Nome da Contraparte')
                ->columnSpan(['md' => 4, 'lg' => 6])
                ->maxLength(255)
                ->autocomplete(false)
                ->required(fn (Get $get): bool => (bool) ($get('is_manual_counterparty') ?? false))
                ->hidden(fn (Get $get): bool => ! (bool) ($get('is_manual_counterparty') ?? false)),
            TextInput::make('number')
                ->label('Número')
                ->disabled()
                ->visibleOn('edit')
                ->columnSpan(['md' => 2, 'lg' => 2]),
            Select::make('status')
                ->label('Status')
                ->options(Status::toSelectArray())
                ->native(false)
                ->disabled()
                ->visibleOn('edit')
                ->columnSpan(['md' => 2, 'lg' => 2]),
            DatePicker::make('order_date')
                ->label('Data do Pedido')
                ->default(now())
                ->required()
                ->displayFormat('d/m/Y')
                ->columnSpan(['md' => 2, 'lg' => 2]),
            Textarea::make('observations')
                ->label('Observações')
                ->rows(3)
                ->columnSpanFull(),
        ];

        $financialFields = [
            Select::make('payment_method')
                ->label('Forma de Pagamento')
                ->options(PaymentMethod::toSelectArray())
                ->default(fn (): ?string => CompanyPreference::getDefaultPaymentMethod(Filament::getTenant()?->id))
                // ->searchable()
                ->native(false)
                ->live()
                ->columnSpan(['md' => 2, 'lg' => 3]),
            Select::make('payment_condition')
                ->label('Condição de Pagamento')
                ->options(PaymentCondition::toGroupedSelectArray())
                ->default(fn (): ?string => CompanyPreference::getDefaultPaymentCondition(Filament::getTenant()?->id))
                ->native(false)
                ->required(fn (Get $get): bool => (string) ($get('payment_method') ?? '') !== PaymentMethod::CREDIT_CARD->value)
                ->visible(fn (Get $get): bool => (string) ($get('payment_method') ?? '') !== PaymentMethod::CREDIT_CARD->value)
                ->columnSpan(['md' => 2, 'lg' => 3]),
            Select::make('financial_category_id')
                ->label('Categoria Financeira')
                ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()?->id ?? 0, 'receivable'))
                ->default(fn (): ?int => self::defaultReceivableFinancialCategoryId())
                ->native(false)
                ->required()
                ->columnSpan(['md' => 2, 'lg' => 3]),
            Select::make('card_payment_profile_id')
                ->label('Perfil de Recebimento no Cartão')
                ->options(fn (): array => CardPaymentProfile::optionsForCompany(Filament::getTenant()?->id ?? 0))
                ->native(false)
                ->required(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->visible(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->columnSpan(['md' => 3, 'lg' => 3]),
            DatePicker::make('payment_date')
                ->label('Data da Venda no Cartão')
                ->default(now())
                ->displayFormat('d/m/Y')
                ->required(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->visible(fn (Get $get): bool => (string) ($get('payment_method') ?? '') === PaymentMethod::CREDIT_CARD->value)
                ->columnSpan(['md' => 2, 'lg' => 3]),
        ];

        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
            ->components([
                ...($includeOrderData ? ($useSections ? [
                    Section::make('Dados do Pedido')
                    ->columns(['default' => 1, 'md' => 6, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema($orderFields),
                ] : $orderFields) : []),
                ...($useSections ? [
                Section::make('Financeiro')
                    ->columns(['default' => 1, 'md' => 6, 'lg' => 12])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema($financialFields),
                ] : $financialFields),
                Hidden::make('company_id'),
            ]);
    }

    public static function defaultReceivableFinancialCategoryId(): ?int
    {
        $tenantId = Filament::getTenant()?->id;

        if (! $tenantId) {
            return null;
        }

        $options = FinancialCategory::optionsForCompany($tenantId, 'receivable');
        $preferredId = CompanyPreference::getDefaultReceivableFinancialCategoryId($tenantId);

        if ($preferredId !== null && array_key_exists($preferredId, $options)) {
            return $preferredId;
        }

        return array_key_first($options);
    }
}
