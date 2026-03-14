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
                        $userCompanies = $currentUser->companies()->pluck('companies.id');

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
                        $addresses = Address::where('company_partner_id', $record->id)->get();
                        foreach ($addresses as $address) {
                            $addressData = [
                                'type'          => $address->type,
                                'street'        => $address->street,
                                'number'        => $address->number,
                                'complement'    => $address->complement,
                                'neighborhood'  => $address->neighborhood,
                                'city'          => $address->city,
                                'state'         => $address->state,
                                'postal_code'   => $address->postal_code,
                                'country'       => $address->country,
                                'is_primary'    => $address->is_primary,
                            ];

                            $createdAddress = $addressService->create(
                                $newCompanyPartner->id,
                                $addressData,
                                $userId
                            );

                            if (!$createdAddress) {
                                Log::warning('Failed to replicate address during company partner replication', [
                                    'source_address_id' => $address->id,
                                    'target_company_partner_id' => $newCompanyPartner->id,
                                    'error' => $addressService->getMessageUser(),
                                ]);
                            }
                        }

                        // 3. Replicar Contatos
                        $contacts = Contact::where('company_partner_id', $record->id)->get();
                        foreach ($contacts as $contact) {
                            $contactData = [
                                'name'              => $contact->name,
                                'email'             => $contact->email,
                                'phone'             => $contact->phone,
                                'mobile'            => $contact->mobile,
                                'document_number'   => $contact->document_number,
                                'is_primary'        => $contact->is_primary,
                                'is_active'         => $contact->is_active ?? true,
                                'notify'            => $contact->notify ?? false,
                            ];

                            $createdContact = $contactService->create(
                                $newCompanyPartner->id,
                                $contactData,
                                $userId
                            );

                            if (!$createdContact) {
                                Log::warning('Failed to replicate contact during company partner replication', [
                                    'source_contact_id' => $contact->id,
                                    'target_company_partner_id' => $newCompanyPartner->id,
                                    'error' => $contactService->getMessageUser(),
                                ]);
                            }
                        }

                        // 4. Replicar Equipamentos (buscados por company_id e owner_id = partner_id)
                        $equipments = Equipment::where('company_id', $record->company_id)
                            ->where('owner_id', $record->partner_id)
                            ->get();

                        foreach ($equipments as $equipment) {
                            $equipmentData = [
                                'company_id'        => $companyId,
                                'owner_id'          => $record->partner_id,
                                'name'              => $equipment->name,
                                'mark'              => $equipment->mark,
                                'model'             => $equipment->model,
                                'type'              => $equipment->type,
                                'placa'             => $equipment->placa,
                                'serial_number'     => $equipment->serial_number,
                                'created_by'        => Auth::id(),
                            ];

                            $createdEquipment = $equipmentService->create($equipmentData);

                            if (!$createdEquipment) {
                                Log::warning('Failed to replicate equipment during company partner replication', [
                                    'source_equipment_id' => $equipment->id,
                                    'target_company_id' => $companyId,
                                    'error' => $equipmentService->getMessageUser(),
                                ]);
                            }
                        }

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
