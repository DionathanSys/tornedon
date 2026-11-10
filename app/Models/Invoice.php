<?php

namespace App\Models;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\Invoice\CancellationReason;
use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use HasAttachments;

    private ?array $resolvedAmounts = null;

    protected $appends = [
        'gross_amount',
        'total_amount',
        'discount_amount',
        'net_value',
        'services_amount',
        'products_amount',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $invoice): void {
            if ($invoice->isDirty('status') && $invoice->status !== null) {
                $invoice->pending = $invoice->status === Status::PENDING;
                $invoice->confirmed = $invoice->status === Status::CONFIRMED;
                $invoice->canceled = $invoice->status === Status::CANCELLED;
            }
        });
        static::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('status') === Status::CANCELLED->value && $invoice->isDirty('status')) {
                throw new \RuntimeException('Uma fatura cancelada não pode ser reaberta.');
            }
            if (in_array($invoice->getRawOriginal('status'), [Status::CONFIRMED->value, Status::CANCELLED->value], true) && $invoice->confirmations()->exists()) {
                if ($invoice->isDirty(['company_id', 'customer_id', 'invoice_date', 'invoice_number', 'financial_policy'])) {
                    throw new \RuntimeException('Os dados comerciais de uma fatura confirmada estão congelados.');
                }
                foreach (['payment_method', 'payment_condition', 'payment_condition_details', 'financial_category_id'] as $field) {
                    if ($invoice->isDirty($field) && ($invoice->status === Status::CANCELLED || $invoice->getRawOriginal($field) !== null || $invoice->accountReceivables()->exists())) {
                        throw new \RuntimeException('As condições financeiras já definidas não podem ser alteradas por edição da fatura.');
                    }
                }
            }
        });
        static::deleting(function (self $invoice): void {
            if ($invoice->status !== Status::PENDING || $invoice->confirmed || $invoice->canceled || $invoice->confirmations()->exists() || $invoice->sources()->exists() || $invoice->operations()->exists() || $invoice->receivableLinks()->exists()) {
                throw new \RuntimeException('Somente rascunhos sem histórico podem ser excluídos.');
            }
            if ($invoice->fiscalDocuments()->exists()) {
                throw new \RuntimeException('Não é possível excluir fatura que já gerou documento fiscal.');
            }
            if ($invoice->accountReceivables()->exists()) {
                throw new \RuntimeException('Não é possível excluir fatura com contas a receber vinculadas.');
            }
        });
    }

    protected $fillable = [
        'financial_policy',
        'customer_id',
        'company_id',
        'invoice_number',
        'invoice_date',
        'payment_method',
        'payment_condition',
        'payment_condition_details',
        'financial_category_id',
        'status',
        'pending',
        'confirmed',
        'auto_bank_slip_issuance',
        'auto_pix_charge_issuance',
        'canceled',
        'created_by',
        'updated_by',
        'confirmed_by',
        'canceled_by',
        'confirmed_at',
        'canceled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => Status::class,
        'cancellation_reason' => CancellationReason::class,
        'invoice_date' => 'date',
        'payment_method' => PaymentMethod::class,
        'payment_condition' => PaymentCondition::class,
        'payment_condition_details' => 'array',
        'financial_category_id' => 'integer',
        'pending' => 'boolean',
        'confirmed' => 'boolean',
        'auto_bank_slip_issuance' => 'boolean',
        'auto_pix_charge_issuance' => 'boolean',
        'canceled' => 'boolean',
        'confirmed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    public function accountReceivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class);
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
    }

    public function installments(): HasManyThrough
    {
        return $this->through('accountReceivables')->has('installments');
    }

    public function payments(): HasManyThrough
    {
        return $this->through('accountReceivables')->has('payments');
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    public function hasProcessingFiscalDocuments(): bool
    {
        return $this->fiscalDocuments()
            ->where(function ($query): void {
                $statuses = [NfeStatus::QUEUED, NfeStatus::IN_PROCESSING];
                $query->whereIn('nfe_status', $statuses)->orWhereIn('nfse_status', $statuses);
            })
            ->exists();
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(InvoiceConfirmation::class);
    }

    public function latestConfirmation(): HasOne
    {
        return $this->hasOne(InvoiceConfirmation::class)->latestOfMany();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(InvoiceSource::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(InvoiceOperation::class);
    }

    public function receivableLinks(): HasMany
    {
        return $this->hasMany(InvoiceReceivableLink::class);
    }

    public function confirmedComposition(): ?InvoiceConfirmation
    {
        return $this->status === Status::PENDING ? null : $this->latestConfirmation;
    }

    public function statusLabel(): string
    {
        $label = $this->status?->description() ?? '-';

        return $this->status === Status::CANCELLED && $this->cancellation_reason
            ? $label.' — '.$this->cancellation_reason->description()
            : $label;
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'origin_invoice_id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    /* ==============================
     |  Computed Attributes
     |==============================*/

    /**
     * Total bruto da fatura: soma dos brutos das OS e requisições vinculadas.
     */
    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['gross_amount'];
            }
        );
    }

    /**
     * Total geral líquido da fatura: soma dos totais líquidos das OS e requisições vinculadas.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['total_amount'];
            }
        );
    }

    /**
     * Total líquido de serviços da fatura: soma dos totais líquidos das OS vinculadas.
     */
    protected function servicesAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['services_amount'];
            }
        );
    }

    /**
     * Total líquido de produtos da fatura: soma dos totais líquidos das requisições vinculadas.
     */
    protected function productsAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['products_amount'];
            }
        );
    }

    /**
     * Total de descontos da fatura: soma dos descontos dos itens de OS e requisições vinculadas.
     */
    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['discount_amount'];
            }
        );
    }

    public function netValue(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                return $this->resolveAmounts()['net_value'];
            }
        );
    }

    /** Resolve authorized invoice totals in a fixed number of queries, without loading item payloads. */
    public static function preloadAmounts(Collection $invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }
        $invoices->loadMissing('latestConfirmation');
        $invoices = $invoices->filter(fn (self $invoice): bool => $invoice->confirmedComposition() === null);
        if ($invoices->isEmpty()) {
            return;
        }
        $ids = $invoices->modelKeys();
        $companies = $invoices->pluck('company_id')->unique()->all();
        $aggregate = static fn (string $source, string $items, string $foreignKey) => DB::table($source)
            ->join($items, $items.'.'.$foreignKey, '=', $source.'.id')
            ->whereIn($source.'.invoice_id', $ids)->whereIn($source.'.company_id', $companies)
            ->selectRaw($source.'.invoice_id, COALESCE(SUM('.$items.'.gross_amount), 0) as gross_amount, COALESCE(SUM('.$items.'.discount_amount), 0) as discount_amount, COALESCE(SUM('.$items.'.total_amount), 0) as total_amount')
            ->groupBy($source.'.invoice_id')->get()->keyBy('invoice_id');
        $services = $aggregate('service_orders', 'service_order_items', 'service_order_id');
        $products = $aggregate('requisitions', 'requisition_items', 'requisition_id');
        $travel = DB::table('service_orders')->whereIn('invoice_id', $ids)->whereIn('company_id', $companies)
            ->selectRaw('invoice_id, COALESCE(SUM(ROUND(COALESCE(travel_value, 0) * 100, 0)), 0) as amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        foreach ($invoices as $invoice) {
            $service = $services->get($invoice->id);
            $product = $products->get($invoice->id);
            $travelAmount = (float) $travel->get($invoice->id, 0);
            $serviceAmount = round(((float) ($service->total_amount ?? 0) + $travelAmount) / 100, 2);
            $productAmount = round((float) ($product->total_amount ?? 0) / 100, 2);
            $total = round($serviceAmount + $productAmount, 2);
            $invoice->resolvedAmounts = ['gross_amount' => round(((float) ($service->gross_amount ?? 0) + $travelAmount + (float) ($product->gross_amount ?? 0)) / 100, 2),
                'discount_amount' => round(((float) ($service->discount_amount ?? 0) + (float) ($product->discount_amount ?? 0)) / 100, 2),
                'services_amount' => $serviceAmount, 'products_amount' => $productAmount, 'total_amount' => $total, 'net_value' => $total];
        }
    }

    /**
     * Resolve os totais da fatura diretamente no SQL para evitar dupla conversão monetária.
     *
     * @return array{
     *     gross_amount: float,
     *     services_amount: float,
     *     products_amount: float,
     *     discount_amount: float,
     *     total_amount: float,
     *     net_value: float
     * }
     */
    private function resolveAmounts(): array
    {
        if ($confirmation = $this->confirmedComposition()) {
            return array_map(fn ($cents): float => round($cents / 100, 2), $confirmation->amounts);
        }
        if ($this->resolvedAmounts !== null) {
            return $this->resolvedAmounts;
        }

        if ($this->relationLoaded('serviceOrders') || $this->relationLoaded('requisitions')) {
            $serviceOrders = $this->relationLoaded('serviceOrders')
                ? $this->serviceOrders
                : $this->serviceOrders()->with('items')->get();

            $requisitions = $this->relationLoaded('requisitions')
                ? $this->requisitions
                : $this->requisitions()->with('items')->get();

            $serviceOrders->loadMissing('items');
            $requisitions->loadMissing('items');

            $servicesAmount = round((float) $serviceOrders->sum('total_amount'), 2);
            $productsAmount = round((float) $requisitions->sum('total_amount'), 2);
            $grossAmount = round(
                (float) $serviceOrders->sum('gross_amount') + (float) $requisitions->sum('gross_amount'),
                2
            );
            $discountAmount = round(
                (float) $serviceOrders->sum('discount_amount') + (float) $requisitions->sum('discount_amount'),
                2
            );
            $totalAmount = round($servicesAmount + $productsAmount, 2);

            return $this->resolvedAmounts = [
                'gross_amount' => $grossAmount,
                'services_amount' => $servicesAmount,
                'products_amount' => $productsAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'net_value' => $totalAmount,
            ];
        }

        // Os itens estão em centavos, enquanto travel_value é armazenado em reais.
        $serviceOrdersByInvoice = DB::table('service_orders')
            ->leftJoin('service_order_items', 'service_order_items.service_order_id', '=', 'service_orders.id')
            ->where('service_orders.invoice_id', $this->getKey())
            ->groupBy('service_orders.id', 'service_orders.travel_value')
            ->selectRaw('
                COALESCE(SUM(service_order_items.gross_amount), 0) + ROUND(COALESCE(service_orders.travel_value, 0) * 100, 0) as gross_amount,
                COALESCE(SUM(service_order_items.discount_amount), 0) as discount_amount,
                COALESCE(SUM(service_order_items.total_amount), 0) + ROUND(COALESCE(service_orders.travel_value, 0) * 100, 0) as total_amount
            ');

        $serviceTotals = DB::query()
            ->fromSub($serviceOrdersByInvoice, 'service_order_totals')
            ->selectRaw('
                COALESCE(SUM(service_order_totals.gross_amount), 0) as gross_amount,
                COALESCE(SUM(service_order_totals.discount_amount), 0) as discount_amount,
                COALESCE(SUM(service_order_totals.total_amount), 0) as total_amount
            ')
            ->first();

        $requisitionsByInvoice = DB::table('requisitions')
            ->leftJoin('requisition_items', 'requisition_items.requisition_id', '=', 'requisitions.id')
            ->where('requisitions.invoice_id', $this->getKey())
            ->groupBy('requisitions.id')
            ->selectRaw('
                COALESCE(SUM(requisition_items.gross_amount), 0) as gross_amount,
                COALESCE(SUM(requisition_items.discount_amount), 0) as discount_amount,
                COALESCE(SUM(requisition_items.total_amount), 0) as total_amount
            ');

        $productTotals = DB::query()
            ->fromSub($requisitionsByInvoice, 'requisition_totals')
            ->selectRaw('
                COALESCE(SUM(requisition_totals.gross_amount), 0) as gross_amount,
                COALESCE(SUM(requisition_totals.discount_amount), 0) as discount_amount,
                COALESCE(SUM(requisition_totals.total_amount), 0) as total_amount
            ')
            ->first();

        $servicesAmount = round(((float) ($serviceTotals->total_amount ?? 0)) / 100, 2);
        $productsAmount = round(((float) ($productTotals->total_amount ?? 0)) / 100, 2);
        $grossAmount = round(
            (((float) ($serviceTotals->gross_amount ?? 0)) / 100) + (((float) ($productTotals->gross_amount ?? 0)) / 100),
            2
        );
        $discountAmount = round(
            (((float) ($serviceTotals->discount_amount ?? 0)) / 100) + (((float) ($productTotals->discount_amount ?? 0)) / 100),
            2
        );
        $totalAmount = round($servicesAmount + $productsAmount, 2);

        return $this->resolvedAmounts = [
            'gross_amount' => $grossAmount,
            'services_amount' => $servicesAmount,
            'products_amount' => $productsAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'net_value' => $totalAmount,
        ];
    }

    public function refresh()
    {
        $this->resolvedAmounts = null;

        return parent::refresh();
    }
}
