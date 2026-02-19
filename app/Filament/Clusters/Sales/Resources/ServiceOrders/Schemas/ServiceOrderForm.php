<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority;
use App\Models\CompanyPreference;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\ItemsRelationManager;
use App\Models\ServiceOrder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire as ComponentsLivewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Livewire;

class ServiceOrderForm
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
                Tabs::make('ServiceOrderTabs')
                    ->columnSpanFull()
                    ->vertical()
                    ->tabs([
                        Tab::make('Dados Gerais')
                            ->icon(Heroicon::InformationCircle)
                            ->schema([
                                Section::make('Informações Principais')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('number')
                                            ->label('Número da OS')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->visibleOn('edit')
                                            ->disabled(),
                                        Select::make('customer_id')
                                            ->label('Cliente')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->disabledOn('edit')
                                            ->afterStateUpdated(fn($state, $set) => $set('equipment_id', null))
                                            ->options(function () {
                                                return \App\Models\Partner::whereHas('companies', function ($query) {
                                                    $query->where('companies.id', Filament::getTenant()->id)
                                                        ->whereJsonContains('company_partner.type', 'customer')
                                                        ->where('company_partner.is_active', true);
                                                })
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id');
                                            }),
                                        Select::make('equipment_id')
                                            ->label('Equipamento')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->searchable()
                                            ->preload()
                                            ->options(function ($get) {
                                                $customerId = $get('customer_id');
                                                if (!$customerId) {
                                                    return [];
                                                }
                                                return \App\Models\Equipment::where('owner_id', $customerId)
                                                    ->orderBy('name')
                                                    ->pluck('name', 'id');
                                            })
                                            ->disabled(fn($get) => !$get('customer_id'))
                                            ->belowContent(fn($get) => !$get('customer_id') ? 'Selecione um cliente para carregar os equipamentos disponíveis' : null),
                                        Select::make('status')
                                            ->label('Status')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->columnStart(1)
                                            ->required()
                                            ->options(State::toSelectArray())
                                            ->default(State::OPEN->value)
                                            ->native(false)
                                            ->selectablePlaceholder(false),
                                        Select::make('priority')
                                            ->label('Prioridade')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->required()
                                            ->options(Priority::toSelectArray())
                                            ->default(Priority::NORMAL->value)
                                            ->native(false)
                                            ->selectablePlaceholder(false),
                                        Select::make('type')
                                            ->label('Tipo')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->required()
                                            ->options(Type::toSelectArray())
                                            ->default(Type::MAINTENANCE->value)
                                            ->native(false)
                                            ->selectablePlaceholder(false),
                                        DatePicker::make('order_date')
                                            ->label('Data da Ordem')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->columnStart(1)
                                            ->required()
                                            ->default(now())
                                            ->displayFormat('d/m/Y'),
                                        DatePicker::make('scheduled_date')
                                            ->label('Data Agendada')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->displayFormat('d/m/Y'),
                                        DatePicker::make('limit_date')
                                            ->label('Data Limite')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->displayFormat('d/m/Y'),
                                        DatePicker::make('completion_date')
                                            ->label('Data de Conclusão')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->displayFormat('d/m/Y'),
                                    ]),
                                Section::make('Atendimento')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('technician_id')
                                            ->label('Técnico Responsável')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->searchable()
                                            ->preload()
                                            ->relationship('technician', 'name')
                                            ->default(fn() => Auth::id()),
                                        Select::make('supervisor_id')
                                            ->label('Supervisor')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->searchable()
                                            ->preload()
                                            ->relationship('supervisor', 'name'),
                                        Select::make('salesperson_id')
                                            ->label('Vendedor')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->searchable()
                                            ->preload()
                                            ->relationship('salesperson', 'name')
                                            ->default(fn() => Auth::id()),
                                        TextInput::make('location')
                                            ->label('Local do Atendimento')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->columnStart(1)
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->default(fn() => Filament::getTenant()->service_provision_location)
                                            ->helperText('Cidade - UF'),
                                    ]),
                            ]),
                        Tab::make('Observações')
                            ->icon(Heroicon::ChatBubbleBottomCenterText)
                            ->schema([
                                Section::make('Anotações')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Textarea::make('customer_observations')
                                            ->label('Observações do Cliente')
                                            ->columnSpan(['md' => 4, 'lg' => 12])
                                            ->rows(3)
                                            ->autocomplete(false),
                                        Textarea::make('technician_observations')
                                            ->label('Observações do Técnico')
                                            ->columnSpan(['md' => 4, 'lg' => 12])
                                            ->rows(3)
                                            ->autocomplete(false),
                                        Textarea::make('solution')
                                            ->label('Solução Aplicada')
                                            ->columnSpan(['md' => 4, 'lg' => 12])
                                            ->rows(4)
                                            ->autocomplete(false)
                                            ->helperText('Descreva os procedimentos realizados e a solução aplicada'),
                                    ]),
                            ]),
                        Tab::make('Valores')
                            ->icon(Heroicon::CurrencyDollar)
                            ->schema([
                                Section::make('Valores e Horas')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Money::make('value_km')
                                            ->label('Valor do KM')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Set $set, $get) {
                                                $valueKm = (float) str_replace(['.', ','], ['', '.'], $get('value_km') ?? '0');
                                                $distanceKm = (float) str_replace(['.', ','], ['', '.'], $get('distance_km') ?? '0');
                                                $set('travel_value', number_format($valueKm * $distanceKm));
                                            })
                                            ->default(350),
                                        Money::make('distance_km')
                                            ->label('Distância em KM')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Set $set, $get) {
                                                $valueKm = (float) str_replace(['.', ','], ['', '.'], $get('value_km') ?? '0');
                                                $distanceKm = (float) str_replace(['.', ','], ['', '.'], $get('distance_km') ?? '0');
                                                $set('travel_value', number_format($valueKm * $distanceKm));
                                            })
                                            ->suffix('km')
                                            ->prefix(null)
                                            ->default(0),
                                        Money::make('travel_value')
                                            ->label('Valor de Deslocamento')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->columnStart(1)
                                            ->disabled()
                                            ->dehydrated()
                                            ->default(0),
                                        Money::make('discount_amount')
                                            ->label('Desconto')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                            ->default(0),
                                        Select::make('payment_method')
                                            ->label('Forma de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->columnStart(1)
                                            ->options(PaymentMethod::toSelectArray())
                                            ->native(false)
                                            ->searchable()
                                            ->default(fn() => CompanyPreference::getDefaultPaymentMethod()),
                                        Select::make('payment_condition')
                                            ->label('Condição de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->options(PaymentCondition::toGroupedSelectArray())
                                            ->native(false)
                                            ->searchable()
                                            ->default(fn() => CompanyPreference::getDefaultPaymentCondition()),
                                    ]),
                            ]),
                        Tab::make('Aprovação')
                            ->icon(Heroicon::CheckCircle)
                            ->schema([
                                Section::make('Aprovação e Avaliação')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Toggle::make('requires_approval')
                                            ->label('Requer Aprovação do Cliente')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->inline(false)
                                            ->default(false),
                                        Toggle::make('approved_by_customer')
                                            ->label('Aprovado pelo Cliente')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->inline(false)
                                            ->default(false),
                                        DateTimePicker::make('approved_at')
                                            ->label('Data de Aprovação')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->columnStart(1)
                                            ->displayFormat('d/m/Y H:i'),
                                        DatePicker::make('warranty_expires_at')
                                            ->label('Garantia Válida Até')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->displayFormat('d/m/Y'),
                                    ]),
                            ]),
                        Tab::make('Outros')
                            ->icon(Heroicon::EllipsisHorizontalCircle)
                            ->schema([
                                Section::make('Informações Adicionais')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        KeyValue::make('additional_info')
                                            ->label('Informações Adicionais')
                                            ->columnSpanFull()
                                            ->keyLabel('Chave')
                                            ->valueLabel('Valor')
                                            ->addActionLabel('Adicionar informação')
                                            ->reorderable(),
                                    ]),
                            ]),
                        Tab::make('Serviços')
                            ->icon(Heroicon::WrenchScrewdriver)
                            ->schema([
                                Section::make('Serviços Realizados')
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        ComponentsLivewire::make(ItemsRelationManager::class, fn(ServiceOrder $record) => [
                                            'ownerRecord' => $record,
                                            'pageClass' => EditServiceOrder::class,
                                            ])
                                            ->key('items-relation-manager')
                                            ->columnSpanFull()

                                    ])
                                    ->visibleOn([Operation::Edit]),
                            ]),
                    ]),
            ]);
    }
}
