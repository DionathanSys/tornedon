<?php

namespace App\Filament\Operation\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Operation\Concerns\HasModuleForm;
use App\Filament\Operation\Concerns\HasOperationActions;
use App\Filament\Operation\Concerns\HasOperationFeedback;
use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\OperationResourceSupport;
use App\Filament\Operation\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\ProductionRequest;
use App\Models\ProductionRequestItem;
use App\Services\ProductionRequest\ProductionRequestService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class EditProductionRequest extends Page
{
    use HasModuleForm, HasOperationActions, HasOperationFeedback;
    use HasOperationResourcePage;

    protected static string $resource = ProductionRequestResource::class;

    protected string $view = 'filament.operation.resources.production-requests.pages.detail';

    /** @var ProductionRequest */
    #[Locked]
    public $record;

    public array $itemData = [];

    public bool $showItemForm = false;

    public bool $showDeliverConfirmation = false;

    public ?int $editingItemId = null;

    public array $deliverData = [];

    public bool $showDeleteItemModal = false;

    #[Locked]
    public ?int $deletingItemId = null;

    public function moduleKey(): string
    {
        return 'production_requests';
    }

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

    public function save(): void
    {
        $this->reloadRecord();
        if ($this->record->status !== Status::OPEN) {
            $this->error('Somente pedidos abertos podem ser editados.');

            return;
        }

        $data = $this->moduleFormPayload();
        $data['company_id'] = $this->record->company_id;
        $data['customer_id'] = $this->record->customer_id;
        $data['manual_counterparty_name'] = $this->record->manual_counterparty_name;
        $data['order_date'] = $this->record->order_date?->toDateString();
        $data['observations'] = $this->record->observations;

        $service = app(ProductionRequestService::class);
        $updated = $service->update($this->record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            $this->serviceFailed($service);

            return;
        }

        $this->record = $updated;
        $this->loadRecordRelations();
        $this->fillMainForm();

        $this->success('Pedido salvo com sucesso.');
    }

    public function toggleItemForm(): void
    {
        $this->reloadRecord();
        abort_unless($this->record->status === Status::OPEN, 403);
        $this->resetValidation();
        if ($this->showItemForm && $this->editingItemId === null) {
            $this->showItemForm = false;

            return;
        }

        $this->resetItemForm();
        $this->showItemForm = true;
    }

    public function editItem(int $itemId): void
    {
        $this->reloadRecord();
        abort_unless($this->record->status === Status::OPEN, 403);
        $this->resetValidation();
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
        $this->reloadRecord();
        if ($this->record->status !== Status::OPEN) {
            $this->error('Somente pedidos abertos podem receber itens.');

            return;
        }

        $data = $this->validate([
            'itemData.product_id' => ['required', 'integer'],
            'itemData.unit_of_measure' => ['required', 'string', 'max:10'],
            'itemData.quantity' => ['required'],
            'itemData.unit_price' => ['required'],
        ])['itemData'];

        $product = Product::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where('is_active', true)
            ->where('is_invoiceable', true)
            ->find((int) $data['product_id']);

        if (! $product) {
            $this->addError('itemData.product_id', 'Selecione um produto ativo desta empresa.');

            return;
        }

        $quantity = OperationResourceSupport::normalizeNumber($data['quantity']);
        if (! is_numeric($quantity) || $quantity <= 0) {
            $this->addError('itemData.quantity', 'A quantidade deve ser maior que zero.');

            return;
        }
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
            $this->success('Item atualizado com sucesso.');
        } else {
            $this->record->items()->create([
                ...$payload,
                'created_by' => Auth::id(),
                'sequence' => ((int) $this->record->items()->max('sequence')) + 1,
            ]);
            $this->success('Item adicionado com sucesso.');
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
        abort_unless($this->showDeleteItemModal && $this->deletingItemId === $itemId, 403);
        $this->reloadRecord();
        if ($this->record->status !== Status::OPEN) {
            $this->error('Somente pedidos abertos podem excluir itens.');

            return;
        }

        $this->record->items()->whereKey($itemId)->firstOrFail()->delete();
        $this->syncPackageUnitPrices();
        $this->loadRecordRelations();
        $this->showDeleteItemModal = false;
        $this->success('Item excluído com sucesso.');
    }

    public function requestItemDelete(int $itemId): void
    {
        $this->reloadRecord();
        abort_unless($this->record->status === Status::OPEN, 403);
        $this->record->items()->whereKey($itemId)->firstOrFail();
        $this->deletingItemId = $itemId;
        $this->showDeleteItemModal = true;
    }

    public function confirmItemDelete(): void
    {
        abort_unless($this->deletingItemId !== null, 403);
        $this->deleteItem($this->deletingItemId);
    }

    public function deliver(): void
    {
        $this->reloadRecord();
        $this->resetValidation();
        if ($this->record->status !== Status::OPEN) {
            $this->error('Somente pedidos abertos podem ser entregues.');

            return;
        }

        $this->resetDeliverForm();
        $this->showDeliverConfirmation = true;
    }

    public function confirmDeliver(): void
    {
        abort_unless($this->showDeliverConfirmation, 403);
        $this->reloadRecord();
        $this->resetValidation();
        $data = $this->validate([
            'deliverData.mark_as_received' => ['required', 'boolean'],
            'deliverData.financial_account_id' => [($this->deliverData['mark_as_received'] ?? false) ? 'required' : 'nullable', Rule::in(array_keys($this->financialAccountOptions))],
            'deliverData.received_at' => [($this->deliverData['mark_as_received'] ?? false) ? 'required' : 'nullable', 'date'],
        ])['deliverData'];
        $service = app(ProductionRequestService::class);
        $delivered = $service->deliver($this->record, [
            'delivered_at' => now()->toDateString(),
            'mark_as_received' => (bool) ($data['mark_as_received'] ?? false),
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'received_at' => $data['received_at'] ?? now()->toDateString(),
        ], Auth::id());

        if ($service->hasError() || $delivered === null) {
            $this->serviceFailed($service, 'deliverData');

            return;
        }

        $this->record = $delivered;
        $this->loadRecordRelations();
        $this->fillMainForm();
        $this->resetDeliverForm();
        $this->showDeliverConfirmation = false;
        $this->success('Pedido entregue com sucesso.');
    }

    public function cancelDeliverConfirmation(): void
    {
        $this->showDeliverConfirmation = false;
        $this->resetDeliverForm();
    }

    public function cancel(): void
    {
        abort_unless($this->showConfirmation && $this->confirmationOperation === 'cancel', 403);
        $this->reloadRecord();
        $service = app(ProductionRequestService::class);

        if (! $service->cancel($this->record, Auth::id())) {
            $this->serviceFailed($service);

            return;
        }

        $this->record->refresh();
        $this->loadRecordRelations();
        $this->success('Pedido cancelado com sucesso.');
    }

    private function reloadRecord(): void
    {
        $this->record = ProductionRequest::query()->where('company_id', Filament::getTenant()->id)->findOrFail($this->record->id);
        $this->loadRecordRelations();
    }

    public function hydrate(): void
    {
        $this->reloadRecord();
    }

    protected function getOperationActions(): array
    {
        $actions = [['label' => 'Lista', 'icon' => 'o-arrow-left', 'url' => $this->getListUrl()]];
        if ($this->record->status === Status::OPEN) {
            $actions[] = ['label' => 'Salvar', 'icon' => 'o-check', 'method' => 'save', 'primary' => true];
            $actions[] = ['label' => 'Entregar', 'icon' => 'o-truck', 'method' => 'deliver'];
            $actions[] = ['label' => 'Cancelar', 'icon' => 'o-x-circle', 'method' => 'cancel', 'more' => true, 'confirm' => true];
        }

        return $actions;
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
        $this->initializeModuleForm($this->record);
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
