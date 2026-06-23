<?php

namespace App\Models;

use App\Enum\WarrantyClaim\CoverageType;
use App\Enum\WarrantyClaim\Responsibility;
use App\Enum\WarrantyClaim\Status;
use App\Enum\WarrantyClaim\SupplierDecision;
use App\Enum\WarrantyClaim\SupplierResolution;
use App\Enum\WarrantyClaim\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    protected $fillable = [
        'company_id',
        'number',
        'type',
        'status',
        'customer_id',
        'supplier_id',
        'service_order_id',
        'origin_service_order_id',
        'origin_requisition_id',
        'origin_invoice_id',
        'origin_fiscal_document_id',
        'remittance_fiscal_document_id',
        'product_id',
        'equipment_id',
        'quantity',
        'serial_number',
        'lot_number',
        'expires_at',
        'coverage_type',
        'responsibility',
        'customer_issue_description',
        'technical_diagnosis',
        'resolution_notes',
        'supplier_protocol',
        'advanced_replacement',
        'supplier_decision',
        'supplier_resolution',
        'sent_to_supplier_at',
        'returned_from_supplier_at',
        'closed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => Type::class,
        'status' => Status::class,
        'quantity' => 'decimal:4',
        'expires_at' => 'date',
        'coverage_type' => CoverageType::class,
        'responsibility' => Responsibility::class,
        'advanced_replacement' => 'boolean',
        'supplier_decision' => SupplierDecision::class,
        'supplier_resolution' => SupplierResolution::class,
        'sent_to_supplier_at' => 'datetime',
        'returned_from_supplier_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function originServiceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'origin_service_order_id');
    }

    public function originRequisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'origin_requisition_id');
    }

    public function originInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'origin_invoice_id');
    }

    public function originFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'origin_fiscal_document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remittanceFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'remittance_fiscal_document_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasGeneratedRemittanceFiscalDocument(): bool
    {
        return $this->remittance_fiscal_document_id !== null;
    }
}
