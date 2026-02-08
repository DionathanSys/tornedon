<?php

namespace App\Models;

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
}
