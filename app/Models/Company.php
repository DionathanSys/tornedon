<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }   

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'company_partner', 'company_id', 'partner_id');
    }
}
