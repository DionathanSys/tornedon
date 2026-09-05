<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CnpjProviderSecret extends Model
{
    protected $fillable = [
        'provider',
        'value',
    ];

    protected $casts = [
        'value' => 'encrypted:array',
    ];
}
