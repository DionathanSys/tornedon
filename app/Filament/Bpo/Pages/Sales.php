<?php

namespace App\Filament\Bpo\Pages;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Filament\Forms\Components\PaymentConditionFields;
use App\Models\AccountReceivable;
use App\Models\BpoSale;
use App\Models\CompanyPartner;
use App\Models\Product;
use App\Models\SaleTemplate;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Access\UserAccessService;
use App\Services\Bpo\QuickSaleWorkflow;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
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
        abort_unless(static::canAccess(), 403);
    }

    private function allows(string $permission): bool
    {
        return app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), $permission, 'bpo');
    }

    private function saleAction(string $name, bool $editing = false): Action
    {
        return Action::make($name)->label($editing ? 'Continuar rascunho' : 'Nova venda')->icon('heroicon-o-shopping-cart')
            ->modalHeading($editing ? 'Continuar venda' : 'Nova venda')->modalWidth('5xl')->modalSubmitActionLabel('Salvar rascunho')
            ->visible(fn (?BpoSale $record) => ! $record?->finalized_at && $this->allows('bpo_sales.'.($editing ? 'update' : 'create')))
            ->fillForm(function (?BpoSale $record): array {
                if (! $record) {
                    return ['sale_date' => today()->toDateString(), 'items' => [['quantity' => 1, 'discount_amount' => 0]]];
                }
                $sale = app(QuickSaleWorkflow::class)->find(Filament::auth()->user(), Filament::getTenant(), $record->id, 'update');
                $source = $sale->source();

                return ['revision' => $sale->revision, 'document_type' => $sale->template_snapshot['document_type'], 'sale_template_id' => $sale->sale_template_id, 'customer_id' => $source->customer_id,
                    'sale_date' => ($source instanceof ServiceOrder ? $source->order_date : $source->sale_date)->toDateString(),
                    'description' => $sale->payment_settings['description'] ?? null,
                    'items' => $source->items->map(fn ($item) => ['catalog_id' => $item->service_id ?? $item->product_id, 'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price, 'discount_amount' => $item->discount_amount])->all()];
            })->schema([
                Hidden::make('revision')->default(0),
                Hidden::make('document_type'),
                Select::make('sale_template_id')->label('Modelo de venda')->options(fn () => SaleTemplate::where('company_id', Filament::getTenant()->id)->where('is_active', true)->pluck('name', 'id'))
                    ->required()->searchable()->live()->disabled($editing)->dehydrated()->afterStateUpdated(function ($state, Set $set): void {
                        $template = SaleTemplate::where('company_id', Filament::getTenant()->id)->find($state);
                        $set('document_type', $template?->document_type?->value);
                        $set('items', [['quantity' => 1, 'discount_amount' => 0]]);
                    }),
                Select::make('customer_id')->label('Cliente')->options(fn () => CompanyPartner::where('company_id', Filament::getTenant()->id)->where('is_active', true)
                    ->whereJsonContains('company_partner.type', 'customer')->with('partner')->get()->mapWithKeys(fn ($link) => [$link->partner_id => $link->partner->name]))->searchable()->required(),
                DatePicker::make('sale_date')->label('Data da venda')->required(),
                Textarea::make('description')->label('Descrição do serviço / observações')->maxLength(1000),
                Repeater::make('items')->label('Itens da venda')->minItems(1)->maxItems(100)->columns(4)->schema([
                    Select::make('catalog_id')->label('Serviço ou produto')->required()->searchable()->live()->options(function (Get $get) {
                        $template = SaleTemplate::where('company_id', Filament::getTenant()->id)->find($get('../../sale_template_id'));
                        if (! $template) {
                            return [];
                        }

                        return (($get('../../document_type') ?? $template->document_type->value) === 'nfse' ? Service::query() : Product::query())
                            ->where('company_id', Filament::getTenant()->id)->where('is_active', true)->pluck('name', 'id');
                    })->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        $template = SaleTemplate::where('company_id', Filament::getTenant()->id)->find($get('../../sale_template_id'));
                        $catalog = (($get('../../document_type') ?? $template?->document_type?->value) === 'nfse' ? Service::query() : Product::query())
                            ->where('company_id', Filament::getTenant()->id)->find($state);
                        $set('unit_price', $catalog instanceof Service ? $catalog->price : $catalog?->sale_price_value);
                    }),
                    TextInput::make('quantity')->label('Quantidade')->numeric()->minValue(0.001)->required()->default(1),
                    TextInput::make('unit_price')->label('Preço unitário (R$)')->numeric()->minValue(0.01)->required(),
                    TextInput::make('discount_amount')->label('Desconto (R$)')->numeric()->minValue(0)->default(0),
                ])->helperText('NFS-e: um serviço por venda. NF-e: múltiplos produtos. Os valores são recalculados no servidor.'),
            ])->action(function (array $data, ?BpoSale $record) use ($editing): void {
                app(QuickSaleWorkflow::class)->save(Filament::auth()->user(), Filament::getTenant(), $data, $this->requestId,
                    $editing ? $record->id : null, (int) ($data['revision'] ?? 0));
                $this->requestId = (string) Str::uuid();
                Notification::make()->title('Rascunho salvo')->body('Use Finalizar para revisar o pagamento e gerar a fatura.')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->saleAction('newSale'), Action::make('templates')->label('Modelos de venda')->url(SaleTemplates::getUrl(panel: 'bpo', tenant: Filament::getTenant()))->visible(fn () => SaleTemplates::canAccess())];
    }

    private function paymentSchema(): array
    {
        return [
            Hidden::make('revision'),
            TextEntry::make('review_total')->label('Total da venda')->state(fn (BpoSale $record) => 'R$ '.number_format($record->source()->total_amount, 2, ',', '.')),
            Select::make('financial_policy')->label('Tratamento financeiro')->options(['create_receivable' => 'Gerar contas a receber', 'link_existing' => 'Vincular recebível existente', 'fiscal_only' => 'Somente emitir nota'])->default('create_receivable')->required()->live(),
            Select::make('financial_target_id')->label('Recebível existente')->searchable()->options(fn (BpoSale $record) => AccountReceivable::where('company_id', Filament::getTenant()->id)
                ->where('customer_id', $record->source()->customer_id)->whereNull('invoice_id')->whereNull('fiscal_document_id')->get()->mapWithKeys(fn ($title) => [$title->id => '#'.$title->id.' · '.$title->description.' · R$ '.number_format($title->due_amount, 2, ',', '.')]))
                ->visible(fn (Get $get) => $get('financial_policy') === 'link_existing')->required(fn (Get $get) => $get('financial_policy') === 'link_existing'),
            Select::make('payment_method')->label('Forma de pagamento')->options(array_diff_key(Method::toSelectArray(), array_flip(['cartao_credito', 'cartao_debito'])))->default(Method::PIX->value)->required(),
            Select::make('payment_condition')->label('Condição de pagamento')->options(Condition::toGroupedSelectArray())->required()->live(),
            PaymentConditionFields::make(),
            SelectFinancialCategory::make('financial_category_id', 'receivable'),
            Toggle::make('request_emission')->label('Solicitar emissão após finalizar')->default(false)
                ->helperText('Desmarcado: a venda fica finalizada e você pode solicitar emissão depois. Não registra pagamento ou cobrança bancária automaticamente.'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(BpoSale::where('company_id', Filament::getTenant()->id)->with(['serviceOrder.customer', 'requisition.customer', 'invoice.fiscalDocuments']))->columns([
            TextColumn::make('id')->label('Venda')->sortable(),
            TextColumn::make('template_snapshot.name')->label('Modelo'),
            TextColumn::make('customer')->label('Cliente')->state(fn (BpoSale $record) => $record->serviceOrder?->customer?->name ?? $record->requisition?->customer?->name),
            TextColumn::make('invoice.invoice_number')->label('Fatura')->placeholder('Rascunho'),
            TextColumn::make('finalized_at')->label('Venda')->formatStateUsing(fn ($state) => $state ? 'Finalizada' : 'Rascunho')->placeholder('Rascunho')->badge(),
            TextColumn::make('fiscal_status')->label('Fiscal')->state(function (BpoSale $record) {
                $document = $record->invoice?->fiscalDocuments?->first();

                if ($document && ! $document->emission_requested_at && ($document->isNfse() ? $document->isNfsePending() : $document->isNfePending())) {
                    return 'Emissão não solicitada';
                }

                return $document ? (($document->isNfse() ? $document->nfse_status : $document->nfe_status)?->description() ?? 'Emissão não solicitada') : 'Em preparação';
            }),
        ])->recordActions([
            $this->saleAction('editSale', true),
            Action::make('finalize')->label('Finalizar')->modalHeading('Revisar e finalizar venda')->modalWidth('3xl')
                ->visible(fn (BpoSale $record) => ! $record->finalized_at && $this->allows('bpo_sales.approve'))
                ->fillForm(fn (BpoSale $record) => ['revision' => $record->revision, 'financial_policy' => 'create_receivable', 'payment_method' => Method::PIX->value] + ($record->payment_settings ?? []))
                ->schema($this->paymentSchema())->action(function (BpoSale $record, array $data): void {
                    $workflow = app(QuickSaleWorkflow::class);
                    $sale = $workflow->finalize(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['revision'], $data);
                    Notification::make()->title('Venda finalizada')->body('Fatura criada. O tratamento financeiro foi registrado.')->success()->send();
                    if ($data['request_emission'] ?? false) {
                        $workflow->requestEmission(Filament::auth()->user(), Filament::getTenant(), $sale->id);
                        Notification::make()->title('Emissão solicitada')->success()->send();
                    }
                }),
            Action::make('emit')->label('Solicitar emissão')->requiresConfirmation()->visible(function (BpoSale $record): bool {
                $document = $record->invoice?->fiscalDocuments?->first();

                return $record->finalized_at && $document && $this->allows('fiscal_documents.issue')
                    && ! ($document->isNfse() ? $document->blocksNfseResubmission() : $document->blocksNfeResubmission());
            })
                ->action(function (BpoSale $record): void {
                    app(QuickSaleWorkflow::class)->requestEmission(Filament::auth()->user(), Filament::getTenant(), $record->id);
                    Notification::make()->title('Emissão solicitada')->success()->send();
                }),
            Action::make('details')->label('Resumo')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                ->before(fn (BpoSale $record) => app(QuickSaleWorkflow::class)->find(Filament::auth()->user(), Filament::getTenant(), $record->id))
                ->schema([
                    TextEntry::make('origin')->label('Origem operacional')->state(fn (BpoSale $record) => ($record->service_order_id ? 'OS #' : 'Requisição #').$record->source()->number),
                    TextEntry::make('invoice.invoice_number')->label('Fatura')->placeholder('Ainda não finalizada'),
                    TextEntry::make('amount')->label('Total')->state(fn (BpoSale $record) => 'R$ '.number_format($record->source()->total_amount, 2, ',', '.')),
                    TextEntry::make('payment_settings.financial_policy')->label('Política financeira')->formatStateUsing(fn ($state) => ['create_receivable' => 'Gerar recebível', 'link_existing' => 'Recebível vinculado', 'fiscal_only' => 'Somente nota'][$state] ?? 'A definir'),
                    TextEntry::make('stock')->label('Estoque')->state(fn (BpoSale $record) => ($record->template_snapshot['stock_control_enabled'] ?? false) ? 'Reservas na requisição; saída após autorização' : 'Sem movimentação de estoque'),
                ]),
        ])->defaultSort('id', 'desc')->paginated([15, 30, 50]);
    }
}
