<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyCardStatementItem extends Model
{
    protected $fillable = [
        'company_card_statement_id',
        'company_card_transaction_id',
        'amount_allocated',
    ];

    protected $casts = [
        'amount_allocated' => MoneyCast::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CompanyCardStatement::class, 'company_card_statement_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CompanyCardTransaction::class, 'company_card_transaction_id');
    }
}
