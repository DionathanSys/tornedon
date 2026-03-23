<?php

namespace App\Services\Service\Actions;

use App\Models\Service;
use App\Services\Service\Validators\ServiceValidator;
use App\Support\Audit\AuditLog;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateServiceAction
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
        private int $createdBy,
    ) {}

    /**
     * Cria um novo servico.
     *
     * @param  array  $data
     * @return Service|null
     */
    public function execute(array $data): ?Service
    {
        try {
            Log::debug('CreateServiceAction: iniciando criacao de servico', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'service_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
            ]);

            $validated = ServiceValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $service = Service::create($validated);

            Log::info('CreateServiceAction: servico criado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'service_id' => $service->id,
                'company_id' => $service->company_id,
                'user_id' => $this->createdBy,
                'validated_payload' => AuditLog::payload($validated, self::AUDIT_FIELDS),
                'service_snapshot' => AuditLog::snapshot($service, self::AUDIT_FIELDS),
            ]);

            $this->setSuccess();
            return $service;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados do servico', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'service_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $message = ($e->getCode() === '23000')
                ? 'Ja existe um servico com estas caracteristicas'
                : 'Erro ao criar servico no banco de dados';

            $this->setError($message);

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'sql_code' => $e->getCode(),
                'service_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar servico');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'service_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
                'user_id' => $this->createdBy,
            ]);

            return null;
        }
    }
}
