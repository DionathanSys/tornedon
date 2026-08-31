<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\Components\DiscountAmountField;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Mobile\Resources\MobileServiceOrders\Pages\EditMobileServiceOrder;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Models\CompanyPreference;
use App\Models\ServiceOrder;
use App\Services\Equipment\EquipmentService;
use App\Services\Partner\PartnerService;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
use App\Support\ServiceOrderTravelData;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Support\HtmlString;
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
                    ->persistTab()
                    ->tabs([
                        Tab::make('Dados Gerais')
                            ->icon(Heroicon::InformationCircle)
                            ->schema([
                                Hidden::make('number'),
                                Hidden::make('status'),
                                Group::make()
                                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12])
                                    ->columnSpanFull()
                                    ->schema([
                                        SelectPartner::make('customer_id', 'customer')
                                            ->label('Cliente')
                                            ->columnSpanFull()
                                            ->live()
                                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                                if (! $state) {
                                                    return;
                                                }

                                                $companyId = Filament::getTenant()->id;
                                                $companyDefaults = app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer($companyId, null);
                                                $defaults = app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer($companyId, (int) $state);

                                                if (blank($get('payment_method')) || $get('payment_method') === $companyDefaults['payment_method']) {
                                                    $set('payment_method', $defaults['payment_method']);
                                                }

                                                if (blank($get('payment_condition')) || $get('payment_condition') === $companyDefaults['payment_condition']) {
                                                    $set('payment_condition', $defaults['payment_condition']);
                                                }
                                            })
                                            ->disabledOn('edit')
                                            ->getSearchResultsUsing(
                                                fn (string $search): array => (new PartnerService)
                                                    ->searchForSelect($search, Filament::getTenant()->id, 'customer')
                                            )
                                            ->getOptionLabelUsing(
                                                fn ($value): ?string => (new PartnerService)
                                                    ->getLabelForSelect((int) $value, ['document_number' => false])
                                            ),
                                        Select::make('equipment_id')
                                            ->label('Equipamento')
                                            ->columnSpanFull()
                                            ->searchable()
                                            ->visibleOn('edit')
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false)
                                            ->getSearchResultsUsing(
                                                fn (string $search, Get $get): array => (new EquipmentService)
                                                    ->searchForSelect($search, Filament::getTenant()->id, $get('customer_id'))
                                            )
                                            ->getOptionLabelUsing(
                                                fn ($value): ?string => (new EquipmentService)
                                                    ->getLabelForSelect((int) $value, ['document_number' => false, 'owner'])
                                            )
                                            ->disabled(fn ($get) => ! $get('customer_id')),
                                        DatePicker::make('order_date')
                                            ->label('Data da Ordem')
                                            ->columnSpan(fn ($operation) => $operation === 'create' ? 1 : ['md' => 2, 'lg' => 2])
                                            ->required()
                                            ->default(now())
                                            ->displayFormat('d/m/Y')
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        Section::make('Outras Informações')
                                            ->columnSpanFull()
                                            ->contained(false) // fn($operation) => $operation !== 'edit')
                                            ->collapsible()
                                            ->visibleOn('edit')
                                            ->collapsed()
                                            ->schema([
                                                Select::make('priority')
                                                    ->label('Prioridade')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->required()
                                                    ->options(Priority::toSelectArray())
                                                    ->visibleOn('edit')
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
                                                DatePicker::make('scheduled_date')
                                                    ->label('Data Agendada')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->visibleOn('edit')
                                                    ->displayFormat('d/m/Y')
                                                    ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('limit_date')
                                                    ->label('Data Limite')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->displayFormat('d/m/Y')
                                                    ->visible(false)
                                                    ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('warranty_expires_at')
                                                    ->label('Garantia Válida Até')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->visibleOn('edit')
                                                    ->displayFormat('d/m/Y')
                                                    ->default(fn () => CompanyPreference::get('default_warranty_days'))
                                                    ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('completion_date')
                                                    ->label('Data de Conclusão')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->displayFormat('d/m/Y')
                                                    ->visibleOn('edit')
                                                    ->disabled(fn ($record) => ! $record?->state()?->canEdit()),
                                            ]),
                                    ]),
                                Section::make('Atendimento')
                                    ->collapsible()
                                    ->collapsed()
                                    ->contained(false)
                                    ->columnSpanFull()
                                    ->schema([
                                        SelectPartner::make('technician_id', 'technician')
                                            ->label('Técnico')
                                            ->columnSpanFull()
                                            ->required(false)
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        TextInput::make('follow_up_responsible_name')
                                            ->label('Representante do cliente')
                                            ->columnSpanFull()
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        TextInput::make('location')
                                            ->label('Local do Atendimento')
                                            ->columnSpanFull()
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->default(fn () => Filament::getTenant()->service_provision_location)
                                            ->formatStateUsing(fn ($state) => $state ?? Filament::getTenant()->service_provision_location)
                                            ->helperText('Cidade - UF')
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                    ]),
                                ComponentsLivewire::make(ItemsRelationManager::class, fn (ServiceOrder $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => EditMobileServiceOrder::class,
                                ])
                                    ->key('items-relation-manager')
                                    ->columnSpanFull()
                                    ->visibleOn([Operation::Edit]),
                            ]),
                        Tab::make('Observações')
                            ->icon(Heroicon::ChatBubbleBottomCenterText)
                            ->schema([
                                Section::make('Anotações')
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        Textarea::make('customer_observations')
                                            ->label('Observações do Cliente')
                                            ->columnSpanFull()
                                            ->rows(3)
                                            ->autocomplete(false),
                                        Textarea::make('items_received')
                                            ->label('Itens Recebidos')
                                            ->columnSpanFull()
                                            ->rows(3),
                                        TextInput::make('technician_observations')
                                            ->label('Observações do Técnico')
                                            ->columnSpanFull()
                                            ->datalist(fn () => [
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
                                            ->columnSpanFull()
                                            ->rows(4)
                                            ->autocomplete(false)
                                            ->helperText('Descreva os procedimentos realizados e a solução aplicada'),
                                    ]),
                            ]),
                        Tab::make('Valores')
                            ->icon(Heroicon::CurrencyDollar)
                            ->schema([
                                Group::make()
                                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12])
                                    ->columnSpanFull()
                                    ->schema([
                                        Money::make('value_km')
                                            ->label('Valor do KM')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, Set $set, Get $get) => $set(
                                                'travel_value',
                                                ServiceOrderTravelData::format(
                                                    ServiceOrderTravelData::calculate($get('value_km'), $get('distance_km'))
                                                )
                                            ))
                                            ->default(fn () => CompanyPreference::get('default_value_km', default: 3.5))
                                            ->formatStateUsing(fn ($state) => ServiceOrderTravelData::format(
                                                filled($state) ? $state : CompanyPreference::get('default_value_km', default: 3.5)
                                            ))
                                            ->dehydrateStateUsing(fn ($state) => ServiceOrderTravelData::normalize($state)),
                                        Money::make('distance_km')
                                            ->label('Distância em KM')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, Set $set, Get $get) => $set(
                                                'travel_value',
                                                ServiceOrderTravelData::format(
                                                    ServiceOrderTravelData::calculate($get('value_km'), $get('distance_km'))
                                                )
                                            ))
                                            ->suffix('km')
                                            ->prefix(null)
                                            ->default(0)
                                            ->formatStateUsing(fn ($state) => ServiceOrderTravelData::format($state))
                                            ->dehydrateStateUsing(fn ($state) => ServiceOrderTravelData::normalize($state)),
                                        Money::make('travel_value')
                                            ->label('Valor de Deslocamento')
                                            ->columnSpan(['md' => 2, 'lg' => 4])
                                            ->disabled()
                                            ->dehydrated()
                                            ->default(0)
                                            ->formatStateUsing(fn ($state) => ServiceOrderTravelData::format($state))
                                            ->dehydrateStateUsing(fn ($state, Get $get) => ServiceOrderTravelData::calculate(
                                                $get('value_km'),
                                                $get('distance_km')
                                            )),
                                    ]),

                                Select::make('payment_method')
                                    ->label('Forma de Pagamento')
                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                    ->columnStart(1)
                                    ->options(PaymentMethod::toSelectArray())
                                    ->native(false)
                                    ->searchable()
                                    ->default(fn () => app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer(
                                        Filament::getTenant()->id,
                                        null,
                                    )['payment_method']),
                                Select::make('payment_condition')
                                    ->label('Condição de Pagamento')
                                    ->columnSpan(['md' => 2, 'lg' => 4])
                                    ->options(PaymentCondition::toGroupedSelectArray())
                                    ->native(false)
                                    ->searchable()
                                    ->default(fn () => app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer(
                                        Filament::getTenant()->id,
                                        null,
                                    )['payment_condition']),
                                DiscountAmountField::make('service_order')
                                    ->saved(false)
                                    ->visible(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
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
                                Placeholder::make('customer_signature_preview')
                                    ->hiddenLabel()
                                    ->columnSpanFull()
                                    ->content(function (Get $get): ?HtmlString {
                                        $signature = $get('customer_signature');

                                        return filled($signature)
                                            ? new HtmlString('<img src="'.e($signature).'" alt="Assinatura do cliente" class="max-h-96 w-full object-contain">')
                                            : null;
                                    }),
                            ]),
                        Tab::make('Anexos')
                            ->visibleOn([Operation::Edit])
                            ->icon(Heroicon::PaperClip)
                            ->schema([
                                Section::make('Arquivos anexados')
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        ComponentsLivewire::make(AttachmentsRelationManager::class, fn (ServiceOrder $record) => [
                                            'ownerRecord' => $record,
                                            'pageClass' => EditMobileServiceOrder::class,
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
                                            ->formatStateUsing(fn ($state) => static::normalizeAdditionalInfoState($state))
                                            ->dehydrateStateUsing(fn ($state) => static::normalizeAdditionalInfoState($state))
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
                                                        'Pneus com desgaste irregular',
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
                ->map(fn ($value, $key) => [
                    'type' => filled($key) ? (string) $key : null,
                    'observation' => is_scalar($value) ? (string) $value : null,
                ])
                ->filter(fn (array $item) => filled($item['type']) || filled($item['observation']))
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
            ->filter(fn (array $item) => filled($item['type']) || filled($item['observation']))
            ->values()
            ->all();
    }
}
