<?php

namespace App\Filament\Clusters\Partners\Resources\Contacts\Actions;

use App\Filament\Clusters\Partners\Resources\Contacts\Components\ContactComponent;
use App\Services\Contact\ContactService;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Log;

final class CreateContactAction
{
    public static function make(): Action
    {
        return Action::make('create_contact')
            ->label('Contato')
            ->icon(Heroicon::Plus)
            ->badge()
            ->modal()
            ->schema(function (Schema $schema): Schema {
                return $schema
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->components(ContactComponent::make());
            })
            ->action(function (Action $action, array $data, array $arguments) {
                $record = $action->getRecord();
                
                if (!$record || !($record instanceof \App\Models\CompanyPartner)) {
                    notify::error(message: 'Contexto inválido. É necessário estar dentro de um Parceiro.');
                    $action->halt();
                    return;
                }

                Log::debug(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Iniciando criação de novo contato para CompanyPartner',
                    'data'    => $data,
                    'company_partner_id' => $record->id,
                ]);

                $service = new ContactService();
                $result = $service->create($record->id, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                    return;
                }

                notify::success(message: $service->getMessageUser());

                if ($arguments['another'] ?? false) {
                    $action->fillForm([]);
                    $action->halt();
                }

                return $result;
            })
            ->after(function (Action $action) {
                $record = $action->getRecord();
                if ($record) {
                    $record->refresh();
                }
            })
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Salvar')
            ->slideOver();
    }
}
