<?php

namespace App\Services\Partner;

use App\Models\Address;
use App\Models\CompanyPartner;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\User;
use App\Notification\NotifyService;
use App\Services\Address\AddressService;
use App\Services\Contact\ContactService;
use App\Services\Equipment\EquipmentService;
use App\Services\Partner\Validators\CompanyPartnerValidator;
use App\Traits\HandlesServiceResponse;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CompanyPartnerService
{
    use HandlesServiceResponse;

    public function update(CompanyPartner $companyPartner, array $data)
    {
        try {
            $action = new Actions\EditCompanyPartner($companyPartner);
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Erro identificado durante execucao da Action para edicao do vinculo entre Empresa e Parceiro',
                    'action_message' => $action->getMessage(),
                    'errors' => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Vinculo com parceiro atualizado');
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar vinculo entre Empresa e Parceiro', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao atualizar vinculo entre Empresa e Parceiro',
                'errors' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function associatePartnerCompany(int $partnerId, int $companyId, array $data): ?CompanyPartner
    {
        $this->resetResponse();

        try {
            $validatedData = CompanyPartnerValidator::validate($data);

            // Se type for string, converter para array
            if (isset($validatedData['type']) && is_string($validatedData['type'])) {
                $validatedData['type'] = [$validatedData['type']];
            }

            $payload = array_merge($validatedData, [
                'partner_id' => $partnerId,
                'company_id' => $companyId,
            ]);

            $normalized = (new CompanyPartner())->forceFill($payload)->getAttributes();
            $normalized['created_at'] = now();
            $normalized['updated_at'] = now();

            $updateColumns = array_values(array_diff(array_keys($normalized), [
                'company_id',
                'partner_id',
                'created_at',
            ]));

            Log::debug(__METHOD__ . '@' . __LINE__, [
                'message'    => 'Dados preparados para associacao de parceiro com empresa',
                'payload'    => $payload,
                'normalized' => $normalized,
                'update_columns' => $updateColumns,
            ]);

            // Preparar dados para criar (remover chaves que serão usadas no firstOrCreate)
            $createData = Arr::except($normalized, ['company_id', 'partner_id']);

            Log::info(__METHOD__ . '@' . __LINE__, [
                'message' => 'DEBUG: Valores exatos ANTES de firstOrCreate',
                'company_id_param' => $companyId,
                'partner_id_param' => $partnerId,
                'createData_keys' => array_keys($createData),
            ]);

            Log::info('SQL antes de firstOrCreate', [
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'query' => CompanyPartner::where('company_id', $companyId)->where('partner_id', $partnerId)->toSql(),
            ]);

            // Usar firstOrCreate para garantir que insira ou retorne o existente
            $companyPartner = CompanyPartner::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'partner_id' => $partnerId,
                ],
                $createData
            );

            Log::info(__METHOD__ . '@' . __LINE__, [
                'message' => 'DEBUG: Valores DEPOIS de firstOrCreate',
                'company_id_result' => $companyPartner->company_id,
                'partner_id_result' => $companyPartner->partner_id,
                'company_partner_id' => $companyPartner->id,
                'was_created' => $companyPartner->wasRecentlyCreated,
            ]);

            if (! $companyPartner) {
                throw new \RuntimeException('Nao foi possivel localizar o vinculo entre empresa e parceiro apos a gravacao.');
            }

            $this->setSuccess('Parceiro associado a empresa com sucesso');
            return $companyPartner;
        } catch (ValidationException $e) {
            $this->setError('Falha ao validar os dados do vinculo', $e->errors());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Falha ao validar dados do vinculo entre empresa e parceiro',
                'partner_id' => $partnerId,
                'company_id' => $companyId,
                'errors' => $e->errors(),
                'data' => $data,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao vincular parceiro e empresa', [$e->getMessage()], 500);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao vincular parceiro e empresa',
                'partner_id' => $partnerId,
                'company_id' => $companyId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            return null;
        }
    }

    public function replicateToCompanies(int $sourceCompanyPartnerId, array $targetCompanyIds, int $userId): array
    {
        $this->resetResponse();

        $result = [
            'successful' => [],
            'failed' => [],
            'skipped' => [],
        ];

        $sourceCompanyPartner = CompanyPartner::query()->find($sourceCompanyPartnerId);

        Log::info('DEBUG sourceCompanyPartner completo', $sourceCompanyPartner->toArray());

        if (! $sourceCompanyPartner) {
            $message = 'Vinculo de parceiro nao encontrado para replicacao.';
            $this->setError($message, [$message], 404);
            $this->notifyReplicationResult($userId, null, $result, $message);
            return $result;
        }

        Log::info(__METHOD__ . '@' . __LINE__, [
            'message' => 'Iniciando replicacao de parceiro para multiplas empresas',
            'source_company_partner_id' => $sourceCompanyPartnerId,
            'partner_id' => $sourceCompanyPartner->partner_id,
            'target_company_ids' => $targetCompanyIds,
            'requested_by' => $userId,
        ]);

        foreach ($targetCompanyIds as $targetCompanyId) {
            Log::info(__METHOD__ . '@' . __LINE__, [
                'message' => 'DEBUG - Iniciando iteracao de replicacao',
                'target_company_id_atual' => $targetCompanyId,
                'tipo_target_company_id' => gettype($targetCompanyId),
            ]);
            try {
                DB::transaction(function () use ($sourceCompanyPartner, $targetCompanyId, $userId, &$result) {
                    $existingAssociation = CompanyPartner::query()
                        ->where('company_id', $targetCompanyId)
                        ->where('partner_id', $sourceCompanyPartner->partner_id)
                        ->first();

                    if ($existingAssociation) {
                        $result['skipped'][] = [
                            'company_id' => $targetCompanyId,
                            'partner_id' => $sourceCompanyPartner->partner_id,
                            'company_partner_id' => $existingAssociation->id,
                            'reason' => 'Associacao ja existente',
                        ];

                        Log::info(__METHOD__ . '@' . __LINE__, [
                            'message' => 'Associacao ja existente, pulando replicacao do vinculo',
                            'target_company_id' => $targetCompanyId,
                            'partner_id' => $sourceCompanyPartner->partner_id,
                            'company_partner_id' => $existingAssociation->id,
                        ]);

                        return;
                    }

                    $newCompanyPartner = $this->associatePartnerCompany(
                        $sourceCompanyPartner->partner_id,
                        $targetCompanyId,
                        $this->buildCompanyPartnerPayload($sourceCompanyPartner)
                    );

                    if (! $newCompanyPartner) {
                        throw new \RuntimeException($this->getMessageUser() ?: 'Falha ao associar parceiro a empresa de destino.');
                    }

                    $this->replicateAddresses($sourceCompanyPartner, $newCompanyPartner, $userId);
                    $this->replicateContacts($sourceCompanyPartner, $newCompanyPartner, $userId);
                    $this->replicateEquipments($sourceCompanyPartner, $targetCompanyId, $userId);

                    $result['successful'][] = [
                        'company_id' => $targetCompanyId,
                        'partner_id' => $sourceCompanyPartner->partner_id,
                        'company_partner_id' => $newCompanyPartner->id,
                    ];
                });
            } catch (\Throwable $e) {
                $result['failed'][] = [
                    'company_id' => $targetCompanyId,
                    'partner_id' => $sourceCompanyPartner->partner_id,
                    'error' => $e->getMessage(),
                ];

                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Falha ao replicar parceiro para empresa de destino',
                    'source_company_partner_id' => $sourceCompanyPartner->id,
                    'target_company_id' => $targetCompanyId,
                    'partner_id' => $sourceCompanyPartner->partner_id,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if (count($result['failed']) > 0) {
            $this->setError(
                'Replicacao concluida com falhas',
                array_column($result['failed'], 'error'),
                207
            );
        } else {
            $this->setSuccess('Replicacao concluida com sucesso', $result);
        }

        $this->notifyReplicationResult($userId, $sourceCompanyPartner, $result);

        return $result;
    }

    public function notifyReplicationFailure(int $userId, ?int $sourceCompanyPartnerId, string $message): void
    {
        $recipient = User::query()->find($userId);

        if (! $recipient) {
            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Usuario solicitante nao encontrado para notificacao de falha da replicacao',
                'user_id' => $userId,
                'source_company_partner_id' => $sourceCompanyPartnerId,
            ]);
            return;
        }

        (new NotifyService(
            'danger',
            'Erro na replicacao do parceiro',
            $message
        ))->sendToDatabase($recipient);
    }

    public static function companyHasPartner(int $parnerId, int|null $companyId = null): ?CompanyPartner
    {
        $companyId = $companyId ?? Filament::getTenant()?->id;

        if ($companyId == null) {
            Log::error('Empresa nao informada ou nao autenticada!');
            return null;
        }

        return CompanyPartner::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $parnerId)
            ->first();
    }

    public static function getIdCompanyPartner(int $partnerId, int|null $companyId = null): ?int
    {
        $companyId = $companyId ?? Filament::getTenant()?->id;

        if ($companyId == null) {
            Log::error('Empresa nao informada ou nao autenticada!');
            return null;
        }

        $companyPartner = CompanyPartner::query()
            ->select('id')
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->first();

        if (! $companyPartner) {
            Log::error('Vinculo entre Empresa e Parceiro nao encontrado!', [
                'company_id' => $companyId,
                'partner_id' => $partnerId,
            ]);
            return null;
        }

        return $companyPartner->id;
    }

    private function buildCompanyPartnerPayload(CompanyPartner $sourceCompanyPartner): array
    {
        return [
            'type' => $sourceCompanyPartner->type,
            'invoice_threshold' => $sourceCompanyPartner->invoice_threshold,
            'is_active' => true,
            'notify_service_order_closed' => (bool) $sourceCompanyPartner->notify_service_order_closed,
            'notify_requisition_closed' => (bool) $sourceCompanyPartner->notify_requisition_closed,
            'notify_fiscal_document_confirmed' => (bool) $sourceCompanyPartner->notify_fiscal_document_confirmed,
            'email_to_override' => $sourceCompanyPartner->email_to_override,
            'email_cc_override' => $sourceCompanyPartner->email_cc_override,
            'email_bcc_override' => $sourceCompanyPartner->email_bcc_override,
        ];
    }

    private function replicateAddresses(CompanyPartner $sourceCompanyPartner, CompanyPartner $newCompanyPartner, int $userId): void
    {
        $addressService = app(AddressService::class);
        $addresses = $addressService->list($sourceCompanyPartner->id);

        foreach ($addresses as $address) {
            $addressData = Arr::only($address->toArray(), (new Address())->getFillable());
            unset($addressData['company_partner_id'], $addressData['created_by'], $addressData['updated_by']);

            $createdAddress = $addressService->create($newCompanyPartner->id, $addressData, $userId);

            if (! $createdAddress) {
                throw new \RuntimeException($addressService->getMessageUser() ?: 'Falha ao replicar endereco.');
            }
        }
    }

    private function replicateContacts(CompanyPartner $sourceCompanyPartner, CompanyPartner $newCompanyPartner, int $userId): void
    {
        $contactService = app(ContactService::class);
        $contacts = $contactService->list($sourceCompanyPartner->id);

        foreach ($contacts as $contact) {
            $contactData = Arr::only($contact->toArray(), (new Contact())->getFillable());
            unset($contactData['company_partner_id'], $contactData['created_by'], $contactData['updated_by']);

            $createdContact = $contactService->create($newCompanyPartner->id, $contactData, $userId);

            if (! $createdContact) {
                throw new \RuntimeException($contactService->getMessageUser() ?: 'Falha ao replicar contato.');
            }
        }
    }

    private function replicateEquipments(CompanyPartner $sourceCompanyPartner, int $targetCompanyId, int $userId): void
    {
        $equipmentService = app(EquipmentService::class);
        $equipments = $equipmentService->listByCompanyAndPartner(
            $sourceCompanyPartner->company_id,
            $sourceCompanyPartner->partner_id
        );

        foreach ($equipments as $equipment) {
            $equipmentData = Arr::only($equipment->toArray(), (new Equipment())->getFillable());
            unset($equipmentData['company_id']);
            $equipmentData['company_id'] = $targetCompanyId;
            $equipmentData['created_by'] = $userId;

            $createdEquipment = $equipmentService->create($equipmentData);

            if (! $createdEquipment) {
                throw new \RuntimeException($equipmentService->getMessageUser() ?: 'Falha ao replicar equipamento.');
            }
        }
    }

    private function notifyReplicationResult(
        int $userId,
        ?CompanyPartner $sourceCompanyPartner,
        array $result,
        ?string $fallbackMessage = null
    ): void {
        $recipient = User::query()->find($userId);

        if (! $recipient) {
            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Usuario solicitante nao encontrado para notificacao da replicacao',
                'user_id' => $userId,
                'source_company_partner_id' => $sourceCompanyPartner?->id,
            ]);
            return;
        }

        $successCount = count($result['successful']);
        $failureCount = count($result['failed']);
        $skippedCount = count($result['skipped']);
        $partnerName = $sourceCompanyPartner?->partner?->name ?? 'Parceiro';

        $body = $fallbackMessage ?? "Parceiro {$partnerName}: {$successCount} empresa(s) replicada(s), {$skippedCount} ignorada(s) e {$failureCount} falha(s).";

        if ($failureCount === 0) {
            $notification = new NotifyService(
                'success',
                'Replicacao de parceiro concluida',
                $body
            );
            $notification->sendToDatabase($recipient);
            return;
        }

        if ($successCount > 0 || $skippedCount > 0) {
            $details = implode(
                '; ',
                array_map(
                    fn(array $item) => "Empresa {$item['company_id']}: {$item['error']}",
                    $result['failed']
                )
            );

            $notification = new NotifyService(
                'warning',
                'Replicacao de parceiro concluida com ressalvas',
                trim($body . ' ' . $details)
            );
            $notification->sendToDatabase($recipient);
            return;
        }

        $details = implode(
            '; ',
            array_map(
                fn(array $item) => "Empresa {$item['company_id']}: {$item['error']}",
                $result['failed']
            )
        );

        $notification = new NotifyService(
            'danger',
            'Falha na replicacao do parceiro',
            trim(($fallbackMessage ?? "Nao foi possivel replicar o parceiro {$partnerName}.") . ' ' . $details)
        );
        $notification->sendToDatabase($recipient);
    }
}
