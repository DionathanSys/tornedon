<?php

namespace App\Providers;

use App\Models\ServiceOrder;
use App\Policies\ServiceOrderPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();

        // Registrar policies
        Gate::policy(ServiceOrder::class, ServiceOrderPolicy::class);
    }
}
