<?php

namespace App\Providers;

use App\Events\Quote\QuoteApproved;
use App\Events\Quote\QuoteReopened;
use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Listeners\Quote\CreateProductionOrderFromApprovedQuoteListener;
use App\Listeners\Quote\CreateRequisitionFromApprovedQuoteListener;
use App\Listeners\Quote\CreateServiceOrderFromApprovedQuoteListener;
use App\Listeners\Quote\UpdateQuoteItemsStatusListener;
use App\Listeners\Quote\UpdateQuoteItemsStatusOnReopenListener;
use App\Listeners\RequisitionItem\HandleStockReservationCreated;
use App\Listeners\RequisitionItem\HandleStockReservationDeleted;
use App\Listeners\RequisitionItem\HandleStockReservationUpdated;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Policies\CompanyPolicy;
use App\Policies\ServiceOrderPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Mapa de aliases para relacionamentos polimórficos de StockMovement
        Relation::morphMap([
            'requisition'       => \App\Models\Requisition::class,
            'quote'             => \App\Models\Quote::class,
            'service_order'     => \App\Models\ServiceOrder::class,
            'production_order'  => \App\Models\ProductionOrder::class,
        ]);

        // Registrar policies
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(ServiceOrder::class, ServiceOrderPolicy::class);

        // Eventos de item de requisição → atualização da quantidade reservada no estoque
        Event::listen(RequisitionItemCreated::class, HandleStockReservationCreated::class);
        Event::listen(RequisitionItemUpdated::class, HandleStockReservationUpdated::class);
        Event::listen(RequisitionItemDeleted::class, HandleStockReservationDeleted::class);

        // Eventos de orçamento aprovado
        Event::listen(QuoteApproved::class, UpdateQuoteItemsStatusListener::class);
        Event::listen(QuoteApproved::class, CreateRequisitionFromApprovedQuoteListener::class);
        Event::listen(QuoteApproved::class, CreateProductionOrderFromApprovedQuoteListener::class);
        Event::listen(QuoteApproved::class, CreateServiceOrderFromApprovedQuoteListener::class);

        // Eventos de orçamento reaberto
        Event::listen(QuoteReopened::class, UpdateQuoteItemsStatusOnReopenListener::class);
    }
}
