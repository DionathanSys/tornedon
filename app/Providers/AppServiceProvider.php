<?php

namespace App\Providers;

use App\Events\Quote\QuoteApproved;
use App\Events\Quote\QuoteReopened;
use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Forms\Components\Livewire\AutoSubmitTableSelectLivewireComponent;
use App\Listeners\Quote\CreateProductionOrderFromApprovedQuoteListener;
use App\Listeners\Quote\CreateRequisitionFromApprovedQuoteListener;
use App\Listeners\Quote\CreateServiceOrderFromApprovedQuoteListener;
use App\Listeners\Quote\UpdateQuoteItemsStatusListener;
use App\Listeners\Quote\UpdateQuoteItemsStatusOnReopenListener;
use App\Listeners\RequisitionItem\HandleStockReservationCreated;
use App\Listeners\RequisitionItem\HandleStockReservationDeleted;
use App\Listeners\RequisitionItem\HandleStockReservationUpdated;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\Quote;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Policies\AuditEntryPolicy;
use App\Observers\FiscalDocumentObserver;
use App\Observers\InvoiceObserver;
use App\Observers\ProductionOrderObserver;
use App\Observers\RequisitionObserver;
use App\Observers\ServiceOrderObserver;
use App\Policies\CompanyPolicy;
use App\Policies\ServiceOrderPolicy;
use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\Providers\ResendEmailProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmailProviderInterface::class, function ($app) {
            $provider = (string) config('email_notifications.provider', 'resend');

            if ($provider === 'resend') {
                return $app->make(ResendEmailProvider::class);
            }

            if (class_exists($provider)) {
                $instance = $app->make($provider);

                if (! $instance instanceof EmailProviderInterface) {
                    throw new RuntimeException("A classe {$provider} não implementa EmailProviderInterface.");
                }

                return $instance;
            }

            throw new RuntimeException("Provedor de e-mail não suportado: {$provider}");
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();

        Horizon::auth(function ($request) {
            return Auth::check()
                && in_array(Auth::user()->email, [
                    'dev@dev.com',
                ], true);
        });

        Livewire::component('app.filament.relation-managers.attachments-relation-manager', AttachmentsRelationManager::class);
        Livewire::component('app.forms.components.livewire.auto-submit-table-select-livewire-component', AutoSubmitTableSelectLivewireComponent::class);

        // Mapa de aliases para relacionamentos polimórficos de StockMovement
        Relation::morphMap([
            'requisition' => \App\Models\Requisition::class,
            'quote' => \App\Models\Quote::class,
            'service_order' => \App\Models\ServiceOrder::class,
            'production_order' => \App\Models\ProductionOrder::class,
        ]);

        // Registrar policies
        Gate::policy(AuditEntry::class, AuditEntryPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(ServiceOrder::class, ServiceOrderPolicy::class);

        ServiceOrder::observe(ServiceOrderObserver::class);
        Requisition::observe(RequisitionObserver::class);
        ProductionOrder::observe(ProductionOrderObserver::class);
        Invoice::observe(InvoiceObserver::class);
        FiscalDocument::observe(FiscalDocumentObserver::class);

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

        // Replicação de Partners e Equipments
        // ReplicatePartnerOnCreate::register(Event::getFacadeRoot());
        // ReplicateEquipmentOnCreate::register(Event::getFacadeRoot());
    }
}
