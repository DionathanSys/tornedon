<?php

namespace App\Services\Service\Actions;

use App\Models\Service;
use App\Services\Service\Validators\ServiceValidator;
use App\Support\Audit\AuditLog;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateServiceAction
{
    use HandlesActionResponse;

    private const AUDIT_FIELDS = [
        'service_code',
        'name',
        'description',
        'price',
        'min_sale_price',
        'accept_customer_discount',
        'cost',
        'category',
        'is_active',
        'requires_approval',
        'tax_classification',
        'tax_rate',
        'nbs_code',
        'cnae_code',
        'municipal_tax_code',
        'iss_exigibility',
        'ncm_code',
        'cfop_code',
        'origin_code',
        'unit_of_measure',
        'additional_info',
        'company_id',
    ];

    public function __construct(
        private int $updatedBy,
        private Service $service,
    ) {}

    /**
     * Atualiza um servico existente.
     *
     * @param  array  $data
     * @return Service|null
     */
    public function execute(array $data): ?Service
    {
        try {
            $beforeSnapshot = AuditLog::snapshot($this->service, self::AUDIT_FIELDS);

            Log::debug('UpdateServiceAction: iniciando atualizacao de servico', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'before_snapshot' => $beforeSnapshot,
            ]);

            $validated = ServiceValidator::validateUpdate($data, $this->service->id, $this->service->company_id);

            unset($validated['service_code'], $validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            $this->service->update($validated);
            $this->service->refresh();

            $afterSnapshot = AuditLog::snapshot($this->service, self::AUDIT_FIELDS);

            Log::info('UpdateServiceAction: servico atualizado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'company_id' => $this->service->company_id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($validated, self::AUDIT_FIELDS),
                'changes' => AuditLog::diff($beforeSnapshot, $afterSnapshot),
                'after_snapshot' => $afterSnapshot,
            ]);

            $this->setSuccess();
            return $this->service;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados do servico', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'service_id' => $this->service->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar servico no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'sql_code' => $e->getCode(),
                'service_id' => $this->service->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar servico');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'service_id' => $this->service->id,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->updatedBy,
            ]);

            return null;
        }
    }
}
