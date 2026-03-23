<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Services\Partner\Validators\PartnerValidator;
use App\Support\Audit\AuditLog;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreatePartner
{
    use HandlesActionResponse;

    private const AUDIT_FIELDS = [
        'name',
        'document_type',
        'document_number',
        'is_active',
        'state_tax_id',
        'state_tax_indicator',
        'municipal_tax_id',
    ];

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?Partner
    {
        try {
            Log::debug('CreatePartner: iniciando criacao de parceiro', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'partner_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
            ]);

            $validatedData = PartnerValidator::validate($data);

            $data = array_merge($validatedData, [
                'created_by' => $this->createdBy,
            ]);

            $partner = Partner::create($data);

            Log::info('CreatePartner: parceiro criado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'partner_id' => $partner->id,
                'user_id' => $this->createdBy,
                'validated_payload' => AuditLog::payload($validatedData, self::AUDIT_FIELDS),
                'partner_snapshot' => AuditLog::snapshot($partner, self::AUDIT_FIELDS),
            ]);

            $this->setSuccess();
            return $partner;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message' => 'Falha de validacao dos dados',
                'errors' => $e->errors(),
                'partner_payload' => AuditLog::payload($data, self::AUDIT_FIELDS),
            ]);

            return null;
        }
    }
}
