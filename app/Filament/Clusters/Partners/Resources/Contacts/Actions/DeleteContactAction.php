<?php

namespace App\Filament\Clusters\Partners\Resources\Contacts\Actions;

use App\Models\Contact;
use App\Services\Contact\ContactService;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Log;

final class DeleteContactAction
{
    public static function make(): Action
    {
        return Action::make('delete_contact')
            ->label('Excluir')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Excluir Contato')
            ->modalDescription('Tem certeza que deseja excluir este contato? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->action(function (Action $action, array $arguments) {
                $contactId = $arguments['contact_id'] ?? null;

                if (!$contactId) {
                    notify::error(message: 'Contato não identificado. Não foi possível excluir.');
                    $action->halt();
                    return;
                }

                $contact = Contact::find($contactId);

                if (!$contact) {
                    notify::error(message: 'Contato não encontrado. Não foi possível excluir.');
                    $action->halt();
                    return;
                }

                Log::debug('Iniciando exclusão de contato', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'contact_id'         => $contactId,
                    'company_partner_id' => $contact->company_partner_id,
                ]);

                $service = new ContactService();
                $result = $service->delete($contact, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    $action->halt();
                    return;
                }

                notify::success(message: $service->getMessageUser());
            })
            ->successNotificationTitle('Contato excluído com sucesso!')
            ->after(function (Action $action) {
                $livewire = $action->getLivewire();
                
                // O record do Livewire é o CompanyPartner
                if ($livewire && method_exists($livewire, 'getRecord')) {
                    $companyPartner = $livewire->getRecord();
                    if ($companyPartner) {
                        $companyPartner->refresh();
                        $companyPartner->load('contacts');
                    }
                }
                
                if ($livewire && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['contacts']);
                }
            });
    }
}
