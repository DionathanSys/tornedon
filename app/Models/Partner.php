<?php

namespace App\Models;

use App\Enum;
use App\Services\DataReplication\ReplicationService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;   
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'document_type',
        'document_number',
        'is_active',
        'state_tax_id',
        'state_tax_indicator',
        'municipal_tax_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'state_tax_indicator' => Enum\Tax\StateTaxIndicator::class,
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function address(): HasManyThrough
    {
        return $this->hasManyThrough(
            Address::class,
            CompanyPartner::class,
            'partner_id',
            'company_partner_id',
            'id',
            'id'
        );
    }

    public function contacts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Contact::class,
            CompanyPartner::class,
            'partner_id',
            'company_partner_id',
            'id',
            'id'
        );
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_partner', 'partner_id', 'company_id');
    }

    public function sefazItemMappings(): HasMany
    {
        return $this->hasMany(SefazItemMapping::class);
    }

    public function issuedCompanyCreditCards(): HasMany
    {
        return $this->hasMany(CompanyCreditCard::class, 'issuer_partner_id');
    }

    public function companyCardTransactionsAsVendor(): HasMany
    {
        return $this->hasMany(CompanyCardTransaction::class, 'vendor_id');
    }



}
