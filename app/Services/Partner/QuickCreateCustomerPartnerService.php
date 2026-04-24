<?php

namespace App\Services\Partner;

use App\Enum\Partner\Type as PartnerType;
use App\Jobs\ImportCompanyPartnerCnpjDataJob;
use App\Models\CompanyPartner;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuickCreateCustomerPartnerService
{
    use HandlesServiceResponse;

    public function create(int $userId, int $companyId, array $data): ?CompanyPartner
    {
        $this->resetResponse();

        try {
            $partnerPayload = [
                'name' => $data['name'],
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'],
                'state_tax_indicator' => $data['state_tax_indicator'],
                'state_tax_id' => $data['state_tax_id'] ?? null,
                'municipal_tax_id' => $data['municipal_tax_id'] ?? null,
            ];

            $companyPartnerPayload = [
                'type' => [PartnerType::CUSTOMER->value],
                'invoice_threshold' => $data['invoice_threshold'],
                'customer_discount_percentage' => $data['customer_discount_percentage'] ?? 0,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_condition' => $data['payment_condition'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'notify_service_order_closed' => (bool) ($data['notify_service_order_closed'] ?? false),
                'notify_requisition_closed' => (bool) ($data['notify_requisition_closed'] ?? false),
                'notify_production_order_closed' => (bool) ($data['notify_production_order_closed'] ?? false),
                'notify_invoice_confirmed' => (bool) ($data['notify_invoice_confirmed'] ?? false),
                'notify_fiscal_document_confirmed' => (bool) ($data['notify_fiscal_document_confirmed'] ?? false),
                'email_to_override' => null,
                'email_cc_override' => null,
                'email_bcc_override' => null,
            ];

            $shouldImportCnpjData = ($data['document_type'] === 'cnpj')
                && (bool) ($data['import_cnpj_data'] ?? false);

            $companyPartner = DB::transaction(function () use (
                $userId,
                $companyId,
                $partnerPayload,
                $companyPartnerPayload
            ): ?CompanyPartner {
                $partnerService = app(PartnerService::class);
                $partner = $partnerService->findOrCreatePartner($userId, $partnerPayload);

                if (! $partner) {
                    $this->setError(
                        $partnerService->getMessageUser() ?: 'Erro ao criar parceiro.',
                        $partnerService->getErrors(),
                        $partnerService->getStatus(),
                        $partnerService->getErrorCode()
                    );

                    return null;
                }

                $companyPartnerService = app(CompanyPartnerService::class);
                $companyPartner = $companyPartnerService->associatePartnerCompany(
                    $partner->id,
                    $companyId,
                    $companyPartnerPayload
                );

                if (! $companyPartner) {
                    $this->setError(
                        $companyPartnerService->getMessageUser() ?: 'Erro ao vincular parceiro à empresa.',
                        $companyPartnerService->getErrors(),
                        $companyPartnerService->getStatus(),
                        $companyPartnerService->getErrorCode()
                    );

                    return null;
                }

                $currentTypes = collect($companyPartner->type ?? [])
                    ->filter()
                    ->values()
                    ->all();

                $mergedPayload = array_merge($companyPartnerPayload, [
                    'type' => array_values(array_unique([
                        ...$currentTypes,
                        PartnerType::CUSTOMER->value,
                    ])),
                ]);

                $updatedCompanyPartner = $companyPartnerService->update($companyPartner, $mergedPayload);

                if (! $updatedCompanyPartner) {
                    $this->setError(
                        $companyPartnerService->getMessageUser() ?: 'Erro ao atualizar vínculo do parceiro.',
                        $companyPartnerService->getErrors(),
                        $companyPartnerService->getStatus(),
                        $companyPartnerService->getErrorCode()
                    );

                    return null;
                }

                return $updatedCompanyPartner;
            });

            if (! $companyPartner) {
                return null;
            }

            if ($shouldImportCnpjData) {
                ImportCompanyPartnerCnpjDataJob::dispatch($companyPartner->id, $userId);
            }

            $this->setSuccess(
                $shouldImportCnpjData
                    ? 'Parceiro cadastrado com importação por CNPJ em processamento.'
                    : 'Parceiro cadastrado com sucesso.'
            );

            return $companyPartner;
        } catch (\Throwable $e) {
            $this->setError('Erro ao cadastrar parceiro rapidamente.', [$e->getMessage()], 500);

            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Falha ao executar cadastro rápido de parceiro',
                'company_id' => $companyId,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
