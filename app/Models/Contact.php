<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = [
        'company_partner_id',
        'email',
        'phone',
        'mobile',
        'notify',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'notify' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function companyPartner(): BelongsTo
    {
        return $this->belongsTo(CompanyPartner::class, 'company_partner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
