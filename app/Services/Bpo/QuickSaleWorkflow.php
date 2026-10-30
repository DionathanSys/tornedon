<?php

namespace App\Services\Bpo;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\Payment\Condition;
use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\AccountReceivable;
use App\Models\BpoSale;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SaleTemplate;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditRecorder;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Invoice\Actions\ConfirmInvoiceAction;
use App\Services\Requisition\RequisitionService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Support\Financial\PaymentTerms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuickSaleWorkflow
{
    public function authorize(User $user, Company $company, string $permission, ?Model $record = null): void
    {
        abort_unless(app(UserAccessService::class)->allows($user, $company, $permission, 'bpo'), 403);
        if ($record) {
            abort_unless(app(CompanyAuthorization::class)->allowsRecord($user, $company, $permission, $record), 403);
        }
    }

    public function find(User $user, Company $company, int $id, string $action = 'view'): BpoSale
    {
        $sale = BpoSale::where('company_id', $company->id)->findOrFail($id);
        $this->authorize($user, $company, 'bpo_sales.'.$action, $sale);

        return $sale;
    }

    public function save(User $user, Company $company, array $data, string $requestId, ?int $id = null, int $revision = 0): BpoSale
    {
        $this->authorize($user, $company, $id ? 'bpo_sales.update' : 'bpo_sales.create');
        Validator::make(['request_id' => $requestId] + $data, [
            'request_id' => 'required|uuid', 'sale_template_id' => 'required|integer',
            'customer_id' => 'required|integer', 'sale_date' => 'required|date',
            'items' => 'required|array|min:1|max:100', 'items.*.catalog_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001|max:999999',
            'items.*.unit_price' => 'required|numeric|min:0.01|max:999999999',
            'items.*.discount_amount' => 'nullable|numeric|min:0|max:999999999',
            'description' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($user, $company, $data, $requestId, $id, $revision): BpoSale {
            // Also serializes creation/sequence allocation and a repeated first submission.
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $sale = $id ? $this->find($user, $company, $id, 'update') : null;
            $before = $sale ? app(AuditRecorder::class)->snapshot($sale) : null;
            if (! $id && BpoSale::where('company_id', $company->id)->where('request_id', $requestId)->exists()) {
                $this->fail('Esta solicitação já foi salva. Abra a venda existente para continuar.');
            }
            if ($sale) {
                $sale = BpoSale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                if ($sale->finalized_at || $sale->revision !== $revision) {
                    $this->fail('Venda finalizada ou alterada em outra sessão. Reabra o registro.');
                }
                if ((int) $sale->sale_template_id !== (int) $data['sale_template_id']) {
                    $this->fail('O modelo não pode ser trocado em um rascunho existente. Inicie outra venda.');
                }
            }
            $template = SaleTemplate::where('company_id', $company->id)->findOrFail($data['sale_template_id']);
            $this->authorize($user, $company, 'sale_templates.view', $template);
            if (! $template->is_active) {
                $this->fail('O modelo de venda está inativo.');
            }
            $snapshot = $sale?->template_snapshot ?? Arr::only($template->toArray(), ['name', 'document_type', 'operation_nature', 'stock_control_enabled', 'additional_information']);
            $isService = $snapshot['document_type'] === DocumentModel::NFSE->value;
            if ($isService && count($data['items']) !== 1) {
                $this->fail('Utilize um item de serviço por NFS-e.');
            }
            $partner = CompanyPartner::where('company_id', $company->id)->where('partner_id', $data['customer_id'])->where('is_active', true)->firstOrFail();
            $this->authorize($user, $company, 'partners.view', $partner);
            if (! in_array('customer', $partner->type ?? [], true)) {
                $this->fail('Selecione um cliente ativo da empresa.');
            }
            $module = $isService ? 'service_orders' : 'requisitions';
            $this->authorize($user, $company, $module.'.view');
            $this->authorize($user, $company, $module.'.update');
            if (! $isService && ($snapshot['stock_control_enabled'] ?? false)) {
                $this->authorize($user, $company, 'stock_movements.create');
                $this->authorize($user, $company, 'product_stocks.update');
            }
            $sourceService = app($isService ? ServiceOrderService::class : RequisitionService::class);
            $sourceData = ['company_id' => $company->id, 'customer_id' => $partner->partner_id,
                'additional_info' => ['origin' => 'bpo_quick_sale', 'sale_model' => $snapshot['name']]];
            if ($sale) {
                $source = $sale->source();
                $this->authorize($user, $company, $module.'.update', $source);
                if ($source->invoice_id || ! $source->state()->canEdit()) {
                    $this->fail('O registro operacional já foi faturado ou encerrado fora desta bancada.');
                }
                $itemService = app($isService ? ServiceOrderItemService::class : RequisitionItemService::class);
                foreach ($source->items as $item) {
                    $this->requireResult($itemService->delete($item), $itemService);
                }
                $source = $this->requireResult($sourceService->update($source, $sourceData + [($isService ? 'order_date' : 'sale_date') => $data['sale_date']], $user->id), $sourceService);
            } else {
                $this->authorize($user, $company, $module.'.create');
                $sourceData += $isService
                    ? ['order_date' => $data['sale_date'], 'status' => State::OPEN->value, 'type' => Type::OTHER->value, 'priority' => Priority::NORMAL->value, 'requires_approval' => false]
                    : ['sale_date' => $data['sale_date'], 'status' => Status::OPEN->value, 'stock_control_enabled' => (bool) ($snapshot['stock_control_enabled'] ?? false)];
                $source = $this->requireResult($sourceService->create($sourceData, $user->id), $sourceService);
            }
            foreach ($data['items'] as $input) {
                $catalog = ($isService ? Service::query() : Product::query())->where('company_id', $company->id)->where('is_active', true)->findOrFail($input['catalog_id']);
                $this->authorize($user, $company, ($isService ? 'services' : 'products').'.view', $catalog);
                if (! $isService && ! $catalog->unit) {
                    $this->fail('O produto "'.$catalog->name.'" está sem unidade de medida. Complete o cadastro antes de salvar a venda.');
                }
                if (! $isService && ($snapshot['stock_control_enabled'] ?? false) && $catalog->has_stock_control) {
                    $stock = ProductStock::where('company_id', $company->id)->where('product_id', $catalog->id)->lockForUpdate()->first();
                    if (! $stock) {
                        $this->fail('Cadastre o estoque do produto antes de usar um modelo com controle de estoque.');
                    }
                    $this->authorize($user, $company, 'product_stocks.update', $stock);
                }
                $quantity = round((float) $input['quantity'], 3);
                $price = round((float) $input['unit_price'], 2);
                $discount = round((float) ($input['discount_amount'] ?? 0), 2);
                if ($discount >= round($quantity * $price, 2)) {
                    $this->fail('O desconto deve ser menor que o total do item.');
                }
                $itemData = [($isService ? 'service_order_id' : 'requisition_id') => $source->id,
                    ($isService ? 'service_id' : 'product_id') => $catalog->id,
                    'quantity' => $quantity, 'unit_price' => $price, 'discount_amount' => $discount,
                    'discount_percentage' => 0, 'observations' => $data['description'] ?? null];
                if (! $isService) {
                    $itemData['unit_of_measure'] = $catalog->unit?->value;
                }
                $itemService = app($isService ? ServiceOrderItemService::class : RequisitionItemService::class);
                $this->requireResult($itemService->create($itemData, $user->id), $itemService);
            }
            $attributes = ['payment_settings' => Arr::only($data, ['payment_method', 'payment_condition', 'payment_condition_details', 'financial_category_id', 'financial_policy', 'financial_target_id', 'description']),
                'revision' => $sale ? $sale->revision + 1 : 1];
            if ($sale) {
                $sale->update($attributes);
            } else {
                $sale = BpoSale::create($attributes + ['company_id' => $company->id, 'request_id' => $requestId,
                    'sale_template_id' => $template->id, 'template_snapshot' => $snapshot,
                    ($isService ? 'service_order_id' : 'requisition_id') => $source->id, 'created_by' => $user->id]);
            }

            app(AuditRecorder::class)->recordModelEvent($sale, $before ? 'bpo_sale.updated' : 'bpo_sale.created', 'Rascunho de venda rápida salvo',
                $before, app(AuditRecorder::class)->snapshot($sale), $user->id);

            return $sale->fresh();
        });
    }

    public function finalize(User $user, Company $company, int $id, int $revision, array $paymentData = []): BpoSale
    {
        return DB::transaction(function () use ($user, $company, $id, $revision, $paymentData): BpoSale {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $sale = $this->find($user, $company, $id, 'approve');
            $sale = BpoSale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $company, 'bpo_sales.update', $sale);
            if ($sale->finalized_at) {
                return $sale;
            }
            if ($sale->revision !== $revision) {
                $this->fail('Rascunho alterado. Reabra a venda antes de finalizar.');
            }
            $source = $sale->source();
            $isService = $source instanceof ServiceOrder;
            if ($isService && $source->items()->count() !== 1) {
                $this->fail('A venda de serviço deve possuir um item. Revise a OS antes de finalizar.');
            }
            $module = $isService ? 'service_orders' : 'requisitions';
            foreach (['view', 'update', 'issue'] as $action) {
                $this->authorize($user, $company, $module.'.'.$action, $source);
            }
            $this->authorize($user, $company, 'invoices.create');
            $this->authorize($user, $company, 'invoices.update');
            $this->authorize($user, $company, 'invoices.approve');
            $this->authorize($user, $company, 'fiscal_documents.create');
            $this->authorize($user, $company, 'fiscal_documents.update');
            $settings = Arr::only($paymentData, ['payment_method', 'payment_condition', 'payment_condition_details', 'financial_category_id', 'financial_policy', 'financial_target_id']) + ($sale->payment_settings ?? []);
            $policy = $settings['financial_policy'] ?? 'create_receivable';
            Validator::make($settings + ['financial_policy' => $policy], [
                'financial_policy' => ['required', Rule::in(['create_receivable', 'link_existing', 'fiscal_only'])],
                'payment_method' => ['required', Rule::in(['pix', 'transferencia', 'boleto', 'dinheiro', 'outros', 'deposito', 'cheque', 'crediario'])],
                'payment_condition' => ['required', Rule::enum(Condition::class)],
                ...PaymentTerms::rules(),
            ])->validate();
            if ($policy === 'create_receivable') {
                $this->authorize($user, $company, 'account_receivables.create');
                $this->authorize($user, $company, 'account_receivables.update');
            } else {
                $this->authorize($user, $company, 'fiscal_documents.approve');
            }
            if (! empty($settings['financial_category_id'])) {
                $category = FinancialCategory::where('company_id', $company->id)->findOrFail($settings['financial_category_id']);
                $this->authorize($user, $company, 'financial_categories.view', $category);
            }
            $sourceService = app($isService ? ServiceOrderService::class : RequisitionService::class);
            if ($source->invoice_id) {
                $this->fail('Este registro já foi faturado fora da bancada.');
            }
            $source = $this->requireResult($sourceService->close($source, $user->id, false), $sourceService);
            $invoice = $this->requireResult($sourceService->invoice($source, $user->id), $sourceService);
            $this->authorize($user, $company, 'invoices.update', $invoice);
            $invoice->update(['financial_policy' => $policy, 'invoice_date' => $isService ? $source->order_date : $source->sale_date]);
            $confirmation = new ConfirmInvoiceAction($invoice->fresh(), $user->id);
            $this->requireResult($confirmation->execute([
                ...Arr::only($settings, ['payment_method', 'payment_condition', 'payment_condition_details', 'financial_category_id']),
                'nfse_item_description' => $settings['description'] ?? null,
                'sale_operation_nature' => $sale->template_snapshot['operation_nature'] ?? null,
                'nfse_additional_information' => $sale->template_snapshot['additional_information'] ?? null,
                'auto_bank_slip_issuance' => false, 'auto_pix_charge_issuance' => false, 'mark_as_received' => false,
            ]), $confirmation);
            if ($policy === 'link_existing') {
                $target = AccountReceivable::where('company_id', $company->id)->lockForUpdate()->findOrFail($settings['financial_target_id'] ?? 0);
                $this->authorize($user, $company, 'account_receivables.update', $target);
                if ((int) $target->customer_id !== (int) $source->customer_id || $target->invoice_id || $target->fiscal_document_id
                    || $target->status === \App\Enum\AccountReceivable\Status::CANCELLED
                    || (int) round($target->due_amount * 100) !== (int) round($invoice->fresh()->netValue * 100)) {
                    $this->fail('Recebível incompatível: confira cliente, valor original, cancelamento e vínculos existentes.');
                }
                $beforeTarget = app(AuditRecorder::class)->snapshot($target);
                $target->update(['invoice_id' => $invoice->id, 'fiscal_document_id' => $invoice->fiscalDocuments()->sole()->id]);
                app(AuditRecorder::class)->recordModelEvent($target, 'account_receivable.linked_to_invoice', 'Recebível existente vinculado à venda rápida',
                    $beforeTarget, app(AuditRecorder::class)->snapshot($target), $user->id, metadata: ['bpo_sale_id' => $sale->id]);
            }
            $sale->update(['invoice_id' => $invoice->id, 'payment_settings' => $settings, 'finalized_at' => now(), 'revision' => $sale->revision + 1]);
            app(AuditRecorder::class)->recordModelEvent($sale, 'bpo_sale.finalized', 'Venda rápida finalizada', after: app(AuditRecorder::class)->snapshot($sale), actorUserId: $user->id);

            return $sale->fresh();
        });
    }

    public function requestEmission(User $user, Company $company, int $id): void
    {
        $sale = $this->find($user, $company, $id);
        if (! $sale->finalized_at) {
            $this->fail('Finalize a venda antes de solicitar emissão.');
        }
        $invoice = Invoice::where('company_id', $company->id)->findOrFail($sale->invoice_id);
        $this->authorize($user, $company, 'invoices.view', $invoice);
        if ($invoice->canceled) {
            $this->fail('A fatura está cancelada.');
        }
        $document = $invoice->fiscalDocuments()->where('company_id', $company->id)->sole();
        foreach (['view', 'update', 'issue'] as $action) {
            $this->authorize($user, $company, 'fiscal_documents.'.$action, $document);
        }
        $service = app($document->isNfse() ? NfseDocumentService::class : NfeDocumentService::class);
        $this->requireResult($service->emitir($document, $user->id), $service);
    }

    private function requireResult(mixed $result, object $service): mixed
    {
        if ($result === null || $result === false || $service->hasError()) {
            $messages = array_filter(Arr::flatten($service->getErrors()), fn ($message) => is_string($message) && filled($message));
            $this->fail($messages ? implode("\n", $messages) : ($service->getMessage() ?: 'Não foi possível concluir a operação.'));
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
