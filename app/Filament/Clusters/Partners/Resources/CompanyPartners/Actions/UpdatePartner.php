<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions;

use App\Enum;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Filament\Clusters\Partners\Resources\Components\DocumentNumberInput;
use App\Models\CompanyPartner;
use App\Services\Partner\PartnerService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Auth;

class UpdatePartner
{

    public static function make(): Action
    {
        return Action::make('edit-partner')
            ->label('Editar Parceiro')
            ->icon(Heroicon::PencilSquare)
            ->visible(fn($operation): bool => $operation === 'edit')
            ->fillForm(function (Get $get): array {
                $partner = (new PartnerService())->getPartnerById($get('partner_id'));

                return [
                    'partner_id'           => $partner->id,
                    'name'                 => $partner->name,
                    'document_type'        => $partner->document_type,
                    'document_number'      => $partner->document_number,
                    'state_tax_id'         => $partner->state_tax_id,
                    'municipal_tax_id'     => $partner->municipal_tax_id,
                    'state_tax_indicator'  => $partner->state_tax_indicator,
                ];
            })
            ->schema(function (Schema $schema) {
                return $schema
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->schema([
                        Hidden::make('partner_id'),
                        Select::make('document_type')
                            ->label('Tipo de Doc.')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options([
                                'cpf' => 'CPF',
                                'cnpj' => 'CNPJ',
                            ])
                            ->default('cnpj')
                            ->native(false)
                            ->required()
                            ->afterStateUpdatedJs(<<<'JS'
                                $set('document_number', null)
                            JS),
                        DocumentNumberInput::make()
                            ->afterStateUpdated(null),
                        TextInput::make('name')
                            ->label('Nome')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->required(),
                        TextInput::make('state_tax_id')
                            ->label('Inscrição Estadual')
                            ->placeholder('Não definido')
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->autocomplete(false)
                            ->numeric(),
                        TextInput::make('municipal_tax_id')
                            ->label('Inscrição Municipal')
                            ->placeholder('Não definido')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric(),
                        Select::make('state_tax_indicator')
                            ->label('Indicador IE')
                            ->columnSpanFull()
                            ->options(Enum\Tax\StateTaxIndicator::toSelectArray())
                            ->native(false),
                    ]);
            })
            ->action(function (Action $action, array $data) {

                $data['id'] = $data['partner_id'];
                $data['updated_by'] = Auth::id();
                $service = new PartnerService();
                $partner = $service->getPartnerById($data['id']);

                $result = $service->editPartner(Auth::id(), $partner, $data);

                if($service->hasError()){
                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );
                    $action->halt();
                }

                notify::success(message: 'Parceiro atualizado.');
                $action->success();
            })
            ->modalSubmitActionLabel('Salvar')
            ->successRedirectUrl(fn(CompanyPartner $record) => CompanyPartnerResource::getUrl('edit', ['record'=> $record->id]))
        ;
    }
}
