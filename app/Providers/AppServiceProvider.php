<?php

namespace App\Providers;

use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Listeners\RequisitionItem\HandleStockReservationCreated;
use App\Listeners\RequisitionItem\HandleStockReservationDeleted;
use App\Listeners\RequisitionItem\HandleStockReservationUpdated;
use App\Models\ServiceOrder;
use App\Policies\ServiceOrderPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
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

        // Eventos de item de requisição → atualização da quantidade reservada no estoque
        Event::listen(RequisitionItemCreated::class, HandleStockReservationCreated::class);
        Event::listen(RequisitionItemUpdated::class, HandleStockReservationUpdated::class);
        Event::listen(RequisitionItemDeleted::class, HandleStockReservationDeleted::class);
    }
}
