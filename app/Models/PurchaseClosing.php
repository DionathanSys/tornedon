<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\PurchaseClosing\Status;
use App\Models\Concerns\SkipsGeneratedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseClosing extends Model
{
    use SkipsGeneratedColumns;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'start_date',
        'end_date',
        'reference',
        'status',
        'notes',
        'gross_amount',
        'discount_amount',
        'account_payable_id',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => Status::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'gross_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'net_amount' => MoneyCast::class,
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function fiscalDocumentLinks(): HasMany
    {
        return $this->hasMany(PurchaseClosingFiscalDocument::class);
    }

    public function fiscalDocuments(): BelongsToMany
    {
        return $this->belongsToMany(FiscalDocument::class, 'purchase_closing_fiscal_documents')
            ->withPivot(['document_amount', 'discount_amount'])
            ->withTimestamps();
    }
}
