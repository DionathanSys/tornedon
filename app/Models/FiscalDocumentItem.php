<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDocumentItem extends Model
{
    protected $fillable = [
        'fiscal_document_id',
        'product_id',
        'product_code',
        'description',
        'service_id',
        'municipal_tax_code',
        'nbs_code',
        'cnae_code',
        'item_number',
        'product_origin',
        'ncm_code',
        'cest_code',
        'barcode',
        'cfop_code',
        'quantity',
        'unit_of_measure',
        'taxable_unit',
        'taxable_quantity',
        'taxable_unit_price',
        'unit_price',
        'total_price',
        'discount_amount',
        'freight_amount',
        'insurance_amount',
        'other_expenses_amount',
        'included_in_total',
        'tax_data',
        'fiscal_snapshot',
        'additional_information',
        'iss_exigibility',
        'iss_rate',
        'iss_amount',
        'iss_withheld',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'item_number' => 'integer',
        'quantity' => 'decimal:4',
        'taxable_quantity' => 'decimal:4',
        'taxable_unit_price' => MoneyCast::class,
        'unit_price' => MoneyCast::class,
        'total_price' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'freight_amount' => MoneyCast::class,
        'insurance_amount' => MoneyCast::class,
        'other_expenses_amount' => MoneyCast::class,
        'included_in_total' => 'boolean',
        'tax_data' => 'array',
        'fiscal_snapshot' => 'array',
        'iss_rate' => 'float',
        'iss_amount' => 'float',
        'iss_withheld' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productStock(): BelongsTo
    {
        return $this->belongsTo(ProductStock::class, 'product_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function remittanceAssets(): HasMany
    {
        return $this->hasMany(RemittanceAsset::class);
    }

    public function originLinks(): HasMany
    {
        return $this->hasMany(FiscalDocumentItemOrigin::class, 'return_fiscal_document_item_id');
    }

    public function destinationLinks(): HasMany
    {
        return $this->hasMany(FiscalDocumentItemOrigin::class, 'origin_fiscal_document_item_id');
    }

    public function originItems(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'fiscal_document_item_origins',
            'return_fiscal_document_item_id',
            'origin_fiscal_document_item_id'
        )->withPivot([
            'origin_fiscal_document_id',
            'return_fiscal_document_id',
            'linked_quantity',
            'linked_value',
            'origin_document_key',
            'metadata',
        ])->withTimestamps();
    }

    public function returnItems(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'fiscal_document_item_origins',
            'origin_fiscal_document_item_id',
            'return_fiscal_document_item_id'
        )->withPivot([
            'origin_fiscal_document_id',
            'return_fiscal_document_id',
            'linked_quantity',
            'linked_value',
            'origin_document_key',
            'metadata',
        ])->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getServiceCodeAttribute(): ?string
    {
        $value = $this->attributes['municipal_tax_code']
            ?? $this->attributes['service_code']
            ?? $this->fiscal_snapshot['service_code']
            ?? $this->fiscal_snapshot['municipal_tax_code']
            ?? $this->service?->municipal_tax_code
            ?? $this->service?->service_code
            ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function getNbsCodeAttribute(): ?string
    {
        $value = $this->attributes['nbs_code']
            ?? $this->fiscal_snapshot['nbs_code']
            ?? $this->service?->nbs_code
            ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function getCnaeCodeAttribute(): ?string
    {
        $value = $this->attributes['cnae_code']
            ?? $this->fiscal_snapshot['cnae_code']
            ?? $this->service?->cnae_code
            ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function getMunicipalTaxCodeAttribute(): ?string
    {
        $value = $this->attributes['municipal_tax_code']
            ?? $this->fiscal_snapshot['municipal_tax_code']
            ?? $this->attributes['service_code']
            ?? $this->service?->municipal_tax_code
            ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function canEdit(): bool
    {
        return $this->fiscalDocument?->canEditItems() ?? false;
    }

    public function canDelete(): bool
    {
        return $this->fiscalDocument?->canDeleteItems() ?? false;
    }
}
