<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions;

use App\Enum\Partner\Type as PartnerType;
use App\Enum\Tax\StateTaxIndicator;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Models\CompanyPartner;
use App\Notification\NotifyService as notify;
use App\Services\Address\AddressService;
use App\Services\Cnpj\CnpjConsultationService;
use App\Services\Contact\ContactService;
use App\Services\Partner\CompanyPartnerCnpjImportService;
use App\Services\Partner\CompanyPartnerService;
use App\Services\Partner\PartnerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class RegisterPartnerByCnpjAction
{
    private const CNPJ_LENGTH = 18;

    public static function make(): Action
    {
        return Action::make('register_partner_by_cnpj')
            ->label('Via CNPJ')
            ->icon(Heroicon::MagnifyingGlassCircle)
            ->color('primary')
            ->size(Size::Small)
            ->modal()
            ->modalHeading('Cadastrar parceiro via CNPJ')
            ->modalSubmitActionLabel('Cadastrar')
            ->modalCancelActionLabel('Cancelar')
            ->schema(fn (Schema $schema): Schema => $schema->components([
                Hidden::make('lookup_status')->default(null),
                Hidden::make('lookup_document_number')->default(null),
                Hidden::make('lookup_message')->default(null),
                Hidden::make('lookup_warning')->default(null),
                Hidden::make('lookup_name')->default(null),
                Hidden::make('lookup_state_tax_id')->default(null),
                Hidden::make('lookup_state_tax_indicator')->default(null),
                Hidden::make('lookup_municipal_tax_id')->default(null),
                Hidden::make('lookup_email')->default(null),
                Hidden::make('lookup_phone')->default(null),
                Hidden::make('lookup_mobile')->default(null),
                Hidden::make('lookup_street')->default(null),
                Hidden::make('lookup_number')->default(null),
                Hidden::make('lookup_complement')->default(null),
                Hidden::make('lookup_neighborhood')->default(null),
                Hidden::make('lookup_city')->default(null),
                Hidden::make('lookup_state')->default(null),
                Hidden::make('lookup_postal_code')->default(null),
                Hidden::make('lookup_country')->default('Brasil'),
                Hidden::make('lookup_city_code')->default(null),
                TextInput::make('cnpj')
                    ->label('CNPJ')
                    ->helperText('Aceito apenas CNPJ.')
                    ->placeholder('00.000.000/0000-00')
                    ->autocomplete(false)
                    ->mask('99.999.999/9999-99')
                    ->required()
                    ->minLength(self::CNPJ_LENGTH)
                    ->maxLength(self::CNPJ_LENGTH)
                    ->rule('regex:/^\d{2}\.\d{3}\.\d{3}\/\d{4}\-\d{2}$/')
                    ->validationMessages([
                        'required' => 'Informe o CNPJ para realizar o cadastro.',
                        'min_length' => 'O CNPJ deve conter exatamente 18 caracteres.',
                        'max_length' => 'O CNPJ deve conter exatamente 18 caracteres.',
                        'regex' => 'Informe um CNPJ válido no formato 00.000.000/0000-00.',
                    ])
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => self::resetLookupState($set))
                    ->suffixAction(self::lookupAction()),
                Callout::make('Sucesso')
                    ->success()
                    ->description(fn (Get $get): string => (string) ($get('lookup_message') ?? 'Consulta realizada com sucesso.'))
                    ->visible(fn (Get $get): bool => $get('lookup_status') === 'success'),
                Callout::make('Aviso')
                    ->warning()
                    ->description(fn (Get $get): string => (string) ($get('lookup_warning') ?? ''))
                    ->visible(fn (Get $get): bool => filled($get('lookup_warning'))),
                Callout::make('Erro')
                    ->danger()
                    ->description(fn (Get $get): string => (string) ($get('lookup_message') ?? 'Nao foi possivel consultar o CNPJ informado.'))
                    ->visible(fn (Get $get): bool => $get('lookup_status') === 'error'),
            ]))
            ->action(function (Action $action, array $data): void {
                $companyId = Filament::getTenant()?->id;
                $userId = Auth::id();
                $cnpj = (string) ($data['cnpj'] ?? '');

                if (! $companyId || ! $userId) {
                    notify::error(message: 'Nao foi possivel identificar a empresa ou o usuario atual.');
                    $action->halt();
                }

                if (mb_strlen($cnpj) !== self::CNPJ_LENGTH) {
                    notify::warning(
                        title: 'CNPJ invalido',
                        message: 'Informe um CNPJ completo antes de consultar ou cadastrar.',
                    );
                    $action->halt();
                }

                if (($data['lookup_status'] ?? null) !== 'success' || ($data['lookup_document_number'] ?? null) !== $cnpj) {
                    notify::warning(
                        title: 'Consulta pendente',
                        message: 'Consulte o CNPJ informado antes de realizar o cadastro.',
                    );
                    $action->halt();
                }

                try {
                    /** @var CompanyPartner $companyPartner */
                    $companyPartner = DB::transaction(
                        fn (): CompanyPartner => self::registerPartnerFromLookup($data, (int) $companyId, (int) $userId),
                    );

                    notify::success(
                        title: 'Parceiro cadastrado',
                        message: 'Parceiro cadastrado e vinculado com sucesso.',
                    );

                    redirect(CompanyPartnerResource::getUrl('edit', ['record' => $companyPartner]));
                } catch (\RuntimeException $exception) {
                    notify::error(message: $exception->getMessage());
                    $action->halt();
                }
            });
    }

    private static function lookupAction(): Action
    {
        return Action::make('lookup_cnpj')
            ->icon(Heroicon::MagnifyingGlassCircle)
            ->hiddenLabel()
            ->tooltip('Consultar CNPJ')
            ->action(function (Get $get, Set $set): void {
                $cnpj = (string) ($get('cnpj') ?? '');

                if (mb_strlen($cnpj) !== self::CNPJ_LENGTH) {
                    self::resetLookupState($set);

                    notify::warning(
                        title: 'CNPJ invalido',
                        message: 'Informe um CNPJ completo para realizar a consulta.',
                    );

                    return;
                }

                $service = app(CnpjConsultationService::class);
                $vo = $service->consult($cnpj, [
                    'company_id' => Filament::getTenant()?->id,
                    'user_id' => Auth::id(),
                    'source' => 'company_partners_header_register',
                ]);

                if (! $vo) {
                    self::resetLookupState($set);
                    $set('lookup_status', 'error');
                    $set('lookup_document_number', $cnpj);
                    $set('lookup_message', $service->getMessageUser() ?: 'Nao foi possivel consultar o CNPJ informado.');

                    return;
                }

                $mainRegistration = $vo->getMainStateRegistration();
                $addressData = CompanyPartnerCnpjImportService::mapAddressFromVo($vo);
                $contactData = CompanyPartnerCnpjImportService::mapContactFromVo($vo);

                $set('lookup_status', 'success');
                $set('lookup_document_number', $vo->formattedTaxId());
                $set('lookup_message', "CNPJ localizado com sucesso: {$vo->companyName}.");
                $set(
                    'lookup_warning',
                    $mainRegistration?->number
                        ? null
                        : 'Consulta concluida, mas nao foi possivel obter a inscricao estadual.',
                );
                $set('lookup_name', $vo->companyName);
                $set('lookup_state_tax_id', $mainRegistration?->number);
                $set(
                    'lookup_state_tax_indicator',
                    $mainRegistration?->number
                        ? StateTaxIndicator::CONTRIBUINTE_ICMS->value
                        : StateTaxIndicator::NAO_CONTRIBUINTE->value,
                );
                $set('lookup_municipal_tax_id', null);
                $set('lookup_email', $contactData['email']);
                $set('lookup_phone', $contactData['phone']);
                $set('lookup_mobile', $contactData['mobile']);
                $set('lookup_street', $addressData['street']);
                $set('lookup_number', $addressData['number']);
                $set('lookup_complement', $addressData['complement']);
                $set('lookup_neighborhood', $addressData['neighborhood']);
                $set('lookup_city', $addressData['city']);
                $set('lookup_state', $addressData['state']);
                $set('lookup_postal_code', $addressData['postal_code']);
                $set('lookup_country', $addressData['country']);
                $set('lookup_city_code', $addressData['city_code']);
                $set('cnpj', $vo->formattedTaxId());
            });
    }

    private static function registerPartnerFromLookup(array $data, int $companyId, int $userId): CompanyPartner
    {
        $partnerService = app(PartnerService::class);
        $companyPartnerService = app(CompanyPartnerService::class);

        $partner = $partnerService->findOrCreatePartner($userId, [
            'name' => $data['lookup_name'],
            'document_type' => 'cnpj',
            'document_number' => $data['lookup_document_number'],
            'state_tax_id' => $data['lookup_state_tax_id'] ?: null,
            'state_tax_indicator' => $data['lookup_state_tax_indicator'] ?: StateTaxIndicator::NAO_CONTRIBUINTE->value,
            'municipal_tax_id' => $data['lookup_municipal_tax_id'] ?: null,
        ]);

        if (! $partner) {
            throw new \RuntimeException($partnerService->getMessageUser() ?: 'Nao foi possivel cadastrar o parceiro.');
        }

        if (CompanyPartnerService::companyHasPartner($partner->id, $companyId)) {
            throw new \RuntimeException('Este parceiro ja esta vinculado a empresa atual.');
        }

        $companyPartner = $companyPartnerService->associatePartnerCompany($partner->id, $companyId, [
            'type' => [PartnerType::CUSTOMER->value],
            'invoice_threshold' => 0,
            'customer_discount_percentage' => 0,
            'payment_method' => null,
            'payment_condition' => null,
            'is_active' => true,
            'notify_service_order_closed' => false,
            'notify_requisition_closed' => false,
            'notify_production_order_closed' => false,
            'notify_invoice_confirmed' => false,
            'notify_fiscal_document_confirmed' => false,
            'email_to_override' => null,
            'email_cc_override' => null,
            'email_bcc_override' => null,
        ]);

        if (! $companyPartner) {
            throw new \RuntimeException($companyPartnerService->getMessageUser() ?: 'Nao foi possivel vincular o parceiro a empresa.');
        }

        $addressService = app(AddressService::class);
        $address = $addressService->create($companyPartner->id, [
            'street' => $data['lookup_street'],
            'number' => $data['lookup_number'],
            'complement' => $data['lookup_complement'],
            'neighborhood' => $data['lookup_neighborhood'],
            'city' => $data['lookup_city'],
            'state' => $data['lookup_state'],
            'postal_code' => $data['lookup_postal_code'],
            'country' => $data['lookup_country'] ?: 'Brasil',
            'city_code' => $data['lookup_city_code'],
        ], $userId);

        if (! $address) {
            throw new \RuntimeException($addressService->getMessageUser() ?: 'Nao foi possivel cadastrar o endereco do parceiro.');
        }

        if (filled($data['lookup_email'] ?? null) || filled($data['lookup_phone'] ?? null) || filled($data['lookup_mobile'] ?? null)) {
            $contactService = app(ContactService::class);
            $contact = $contactService->create($companyPartner->id, [
                'email' => $data['lookup_email'],
                'phone' => $data['lookup_phone'],
                'mobile' => $data['lookup_mobile'],
                'notify' => false,
                'is_active' => true,
            ], $userId);

            if (! $contact) {
                throw new \RuntimeException($contactService->getMessageUser() ?: 'Nao foi possivel cadastrar o contato do parceiro.');
            }
        }

        return $companyPartner;
    }

    private static function resetLookupState(Set $set): void
    {
        $set('lookup_status', null);
        $set('lookup_document_number', null);
        $set('lookup_message', null);
        $set('lookup_warning', null);
        $set('lookup_name', null);
        $set('lookup_state_tax_id', null);
        $set('lookup_state_tax_indicator', null);
        $set('lookup_municipal_tax_id', null);
        $set('lookup_email', null);
        $set('lookup_phone', null);
        $set('lookup_mobile', null);
        $set('lookup_street', null);
        $set('lookup_number', null);
        $set('lookup_complement', null);
        $set('lookup_neighborhood', null);
        $set('lookup_city', null);
        $set('lookup_state', null);
        $set('lookup_postal_code', null);
        $set('lookup_country', 'Brasil');
        $set('lookup_city_code', null);
    }
}
