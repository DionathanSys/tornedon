<?php

namespace App\Filament\Bpo\Pages;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\InvoiceResource;
use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Filament\Forms\Components\PaymentConditionFields;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\AccountReceivable;
use App\Models\BpoSale;
use App\Models\CardInstitution;
use App\Models\CompanyPartner;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SaleTemplate;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Services\Bpo\QuickSaleWorkflow;
use App\Services\Bpo\SalesReportingService;
use App\Services\Invoice\InvoiceService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Sales extends Page implements HasTable
{
    use HasWorkbenchHeader;
    use InteractsWithTable;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.sales';

    protected static ?string $title = 'Vendas e faturamento';

    #[Locked]
    public string $requestId;

    private ?array $permissions = null;

    private array $catalogOptions = [];

    private array $templateTypes = [];

    private array $catalogLabels = [];

    public function workbenchEyebrow(): string
    {
        return 'VENDAS · FATURAMENTO';
    }

    public function workbenchDescription(): string
    {
        return 'Prepare vendas usando OS e requisições, finalize o financeiro e solicite a emissão agora ou depois.';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() && app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'bpo_sales.view', 'bpo');
    }

    public function mount(): void
    {
        $this->requestId = (string) Str::uuid();
    }

    public function booted(): void
    {
        $this->permissions = null;
        $this->catalogOptions = [];
        $this->templateTypes = [];
        $this->catalogLabels = [];
        abort_unless(static::canAccess(), 403);
    }

    private function allows(string $permission): bool
    {
        $this->permissions ??= app(CompanyAuthorization::class)->effectivePermissions(Filament::auth()->user(), Filament::getTenant());

        return ($this->permissions[$permission] ?? []) !== [];
    }

    private function templateType(mixed $id): ?string
    {
        return $this->templateTypes[$id ?? ''] ??= SaleTemplate::where('company_id', Filament::getTenant()->id)->find($id)?->document_type?->value;
    }

    private function customerOptions(string $search = '', bool $activeOnly = true): array
    {
        return CompanyPartner::where('company_id', Filament::getTenant()->id)->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->whereJsonContains('company_partner.type', 'customer')
            ->when($search !== '', fn ($query) => $query->whereHas('partner', fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('document_number', 'like', '%'.$search.'%')))
            ->with('partner')->limit(50)->get()->mapWithKeys(fn ($link) => [$link->partner_id => $link->partner->name])->all();
    }

    private function customerLabel(mixed $id): ?string
    {
        return CompanyPartner::where('company_id', Filament::getTenant()->id)->where('partner_id', $id)->with('partner')->first()?->partner?->name;
    }

    private function catalogResults(Get $get, string $search = ''): array
    {
        $type = $get('../../document_type') ?: $this->templateType($get('../../sale_template_id'));
        if (! $type) {
            return [];
        }

        return $this->catalogOptions[$type.':'.$search] ??= ($type === 'nfse' ? Service::query() : Product::query())
            ->where('company_id', Filament::getTenant()->id)->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('name')->limit(50)->pluck('name', 'id')->all();
    }

    private function catalogLabel(Get $get, mixed $value): ?string
    {
        $type = $get('../../document_type') ?: $this->templateType($get('../../sale_template_id'));
        if (! $type || ! $value) {
            return null;
        }
        if (! array_key_exists($type.':'.$value, $this->catalogLabels)) {
            $ids = collect($get('../../items') ?? [])->pluck('catalog_id')->push($value)->filter(fn ($id) => is_scalar($id) && filled($id))->unique();
            foreach (($type === 'nfse' ? Service::query() : Product::query())->where('company_id', Filament::getTenant()->id)->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
                $this->catalogLabels[$type.':'.$id] = $name;
            }
            $this->catalogLabels[$type.':'.$value] ??= null;
        }

        return $this->catalogLabels[$type.':'.$value];
    }

    private function saleAction(string $name, bool $editing = false): Action
    {
        return Action::make($name)->label($editing ? 'Continuar rascunho' : 'Nova venda')->icon('heroicon-o-shopping-cart')
            ->modalHeading($editing ? 'Continuar venda' : 'Nova venda')->modalWidth('5xl')->stickyModalFooter()
            ->modalSubmitActionLabel(fn () => ($this->mountedActions[array_key_last($this->mountedActions)]['data']['finalize_now'] ?? false) && $this->allows('bpo_sales.approve') ? 'Finalizar venda' : 'Salvar rascunho')
            ->visible(fn (?BpoSale $record) => ! $record?->finalized_at && $this->allows('bpo_sales.'.($editing ? 'update' : 'create')))
            ->fillForm(function (?BpoSale $record): array {
                if (! $record) {
                    return ['sale_date' => today()->toDateString(), 'items' => [['quantity' => 1, 'discount_amount' => 0]]];
                }
                $sale = app(QuickSaleWorkflow::class)->find(Filament::auth()->user(), Filament::getTenant(), $record->id, 'update');
                $source = $sale->source();

                return ['revision' => $sale->revision, 'document_type' => $sale->template_snapshot['document_type'], 'sale_template_id' => $sale->sale_template_id, 'customer_id' => $source->customer_id,
                    'sale_date' => ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString(),
                    'payment_date' => ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString(),
                    'description' => $sale->payment_settings['description'] ?? null,
                    'items' => $source->items->map(fn ($item) => ['catalog_id' => $item->service_id ?? $item->product_id, 'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price, 'discount_amount' => $item->discount_amount])->all()] + ($sale->payment_settings ?? []);
            })->steps([
                Step::make('Dados da venda')->icon('heroicon-o-document-text')->schema([
                    Hidden::make('revision')->default(0),
                    Hidden::make('document_type'),
                    Select::make('sale_template_id')->label('Modelo de venda')->options(fn () => SaleTemplate::where('company_id', Filament::getTenant()->id)->where('is_active', true)->pluck('name', 'id'))
                        ->required()->searchable()->live()->disabled($editing)->dehydrated()->afterStateUpdated(function ($state, Set $set): void {
                            $template = SaleTemplate::where('company_id', Filament::getTenant()->id)->find($state);
                            $set('document_type', $template?->document_type?->value);
                            // Replacing hydrated rows must preserve UUID keys and initialize every
                            // field, including null values required by Livewire's Alpine entanglement.
                            $set('items', [(string) Str::uuid() => [
                                'catalog_id' => null, 'quantity' => 1, 'unit_price' => null, 'discount_amount' => 0,
                            ]]);
                        }),
                    Select::make('customer_id')->label('Cliente')->options(fn () => $this->customerOptions())->searchable()
                        ->getSearchResultsUsing(fn (string $search) => $this->customerOptions($search))->getOptionLabelUsing(fn ($value) => $this->customerLabel($value))->required(),
                    DatePicker::make('sale_date')->label('Data da venda')->required(),
                    Textarea::make('description')->label('Descrição do serviço / observações')->maxLength(1000),
                ]),
                Step::make('Itens')->icon('heroicon-o-shopping-cart')->schema([
                    Repeater::make('items')->label('Itens da venda')->minItems(1)->maxItems(100)->columns(4)->schema([
                        Select::make('catalog_id')->label('Serviço ou produto')->required()->searchable()->live()->options(fn (Get $get) => $this->catalogResults($get))
                            ->getSearchResultsUsing(fn (Get $get, string $search) => $this->catalogResults($get, $search))
                            ->getOptionLabelUsing(fn (Get $get, $value) => $this->catalogLabel($get, $value))
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $catalog = (($get('../../document_type') ?: $this->templateType($get('../../sale_template_id'))) === 'nfse' ? Service::query() : Product::query())
                                    ->where('company_id', Filament::getTenant()->id)->find($state);
                                $set('unit_price', $catalog instanceof Service ? $catalog->price : $catalog?->sale_price_value);
                            }),
                        TextInput::make('quantity')->label('Quantidade')->numeric()->minValue(0.001)->required()->default(1),
                        TextInput::make('unit_price')->label('Preço unitário (R$)')->numeric()->minValue(0.01)->required(),
                        TextInput::make('discount_amount')->label('Desconto (R$)')->numeric()->minValue(0)->default(0),
                    ])->helperText('NFS-e: um serviço por venda. NF-e: múltiplos produtos. Os valores são recalculados no servidor.'),
                ]),
                Step::make('Conclusão')->icon('heroicon-o-check-circle')->schema([
                    Toggle::make('finalize_now')->label('Finalizar venda agora')->default(false)->live()
                        ->visible(fn () => $this->allows('bpo_sales.approve'))
                        ->helperText('Ative para revisar o pagamento e gerar a fatura. Desativado, a venda será salva como rascunho.'),
                    Grid::make(['default' => 1, 'md' => 2])->schema($this->paymentSchema(inline: true))
                        ->visible(fn (Get $get) => (bool) $get('finalize_now') && $this->allows('bpo_sales.approve')),
                ]),
            ])->action(function (array $data, ?BpoSale $record) use ($editing): void {
                $finalize = (bool) ($data['finalize_now'] ?? false);
                $workflow = app(QuickSaleWorkflow::class);
                $sale = $this->executeWorkflow(fn () => DB::transaction(function () use ($workflow, $data, $record, $editing, $finalize): BpoSale {
                    $sale = $workflow->save(Filament::auth()->user(), Filament::getTenant(), $data, $this->requestId,
                        $editing ? $record->id : null, (int) ($data['revision'] ?? 0));

                    return $finalize ? $workflow->finalize(Filament::auth()->user(), Filament::getTenant(), $sale->id, $sale->revision, $data) : $sale;
                }), $finalize ? 'Não foi possível finalizar a venda' : 'Não foi possível salvar o rascunho');
                $this->requestId = (string) Str::uuid();
                if (! $finalize) {
                    Notification::make()->title('Rascunho salvo')->body('Continue a venda para revisar o pagamento e finalizar.')->success()->send();

                    return;
                }
                Notification::make()->title('Venda finalizada')->body('Fatura criada. O tratamento financeiro foi registrado.')->success()->send();
                if ($data['request_emission'] ?? false) {
                    try {
                        $workflow->requestEmission(Filament::auth()->user(), Filament::getTenant(), $sale->id);
                        Notification::make()->title('Emissão solicitada')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Venda finalizada, mas a emissão não foi solicitada')
                            ->body(e(collect($exception->errors())->flatten()->unique()->implode("\n")))->danger()->send();
                    }
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->saleAction('newSale'), Action::make('templates')->label('Modelos de venda')->url(SaleTemplates::getUrl(panel: 'bpo', tenant: Filament::getTenant()))->visible(fn () => SaleTemplates::canAccess()),
            Action::make('invoices')->label('Faturas e exportações')->url(InvoiceResource::getUrl())->visible(fn () => $this->allows('invoices.view'))];
    }

    private function executeWorkflow(callable $operation, string $failureTitle): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            Notification::make()->title($failureTitle)->body(e(collect($exception->errors())->flatten()->unique()->implode("\n")))->danger()->send();

            $schema = $this->getMountedActionSchema();
            if (! $schema) {
                throw $exception;
            }
            $prefix = $schema->getStatePath();
            $itemKeys = array_keys($schema->getRawState()['items'] ?? []);
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $field = Str::startsWith($field, 'data.') ? Str::after($field, 'data.') : $field;
                // Workflows validate dehydrated (numeric) rows; the live repeater uses UUIDs.
                if (preg_match('/^items\.(\d+)(\..*)?$/', $field, $matches) && isset($itemKeys[(int) $matches[1]])) {
                    $field = 'items.'.$itemKeys[(int) $matches[1]].($matches[2] ?? '');
                }
                $path = $prefix.'.'.$field;
                if (! $schema->getComponentByStatePath($path, withHidden: true, withAbsoluteStatePath: true)) {
                    $path = $prefix.'.'.(array_key_exists('items', $schema->getRawState()) ? 'items' : 'financial_policy');
                }
                $errors[$path] = [...($errors[$path] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function paymentSchema(bool $inline = false): array
    {
        return [
            ...($inline ? [] : [Hidden::make('revision')]),
            TextEntry::make('review_total')->label('Total da venda')->state(fn (Get $get, ?BpoSale $record) => 'R$ '.number_format($inline
                ? collect($get('items') ?? [])->sum(fn (array $item) => round(round((float) ($item['quantity'] ?? 0), 3) * round((float) ($item['unit_price'] ?? 0), 2), 2) - round((float) ($item['discount_amount'] ?? 0), 2))
                : $record->source()->total_amount, 2, ',', '.'))->columnSpanFull(),
            Select::make('financial_policy')->label('Tratamento financeiro')->options(['create_receivable' => 'Gerar contas a receber', 'link_existing' => 'Vincular recebível existente', 'fiscal_only' => 'Somente emitir nota'])->default('create_receivable')->required()->live()
                ->columnSpanFull()->afterStateUpdated(function (Set $set): void {
                    $set('mark_as_received', false);
                    $set('auto_bank_slip_issuance', false);
                    $set('auto_pix_charge_issuance', false);
                }),
            Select::make('financial_target_id')->label('Recebível existente')->searchable()->options(fn (Get $get, ?BpoSale $record) => AccountReceivable::where('company_id', Filament::getTenant()->id)
                ->where('customer_id', $inline ? $get('customer_id') : $record->source()->customer_id)->whereNull('invoice_id')->whereNull('fiscal_document_id')->limit(50)->get()->mapWithKeys(fn ($title) => [$title->id => '#'.$title->id.' · '.$title->description.' · R$ '.number_format($title->due_amount, 2, ',', '.')]))
                ->getSearchResultsUsing(fn (Get $get, ?BpoSale $record, string $search) => AccountReceivable::where('company_id', Filament::getTenant()->id)->where('customer_id', $inline ? $get('customer_id') : $record->source()->customer_id)
                    ->whereNull('invoice_id')->whereNull('fiscal_document_id')->where(fn ($query) => $query->where('description', 'like', '%'.$search.'%')->orWhere('document_number', 'like', '%'.$search.'%'))
                    ->limit(50)->get()->mapWithKeys(fn ($title) => [$title->id => '#'.$title->id.' · '.$title->description]))
                ->getOptionLabelUsing(fn ($value) => AccountReceivable::where('company_id', Filament::getTenant()->id)->find($value)?->description)
                ->visible(fn (Get $get) => $get('financial_policy') === 'link_existing')->required(fn (Get $get) => $get('financial_policy') === 'link_existing'),
            Select::make('payment_method')->label('Forma de pagamento')->options(fn () => $this->allows('card_institutions.view') ? Method::toSelectArray() : array_diff_key(Method::toSelectArray(), [Method::CREDIT_CARD->value => true]))->default(Method::PIX->value)->required()->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('auto_bank_slip_issuance', false);
                    $set('auto_pix_charge_issuance', false);
                    $set('mark_as_received', false);
                }),
            Select::make('card_payment_profile_id')->label('Instituição de cartão')->searchable()->options(fn () => CardInstitution::where('company_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value)->required(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value),
            DatePicker::make('payment_date')->label('Data da venda no cartão')->default(today())
                ->visible(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value)->required(fn (Get $get) => $get('payment_method') === Method::CREDIT_CARD->value),
            Select::make('payment_condition')->label('Condição de pagamento')->options(Condition::toGroupedSelectArray())->required()->live(),
            PaymentConditionFields::make(),
            SelectFinancialCategory::make('financial_category_id', 'receivable')->columnSpanFull(),
            Toggle::make('mark_as_received')->label('O valor já foi recebido na conta da empresa')->default(false)->live()
                ->columnSpanFull()
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        $set('auto_bank_slip_issuance', false);
                        $set('auto_pix_charge_issuance', false);
                    }
                })
                ->visible(fn (Get $get) => $get('financial_policy') === 'create_receivable' && $this->allows('account_receivables.settle') && $this->allows('cash_movements.create') && $this->allows('financial_accounts.view'))
                ->helperText('Registra a baixa e a entrada no caixa. No cartão, marque somente se o repasse já entrou na conta.'),
            DatePicker::make('received_at')->label('Data do recebimento')->default(today())->visible(fn (Get $get) => (bool) $get('mark_as_received'))
                ->required(fn (Get $get) => (bool) $get('mark_as_received')),
            Toggle::make('auto_bank_slip_issuance')->label('Gerar boletos automaticamente')->default(false)->live()
                ->columnSpanFull()
                ->visible(fn (Get $get) => $get('financial_policy') === 'create_receivable' && ! $get('mark_as_received') && $get('payment_method') === Method::BANK_SLIP->value && $this->allows('account_receivables.issue') && $this->allows('financial_accounts.view')),
            Toggle::make('auto_pix_charge_issuance')->label('Gerar cobrança PIX automaticamente')->default(false)->live()
                ->columnSpanFull()
                ->visible(fn (Get $get) => $get('financial_policy') === 'create_receivable' && ! $get('mark_as_received') && $get('payment_method') === Method::PIX->value && $this->allows('account_receivables.issue') && $this->allows('financial_accounts.view')),
            Select::make('financial_account_id')->label('Conta de cobrança / recebimento')->options(fn () => FinancialAccount::optionsForCompany(Filament::getTenant()->id))->searchable()
                ->columnSpanFull()
                ->visible(fn (Get $get) => $get('mark_as_received') || $get('auto_bank_slip_issuance') || $get('auto_pix_charge_issuance'))
                ->required(fn (Get $get) => $get('mark_as_received') || $get('auto_bank_slip_issuance') || $get('auto_pix_charge_issuance')),
            Toggle::make('request_emission')->label('Solicitar emissão após finalizar')->default(false)
                ->columnSpanFull()
                ->visible(fn () => $this->allows('fiscal_documents.issue'))
                ->helperText('A emissão fiscal é independente das opções de cobrança e recebimento acima.'),
        ];
    }

    public function salesSummary(): array
    {
        return app(SalesReportingService::class)->summary(Filament::auth()->user(), Filament::getTenant(), $this->getFilteredTableQuery());
    }

    private function saleFilters(): array
    {
        return [
            Filter::make('period')->label('Período')->columnSpanFull()->columns(3)->schema([
                Select::make('date_basis')->label('Filtrar por')->options(['sale_date' => 'Data da venda', 'finalized_at' => 'Data da finalização'])->default('sale_date')->selectablePlaceholder(false),
                DatePicker::make('from')->label('Data inicial')->default(today()->startOfMonth()),
                DatePicker::make('until')->label('Data final')->default(today()->endOfMonth())->afterOrEqual('from'),
            ])->query(function (Builder $query, array $data): Builder {
                $from = $this->filterDate($data['from'] ?? null);
                $until = $this->filterDate($data['until'] ?? null);
                if ((filled($data['from'] ?? null) && ! $from) || (filled($data['until'] ?? null) && ! $until) || ($from && $until && $from > $until)) {
                    return $query->whereRaw('1 = 0');
                }
                if (! $from && ! $until) {
                    return $query;
                }
                $apply = fn (Builder $source, string $column): Builder => $source
                    ->when($from, fn (Builder $q) => $q->where($column, '>=', $from))
                    ->when($until, fn (Builder $q) => $q->where($column, '<', CarbonImmutable::parse($until)->addDay()->toDateString()));
                if (($data['date_basis'] ?? 'sale_date') === 'finalized_at') {
                    return $apply($query, 'bpo_sales.finalized_at');
                }

                return $query->where(fn (Builder $q) => $q->whereHas('serviceOrder', fn (Builder $source) => $apply($source, 'order_date'))
                    ->orWhereHas('requisition', fn (Builder $source) => $apply($source, 'sale_date')));
            })->indicateUsing(function (array $data): ?string {
                $from = $this->filterDate($data['from'] ?? null);
                $until = $this->filterDate($data['until'] ?? null);
                if (! $from && ! $until) {
                    return null;
                }

                return (($data['date_basis'] ?? 'sale_date') === 'finalized_at' ? 'Finalização: ' : 'Venda: ')
                    .($from ? CarbonImmutable::parse($from)->format('d/m/Y') : 'início').' até '
                    .($until ? CarbonImmutable::parse($until)->format('d/m/Y') : 'hoje em diante');
            }),
            SelectFilter::make('commercial_status')->label('Situação da venda')->options(['draft' => 'Rascunho', 'finalized' => 'Finalizada', 'cancelled' => 'Cancelada'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'draft' => $query->whereNull('finalized_at'),
                    'finalized' => $query->whereNotNull('finalized_at')->whereHas('invoice', fn (Builder $q) => $q->where('confirmed', true)->where('canceled', false)->where('status', '!=', InvoiceStatus::CANCELLED->value)),
                    'cancelled' => $query->whereHas('invoice', fn (Builder $q) => $q->where('canceled', true)->orWhere('status', InvoiceStatus::CANCELLED->value)),
                    default => $query,
                }),
            SelectFilter::make('document_type')->label('Tipo de documento')->options(DocumentModel::toSelectArray())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $q->where('template_snapshot->document_type', $value))),
            SelectFilter::make('customer_id')->label('Cliente')->searchable()->options(fn () => $this->customerOptions(activeOnly: false))
                ->modifyFormFieldUsing(fn (Select $field) => $field->getSearchResultsUsing(fn (string $search) => $this->customerOptions($search, false))->getOptionLabelUsing(fn ($value) => $this->customerLabel($value)))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q
                    ->where(fn (Builder $q) => $q->whereHas('serviceOrder', fn (Builder $source) => $source->where('customer_id', $id))
                        ->orWhereHas('requisition', fn (Builder $source) => $source->where('customer_id', $id))))),
            SelectFilter::make('sale_template_id')->label('Modelo de venda')->searchable()->options(fn () => SaleTemplate::where('company_id', Filament::getTenant()->id)->pluck('name', 'id')),
            SelectFilter::make('fiscal_status')->label('Situação fiscal')->options(['not_requested' => 'Emissão não solicitada'] + NfeStatus::toSelectArray())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, function (Builder $q, string $value): Builder {
                    return $q->whereHas('invoice.fiscalDocuments', function (Builder $documents) use ($value): void {
                        if ($value === 'not_requested') {
                            $documents->whereNull('emission_requested_at');
                        }
                        $state = $value === 'not_requested' ? NfeStatus::PENDING->value : $value;
                        $documents->where(fn (Builder $q) => $q
                            ->where(fn (Builder $q) => $q->where('document_type', 'nfse')->where('nfse_status', $state))
                            ->orWhere(fn (Builder $q) => $q->where('document_type', 'nfe')->where('nfe_status', $state)));
                    });
                })),
            SelectFilter::make('financial_policy')->label('Tratamento financeiro')->options(['create_receivable' => 'Gerar recebível', 'link_existing' => 'Vincular existente', 'fiscal_only' => 'Somente nota'])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $q->where('payment_settings->financial_policy', $value))),
        ];
    }

    private function filterDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date->format('Y-m-d') === $value ? $value : null;
        } catch (\Exception) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        return $table->query(app(SalesReportingService::class)->query(Filament::auth()->user(), Filament::getTenant())
            ->with(['serviceOrder' => fn ($query) => $query->with('customer')->withSum('items as listing_items_amount', 'total_amount'),
                'requisition' => fn ($query) => $query->with('customer')->withSum('items as listing_items_amount', 'total_amount'),
                'invoice.fiscalDocuments' => fn ($query) => $query->select(['id', 'company_id', 'invoice_id', 'document_type', 'nfe_status', 'nfse_status', 'emission_requested_at'])]))->columns([
                    TextColumn::make('id')->label('Venda')->sortable()->searchable(),
                    TextColumn::make('template_snapshot.name')->label('Modelo')->searchable(query: fn (Builder $query, string $search) => $query->where('template_snapshot->name', 'like', '%'.$search.'%')),
                    TextColumn::make('customer')->label('Cliente')->state(fn (BpoSale $record) => $record->serviceOrder?->customer?->name ?? $record->requisition?->customer?->name)
                        ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                            ->whereHas('serviceOrder.customer', fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%'))
                            ->orWhereHas('requisition.customer', fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%')))),
                    TextColumn::make('sale_date')->label('Data da venda')->state(fn (BpoSale $record) => $record->serviceOrder?->order_date ?? $record->requisition?->sale_date)->date('d/m/Y'),
                    TextColumn::make('amount')->label('Valor líquido')->state(function (BpoSale $record): float {
                        $source = $record->serviceOrder ?? $record->requisition;

                        return round((float) ($source?->listing_items_amount ?? 0) / 100 + (float) ($source instanceof ServiceOrder ? $source->travel_value : 0), 2);
                    })->money('BRL'),
                    TextColumn::make('invoice.invoice_number')->label('Fatura')->placeholder('Rascunho')->searchable(),
                    TextColumn::make('commercial_status')->label('Venda')->state(fn (BpoSale $record) => ($record->invoice?->canceled || $record->invoice?->status === InvoiceStatus::CANCELLED) ? 'Cancelada' : ($record->finalized_at ? 'Finalizada' : 'Rascunho'))->badge(),
                    TextColumn::make('finalized_at')->label('Finalizada em')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('fiscal_status')->label('Fiscal')->state(function (BpoSale $record) {
                        $document = $record->invoice?->fiscalDocuments?->first();

                        if ($document && ! $document->emission_requested_at && ($document->isNfse() ? $document->isNfsePending() : $document->isNfePending())) {
                            return 'Emissão não solicitada';
                        }

                        return $document ? (($document->isNfse() ? $document->nfse_status : $document->nfe_status)?->description() ?? 'Emissão não solicitada') : 'Em preparação';
                    }),
                ])->filters($this->saleFilters(), layout: FiltersLayout::Modal)->filtersFormColumns(3)->deferFilters(false)->recordActions([ActionGroup::make([
                    $this->saleAction('editSale', true),
                    Action::make('finalize')->label('Finalizar')->modalHeading('Revisar e finalizar venda')->modalWidth('4xl')->stickyModalFooter()->modalSubmitActionLabel('Finalizar venda')->modalCancelActionLabel('Voltar')
                        ->visible(fn (BpoSale $record) => ! $record->finalized_at && $this->allows('bpo_sales.approve'))
                        ->fillForm(function (BpoSale $record): array {
                            $source = $record->source();

                            return ['revision' => $record->revision, 'payment_date' => ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString(), 'received_at' => today()->toDateString()]
                                + ($record->payment_settings ?? []) + ['financial_policy' => 'create_receivable', 'payment_method' => Method::PIX->value];
                        })
                        ->schema([Grid::make(['default' => 1, 'md' => 2])->schema($this->paymentSchema())])->action(function (BpoSale $record, array $data): void {
                            $workflow = app(QuickSaleWorkflow::class);
                            $sale = $this->executeWorkflow(fn () => $workflow->finalize(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['revision'], $data), 'Não foi possível finalizar a venda');
                            Notification::make()->title('Venda finalizada')->body('Fatura criada. O tratamento financeiro foi registrado.')->success()->send();
                            if ($data['request_emission'] ?? false) {
                                $this->executeWorkflow(fn () => $workflow->requestEmission(Filament::auth()->user(), Filament::getTenant(), $sale->id), 'Venda finalizada, mas a emissão não foi solicitada');
                                Notification::make()->title('Emissão solicitada')->success()->send();
                            }
                        }),
                    Action::make('emit')->label('Solicitar emissão')->requiresConfirmation()->visible(function (BpoSale $record): bool {
                        $document = $record->invoice?->fiscalDocuments?->first();

                        return $record->finalized_at && $document && $this->allows('fiscal_documents.issue')
                            && ! ($document->isNfse() ? $document->blocksNfseResubmission() : $document->blocksNfeResubmission());
                    })
                        ->action(function (BpoSale $record): void {
                            $this->executeWorkflow(fn () => app(QuickSaleWorkflow::class)->requestEmission(Filament::auth()->user(), Filament::getTenant(), $record->id), 'Não foi possível solicitar emissão');
                            Notification::make()->title('Emissão solicitada')->success()->send();
                        }),
                    Action::make('details')->label('Resumo')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                        ->modalHeading('Venda e documentos gerados')->modalWidth('5xl')
                        ->modalContent(fn (BpoSale $record) => $this->saleDetails($record)),
                    ...$this->deliveryActions(),
                ])->label('Ações da venda')->icon('heroicon-o-ellipsis-vertical')])->defaultSort('id', 'desc')->paginated([15, 30, 50]);
    }

    private function saleDetails(BpoSale $record): View
    {
        $sale = app(QuickSaleWorkflow::class)->find(Filament::auth()->user(), Filament::getTenant(), $record->id);
        $source = $sale->source();
        Gate::authorize('view', $source);
        $source->loadMissing($source instanceof ServiceOrder ? 'items.service' : 'items.product');
        $invoice = $sale->invoice;
        if ($invoice && ! $this->canUseInvoice($sale, 'view')) {
            $invoice = null;
        }

        return view('filament.bpo.pages.sale-details', ['sale' => $sale, 'source' => $source, 'invoice' => $invoice]);
    }

    private function canUseInvoice(BpoSale $sale, string $ability): bool
    {
        return $sale->invoice && $this->allows('invoices.'.$ability)
            && app(CompanyAuthorization::class)->recordMatchesScopes($sale->invoice, Filament::auth()->user(), $this->permissions['invoices.'.$ability]);
    }

    private function invoiceFor(BpoSale $sale, string $ability = 'view'): Invoice
    {
        app(QuickSaleWorkflow::class)->find(Filament::auth()->user(), Filament::getTenant(), $sale->id);
        $invoice = Invoice::where('company_id', Filament::getTenant()->id)->findOrFail($sale->invoice_id);
        Gate::authorize('view', $invoice);
        abort_unless(app(CompanyAuthorization::class)->allowsRecord(Filament::auth()->user(), Filament::getTenant(), 'invoices.'.$ability, $invoice), 403);

        return $invoice;
    }

    private function deliveryActions(): array
    {
        return [
            Action::make('invoicePreview')->label('Visualizar fatura')->icon('heroicon-o-document-magnifying-glass')->modalWidth('6xl')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                ->authorize(fn (BpoSale $record) => $this->canUseInvoice($record, 'view'))
                ->modalContent(function (BpoSale $record): HtmlString {
                    $invoice = $this->invoiceFor($record);
                    $service = app(InvoiceService::class);
                    $data = $service->preview($invoice, Filament::auth()->id());

                    return new HtmlString(! empty($data['pdf']) ? '<iframe title="Fatura" src="data:application/pdf;base64,'.e($data['pdf']).'" width="100%" height="600" style="border:0"></iframe>' : '<p>'.e($service->getMessage() ?: 'PDF indisponível.').'</p>');
                }),
            Action::make('invoiceDownload')->label('Baixar fatura')->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (BpoSale $record) => $this->canUseInvoice($record, 'view'))
                ->action(function (BpoSale $record) {
                    $invoice = $this->invoiceFor($record);
                    $service = app(InvoiceService::class);
                    $pdf = $service->pdf($invoice, Filament::auth()->id());
                    if (! $pdf) {
                        Notification::make()->title($service->getMessage() ?: 'Não foi possível gerar o PDF.')->danger()->send();

                        return null;
                    }

                    return response()->streamDownload(fn () => print (base64_decode($pdf)), 'fatura-'.$invoice->invoice_number.'.pdf', ['Content-Type' => 'application/pdf']);
                }),
            Action::make('invoiceEmail')->label('Enviar fatura por e-mail')->icon('heroicon-o-envelope')->modalHeading('Enviar documentos da fatura')->modalWidth('3xl')->modalSubmitActionLabel('Enviar')->modalCancelActionLabel('Voltar')
                ->authorize(fn (BpoSale $record) => $this->canUseInvoice($record, 'update'))
                ->schema([TextInput::make('subject')->label('Assunto')->default('Documentos da sua fatura')->required()->maxLength(255),
                    Textarea::make('body')->label('Mensagem')->default('Segue a documentação da sua fatura. Em caso de dúvidas, permanecemos à disposição.')->required()->maxLength(10000)->rows(5)])
                ->action(function (BpoSale $record, array $data): void {
                    $invoice = $this->invoiceFor($record, 'update');
                    SendInvoiceEmailJob::dispatch($invoice->id, $data['subject'], $data['body'], Filament::auth()->id());
                    Notification::make()->title('Envio de documentos enfileirado')->success()->send();
                }),
        ];
    }
}
