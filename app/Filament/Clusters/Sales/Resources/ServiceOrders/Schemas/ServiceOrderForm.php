<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas;

use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\Equipment;
use App\Models\Partner;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Money;

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
                    ->activeTab(1)
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
                                    ->contained(false)
                                    ->schema([
                                        TextInput::make('number')
                                            ->label('Número da OS')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->required()
                                            ->maxLength(50)
                                            ->unique(ignoreRecord: true)
                                            ->autocomplete(false),
                                        Select::make('customer_id')
                                            ->label('Cliente')
                                            ->columnSpan(['md' => 2, 'lg' => 5])
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->relationship('customer', 'name')
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->required()
                                                    ->maxLength(255),
                                            ]),
                                        Select::make('status')
                                            ->label('Status')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->required()
                                            ->options(State::toSelectArray())
                                            ->default(State::OPEN->value)
                                            ->native(false),
                                        Select::make('priority')
                                            ->label('Prioridade')
                                            ->columnSpan(['md' => 1, 'lg' => 1])
                                            ->required()
                                            ->options(Priority::toSelectArray())
                                            ->default(Priority::NORMAL->value)
                                            ->native(false),
                                        Select::make('type')
                                            ->label('Tipo')
                                            ->columnSpan(['md' => 1, 'lg' => 1])
                                            ->required()
                                            ->options(Type::toSelectArray())
                                            ->default(Type::MAINTENANCE->value)
                                            ->native(false),
                                        DatePicker::make('order_date')
                                            ->label('Data da Ordem')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->required()
                                            ->default(now())
                                            ->native(false)
                                            ->displayFormat('d/m/Y'),
                                        DatePicker::make('scheduled_date')
                                            ->label('Data Agendada')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->native(false)
                                            ->displayFormat('d/m/Y'),
                                        DatePicker::make('limit_date')
                                            ->label('Data Limite')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->native(false)
                                            ->displayFormat('d/m/Y'),
                                        DatePicker::make('completion_date')
                                            ->label('Data de Conclusão')
                                            ->columnSpan(['md' => 1, 'lg' => 2])
                                            ->native(false)
                                            ->displayFormat('d/m/Y'),
                                    ]),
                            ]),
                        Tab::make('Atendimento')
                            ->icon(Heroicon::WrenchScrewdriver)
                            ->schema([
                                Section::make('Informações de Atendimento')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Select::make('equipment_id')
                                            ->label('Equipamento')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->searchable()
                                            ->preload()
                                            ->relationship('equipment', 'name'),
                                        TextInput::make('location')
                                            ->label('Local do Atendimento')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->maxLength(255)
                                            ->autocomplete(false),
                                        Select::make('technician_id')
                                            ->label('Técnico Responsável')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->searchable()
                                            ->preload()
                                            ->relationship('technician', 'name'),
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
                                            ->relationship('salesperson', 'name'),
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
                                        TextInput::make('estimated_hours')
                                            ->label('Horas Estimadas')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->numeric()
                                            ->suffix('h')
                                            ->default(0)
                                            ->minValue(0)
                                            ->step(0.5),
                                        TextInput::make('actual_hours')
                                            ->label('Horas Reais')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->numeric()
                                            ->suffix('h')
                                            ->default(0)
                                            ->minValue(0)
                                            ->step(0.5),
                                        Money::make('travel_value')
                                            ->label('Valor de Deslocamento')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                            ->default(0),
                                        Money::make('discount_amount')
                                            ->label('Desconto')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                            ->default(0),
                                        TextInput::make('payment_method')
                                            ->label('Forma de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->maxLength(50)
                                            ->autocomplete(false),
                                        TextInput::make('payment_condition')
                                            ->label('Condição de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->maxLength(100)
                                            ->autocomplete(false),
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
                                            ->native(false)
                                            ->displayFormat('d/m/Y H:i'),
                                        TextInput::make('customer_rating')
                                            ->label('Avaliação do Cliente')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->numeric()
                                            ->suffix('⭐')
                                            ->minValue(0)
                                            ->maxValue(5)
                                            ->step(0.5)
                                            ->helperText('0 a 5 estrelas'),
                                        Textarea::make('customer_feedback')
                                            ->label('Feedback do Cliente')
                                            ->columnSpan(['md' => 3, 'lg' => 9])
                                            ->rows(3)
                                            ->autocomplete(false),
                                        DatePicker::make('warranty_expires_at')
                                            ->label('Garantia Válida Até')
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->native(false)
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
                    ]),
            ]);
    }
}
