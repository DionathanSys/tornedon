<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Audit\AuditSource;
use App\Enum\Payment\Condition;
use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Models\AccountPayable;
use App\Models\SefazDistributionDocument;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

class GenerateSefazDistributionPayableAction
{
    public function __construct(
        private readonly SefazDfeStorageService $storageService,
        private readonly SefazDistributionFiscalDocumentXmlParser $parser,
        private readonly AccountPayableService $accountPayableService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array{payment_method:string,payment_condition:string,due_date:string,description?:?string}  $paymentData
     */
    public function execute(SefazDistributionDocument $distributionDocument, array $paymentData, int $userId): AccountPayable
    {
        if (! $distributionDocument->full_xml_available) {
            throw new \RuntimeException('O DF-e ainda não possui XML completo disponível.');
        }

        if ($distributionDocument->import_status === ImportStatus::IGNORED) {
            throw new \RuntimeException('Não é possível gerar contas a pagar para um DF-e ignorado.');
        }

        if (blank($distributionDocument->partner_id)) {
            throw new \RuntimeException('Vincule um fornecedor antes de gerar contas a pagar.');
        }

        $xml = $this->storageService->read($distributionDocument->full_xml_path);

        if (! is_string($xml) || trim($xml) === '') {
            throw new \RuntimeException('O XML completo do DF-e não foi encontrado no storage.');
        }

        $parsed = $this->parser->parse($xml);
        $amount = $this->resolveAmount($parsed, $distributionDocument);

        if ($amount <= 0) {
            throw new \RuntimeException('Não foi possível identificar um valor válido para gerar a conta a pagar.');
        }

        return DB::transaction(function () use ($distributionDocument, $paymentData, $userId, $amount): AccountPayable {
            $locked = SefazDistributionDocument::query()
                ->whereKey($distributionDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->account_payable_id !== null) {
                $payable = AccountPayable::query()
                    ->where('company_id', $locked->company_id)
                    ->find($locked->account_payable_id);

                if ($payable instanceof AccountPayable) {
                    return $payable;
                }

                throw new \RuntimeException('O DF-e já possui referência para uma conta a pagar não encontrada.');
            }

            $condition = Condition::from($paymentData['payment_condition']);
            $description = filled($paymentData['description'] ?? null)
                ? (string) $paymentData['description']
                : "DF-e NF #{$locked->document_number}";

            $payload = [
                'supplier_id' => $locked->partner_id,
                'company_id' => $locked->company_id,
                'fiscal_document_id' => null,
                'sequence_number' => '01',
                'status' => AccountPayableStatus::PENDING->value,
                'payment_method' => $paymentData['payment_method'],
                'due_date' => $paymentData['due_date'],
                'due_amount' => $amount,
                'description' => $description,
                'document_number' => $locked->document_key,
                'note_number' => $locked->document_number,
            ];

            $installmentCount = $condition->installments();

            if ($installmentCount > 1) {
                $payload['installment_count'] = $installmentCount;

                if ($condition->isTerm()) {
                    $payload['installment_due_mode'] = $condition->value;
                }
            }

            $payable = $this->accountPayableService->create($payload, $userId);

            if ($this->accountPayableService->hasError() || ! $payable) {
                throw new \RuntimeException('Erro ao criar conta a pagar: '.$this->accountPayableService->getMessageUser());
            }

            $before = $this->auditRecorder->snapshot($locked);
            $locked->forceFill([
                'account_payable_id' => $payable->id,
                'last_action' => 'account_payable_generated',
                'last_action_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $this->auditRecorder->recordModelEvent(
                $locked->fresh(),
                'sefaz_distribution.account_payable_generated',
                'Conta a pagar gerada a partir do DF-e detectado',
                $before,
                $this->auditRecorder->snapshot($locked->fresh()),
                $userId,
                AuditSource::WEB,
                [
                    'account_payable_id' => $payable->id,
                    'amount' => $amount,
                ],
            );

            return $payable;
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function resolveAmount(array $parsed, SefazDistributionDocument $distributionDocument): float
    {
        $amount = data_get($parsed, 'totals.ICMSTot.vNF')
            ?? data_get($parsed, 'payment.detPag.vPag')
            ?? $distributionDocument->total_amount;

        return round((float) $amount, 2);
    }
}
