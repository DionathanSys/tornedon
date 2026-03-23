<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\Components\DiscountAmountField;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\ItemsRelationManager;
use App\Filament\RelationManagers\OrderAttachmentsRelationManager;
use App\Forms\Components\SignaturePad;
use App\Models\CompanyPreference;
use App\Models\ServiceOrder;
use App\Services\Equipment\EquipmentService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire as ComponentsLivewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ServiceOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
            ->components([
                Tabs::make('ServiceOrderTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Dados Gerais')
                            ->icon(Heroicon::InformationCircle)
                            ->schema([
                                Section::make('Informações Principais')
                                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
                                    ->columnSpanFull()
                                    ->schema([
                                        Hidden::make('number'),
                                        Hidden::make('status'),
                                        Group::make()
                                            ->columns(['sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12])
                                            ->columnSpanFull()
                                            ->schema([
                                                SelectPartner::make('customer_id', 'customer')
                                                    ->label('Cliente')
                                                    ->columnSpan(['md' => 6, 'lg' => 8, 'xl' => 6])
                                                    ->columnStart(1)
                                                    ->live(onBlur: true)
                                                    ->disabledOn('edit'),
                                                Select::make('equipment_id')
                                                    ->label('Equipamento')
                                                    ->columnSpan(['md' => 6, 'lg' => 8, 'xl' => 6])
                                                    ->searchable()
                                                    ->visibleOn('edit')
                                                    ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false)
                                                    ->getSearchResultsUsing(
                                                        fn(string $search): array => (new EquipmentService())
                                                            ->searchForSelect($search, Filament::getTenant()->id)
                                                    )
                                                    ->getOptionLabelUsing(
                                                        fn($value): ?string => (new EquipmentService())
                                                            ->getLabelForSelect((int) $value)
                                                    )
                                                    ->disabled(fn($get) => ! $get('customer_id'))
                                                    ->belowContent(fn($get) => ! $get('customer_id') ? 'Selecione um cliente para carregar os equipamentos disponíveis' : null),
                                                Select::make('priority')
                                                    ->label('Prioridade')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->required()
                                                    ->options(Priority::toSelectArray())
                                                    ->default(Priority::NORMAL->value)
                                                    ->native(false)
                                                    ->selectablePlaceholder(false),
                                                Select::make('type')
                                                    ->label('Tipo')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->required()
                                                    ->visibleOn('edit')
                                                    ->options(Type::toSelectArray())
                                                    ->default(Type::MAINTENANCE->value)
                                                    ->native(false)
                                                    ->selectablePlaceholder(false),
                                                DatePicker::make('order_date')
                                                    ->label('Data da Ordem')
                                                    ->columnSpan(fn($operation) => $operation === 'create' ? ['md' => 3, 'lg' => 4] : ['md' => 2, 'lg' => 2])
                                                    ->required()
                                                    ->default(now())
                                                    ->displayFormat('d/m/Y')
                                                    ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('scheduled_date')
                                                    ->label('Data Agendada')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->visibleOn('edit')
                                                    ->displayFormat('d/m/Y')
                                                    ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('limit_date')
                                                    ->label('Data Limite')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->displayFormat('d/m/Y')
                                                    ->visible(false)
                                                    ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('warranty_expires_at')
                                                    ->label('Garantia Válida Até')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->visibleOn('edit')
                                                    ->displayFormat('d/m/Y')
                                                    ->default(fn() => CompanyPreference::get('default_warranty_days'))
                                                    ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('completion_date')
                                                    ->label('Data de Conclusão')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->displayFormat('d/m/Y')
                                                    ->visibleOn('edit')
                                                    ->disabled(fn($record) => ! $record?->state()?->canEdit()),
                                            ]),

                                    ]),
                                Section::make('Atendimento')
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->collapsible()
                                    ->collapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        SelectPartner::make('technician_id', 'technician')
                                            ->label('Técnico')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->required(false)
                                            ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        SelectPartner::make('salesperson_id', 'salesperson')
                                            ->label('Vendedor')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->required(false)
                                            ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        TextInput::make('location')
                                            ->label('Local do Atendimento')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->default(fn() => Filament::getTenant()->service_provision_location)
                                            ->formatStateUsing(fn($state) => $state ?? Filament::getTenant()->service_provision_location)
                                            ->helperText('Cidade - UF')
                                            ->disabled(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                    ]),
                                ComponentsLivewire::make(ItemsRelationManager::class, fn(ServiceOrder $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => EditServiceOrder::class,
                                ])
                                    ->key('items-relation-manager')
                                    ->columnSpanFull()
                                    ->visibleOn([Operation::Edit]),
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
                                        TextInput::make('technician_observations')
                                            ->label('Observações do Técnico')
                                            ->columnSpan(['md' => 4, 'lg' => 12])
                                            ->datalist(fn() => [
                                                'Checar disponibilidade de peças',
                                                'Entrar em contato com o cliente para alinhamento',
                                                'Agendar visita técnica',
                                                'Aguardar chegada de peças',
                                                'Realizar manutenção preventiva',
                                                'Realizar manutenção corretiva',
                                                'Realizar testes de funcionamento',
                                                'Fornecer orientações de uso ao cliente',
                                            ])
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
                                Section::make('Valores')
                                    ->columns(['sm' => 1,'md' => 6,'lg' => 12,])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Money::make('value_km')
                                            ->label('Valor do KM')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Set $set, $get) {
                                                $valueKm = (float) str_replace(['.', ','], ['', '.'], $get('value_km') ?? '0');
                                                $distanceKm = (float) str_replace(['.', ','], ['', '.'], $get('distance_km') ?? '0');
                                                $set('travel_value', number_format($valueKm * $distanceKm));
                                            })
                                            ->default(fn() => CompanyPreference::get('default_value_km', default: 3.5))
                                            ->formatStateUsing(fn($state) => is_numeric($state)
                                                ? number_format((float) $state, 2, ',', '.')
                                                : number_format((float) CompanyPreference::get('default_value_km', default: 3.5), 2, ',', '.')),
                                        Money::make('distance_km')
                                            ->label('Distância em KM')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
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
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->disabled()
                                            ->dehydrated()
                                            ->default(0),
                                        Select::make('payment_method')
                                            ->label('Forma de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->columnStart(1)
                                            ->options(PaymentMethod::toSelectArray())
                                            ->native(false)
                                            ->searchable()
                                            ->default(fn() => CompanyPreference::getDefaultPaymentMethod()),
                                        Select::make('payment_condition')
                                            ->label('Condição de Pagamento')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->options(PaymentCondition::toGroupedSelectArray())
                                            ->native(false)
                                            ->searchable()
                                            ->default(fn() => CompanyPreference::getDefaultPaymentCondition()),
                                        DiscountAmountField::make('service_order')
                                            ->saved(false)
                                            ->visible(fn($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                    ]),
                            ]),
                        Tab::make('Aprovação')
                            ->icon(Heroicon::CheckCircle)
                            ->visible(false)
                            ->schema([
                                Section::make('Aprovação e Avaliação')
                                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
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
                                            ->disabled()
                                            ->displayFormat('d/m/Y H:i'),
                                    ]),
                            ]),
                        Tab::make('Assinatura')
                            ->visibleOn('edit')
                            ->icon(Heroicon::PencilSquare)
                            ->schema([
                                Section::make('Assinatura do Cliente')
                                    ->description('Use esta área para coletar a assinatura diretamente na tela em celulares, tablets ou notebooks com toque.')
                                    ->columns([
                                        'sm' => 1,
                                        'md' => 4,
                                        'lg' => 12,
                                    ])
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        SignaturePad::make('customer_signature')
                                            ->hiddenLabel()
                                            ->canvasHeight('300px')
                                            ->columnSpan(['md' => 2, 'lg' => 6]),
                                        DateTimePicker::make('customer_signed_at')
                                            ->label('Última assinatura')
                                            ->seconds(false)
                                            ->columnStart(1)
                                            ->displayFormat('d/m/Y H:i')
                                            ->columnSpan(['md' => 1, 'lg' => 3])
                                            ->readOnly()
                                            ->dehydrated(false),
                                        Callout::make('Ajuda')
                                            ->info()
                                            ->columnStart(1)
                                            ->columnSpan(['md' => 2, 'lg' => 6])
                                            ->description('Assine dentro da caixa azul. Use "Limpar" para remover o desenho atual apenas do formulário e clique em "Salvar" para gravar a nova assinatura ou confirmar a remoção. Use "Cancelar" para sair sem salvar.'),
                                    ]),
                            ]),
                        Tab::make('Anexos')
                            ->visibleOn([Operation::Edit])
                            ->icon(Heroicon::PaperClip)
                            ->schema([
                                Section::make('Arquivos anexados')
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        ComponentsLivewire::make(OrderAttachmentsRelationManager::class, fn(ServiceOrder $record) => [
                                            'ownerRecord' => $record,
                                            'pageClass' => EditServiceOrder::class,
                                        ])
                                            ->key('attachments-relation-manager')
                                            ->columnSpanFull(),
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
                                        Repeater::make('additional_info')
                                            ->label('Informações Adicionais')
                                            ->columnSpanFull()
                                            ->defaultItems(0)
                                            ->addActionLabel('Adicionar informação')
                                            ->reorderable()
                                            ->collapsible()
                                            ->itemLabel(function (array $state): ?string {
                                                $label = filled($state['type'] ?? null) ? (string) $state['type'] : null;
                                                $observation = $state['observation'] ?? null;

                                                return $label
                                                    ? (filled($observation) ? "{$label}: {$observation}" : $label)
                                                    : ($observation ?: 'Informação adicional');
                                            })
                                            ->formatStateUsing(fn($state) => static::normalizeAdditionalInfoState($state))
                                            ->dehydrateStateUsing(fn($state) => static::normalizeAdditionalInfoState($state))
                                            ->columns(['md' => 4, 'lg' => 12])
                                            ->schema([
                                                TextInput::make('type')
                                                    ->label('Padrão')
                                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                                    ->datalist([
                                                        'Terminal de direção com folga',
                                                        'Rolamento de roda com folga',
                                                        'Embuchamento do eixo com folga',
                                                        'Mola dianteira substituída',
                                                        'Pneus com desgaste irregular'
                                                    ])
                                                    ->required(),
                                                TextInput::make('observation')
                                                    ->label('Observação')
                                                    ->columnSpan(['md' => 2, 'lg' => 8])
                                                    ->maxLength(255)
                                                    ->placeholder('Detalhes ou observação personalizável'),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function normalizeAdditionalInfoState(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        if ($state === []) {
            return [];
        }

        $isAssoc = array_keys($state) !== range(0, count($state) - 1);

        if ($isAssoc) {
            return collect($state)
                ->map(fn($value, $key) => [
                    'type' => filled($key) ? (string) $key : null,
                    'observation' => is_scalar($value) ? (string) $value : null,
                ])
                ->filter(fn(array $item) => filled($item['type']) || filled($item['observation']))
                ->values()
                ->all();
        }

        return collect($state)
            ->map(function ($item) {
                if (! is_array($item)) {
                    return null;
                }

                return [
                    'type' => filled($item['type'] ?? null)
                        ? (string) $item['type']
                        : null,
                    'observation' => filled($item['observation'] ?? null)
                        ? (string) $item['observation']
                        : null,
                ];
            })
            ->filter(fn(array $item) => filled($item['type']) || filled($item['observation']))
            ->values()
            ->all();
    }
}
