<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Schemas\ProductionRequestForm;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\Product;
use App\Models\ProductionRequest;
use App\Models\ProductionRequestItem;
use App\Notification\NotifyService as notify;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class EditProductionRequest extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string $resource = ProductionRequestResource::class;

    protected string $view = 'filament.shop.resources.production-requests.pages.mobile-detail';

    public ProductionRequest $record;

    public ?array $data = [];

    public array $itemData = [];

    public bool $showItemForm = false;

    public ?int $editingItemId = null;

    public function mount(int|string|ProductionRequest $record): void
    {
        $recordKey = $record instanceof ProductionRequest ? $record->getKey() : $record;

        $this->record = ProductionRequest::query()
            ->where('company_id', Filament::getTenant()->id)
            ->whereKey($recordKey)
            ->firstOrFail();

        $this->loadRecordRelations();
        $this->fillMainForm();
        $this->resetItemForm();
    }

    public function form(Schema $schema): Schema
    {
        return ProductionRequestForm::configure($schema, includeOrderData: false)->statePath('data');
    }

    public function save(): void
    {
        if ($this->record->status !== Status::OPEN) {
            notify::error('Somente pedidos abertos podem ser editados.');

            return;
        }

        $data = $this->form->getState();
        $data['company_id'] = $this->record->company_id;
        $data['customer_id'] = $this->record->customer_id;
        $data['manual_counterparty_name'] = $this->record->manual_counterparty_name;
        $data['order_date'] = $this->record->order_date?->toDateString();
        $data['observations'] = $this->record->observations;
        $data['additional_info'] = $this->extractAdditionalInfo($data);

        $service = app(ProductionRequestService::class);
        $updated = $service->update($this->record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            return;
        }

        $this->record = $updated;
        $this->loadRecordRelations();
        $this->fillMainForm();

        notify::success('Pedido salvo com sucesso.');
    }

    public function toggleItemForm(): void
    {
        if ($this->showItemForm && $this->editingItemId === null) {
            $this->showItemForm = false;

            return;
        }

        $this->resetItemForm();
        $this->showItemForm = true;
    }

    public function editItem(int $itemId): void
    {
        $item = $this->record->items()->whereKey($itemId)->firstOrFail();

        $this->editingItemId = $item->id;
        $this->itemData = [
            'product_id' => $item->product_id,
            'unit_of_measure' => $item->unit_of_measure,
            'quantity' => (string) $item->quantity,
            'unit_price' => number_format((float) $item->unit_price, 2, ',', '.'),
        ];
        $this->showItemForm = true;
    }

    public function saveItem(bool $createAnother = false): void
    {
        if ($this->record->status !== Status::OPEN) {
            notify::error('Somente pedidos abertos podem receber itens.');

            return;
        }

        $data = validator($this->itemData, [
            'product_id' => ['required', 'integer'],
            'unit_of_measure' => ['required', 'string', 'max:10'],
            'quantity' => ['required'],
            'unit_price' => ['required'],
        ])->validate();

        $product = Product::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where('is_active', true)
            ->find((int) $data['product_id']);

        if (! $product) {
            notify::error('Produto nao encontrado.');

            return;
        }

        $payload = [
            'product_id' => $product->id,
            'description' => $product->name,
            'unit_of_measure' => $data['unit_of_measure'],
            'quantity' => $this->toDecimal($data['quantity']),
            'unit_price' => $this->toDecimal($data['unit_price']),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'updated_by' => Auth::id(),
        ];

        if ($this->editingItemId) {
            $item = $this->record->items()->whereKey($this->editingItemId)->firstOrFail();
            $item->update($payload);
            notify::success('Item atualizado com sucesso.');
        } else {
            $this->record->items()->create([
                ...$payload,
                'created_by' => Auth::id(),
                'sequence' => ((int) $this->record->items()->max('sequence')) + 1,
            ]);
            notify::success('Item adicionado com sucesso.');
        }

        $this->loadRecordRelations();

        if ($createAnother) {
            $this->resetItemForm();
            $this->showItemForm = true;

            return;
        }

        $this->resetItemForm();
        $this->showItemForm = false;
    }

    public function deleteItem(int $itemId): void
    {
        if ($this->record->status !== Status::OPEN) {
            notify::error('Somente pedidos abertos podem excluir itens.');

            return;
        }

        $this->record->items()->whereKey($itemId)->delete();
        $this->loadRecordRelations();
        notify::success('Item excluido com sucesso.');
    }

    public function deliver(): void
    {
        $service = app(ProductionRequestService::class);
        $delivered = $service->deliver($this->record, ['delivered_at' => now()->toDateString()], Auth::id());

        if ($service->hasError() || $delivered === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            return;
        }

        $this->record = $delivered;
        $this->loadRecordRelations();
        $this->fillMainForm();
        notify::success('Pedido entregue com sucesso.');
    }

    public function cancel(): void
    {
        $service = app(ProductionRequestService::class);

        if (! $service->cancel($this->record, Auth::id())) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            return;
        }

        $this->record->refresh();
        $this->loadRecordRelations();
        notify::success('Pedido cancelado com sucesso.');
    }

    public function getListUrl(): string
    {
        return ProductionRequestResource::getUrl();
    }

    public function getProductOptionsProperty(): array
    {
        return Product::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function updatedItemDataProductId($value): void
    {
        $product = filled($value)
            ? Product::query()->where('company_id', Filament::getTenant()->id)->find((int) $value)
            : null;

        if (! $product) {
            return;
        }

        $this->itemData['unit_of_measure'] = $product->unit?->value ?? 'UN';
        $this->itemData['unit_price'] = number_format((float) ($product->sale_price_value ?? 0), 2, ',', '.');
    }

    private function fillMainForm(): void
    {
        $this->form->fill([
            ...$this->record->attributesToArray(),
            'is_manual_counterparty' => blank($this->record->customer_id) && filled($this->record->manual_counterparty_name),
            'card_payment_profile_id' => data_get($this->record->additional_info, 'card_payment_profile_id'),
            'payment_date' => data_get($this->record->additional_info, 'payment_date'),
        ]);
    }

    private function loadRecordRelations(): void
    {
        $this->record->load(['customer', 'accountReceivable', 'items.product']);
    }

    private function resetItemForm(): void
    {
        $this->editingItemId = null;
        $this->itemData = [
            'product_id' => null,
            'unit_of_measure' => 'UN',
            'quantity' => '1,000',
            'unit_price' => '0,00',
        ];
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

    private function toDecimal(mixed $value): float
    {
        $normalized = trim((string) $value);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }
}
