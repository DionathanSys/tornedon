<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Schemas\ProductionRequestForm;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Notification\NotifyService as notify;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class CreateProductionRequest extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string $resource = ProductionRequestResource::class;

    protected string $view = 'filament.shop.resources.production-requests.pages.mobile-create';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'is_manual_counterparty' => false,
            'order_date' => now()->toDateString(),
            'status' => Status::OPEN->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return ProductionRequestForm::configure($schema)->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['company_id'] = Filament::getTenant()->id;
        $data['status'] = Status::OPEN->value;
        $data['additional_info'] = $this->extractAdditionalInfo($data);

        $service = app(ProductionRequestService::class);
        $record = $service->create($data, Auth::id());

        if ($service->hasError() || $record === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            return;
        }

        notify::success('Pedido criado com sucesso.');

        $this->redirect(ProductionRequestResource::getUrl('edit', ['record' => $record]), navigate: true);
    }

    public function getListUrl(): string
    {
        return ProductionRequestResource::getUrl();
    }

    private function extractAdditionalInfo(array &$data): array
    {
        $additionalInfo = $data['additional_info'] ?? [];
        $additionalInfo['card_payment_profile_id'] = $data['card_payment_profile_id'] ?? null;
        $additionalInfo['payment_date'] = $data['payment_date'] ?? null;

        unset($data['card_payment_profile_id'], $data['payment_date'], $data['is_manual_counterparty']);

        if (filled($data['customer_id'] ?? null)) {
            $data['manual_counterparty_name'] = null;
        }

        return $additionalInfo;
    }
}
