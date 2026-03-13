<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions;

use App\Domain\DTO\Cnpj\CnpjVO;
use App\Models\CompanyPartner;
use App\Notification\NotifyService as notify;
use App\Services\Address\AddressService;
use App\Services\Cnpj\CnpjConsultationService;
use App\Services\Contact\ContactService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ImportCnpjData
{
    public static function make(): Action
    {
        return Action::make('import-cnpj-data')
            ->label('Importar dados via CNPJ')
            ->icon(Heroicon::ArrowDownTray)
            ->color('info')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->modalHeading('Importar dados via CNPJ')
            ->modalDescription(
                'Esta ação irá consultar os dados cadastrais do CNPJ deste parceiro na Receita Federal '
                . 'e importará automaticamente o endereço e o contato (telefone e e-mail) registrados. '
                . 'Novos registros serão criados mesmo que já existam outros cadastrados. '
                . 'Deseja continuar?'
            )
            ->modalSubmitActionLabel('Importar')
            ->modalCancelActionLabel('Cancelar')
            ->visible(fn($operation, Get $get): bool =>
                $operation === 'edit'
                && ($get('document_type') === 'cnpj')
            )
            ->action(function (Action $action): void {
                /** @var CompanyPartner $record */
                $record = $action->getRecord();
                $record->loadMissing('partner');

                $partner = $record->partner;

                if (! $partner || $partner->document_type !== 'cnpj') {
                    notify::warning(
                        title: 'Importação não disponível',
                        message: 'O parceiro não possui CNPJ cadastrado.',
                    );
                    return;
                }

                // 1. Consulta o CNPJ
                $cnpjService = new CnpjConsultationService();
                $vo = $cnpjService->consult($partner->document_number);

                if ($cnpjService->hasError()) {
                    notify::error(
                        title: 'Erro ao consultar CNPJ',
                        message: $cnpjService->getMessage(),
                    );
                    return;
                }

                // 2. Monta o array de endereço a partir do VO
                $addressData = self::mapAddressFromVo($vo);

                // 3. Cria o endereço via service
                $addressService = new AddressService();
                $addressService->create($record->id, $addressData, Auth::id());

                if ($addressService->hasError()) {
                    notify::error(
                        title: 'Erro ao importar endereço',
                        message: $addressService->getMessageUser(),
                    );
                    return;
                }

                // 4. Monta os dados de contato a partir do VO e cria via service
                $contactData = self::mapContactFromVo($vo);

                $contactService = new ContactService();
                $contactService->create($record->id, $contactData, Auth::id());

                if ($contactService->hasError()) {
                    notify::error(
                        title: 'Erro ao importar contato',
                        message: $contactService->getMessageUser(),
                    );
                    return;
                }

                notify::success(
                    title: 'Dados importados',
                    message: "Endereço e contato de {$vo->companyName} importados com sucesso.",
                );

                // 5. Recarrega o formulário para exibir os novos registros
                $livewire = $action->getLivewire();
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $record->refresh();
                    $record->load(['addresses', 'contacts']);
                    $livewire->refreshFormData(['addresses', 'contacts']);
                }
            });
    }

    private static function mapContactFromVo(CnpjVO $vo): array
    {
        return [
            'email'     => $vo->email,
            'phone'     => $vo->phone,
            'mobile'    => null,
            'notify'    => false,
            'is_active' => true,
        ];
    }

    private static function mapAddressFromVo(CnpjVO $vo): array
    {
        $address = $vo->address;

        return [
            'street'        => $address->street ?? '',
            'number'        => $address->number ?? 'S/N',
            'complement'    => $address->details,
            'neighborhood'  => $address->district,
            'city'          => $address->city ?? '',
            'state'         => $address->state ?? '',
            'postal_code'   => self::formatPostalCode($address->zip),
            'country'       => 'Brasil',
            'city_code'     => $address->municipalityCode,
        ];
    }

    private static function formatPostalCode(?string $zip): string
    {
        if (! $zip) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $zip);

        if (strlen($digits) === 8) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
        }

        return $digits;
    }
}
