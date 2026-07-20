<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Schemas\ProductionRequestForm;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\FinancialAccount;
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

    public bool $showDeliverConfirmation = false;

    public ?int $editingItemId = null;

    public array $deliverData = [];

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
        $this->resetDeliverForm();
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
            'quantity' => $this->formatQuantity((float) $item->quantity),
            'unit_price' => number_format((float) $item->unit_price, 2, ',', '.'),
        ];
        $this->applyUnitPriceForQuantity();
        $this->showItemForm = true;
    }

    public function incrementItemQuantity(): void
    {
        $this->itemData['quantity'] = $this->formatQuantity(
            $this->toDecimal($this->itemData['quantity'] ?? 0) + 1
        );

        $this->applyUnitPriceForQuantity();
    }

    public function decrementItemQuantity(): void
    {
        $quantity = $this->toDecimal($this->itemData['quantity'] ?? 0) - 1;

        $this->itemData['quantity'] = $this->formatQuantity(max(0.001, $quantity));
        $this->applyUnitPriceForQuantity();
    }

    public function incrementItemUnitPrice(): void
    {
        $this->itemData['unit_price'] = $this->formatMoney(
            $this->toDecimal($this->itemData['unit_price'] ?? 0) + 1
        );
    }

    public function decrementItemUnitPrice(): void
    {
        $unitPrice = $this->toDecimal($this->itemData['unit_price'] ?? 0) - 1;

        $this->itemData['unit_price'] = $this->formatMoney(max(0, $unitPrice));
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
            ->where('is_invoiceable', true)
            ->find((int) $data['product_id']);

        if (! $product) {
            notify::error('Produto nao encontrado.');

            return;
        }

        $quantity = $this->toDecimal($data['quantity']);
        $unitPrice = $this->packageUnitPrice($this->resolveTotalPackageQuantity($quantity));

        $payload = [
            'product_id' => $product->id,
            'description' => $product->name,
            'unit_of_measure' => $data['unit_of_measure'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
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

        $this->syncPackageUnitPrices();
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
        $this->syncPackageUnitPrices();
        $this->loadRecordRelations();
        notify::success('Item excluido com sucesso.');
    }

    public function deliver(): void
    {
        if ($this->record->status !== Status::OPEN) {
            notify::error('Somente pedidos abertos podem ser entregues.');

            return;
        }

        $this->resetDeliverForm();
        $this->showDeliverConfirmation = true;
    }

    public function confirmDeliver(): void
    {
        $service = app(ProductionRequestService::class);
        $delivered = $service->deliver($this->record, [
            'delivered_at' => now()->toDateString(),
            'mark_as_received' => (bool) ($this->deliverData['mark_as_received'] ?? false),
            'financial_account_id' => $this->deliverData['financial_account_id'] ?? null,
            'received_at' => $this->deliverData['received_at'] ?? now()->toDateString(),
        ], Auth::id());

        if ($service->hasError() || $delivered === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            return;
        }

        $this->record = $delivered;
        $this->loadRecordRelations();
        $this->fillMainForm();
        $this->resetDeliverForm();
        $this->showDeliverConfirmation = false;
        notify::success('Pedido entregue com sucesso.');
    }

    public function cancelDeliverConfirmation(): void
    {
        $this->showDeliverConfirmation = false;
        $this->resetDeliverForm();
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
            ->where('is_invoiceable', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function getFinancialAccountOptionsProperty(): array
    {
        return FinancialAccount::optionsForCompany(Filament::getTenant()->id);
    }

    public function updatedItemDataQuantity(mixed $value = null): void
    {
        $this->applyUnitPriceForQuantity();
    }

    public function updatedItemDataProductId($value): void
    {
        $product = filled($value)
            ? Product::query()
                ->where('company_id', Filament::getTenant()->id)
                ->where('is_active', true)
                ->where('is_invoiceable', true)
                ->find((int) $value)
            : null;

        if (! $product) {
            return;
        }

        $this->itemData['unit_of_measure'] = $product->unit?->value ?? 'UN';
        $this->applyUnitPriceForQuantity();
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
            'unit_price' => '15,00',
        ];
    }

    private function resetDeliverForm(): void
    {
        $this->deliverData = [
            'mark_as_received' => false,
            'financial_account_id' => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id),
            'received_at' => now()->toDateString(),
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

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, ',', '.');
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function applyUnitPriceForQuantity(): void
    {
        $quantity = $this->toDecimal($this->itemData['quantity'] ?? 0);

        $this->itemData['unit_price'] = $this->formatMoney(
            $this->packageUnitPrice($this->resolveTotalPackageQuantity($quantity))
        );
    }

    private function resolveTotalPackageQuantity(float $currentQuantity): float
    {
        $existingQuantity = $this->record->items
            ->reject(fn (ProductionRequestItem $item): bool => $this->editingItemId !== null && $item->id === $this->editingItemId)
            ->sum(fn (ProductionRequestItem $item): float => (float) $item->quantity);

        return round((float) $existingQuantity + $currentQuantity, 3);
    }

    private function syncPackageUnitPrices(): void
    {
        $items = $this->record->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $unitPrice = $this->packageUnitPrice(
            (float) $items->sum(fn (ProductionRequestItem $item): float => (float) $item->quantity)
        );

        foreach ($items as $item) {
            if ((float) $item->unit_price === $unitPrice) {
                continue;
            }

            $item->update([
                'unit_price' => $unitPrice,
                'updated_by' => Auth::id(),
            ]);
        }
    }

    private function packageUnitPrice(float $totalQuantity): float
    {
        return $totalQuantity > 1 ? 12 : 15;
    }
}
