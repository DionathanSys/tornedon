<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Product\Unit;
use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\Components\DiscountAmountField;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\Requisitions\Pages\EditRequisition;
use App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\ItemsRelationManager;
use App\Models\CompanyPreference;
use Filament\Schemas\Components\Grid;
use App\Models\Requisition;
use App\Services\Equipment\EquipmentService;
use App\Services\Partner\PartnerService;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\FontWeight;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Operation;
use Illuminate\Support\HtmlString;
use Leandrocfe\FilamentPtbrFormFields\Money;

class RequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
            ->components([
                Section::make('Dados da Requisição')
                    ->columns(['sm' => 1,'md' => 6,'lg' => 8,'xl' => 12,])
                    ->columnSpanFull()
                    ->schema([
                        Hidden::make('number'),
                        Hidden::make('status'),
                        SelectPartner::make('customer_id', 'customer')
                            ->label('Cliente')
                            ->columnSpan(['md' => 6, 'lg' => 8, 'xl' => 6])
                            ->columnStart(1)
                            ->disabledOn('edit'),
                        Select::make('equipment_id')
                            ->label('Equipamento')
                            ->columnSpan(['md' => 6, 'lg' => 8, 'xl' => 6])
                            ->searchable()
                            ->visibleOn('edit')
                            ->getSearchResultsUsing(
                                fn(string $search, Get $get): array => (new EquipmentService())
                                    ->searchForSelect($search, Filament::getTenant()->id, $get('customer_id'), 20, ['owner' => false])
                            )
                            ->getOptionLabelUsing(
                                fn($value): ?string => (new EquipmentService())
                                    ->getLabelForSelect((int) $value, ['owner' => false])
                            )
                            ->disabled(fn($get) => !$get('customer_id'))
                            ->belowContent(fn($get) => !$get('customer_id') ? 'Selecione um cliente para carregar os equipamentos disponíveis' : null),
                        DatePicker::make('sale_date')
                            ->label('Data da Venda')
                            ->columnSpan(['md' => 2, 'lg' => 3, 'xl' => 3])
                            ->default(now())
                            ->maxDate(now())
                            ->disabledOn('edit')
                            ->required()
                            ->displayFormat('d/m/Y'),
                        SelectPartner::make('salesperson_id', 'salesperson')
                            ->label('Vendedor')
                            ->columnSpan(['md' => 4, 'lg' => 5, 'xl' => 3])
                            ->columnStart(fn($operation) => $operation === 'create' ? 1 : null)
                            ->disabled(fn($record) => $record ? !$record->state()->canEdit() : false)
                            ->required(false),
                    ]),
                Section::make('Pagamento e Entrega')
                    ->columns(['md' => 6, 'lg' => 8, 'xl' => 12])
                    ->disabled(fn($record) => $record ? !$record->state()->canEdit() : false)
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->collapsible()
                    ->persistCollapsed()
                    ->schema([
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2, 'xl' => 3])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->default(fn() => CompanyPreference::getDefaultPaymentMethod(Filament::getTenant()->id)),
                        Select::make('payment_condition')
                            ->label('Condição de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2, 'xl' => 3])
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable()
                            ->default(fn() => CompanyPreference::getDefaultPaymentCondition(Filament::getTenant()->id)),
                        DatePicker::make('delivery_date')
                            ->label('Data de Entrega')
                            ->columnSpan(['md' => 2, 'lg' => 2, 'xl' => 2])
                            ->displayFormat('d/m/Y')
                            ->default(now())
                            ->nullable(),
                        TextInput::make('delivery_address')
                            ->label('Endereço de Entrega')
                            ->columnSpan(['md' => 4, 'lg' => 6, 'xl' => 4])
                            ->maxLength(255),
                        Textarea::make('observations')
                            ->label('Observações')
                            ->columnSpanFull()
                            ->rows(3)
                            ->maxLength(1000),
                        DiscountAmountField::make('requisition')
                            ->columnSpan(['md' => 2, 'lg' => 2, 'xl' => 3])
                            ->visibleOn('edit'),
                    ]),
                Livewire::make(ItemsRelationManager::class, fn(Requisition $record) => [
                    'ownerRecord' => $record,
                    'pageClass' => EditRequisition::class,
                ])
                    ->key('items-relation-manager')
                    ->columnSpanFull()
                    ->visibleOn([Operation::Edit]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
