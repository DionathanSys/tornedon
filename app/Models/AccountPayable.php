<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\AccountPayable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayable extends Model
{
    protected $fillable = [
        'supplier_id',
        'company_id',
        'fiscal_document_id',
        'sequence_number',
        'status',
        'due_date',
        'paid_date',
        'due_amount',
        'paid_amount',
        'document_number',
        'description',
        'paid',
        'type',
        'payment_method',
    ];

    protected $casts = [
        'status' => Status::class,
        'payment_method' => PaymentMethod::class,
        'due_date' => 'date',
        'paid_date' => 'date',
        'due_amount' => MoneyCast::class,
        'paid_amount' => MoneyCast::class,
        'paid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
