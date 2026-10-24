<?php

namespace App\Models;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitmentSetting extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'is_enabled', 'parser', 'nfse_service_id', 'payment_method', 'payment_condition', 'payment_condition_details', 'financial_category_id', 'financial_account_id', 'nfse_footer_message', 'created_by'];

    protected $casts = ['is_enabled' => 'boolean', 'payment_method' => Method::class, 'payment_condition' => Condition::class, 'payment_condition_details' => 'array'];

    protected function contextRelations(): array
    {
        return ['nfseService', 'financialCategory', 'financialAccount'];
    }

    public function nfseService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'nfse_service_id');
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
