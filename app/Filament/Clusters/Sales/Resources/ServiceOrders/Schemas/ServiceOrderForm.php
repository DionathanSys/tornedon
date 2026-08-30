<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource as FinancialFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\Components\DiscountAmountField;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource as SalesFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CaptureServiceOrderSignatureAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\ProductsRelationManager;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\ReceivedAssetsRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Forms\Components\SignaturePad;
use App\Models\CompanyPreference;
use App\Models\ServiceOrder;
use App\Services\Equipment\EquipmentService;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
use App\Support\ServiceOrderTravelData;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire as ComponentsLivewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
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
                    ->persistTab()
                    ->tabs([
                        Tab::make('Dados Gerais')
                            ->icon(Heroicon::InformationCircle)
                            ->schema([
                                Section::make('Informações Principais')
                                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 12])
                                    ->columnSpanFull()
                                    ->schema([
                                        Hidden::make('number'),
                                        Group::make()
                                            ->columns(['sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12])
                                            ->columnSpanFull()
                                            ->schema([
                                                SelectPartner::make('customer_id', 'customer')
                                                    ->label('Cliente')
                                                    ->columnSpan(['md' => 6, 'lg' => 8, 'xl' => 6])
                                                    ->columnStart(1)
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                                        if (! $state) {
                                                            return;
                                                        }

                                                        $companyId = Filament::getTenant()->id;
                                                        $companyDefaults = app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer($companyId, null);
                                                        $defaults = app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer(
                                                            $companyId,
                                                            (int) $state,
                                                        );

                                                        if (blank($get('payment_method')) || $get('payment_method') === $companyDefaults['payment_method']) {
                                                            $set('payment_method', $defaults['payment_method']);
                                                        }

                                                        if (blank($get('payment_condition')) || $get('payment_condition') === $companyDefaults['payment_condition']) {
                                                            $set('payment_condition', $defaults['payment_condition']);
                                                        }
                                                    })
                                                    ->disabledOn('edit'),
                                                Select::make('equipment_id')
                                                    ->label('Equipamento')
                                                    ->columnSpan(['md' => 6, 'lg' => 8, 'xl' => 6])
                                                    ->searchable()
                                                    ->visibleOn('edit')
                                                    ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false)
                                                    ->getSearchResultsUsing(
                                                        fn (string $search, Get $get): array => (new EquipmentService)
                                                            ->searchForSelect($search, Filament::getTenant()->id, $get('customer_id'), 20, ['owner' => false])
                                                    )
                                                    ->getOptionLabelUsing(
                                                        fn ($value): ?string => (new EquipmentService)
                                                            ->getLabelForSelect((int) $value, ['owner' => false])
                                                    )
                                                    ->disabled(fn ($get) => ! $get('customer_id'))
                                                    ->belowContent(fn ($get) => ! $get('customer_id') ? 'Selecione um cliente para carregar os equipamentos disponíveis' : null),
                                                Select::make('priority')
                                                    ->label('Prioridade')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->required()
                                                    ->visibleOn('edit')
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
                                                    ->columnSpan(fn ($operation) => $operation === 'create' ? ['md' => 3, 'lg' => 4] : ['md' => 2, 'lg' => 2])
                                                    ->required()
                                                    ->default(now())
                                                    ->maxDate(now())
                                                    ->displayFormat('d/m/Y')
                                                    ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                                DatePicker::make('scheduled_date')
                                                    ->label('Data Agendada')
                                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                                    ->visibleOn('edit')
                                                    ->minDate(now())
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
                                                    ->minDate(fn (Get $get) => $get('order_date'))
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
                                Section::make('Anotações')
                                    ->columns(['sm' => 1, 'md' => 3, 'lg' => 12])
                                    ->collapsible()
                                    ->secondary()
                                    ->collapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        Textarea::make('customer_observations')
                                            ->label('Observações do Cliente')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->rows(2)
                                            ->autocomplete(false),
                                        Textarea::make('general_observations')
                                            ->label('Observações gerais')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->rows(2)
                                            ->autocomplete(false),
                                        Textarea::make('items_received')
                                            ->label('Itens Recebidos ')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->rows(2),
                                        Textarea::make('technician_observations')
                                            ->label('Observações do Técnico')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->rows(2)
                                            ->autocomplete(false),
                                        Textarea::make('internal_observations')
                                            ->label('Observações internas')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->rows(2)
                                            ->autocomplete(false),
                                        Textarea::make('solution')
                                            ->label('Solução Aplicada')
                                            ->columnSpan(['md' => 1, 'lg' => 4])
                                            ->rows(2)
                                            ->autocomplete(false),
                                    ]),
                                Section::make('Atendimento')
                                    ->columns(['md' => 6, 'lg' => 12])
                                    ->collapsible()
                                    ->secondary()
                                    ->collapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        Group::make([
                                            Money::make('value_km')
                                                ->label('Valor do KM')
                                                ->live(onBlur: true)
                                                ->afterStateUpdated(fn ($state, Set $set, Get $get) => $set(
                                                    'travel_value',
                                                    ServiceOrderTravelData::format(
                                                        ServiceOrderTravelData::calculate($get('value_km'), $get('distance_km'))
                                                    )
                                                ))
                                                ->default(fn () => CompanyPreference::get('default_value_km', default: 3.50))
                                                ->formatStateUsing(fn ($state) => ServiceOrderTravelData::format(
                                                    filled($state) ? number_format($state, 2, ',', '.') : number_format(CompanyPreference::get('default_value_km', default: 3.50), 2, ',', '.')
                                                ))
                                                ->dehydrateStateUsing(fn ($state) => ServiceOrderTravelData::normalize($state)),
                                            Money::make('distance_km')
                                                ->label('Distância em KM')
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
                                                ->disabled()
                                                ->dehydrated()
                                                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                                ->default(0),
                                        ])->columns(['md' => 3])->columnSpan(['md' => 3, 'lg' => 6]),
                                        Group::make([
                                            Select::make('payment_method')
                                                ->label('Forma de Pagamento')
                                                ->options(PaymentMethod::toSelectArray())
                                                ->native(false)
                                                ->searchable()
                                                ->default(fn () => app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer(
                                                    Filament::getTenant()->id,
                                                    null,
                                                )['payment_method']),
                                            Select::make('payment_condition')
                                                ->label('Condição de Pagamento')
                                                ->options(PaymentCondition::toGroupedSelectArray())
                                                ->native(false)
                                                ->searchable()
                                                ->default(fn () => app(CustomerPaymentDefaultsResolver::class)->defaultsForCustomer(
                                                    Filament::getTenant()->id,
                                                    null,
                                                )['payment_condition']),
                                        ])
                                            ->columns(['md' => 2])
                                            ->columnSpan(['md' => 3, 'lg' => 6]),
                                        DiscountAmountField::make('service_order')
                                            ->saved(false)
                                            ->visible(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        SelectPartner::make('technician_id', 'technician', ['document_number' => false])
                                            ->label('Técnico')
                                            ->placeholder('Técnico')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->required(false)
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        SelectPartner::make('salesperson_id', 'salesperson', ['document_number' => false])
                                            ->label('Vendedor')
                                            ->placeholder('Vendedor')
                                            ->columnSpan(['md' => 2, 'lg' => 2])
                                            ->required(false)
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        TextInput::make('follow_up_responsible_name')
                                            ->label('Representante do cliente')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                        TextInput::make('location')
                                            ->label('Local do Atendimento')
                                            ->columnSpan(['md' => 2, 'lg' => 3])
                                            ->maxLength(255)
                                            ->autocomplete(false)
                                            ->default(fn () => Filament::getTenant()->service_provision_location)
                                            ->formatStateUsing(fn ($state) => $state ?? Filament::getTenant()->service_provision_location)
                                            ->helperText('Cidade - UF')
                                            ->disabled(fn ($record, $operation) => $operation === 'edit' ? ! $record?->state()?->canEdit() : false),
                                    ]),

                                ComponentsLivewire::make(ItemsRelationManager::class, fn (ServiceOrder $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => EditServiceOrder::class,
                                ])
                                    ->key('items-relation-manager')
                                    ->columnSpanFull()
                                    ->visibleOn([Operation::Edit]),
                                ComponentsLivewire::make(ProductsRelationManager::class, fn (ServiceOrder $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => EditServiceOrder::class,
                                ])
                                    ->key('products-relation-manager')
                                    ->columnSpanFull()
                                    ->visibleOn([Operation::Edit]),

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
                                    ->footerActionsAlignment(Alignment::End)
                                    ->footerActions([
                                        CaptureServiceOrderSignatureAction::make(),
                                        Action::make('saveSignature')
                                            ->label('Salvar assinatura')
                                            ->icon(Heroicon::Bookmark)
                                            ->color('primary')
                                            ->action(function ($livewire, Section $component): void {
                                                $livewire->saveFormComponentOnly($component);
                                                $livewire->refreshFormData(['customer_signature', 'customer_signed_at']);

                                                Notification::make()
                                                    ->success()
                                                    ->title('Assinatura salva com sucesso.')
                                                    ->send();
                                            }),
                                    ])
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
                                            ->description('Assine dentro da caixa azul. Use "Limpar" para remover o desenho atual apenas do formulário e clique em "Salvar assinatura" para gravar a nova assinatura ou confirmar a remoção.'),
                                    ]),
                            ]),
                        Tab::make('Remessa')
                            ->visibleOn([Operation::Edit])
                            ->icon(Heroicon::Truck)
                            ->schema([
                                Section::make('Resumo da remessa')
                                    ->columns(['md' => 3, 'lg' => 12])
                                    ->columnSpanFull()
                                    ->schema([
                                        TextEntry::make('remittance_assets_count')
                                            ->label('Itens vinculados')
                                            ->state(fn (ServiceOrder $record): int => $record->remittanceAssets()->count())
                                            ->columnSpan(['md' => 1, 'lg' => 4]),
                                        TextEntry::make('remittance_origin_document')
                                            ->label('NF de remessa')
                                            ->state(fn (ServiceOrder $record): string => self::formatLinkedFiscalDocument(
                                                $record->remittanceAssets()->with('fiscalDocument')->first()?->fiscalDocument
                                            ))
                                            ->url(fn (ServiceOrder $record): ?string => ($originDocument = $record->remittanceAssets()->with('fiscalDocument')->first()?->fiscalDocument)
                                                ? FinancialFiscalDocumentResource::getUrl('edit', ['record' => $originDocument])
                                                : null)
                                            ->openUrlInNewTab()
                                            ->columnSpan(['md' => 1, 'lg' => 4]),
                                        TextEntry::make('remittance_return_document')
                                            ->label('NF de retorno')
                                            ->state(fn (ServiceOrder $record): string => self::formatLinkedFiscalDocument($record->linkedReturnFiscalDocument()))
                                            ->url(fn (ServiceOrder $record): ?string => ($returnDocument = $record->linkedReturnFiscalDocument())
                                                ? SalesFiscalDocumentResource::getUrl('edit', ['record' => $returnDocument])
                                                : null)
                                            ->openUrlInNewTab()
                                            ->columnSpan(['md' => 1, 'lg' => 4]),
                                    ]),
                                Section::make('Ativos recebidos')
                                    ->columnSpanFull()
                                    ->contained(false)
                                    ->schema([
                                        ComponentsLivewire::make(ReceivedAssetsRelationManager::class, fn (ServiceOrder $record) => [
                                            'ownerRecord' => $record,
                                            'pageClass' => EditServiceOrder::class,
                                        ])
                                            ->key('received-assets-relation-manager')
                                            ->columnSpanFull(),
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
                                        ComponentsLivewire::make(AttachmentsRelationManager::class, fn (ServiceOrder $record) => [
                                            'ownerRecord' => $record,
                                            'pageClass' => EditServiceOrder::class,
                                        ])
                                            ->key('attachments-relation-manager')
                                            ->columnSpanFull(),
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

    private static function formatLinkedFiscalDocument(mixed $document): string
    {
        if ($document === null) {
            return '-';
        }

        $number = $document->document_number;
        $series = $document->document_series;

        if (filled($number) && filled($series)) {
            return sprintf('%s / Série %s', $number, $series);
        }

        if (filled($number)) {
            return (string) $number;
        }

        if (filled($document->document_key)) {
            return 'Chave '.$document->document_key;
        }

        return 'Documento #'.$document->id;
    }
}
