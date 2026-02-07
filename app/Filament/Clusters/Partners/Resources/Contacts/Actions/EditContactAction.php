<?php

namespace App\Filament\Clusters\Partners\Resources\Contacts\Actions;

use App\Filament\Clusters\Partners\Resources\Contacts\Components\ContactComponent;
use App\Models\Contact;
use App\Services\Contact\ContactService;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Log;

final class EditContactAction
{
    public static function make(): Action
    {
        return Action::make('edit_contact')
            ->label('Editar')
            ->icon(Heroicon::PencilSquare)
            ->modal()
            ->fillForm(function (array $arguments): array {
                $contactId = $arguments['contact_id'] ?? null;

                if (!$contactId) {
                    return [];
                }

                $contact = Contact::find($contactId);

                if (!$contact) {
                    return [];
                }

                return [
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'mobile' => $contact->mobile,
                    'notify' => $contact->notify,
                    'is_active' => $contact->is_active,
                ];
            })
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
                $contactId = $arguments['contact_id'] ?? null;

                if (!$contactId) {
                    notify::error(message: 'Contato não identificado. Não foi possível editar.');
                    $action->halt();
                    return;
                }

                $contact = Contact::find($contactId);

                if (!$contact) {
                    notify::error(message: 'Contato não encontrado. Não foi possível editar.');
                    $action->halt();
                    return;
                }

                Log::debug('Iniciando atualização de contato', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'contact_id'         => $contactId,
                    'company_partner_id' => $contact->company_partner_id,
                    'data'               => $data,
                ]);

                $service = new ContactService();
                $result = $service->update($contact, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                    return;
                }

                notify::success(message: $service->getMessageUser());

                return $result;
            })
            ->after(function (Action $action) {
                $record = $action->getRecord();
                if ($record) {
                    $record->refresh();
                    $record->load('contacts');
                }
                
                $livewire = $action->getLivewire();
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['contacts']);
                }
            })
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Atualizar')
            ->slideOver();
    }
}
