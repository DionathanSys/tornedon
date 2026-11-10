<?php

namespace App\Filament\Bpo\Pages;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Invoice\CancellationReason;
use App\Enum\Invoice\Status as InvoiceStatus;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\InvoiceResource;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\BpoSale;
use App\Models\CompanyPartner;
use App\Models\Invoice;
use App\Models\SaleTemplate;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Malzariey\FilamentDaterangepickerFilter\Fields\DateRangePicker;

class Sales extends Page implements HasTable
{
    use HasWorkbenchHeader;
    use InteractsWithTable;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.sales';

    protected static ?string $title = 'Vendas e faturamento';

    private ?array $permissions = null;

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

    public function booted(): void
    {
        $this->permissions = null;
        abort_unless(static::canAccess(), 403);
    }

    protected function allows(string $permission): bool
    {
        $this->permissions ??= app(CompanyAuthorization::class)->effectivePermissions(Filament::auth()->user(), Filament::getTenant());

        return ($this->permissions[$permission] ?? []) !== [];
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

    protected function getHeaderActions(): array
    {
        return [Action::make('newSale')->label('Nova venda')->icon('heroicon-o-plus')->url(SaleEditor::getUrl())->visible(fn () => $this->allows('bpo_sales.create')), Action::make('templates')->label('Modelos de venda')->url(SaleTemplates::getUrl(panel: 'bpo', tenant: Filament::getTenant()))->visible(fn () => SaleTemplates::canAccess()),
            Action::make('invoices')->label('Faturas e exportações')->url(InvoiceResource::getUrl())->visible(fn () => $this->allows('invoices.view'))];
    }

    private function executeWorkflow(callable $operation, string $failureTitle): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            Notification::make()->title($failureTitle)->body(e(collect($exception->errors())->flatten()->unique()->implode("\n")))->danger()->send();

            throw $exception;
        }
    }

    public function salesSummary(): array
    {
        return app(SalesReportingService::class)->summary(Filament::auth()->user(), Filament::getTenant(), $this->getFilteredTableQuery());
    }

    private function saleFilters(): array
    {
        return [
            $this->saleDateFilter('sale_date', 'Data da venda', defaultMonth: true),
            $this->saleDateFilter('finalized_at', 'Data da finalização'),
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

    private function saleDateFilter(string $name, string $label, bool $defaultMonth = false): Filter
    {
        return Filter::make($name)->label($label)->schema([
            DateRangePicker::make('date_range')->label($label)->format('d/m/Y')
                ->default(fn () => $defaultMonth ? today()->startOfMonth()->format('d/m/Y').' - '.today()->endOfMonth()->format('d/m/Y') : null)
                ->firstDayOfWeek(0)->alwaysShowCalendar()->autoApply(),
        ])->query(function (Builder $query, array $data) use ($name): Builder {
            [$from, $until] = $this->filterDateRange($data['date_range'] ?? null);
            if (filled($data['date_range'] ?? null) && (! $from || ! $until)) {
                return $query->whereRaw('1 = 0');
            }
            if (! $from || ! $until) {
                return $query;
            }
            $apply = fn (Builder $source, string $column): Builder => $source
                ->where($column, '>=', $from)
                ->where($column, '<', CarbonImmutable::parse($until)->addDay()->toDateString());
            if ($name === 'finalized_at') {
                return $apply($query, 'bpo_sales.finalized_at');
            }

            return $query->where(fn (Builder $q) => $q->whereHas('serviceOrder', fn (Builder $source) => $apply($source, 'order_date'))
                ->orWhereHas('requisition', fn (Builder $source) => $apply($source, 'sale_date')));
        })->indicateUsing(function (array $data) use ($label): ?string {
            [$from, $until] = $this->filterDateRange($data['date_range'] ?? null);

            return $from && $until ? $label.': '.CarbonImmutable::parse($from)->format('d/m/Y').' até '.CarbonImmutable::parse($until)->format('d/m/Y') : null;
        });
    }

    private function filterDateRange(mixed $value): array
    {
        if (! is_string($value) || ! preg_match('/^\d{2}\/\d{2}\/\d{4} - \d{2}\/\d{2}\/\d{4}$/', $value)) {
            return [null, null];
        }
        try {
            [$start, $end] = explode(' - ', $value);
            $from = CarbonImmutable::createFromFormat('!d/m/Y', $start);
            $until = CarbonImmutable::createFromFormat('!d/m/Y', $end);
            if ($from->format('d/m/Y') !== $start || $until->format('d/m/Y') !== $end || $from->gt($until)) {
                return [null, null];
            }

            return [$from->toDateString(), $until->toDateString()];
        } catch (\Exception) {
            return [null, null];
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

                        return round(((float) ($record->serviceOrder?->listing_items_amount ?? 0) + (float) ($record->requisition?->listing_items_amount ?? 0)) / 100 + (float) ($source instanceof ServiceOrder ? $source->travel_value : 0), 2);
                    })->money('BRL'),
                    TextColumn::make('invoice.invoice_number')->label('Fatura')->placeholder('Rascunho')->searchable(),
                    TextColumn::make('commercial_status')->label('Venda')->state(fn (BpoSale $record) => $record->invoice?->cancellation_reason === CancellationReason::REBILLING ? 'Revertida para refaturamento' : (($record->invoice?->canceled || $record->invoice?->status === InvoiceStatus::CANCELLED) ? 'Cancelada' : ($record->finalized_at ? 'Finalizada' : 'Rascunho')))->badge(),
                    TextColumn::make('finalized_at')->label('Finalizada em')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('fiscal_status')->label('Fiscal')->state(function (BpoSale $record) {
                        $document = $record->invoice?->fiscalDocuments?->first();

                        if ($document && ! $document->emission_requested_at && ($document->isNfse() ? $document->isNfsePending() : $document->isNfePending())) {
                            return 'Emissão não solicitada';
                        }

                        return $document ? (($document->isNfse() ? $document->nfse_status : $document->nfe_status)?->description() ?? 'Emissão não solicitada') : 'Em preparação';
                    }),
                ])->filters($this->saleFilters(), layout: FiltersLayout::Modal)->filtersFormColumns(3)->deferFilters(false)->recordActions([ActionGroup::make([
                    Action::make('editSale')->label('Abrir venda')->icon('heroicon-o-pencil-square')
                        ->url(fn (BpoSale $record) => SaleEditor::getUrl(['sale' => $record->id]))
                        ->visible(fn (BpoSale $record) => ! $record->finalized_at && $this->allows('bpo_sales.update')),
                    Action::make('finalize')->label('Revisar e finalizar')
                        ->url(fn (BpoSale $record) => SaleEditor::getUrl(['sale' => $record->id]))
                        ->visible(fn (BpoSale $record) => ! $record->finalized_at && $this->allows('bpo_sales.approve')),
                    Action::make('emit')->label('Solicitar emissão')->requiresConfirmation()->visible(function (BpoSale $record): bool {
                        return $record->finalized_at && $this->allows('fiscal_documents.issue')
                            && ($record->invoice?->fiscalDocuments?->contains(fn ($document) => ! ($document->isNfse() ? $document->blocksNfseResubmission() : $document->blocksNfeResubmission())) ?? false);
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
        $linkedRequisition = $source instanceof ServiceOrder ? $sale->requisition : null;
        if ($linkedRequisition && Gate::allows('view', $linkedRequisition)) {
            $linkedRequisition->loadMissing('items.product');
        } else {
            $linkedRequisition = null;
        }

        return view('filament.bpo.pages.sale-details', ['sale' => $sale, 'source' => $source, 'invoice' => $invoice, 'linkedRequisition' => $linkedRequisition]);
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
