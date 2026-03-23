<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Services\Partner\Validators\PartnerValidator;
use App\Support\Audit\AuditLog;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditPartner
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

    public function __construct(protected int $updatedBy, protected Partner $partner)
    {
    }

    public function execute(array $data): ?Partner
    {
        try {
            $beforeSnapshot = AuditLog::snapshot($this->partner, self::AUDIT_FIELDS);
            $validatedData = PartnerValidator::validate($data, $this->partner->id);

            $data = array_merge($validatedData, [
                'updated_by' => $this->updatedBy,
            ]);

            $this->partner->update($data);
            $this->partner->refresh();

            $afterSnapshot = AuditLog::snapshot($this->partner, self::AUDIT_FIELDS);

            Log::info('EditPartner: parceiro atualizado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'partner_id' => $this->partner->id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($validatedData, self::AUDIT_FIELDS),
                'changes' => AuditLog::diff($beforeSnapshot, $afterSnapshot),
                'after_snapshot' => $afterSnapshot,
            ]);

            $this->setSuccess();
            return $this->partner;
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message' => 'Falha de validacao dos dados',
                'errors' => $e->errors(),
                'partner_id' => $this->partner->id,
                'user_id' => $this->updatedBy,
                'requested_changes' => AuditLog::payload($data, self::AUDIT_FIELDS),
            ]);

            return null;
        }
    }
}
