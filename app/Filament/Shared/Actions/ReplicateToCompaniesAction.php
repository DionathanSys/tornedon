<?php

namespace App\Filament\Shared\Actions;

use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Equipment;
use App\Services\DataReplication\ReplicationService;
use App\Services\Partner\PartnerService;
use App\Services\Address\AddressService;
use App\Services\Contact\ContactService;
use App\Services\Equipment\EquipmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ReplicateToCompaniesAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Replicar para outras empresas')
            ->icon(Heroicon::ArrowUturnRight)
            ->color('warning')
            ->schema([
                CheckboxList::make('target_company_ids')
                    ->label('Empresas de destino')
                    ->helperText('Selecione as empresas para as quais deseja copiar este parceiro')
                    ->columnSpanFull()
                    ->options(function (Model $record) {
                        // Obter empresas às quais o usuário pode ter acesso
                        $currentUser = Auth::user();
                        $userCompanies = $currentUser->companies->pluck('id');

                        ds($userCompanies)->label('User Companies');
                        // Excluir a empresa atual se aplicável
                        $currentCompanyId = $record->company_id ?? $currentUser->current_company_id;

                        return Company::whereIn('id', $userCompanies)
                            ->where('id', '!=', $currentCompanyId)
                            ->pluck('name', 'id');
                    })
                    ->columns(2)
                    ->required()
                    ->minItems(1),
            ])
            ->action(fn(CompanyPartner $record, array $data) => $this->handleReplication($record, $data['target_company_ids']))
            ->modalHeading('Replicar Registro')
            ->modalSubmitActionLabel('Replicar')
            ->modalCancelActionLabel('Cancelar');
    }

    /**
     * Processa a replicação com todas as entidades associadas
     */
    protected function handleReplication(CompanyPartner $record, array $targetCompanyIds): void
    {
        try {
            $result = DB::transaction(function () use ($record, $targetCompanyIds) {
                $partnerService = app(PartnerService::class);
                $addressService = app(AddressService::class);
                $contactService = app(ContactService::class);
                $equipmentService = app(EquipmentService::class);

                $successful = [];
                $failed = [];
                $userId = Auth::id() ?? 0;

                foreach ($targetCompanyIds as $companyId) {
                    try {
                        // 1. Associar Partner com Company
                        $companyPartnerData = [
                            'type' => $record->type,
                            'invoice_threshold' => $record->invoice_threshold,
                            'is_active' => true,
                        ];

                        Log::info('Associando parceiro com empresa', [
                            'source_company_partner_id' => $record->id,
                            'target_company_id'         => $companyId,
                            'partner_id'                => $record->partner_id,
                            'data'                      => $companyPartnerData,
                        ]);

                        $existingAssociation = CompanyPartner::where('company_id', $companyId)
                            ->where('partner_id', $record->partner_id)
                            ->first();

                        if($existingAssociation) {
                            Log::info('Associação já existe, pulando criação', [
                                'company_id' => $companyId,
                                'partner_id' => $record->partner_id,
                            ]);
                            continue;
                        }

                        $newCompanyPartner = $partnerService->associatePartnerCompany(
                            $record->partner_id,
                            $companyId,
                            $companyPartnerData
                        );

                        if (!$newCompanyPartner) {
                            throw new \Exception(
                                "Falha ao associar partner: " . $partnerService->getMessageUser()
                            );
                        }
                        //TODO Usar Service
                        // 2. Replicar Endereços
                        $addresses = $addressService->list($record->id);
                        $addresses->each(function ($address) use ($addressService, $newCompanyPartner, $userId) {
                            $addressData = Arr::except($address->toArray(), [
                                'id',
                                'company_partner_id',
                                'created_at',
                                'updated_at',
                                'created_by',
                                'updated_by'
                            ]);
                            $createdAddress = $addressService->create(
                                $newCompanyPartner->id,
                                $addressData,
                                $userId
                            );

                            if (!$createdAddress) {
                                Log::warning($addressService->getMessage(), [
                                    'source_address_id'         => $address->id,
                                    'target_company_partner_id' => $newCompanyPartner->id,
                                    'message'                   => $addressService->getMessage(),
                                ]);
                            }
                        });

                        // 3. Replicar Contatos
                        $contacts = $contactService->list($record->id);
                        $contacts->each(function ($contact) use ($contactService, $newCompanyPartner, $userId) {
                            $contactData = Arr::except($contact->toArray(), [
                                'id',
                                'company_partner_id',
                                'created_at',
                                'updated_at',
                                'created_by',
                                'updated_by'
                            ]);
                            $createdContact = $contactService->create(
                                $newCompanyPartner->id,
                                $contactData,
                                $userId
                            );

                            if (!$createdContact) {
                                Log::warning($contactService->getMessage(), [
                                    'source_contact_id'         => $contact->id,
                                    'target_company_partner_id' => $newCompanyPartner->id,
                                    'message'                   => $contactService->getMessage(),
                                ]);
                            }
                        });

                        // 4. Replicar Equipamentos (buscados por company_id e owner_id = partner_id)
                        $equipments = $equipmentService->listByCompanyAndPartner($record->company_id, $record->partner_id);

                        $equipments->each(function ($equipment) use ($equipmentService, $newCompanyPartner, $companyId, $userId) {
                            $equipmentData = Arr::except($equipment->toArray(), [
                                'id',
                                'company_id',
                                'created_at',
                                'updated_at',
                                'created_by',
                                'updated_by'
                            ]);
                            $equipmentData['company_id'] = $companyId;

                            $createdEquipment = $equipmentService->create(
                                $equipmentData,
                                $userId
                            );

                            if (!$createdEquipment) {
                                Log::warning($equipmentService->getMessage(), [
                                    'source_equipment_id' => $equipment->id,
                                    'target_company_id' => $companyId,
                                    'target_partner_id' => $newCompanyPartner->partner_id,
                                    'message' => $equipmentService->getMessage(),
                                ]);
                            }
                        });
                        $successful[] = [
                            'company_id' => $companyId,
                            'partner_id' => $record->partner_id,
                        ];
                    } catch (\Exception $e) {
                        $failed[] = [
                            'company_id' => $companyId,
                            'error' => $e->getMessage(),
                        ];

                        Log::error('Failed to replicate company partner to target company', [
                            'source_company_partner_id' => $record->id,
                            'target_company_id' => $companyId,
                            'partner_id' => $record->partner_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return [
                    'successful' => $successful,
                    'failed' => $failed,
                ];
            });

            $successCount = count($result['successful']);
            $failureCount = count($result['failed']);

            if ($successCount > 0) {
                Notification::make()
                    ->title('Replicação concluída')
                    ->body("Parceiro replicado com sucesso para {$successCount} empresa(s).")
                    ->success()
                    ->send();
            }

            if ($failureCount > 0) {
                $failureDetails = implode(
                    ", ",
                    array_map(
                        fn($f) => "Empresa ID {$f['company_id']}: {$f['error']}",
                        $result['failed']
                    )
                );

                Notification::make()
                    ->title('Replicação com erros')
                    ->body("Falha em {$failureCount} empresa(s): {$failureDetails}")
                    ->warning()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('Replication failed with exception', [
                'record_class' => get_class($record),
                'record_id' => $record->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Erro na replicação')
                ->body('Ocorreu um erro ao tentar replicar o registro. Por favor, tente novamente ou contacte o suporte.')
                ->danger()
                ->send();
        }
    }
}
