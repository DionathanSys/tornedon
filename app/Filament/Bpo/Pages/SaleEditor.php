<?php

namespace App\Filament\Bpo\Pages;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\ProductResource;
use App\Filament\Bpo\Resources\ServiceResource;
use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Filament\Forms\Components\PaymentConditionFields;
use App\Models\AccountReceivable;
use App\Models\BpoSale;
use App\Models\CardInstitution;
use App\Models\CompanyPartner;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\SaleTemplate;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Access\CompanyAuthorization;
use App\Services\Bpo\QuickSaleWorkflow;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class SaleEditor extends Page
{
    use HasWorkbenchHeader;

    protected static string $layout = 'components.bpo.layout';

    protected static ?string $slug = 'preparar-venda';

    protected static ?string $title = 'Preparar venda';

    protected string $view = 'filament.bpo.pages.sale-editor';

    #[Url]
    #[Locked]
    public ?int $sale = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public string $requestId;

    public ?array $data = [];

    private ?array $permissions = null;

    public static function canAccess(): bool
    {
        return Sales::canAccess();
    }

    public function booted(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function allows(string $permission): bool
    {
        $this->permissions ??= app(CompanyAuthorization::class)->effectivePermissions(Filament::auth()->user(), Filament::getTenant());

        return ($this->permissions[$permission] ?? []) !== [];
    }

    public function workbenchEyebrow(): string
    {
        return 'VENDAS · FATURAMENTO';
    }

    public function workbenchDescription(): string
    {
        return 'Revise a origem, os itens, o conteúdo da nota e o financeiro antes de finalizar.';
    }

    public function mount(): void
    {
        $this->requestId = (string) Str::uuid();
        $workflow = app(QuickSaleWorkflow::class);
        if (! $this->sale) {
            $workflow->authorize(Filament::auth()->user(), Filament::getTenant(), 'bpo_sales.create');
            $this->form->fill(['source_type' => 'new', 'sale_date' => today()->toDateString(), 'payment_date' => today()->toDateString(), 'items' => []]);

            return;
        }
        $sale = $workflow->find(Filament::auth()->user(), Filament::getTenant(), $this->sale, 'update');
        if ($sale->finalized_at) {
            $this->redirect(Sales::getUrl());

            return;
        }
        $source = $sale->source();
        $workflow->authorize(Filament::auth()->user(), Filament::getTenant(), $source instanceof ServiceOrder ? 'service_orders.view' : 'requisitions.view', $source);
        $this->revision = $sale->revision;
        $this->requestId = $sale->request_id;
        $settings = $sale->payment_settings ?? [];
        $settings['financial_policy'] ??= 'create_receivable';
        $settings['payment_method'] ??= Method::PIX->value;
        $this->form->fill(array_replace([
            'source_type' => 'new', 'sale_template_id' => $sale->sale_template_id,
            'document_type' => $sale->template_snapshot['document_type'], 'customer_id' => $source->customer_id,
            'sale_date' => ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString(),
            'payment_date' => ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString(),
            'received_at' => today()->toDateString(),
            'items' => $this->sourceItems($source),
            'travel_amount' => $source instanceof ServiceOrder ? (float) $source->travel_value : 0,
            'has_product_items' => $source instanceof ServiceOrder && ($source->requisition?->items()->exists() ?? false),
            'fiscal_description' => $sale->payment_settings['description'] ?? null,
            'fiscal_additional_information' => $sale->template_snapshot['additional_information'] ?? null,
        ], $settings));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Origem da venda')->description('Inicie uma venda ou utilize os itens de um documento operacional.')->columns(2)->schema([
                Select::make('source_type')->native(false)->label('Origem')->options(['new' => 'Nova venda', 'service_order' => 'Ordem de serviço (OS)', 'requisition' => 'Requisição', 'production_order' => 'Ordem de produção (OP)'])
                    ->required()->live()->disabled(fn () => (bool) $this->sale)->dehydrated()
                    ->afterStateUpdated(function (Set $set): void {
                        foreach (['source_id', 'customer_id', 'document_type', 'sale_template_id'] as $field) {
                            $set($field, null);
                        }
                        $set('items', []);
                        $set('has_product_items', false);
                    }),
                Select::make('source_id')->native(false)->label('Documento de origem')->searchable()->live()
                    ->options(fn (Get $get) => $this->sourceOptions($get('source_type')))
                    ->getSearchResultsUsing(fn (Get $get, string $search) => $this->sourceOptions($get('source_type'), $search))
                    ->getOptionLabelUsing(fn ($value, Get $get) => $this->sourceOptions($get('source_type'), id: $value)[$value] ?? null)
                    ->visible(fn (Get $get) => $get('source_type') !== 'new')->required(fn (Get $get) => $get('source_type') !== 'new')
                    ->helperText(fn (Get $get) => $get('source_type') === 'production_order' ? 'Selecione uma OP concluída, com requisição gerada pela produção.' : 'A OS inclui os serviços e os produtos da requisição vinculada.')
                    ->disabled(fn () => (bool) $this->sale)->dehydrated()->afterStateUpdated(fn ($state) => $this->loadSource($state)),
                Select::make('sale_template_id')->native(false)->label('Modelo de venda')->options(fn (Get $get) => SaleTemplate::where('company_id', Filament::getTenant()->id)->where('is_active', true)
                    ->when($get('source_type') !== 'new', fn ($query) => $query->where('document_type', $get('source_type') === 'service_order' ? 'nfse' : 'nfe'))->pluck('name', 'id'))
                    ->searchable()->required()->live()->disabled(fn () => (bool) $this->sale)->dehydrated()
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        $template = SaleTemplate::where('company_id', Filament::getTenant()->id)->find($state);
                        if ($get('source_type') === 'new' && $get('document_type') !== $template?->document_type?->value) {
                            $set('items', []);
                        }
                        $set('document_type', $template?->document_type?->value);
                        $set('fiscal_additional_information', $template?->additional_information);
                    }),
                Hidden::make('document_type'),
                Select::make('customer_id')->native(false)->label('Cliente')->searchable()->required()
                    ->options(fn () => $this->customers())
                    ->getSearchResultsUsing(fn (string $search) => $this->customers($search))
                    ->getOptionLabelUsing(fn ($value) => $this->customers(id: $value)[$value] ?? null)
                    ->disabled(fn (Get $get) => $get('source_type') !== 'new')->dehydrated(),
                DatePicker::make('sale_date')->label('Data da venda')->required()->disabled(fn (Get $get) => $get('source_type') !== 'new')->dehydrated(),
                Textarea::make('description')->label('Observações dos itens de origem')->maxLength(1000)->columnSpanFull()
                    ->helperText('Podem compor as informações adicionais geradas automaticamente na nota.')
                    ->visible(fn (Get $get) => $get('source_type') === 'new'),
            ]),
            Section::make('Itens da venda')->description('Confira quantidades, descontos e valores líquidos.')->schema([
                Hidden::make('items')->default([]),
                Hidden::make('travel_amount')->default(0),
                Hidden::make('has_product_items')->default(false),
                View::make('filament.bpo.pages.sale-items'),
            ]),
            Section::make(fn (Get $get) => $get('document_type') === 'nfe' ? 'Informações adicionais' : 'Conteúdo da nota fiscal')->description('Este conteúdo será levado ao documento fiscal na finalização da venda.')->schema([
                Textarea::make('fiscal_description')->label('Discriminação do serviço na NFS-e')->rows(6)->maxLength(10000)
                    ->visible(fn (Get $get) => $get('document_type') === 'nfse')
                    ->helperText('Descreva o serviço, período de referência, contrato e demais informações que devem constar no corpo da nota. Em branco, será usada a descrição dos serviços.'),
                Textarea::make('fiscal_additional_information')->label(fn (Get $get) => $get('document_type') === 'nfse' ? 'Informações adicionais da NFS-e' : 'Informações ao contribuinte na NF-e')
                    ->rows(4)->maxLength(2000)->helperText('Texto específico desta venda. Revise as informações sugeridas pelo modelo.'),
                Textarea::make('additional_tax_information')->label('Informações ao Fisco')->rows(3)->maxLength(2000)
                    ->visible(fn (Get $get) => $this->hasNfe($get)),
            ]),
            Section::make('Informações adicionais de compra')->description('Dados de compra que constarão na NF-e.')->columns(['md' => 3])
                ->visible(fn (Get $get) => $this->hasNfe($get))->schema([
                    TextInput::make('additional_purchase_information_nota_empenho')->label('Nota de Empenho')->maxLength(60),
                    TextInput::make('additional_purchase_information_pedido')->label('Pedido')->maxLength(60),
                    TextInput::make('additional_purchase_information_contrato')->label('Contrato')->maxLength(60),
                ]),
            Section::make('Pagamento e conclusão')->schema([
                Grid::make(2)->schema($this->paymentSchema()),
            ])->visible(fn () => $this->allows('bpo_sales.approve')),
        ]);
    }

    private function hasNfe(Get $get): bool
    {
        return $get('document_type') === 'nfe' || (bool) $get('has_product_items');
    }

    private function saleMoneyValue(mixed $value): float
    {
        return (float) (is_string($value) && str_contains($value, ',')
            ? str_replace(',', '.', str_replace('.', '', $value))
            : $value);
    }

    private function saleMoneyField(string $name): Money
    {
        return Money::make($name)
            ->formatStateUsing(fn ($state) => $state === null ? null : number_format($this->saleMoneyValue($state), 2, ',', '.'))
            ->mutateStateForValidationUsing(fn ($state) => filled($state) ? $this->saleMoneyValue($state) : $state)
            ->dehydrateStateUsing(fn ($state) => $this->saleMoneyValue($state))
            ->rules(['numeric']);
    }

    private function paymentSchema(): array
    {
        return [
            TextEntry::make('review_total')->label('Total da venda')->state(fn (Get $get) => 'R$ '.number_format(
                collect($get('items') ?? [])->sum(fn (array $item) => round(round((float) ($item['quantity'] ?? 0), 3) * round($this->saleMoneyValue($item['unit_price'] ?? 0), 2), 2) - round($this->saleMoneyValue($item['discount_amount'] ?? 0), 2)) + (float) ($get('travel_amount') ?? 0), 2, ',', '.'))->columnSpanFull(),
            Select::make('financial_policy')->native(false)->label('Tratamento financeiro')->options(['create_receivable' => 'Gerar contas a receber', 'link_existing' => 'Vincular recebível existente', 'fiscal_only' => 'Somente emitir nota'])->default('create_receivable')->required()->live()
                ->columnSpanFull()->afterStateUpdated(function (Set $set): void {
                    $set('mark_as_received', false);
                    $set('auto_bank_slip_issuance', false);
                    $set('auto_pix_charge_issuance', false);
                }),
            Select::make('financial_target_id')->native(false)->label('Recebível existente')->searchable()->options(fn (Get $get) => AccountReceivable::where('company_id', Filament::getTenant()->id)
                ->where('customer_id', $get('customer_id'))->whereNull('invoice_id')->whereNull('fiscal_document_id')->limit(50)->get()->mapWithKeys(fn ($title) => [$title->id => '#'.$title->id.' · '.$title->description.' · R$ '.number_format($title->due_amount, 2, ',', '.')]))
                ->getSearchResultsUsing(fn (Get $get, string $search) => AccountReceivable::where('company_id', Filament::getTenant()->id)->where('customer_id', $get('customer_id'))
                    ->whereNull('invoice_id')->whereNull('fiscal_document_id')->where(fn ($query) => $query->where('description', 'like', '%'.$search.'%')->orWhere('document_number', 'like', '%'.$search.'%'))
                    ->limit(50)->get()->mapWithKeys(fn ($title) => [$title->id => '#'.$title->id.' · '.$title->description]))
                ->getOptionLabelUsing(fn ($value) => AccountReceivable::where('company_id', Filament::getTenant()->id)->find($value)?->description)
                ->visible(fn (Get $get) => $get('financial_policy') === 'link_existing')->required(fn (Get $get) => $get('financial_policy') === 'link_existing'),
            Select::make('payment_method')->native(false)->label('Forma de pagamento')->options(fn () => $this->allows('card_institutions.view') ? Method::toSelectArray() : array_diff_key(Method::toSelectArray(), [Method::CREDIT_CARD->value => true]))->default(Method::PIX->value)->required()->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('auto_bank_slip_issuance', false);
                    $set('auto_pix_charge_issuance', false);
                    $set('mark_as_received', false);
                }),
            Select::make('card_payment_profile_id')->native(false)->label('Instituição de cartão')->searchable()->options(fn () => CardInstitution::where('company_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value)->required(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value),
            DatePicker::make('payment_date')->label('Data da venda no cartão')->default(today())
                ->visible(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value)->required(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value),
            Select::make('payment_condition')->native(false)->label('Condição de pagamento')->options(Condition::toGroupedSelectArray())->required()->live(),
            PaymentConditionFields::make(),
            SelectFinancialCategory::make('financial_category_id', 'receivable')->columnSpanFull(),
            Toggle::make('mark_as_received')->label('O valor já foi recebido na conta da empresa')->default(false)->live()
                ->columnSpanFull()->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        $set('auto_bank_slip_issuance', false);
                        $set('auto_pix_charge_issuance', false);
                    }
                })
                ->visible(fn (Get $get) => $get('financial_policy') === 'create_receivable' && $this->allows('account_receivables.settle') && $this->allows('cash_movements.create') && $this->allows('financial_accounts.view'))
                ->helperText('Registra a baixa e a entrada no caixa. No cartão, marque somente se o repasse já entrou na conta.'),
            DatePicker::make('received_at')->label('Data do recebimento')->default(today())->visible(fn (Get $get) => (bool) $get('mark_as_received'))
                ->required(fn (Get $get) => (bool) $get('mark_as_received')),
            Toggle::make('auto_bank_slip_issuance')->label('Gerar boletos automaticamente')->default(false)->live()->columnSpanFull()
                ->visible(fn (Get $get) => $get('financial_policy') === 'create_receivable' && ! $get('mark_as_received') && $get('payment_method') === Method::BANK_SLIP->value && $this->allows('account_receivables.issue') && $this->allows('financial_accounts.view')),
            Toggle::make('auto_pix_charge_issuance')->label('Gerar cobrança PIX automaticamente')->default(false)->live()->columnSpanFull()
                ->visible(fn (Get $get) => $get('financial_policy') === 'create_receivable' && ! $get('mark_as_received') && $get('payment_method') === Method::PIX->value && $this->allows('account_receivables.issue') && $this->allows('financial_accounts.view')),
            Select::make('financial_account_id')->native(false)->label('Conta de cobrança / recebimento')->options(fn () => FinancialAccount::optionsForCompany(Filament::getTenant()->id))->searchable()->columnSpanFull()
                ->visible(fn (Get $get) => $get('mark_as_received') || $get('auto_bank_slip_issuance') || $get('auto_pix_charge_issuance'))
                ->required(fn (Get $get) => $get('mark_as_received') || $get('auto_bank_slip_issuance') || $get('auto_pix_charge_issuance')),
            Toggle::make('request_emission')->label('Solicitar emissão após finalizar')->default(false)->columnSpanFull()
                ->visible(fn () => $this->allows('fiscal_documents.issue'))
                ->helperText('A emissão fiscal é independente das opções de cobrança e recebimento acima.'),
        ];
    }

    private function customers(string $search = '', mixed $id = null): array
    {
        return CompanyPartner::where('company_id', Filament::getTenant()->id)->whereJsonContains('company_partner.type', 'customer')
            ->where('is_active', true)->when($id, fn ($query) => $query->where('partner_id', $id))
            ->when($search, fn ($query) => $query->whereHas('partner', fn ($query) => $query->where('name', 'like', '%'.$search.'%')))
            ->with('partner')->limit(50)->get()->mapWithKeys(fn ($link) => [$link->partner_id => $link->partner->name])->all();
    }

    private function sourceQuery(?string $type): ?Builder
    {
        [$model, $module] = match ($type) {
            'service_order' => [ServiceOrder::class, 'service_orders'],
            'requisition' => [Requisition::class, 'requisitions'],
            'production_order' => [ProductionOrder::class, 'production_orders'],
            default => [null, null],
        };
        if (! $model || ! $this->allows($module.'.view')) {
            return null;
        }

        return app(CompanyAuthorization::class)->scopeQuery($model::query(), Filament::auth()->user(), Filament::getTenant(), $module.'.view')
            ->where('company_id', Filament::getTenant()->id)->whereNull('invoice_id');
    }

    private function sourceOptions(?string $type, string $search = '', mixed $id = null): array
    {
        $query = $this->sourceQuery($type);
        if (! $query) {
            return [];
        }
        $number = $type === 'production_order' ? 'production_order_number' : 'number';

        return $query->when($id, fn ($query) => $query->whereKey($id))
            ->when($type === 'production_order', fn ($query) => $query->where('status', 'completed')->whereHas('requisition', fn ($query) => $query->whereNull('invoice_id')))
            ->when($type === 'service_order', fn ($query) => $query->whereIn('status', ['aberta', 'encerrada']))
            ->when($type === 'requisition', fn ($query) => $query->whereIn('status', ['open', 'closed'])->whereNull('service_order_id'))
            ->when($search, fn ($query) => $query->where(fn ($query) => $query->where($number, 'like', '%'.$search.'%')->orWhereHas('customer', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))))
            ->with('customer')->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn ($record) => [$record->id => '#'.$record->{$number}.' · '.$record->customer?->name])->all();
    }

    public function loadSource(mixed $id): void
    {
        if ($this->sale) {
            return;
        }
        $selected = $this->sourceQuery($this->data['source_type'] ?? null)?->find($id);
        $source = $selected instanceof ProductionOrder ? $selected->requisition : $selected;
        if (! $source) {
            $this->data['items'] = [];

            return;
        }
        $module = $source instanceof ServiceOrder ? 'service_orders' : 'requisitions';
        app(QuickSaleWorkflow::class)->authorize(Filament::auth()->user(), Filament::getTenant(), $module.'.view', $source);
        $this->data['customer_id'] = $source->customer_id;
        $this->data['sale_date'] = ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString();
        $this->data['document_type'] = $source instanceof ServiceOrder ? 'nfse' : 'nfe';
        $this->data['items'] = $this->sourceItems($source);
        $this->data['travel_amount'] = $source instanceof ServiceOrder ? (float) $source->travel_value : 0;
        $this->data['has_product_items'] = $source instanceof ServiceOrder && ($source->requisition?->items()->exists() ?? false);
    }

    private function sourceItems(ServiceOrder|Requisition $source): array
    {
        $source->loadMissing($source instanceof ServiceOrder ? 'items.service' : 'items.product');

        $items = $source->items->map(fn ($item) => ['catalog_id' => $item->service_id ?? $item->product_id,
            'label' => $item->service?->name ?? $item->product?->name, 'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price, 'discount_amount' => (float) $item->discount_amount])->all();
        if ($source instanceof ServiceOrder && $source->requisition) {
            app(QuickSaleWorkflow::class)->authorize(Filament::auth()->user(), Filament::getTenant(), 'requisitions.view', $source->requisition);
            $items = [...$items, ...$this->sourceItems($source->requisition)];
        }

        return $items;
    }

    public function catalogAction(): Action
    {
        $resource = ($this->data['document_type'] ?? null) === 'nfse' ? ServiceResource::class : ProductResource::class;

        return Action::make('catalog')->label($resource === ServiceResource::class ? 'Cadastrar serviço' : 'Cadastrar produto')
            ->color('gray')->icon('heroicon-o-plus')->url($resource::getUrl('create'))->openUrlInNewTab()
            ->visible(fn () => ($this->data['source_type'] ?? 'new') === 'new' && filled($this->data['document_type'] ?? null) && $resource::canCreate());
    }

    public function addItemAction(): Action
    {
        return $this->itemAction('addItem');
    }

    public function editItemAction(): Action
    {
        return $this->itemAction('editItem', true)->iconButton()->color('gray')->tooltip('Editar item');
    }

    private function itemAction(string $name, bool $editing = false): Action
    {
        return Action::make($name)->label($editing ? 'Editar item' : 'Adicionar item')->icon($editing ? 'heroicon-o-pencil-square' : 'heroicon-o-plus')
            ->modalWidth('3xl')->modalSubmitActionLabel($editing ? 'Salvar item' : 'Adicionar à venda')
            ->visible(fn () => ($this->data['source_type'] ?? 'new') === 'new' && filled($this->data['document_type'] ?? null))
            ->fillForm(fn (array $arguments) => $editing ? ($this->data['items'][$arguments['key'] ?? ''] ?? []) : ['quantity' => 1, 'discount_amount' => 0])
            ->schema([
                Select::make('catalog_id')->native(false)->label(($this->data['document_type'] ?? null) === 'nfse' ? 'Serviço' : 'Produto')->required()->searchable()->live()
                    ->options(fn () => $this->catalogOptionsForItems())
                    ->getSearchResultsUsing(fn (string $search) => $this->catalogOptionsForItems($search))
                    ->getOptionLabelUsing(fn ($value) => $this->catalogOptionsForItems(id: $value)[$value] ?? null)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $catalog = $this->catalogQuery()->find($state);
                        $price = $catalog instanceof Service ? $catalog->price : $catalog?->sale_price_value;
                        $set('unit_price', $price === null ? null : number_format((float) $price, 2, ',', '.'));
                    }),
                Grid::make(3)->schema([
                    TextInput::make('quantity')->label('Quantidade')->numeric()->minValue(0.001)->maxValue(999999)->required(),
                    $this->saleMoneyField('unit_price')->label('Preço unitário')->minValue(0.01)->maxValue(999999999)->required(),
                    $this->saleMoneyField('discount_amount')->label('Desconto (R$)')->minValue(0)->maxValue(999999999),
                ]),
            ])->action(function (array $data, array $arguments) use ($editing): void {
                abort_unless(($this->data['source_type'] ?? null) === 'new', 403);
                if (($this->data['document_type'] ?? null) === 'nfse' && ! $editing && count($this->data['items'] ?? [])) {
                    throw ValidationException::withMessages(['catalog_id' => 'Utilize um serviço por NFS-e. Edite o serviço existente para ajustar a venda.']);
                }
                $catalog = $this->catalogQuery()->findOrFail($data['catalog_id']);
                if ($data['discount_amount'] >= round($data['quantity'] * $data['unit_price'], 2)) {
                    throw ValidationException::withMessages(['discount_amount' => 'O desconto deve ser menor que o total do item.']);
                }
                $key = $editing ? ($arguments['key'] ?? '') : (string) Str::uuid();
                abort_if($editing && ! isset($this->data['items'][$key]), 404);
                $this->data['items'][$key] = $data + ['label' => $catalog->name];
            });
    }

    private function catalogQuery(): Builder
    {
        $isService = ($this->data['document_type'] ?? null) === 'nfse';

        return app(CompanyAuthorization::class)->scopeQuery($isService ? Service::query() : Product::query(), Filament::auth()->user(), Filament::getTenant(), $isService ? 'services.view' : 'products.view')
            ->where('company_id', Filament::getTenant()->id)->where('is_active', true);
    }

    private function catalogOptionsForItems(string $search = '', mixed $id = null): array
    {
        return $this->catalogQuery()->when($id, fn ($query) => $query->whereKey($id))
            ->when($search, fn ($query) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('name')->limit(50)->pluck('name', 'id')->all();
    }

    public function removeItem(string $key): void
    {
        abort_unless(($this->data['source_type'] ?? null) === 'new', 403);
        unset($this->data['items'][$key]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Voltar às vendas')->color('gray')->url(Sales::getUrl())];
    }

    public function saveDraft(): void
    {
        $this->persist(false);
    }

    public function finalizeSale(): void
    {
        $this->persist(true);
    }

    private function persist(bool $finalize): void
    {
        $this->resetErrorBag();
        $workflow = app(QuickSaleWorkflow::class);
        $user = Filament::auth()->user();
        $company = Filament::getTenant();
        if ($finalize) {
            $workflow->authorize($user, $company, 'bpo_sales.approve');
        }
        // Drafts do not require payment terms; completion validates the entire schema.
        if ($finalize) {
            $data = $this->form->getState();
        } else {
            $data = $this->data;
        }
        $data['items'] = array_values($data['items'] ?? []);
        try {
            $sale = DB::transaction(function () use ($workflow, $user, $company, $data, $finalize): BpoSale {
                $method = ($data['source_type'] ?? 'new') === 'new' ? 'save' : 'saveExisting';
                $sale = $workflow->{$method}($user, $company, $data, $this->requestId, $this->sale, $this->revision);

                return $finalize ? $workflow->finalize($user, $company, $sale->id, $sale->revision, $data) : $sale;
            });
        } catch (ValidationException $exception) {
            Notification::make()->title($finalize ? 'Não foi possível finalizar a venda' : 'Não foi possível salvar o rascunho')
                ->body(collect($exception->errors())->flatten()->unique()->implode("\n"))->danger()->send();
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }
        $this->sale = $sale->id;
        $this->revision = $sale->revision;
        Notification::make()->title($finalize ? 'Venda finalizada' : 'Rascunho salvo')->success()->send();
        if ($finalize && ($data['request_emission'] ?? false)) {
            try {
                $workflow->requestEmission($user, $company, $sale->id);
                Notification::make()->title('Emissão solicitada')->success()->send();
            } catch (ValidationException $exception) {
                Notification::make()->title('Venda finalizada, mas a emissão não foi solicitada')
                    ->body(collect($exception->errors())->flatten()->unique()->implode("\n"))->danger()->send();
            }
        }
        $this->redirect($finalize ? Sales::getUrl() : static::getUrl(['sale' => $sale->id]), navigate: true);
    }
}
