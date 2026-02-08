<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class NcmCode extends Model
{
    protected $fillable = [
        'code',
        'description',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? str_replace('.', '', trim($value)) : null,
        );
    }
}
