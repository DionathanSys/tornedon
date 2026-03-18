<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyPartner extends Model
{
    protected $table = 'company_partner';

    protected $appends = [
        'address',
    ];

    protected $fillable = [
        'partner_id',
        'company_id',
        'type',
        'invoice_threshold',
        'is_active',
        'notify_service_order_closed',
        'notify_requisition_closed',
        'notify_fiscal_document_confirmed',
        'email_to_override',
        'email_cc_override',
        'email_bcc_override',
    ];

    protected $casts = [
        'invoice_threshold' => MoneyCast::class,
        'type'              => 'array',
        'is_active'         => 'boolean',
        'notify_service_order_closed' => 'boolean',
        'notify_requisition_closed' => 'boolean',
        'notify_fiscal_document_confirmed' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'company_partner_id', 'id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'company_partner_id', 'id');
    }

    public function emailDispatches(): HasMany
    {
        return $this->hasMany(EmailDispatch::class, 'company_partner_id', 'id');
    }

    public function address(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->company()->first()->address
        );
    }
}
