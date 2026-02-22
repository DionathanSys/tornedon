<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\EditRequisition;
use App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\ItemsRelationManager;
use App\Models\CompanyPreference;
use App\Models\Requisition;
use App\Services\Equipment\EquipmentService;
use App\Services\Partner\PartnerService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class RequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 12,
            ])
            ->components([
                Section::make('Dados da Requisição')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visibleOn('edit')
                            ->disabled(),
                        Select::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options(Status::toSelectArray())
                            ->native(false)
                            ->default(Status::OPEN->value)
                            ->visibleOn('edit')
                            ->disabled(),
                        DatePicker::make('sale_date')
                            ->label('Data da Venda')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->default(now())
                            ->required()
                            ->displayFormat('d/m/Y'),
                        SelectPartner::make('salesperson_id', 'salesperson')
                            ->label('Vendedor')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->required(false),
                        SelectPartner::make('customer_id', 'customer')
                            ->label('Cliente'),
                        Select::make('equipment_id')
                            ->label('Equipamento')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->columnStart(1)
                            ->searchable()
                            ->getSearchResultsUsing(
                                fn(string $search): array => (new EquipmentService())
                                    ->searchForSelect($search, Filament::getTenant()->id)
                            )
                            ->getOptionLabelUsing(
                                fn($value): ?string => (new EquipmentService())
                                    ->getLabelForSelect((int) $value)
                            )
                            ->disabled(fn($get) => !$get('customer_id'))
                            ->belowContent(fn($get) => !$get('customer_id') ? 'Selecione um cliente para carregar os equipamentos disponíveis' : null),
                    ]),
                Section::make('Pagamento e Entrega')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->default(fn() => CompanyPreference::getDefaultPaymentMethod(Filament::getTenant()->id)),
                        Select::make('payment_condition')
                            ->label('Condição de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable()
                            ->default(fn() => CompanyPreference::getDefaultPaymentCondition(Filament::getTenant()->id)),
                        Money::make('discount_amount')
                            ->label('Desconto')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->default(0)
                            ->prefix('R$'),
                        DatePicker::make('delivery_date')
                            ->label('Data de Entrega')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->displayFormat('d/m/Y')
                            ->nullable(),
                        TextInput::make('delivery_address')
                            ->label('Endereço de Entrega')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->maxLength(255),
                        Textarea::make('observations')
                            ->label('Observações')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->rows(3)
                            ->maxLength(1000),
                    ]),
                Section::make()
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        Livewire::make(ItemsRelationManager::class, fn(Requisition $record) => [
                            'ownerRecord' => $record,
                            'pageClass' => EditRequisition::class,
                        ]),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
