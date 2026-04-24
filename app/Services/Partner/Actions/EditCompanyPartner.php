<?php

namespace App\Services\Partner\Actions;

use App\Models\CompanyPartner;
use App\Services\Partner\Validators\CompanyPartnerValidator;
use App\Support\Audit\AuditLog;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditCompanyPartner
{
    use HandlesActionResponse;

    private const AUDIT_FIELDS = [
        'partner_id',
        'company_id',
        'type',
        'invoice_threshold',
        'customer_discount_percentage',
        'payment_method',
        'payment_condition',
        'is_active',
        'notify_service_order_closed',
        'notify_requisition_closed',
        'notify_production_order_closed',
        'notify_invoice_confirmed',
        'notify_fiscal_document_confirmed',
        'email_to_override',
        'email_cc_override',
        'email_bcc_override',
    ];

    public function __construct(protected CompanyPartner $companyPartner)
    {
    }

    public function execute(array $data): ?CompanyPartner
    {
        $beforeSnapshot = AuditLog::snapshot($this->companyPartner, self::AUDIT_FIELDS);
        $validatedData = CompanyPartnerValidator::validate($data);

        Log::debug('EditCompanyPartner: iniciando atualizacao do vinculo', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'actor_id' => Auth::id(),
            'company_partner_id' => $this->companyPartner->id,
            'requested_changes' => AuditLog::payload($validatedData, self::AUDIT_FIELDS),
            'before_snapshot' => $beforeSnapshot,
        ]);

        $this->companyPartner->update($validatedData);
        $this->companyPartner->refresh();

        $afterSnapshot = AuditLog::snapshot($this->companyPartner, self::AUDIT_FIELDS);

        Log::info('EditCompanyPartner: vinculo empresa-parceiro atualizado', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'actor_id' => Auth::id(),
            'company_partner_id' => $this->companyPartner->id,
            'requested_changes' => AuditLog::payload($validatedData, self::AUDIT_FIELDS),
            'changes' => AuditLog::diff($beforeSnapshot, $afterSnapshot),
            'after_snapshot' => $afterSnapshot,
        ]);

        $this->setSuccess();
        return $this->companyPartner;
    }
}
