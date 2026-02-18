<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Auth;

class Tenant
{
    public static function id(): int
    {
        return Auth::user()->current_company_id;
    }
}