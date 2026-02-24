<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSequence extends Model
{
    protected $fillable = [
        'company_id',
        'last_number',
    ];
}
