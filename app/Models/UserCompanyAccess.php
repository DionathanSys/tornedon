<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCompanyAccess extends Model
{
    protected $fillable = ['company_id', 'permissions', 'panels', 'navigation'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'panels' => 'array', 'navigation' => 'array'];
    }
}
