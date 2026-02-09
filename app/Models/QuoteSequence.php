<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteSequence extends Model
{
    protected $fillable = [
        'company_id',
        'last_number',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
