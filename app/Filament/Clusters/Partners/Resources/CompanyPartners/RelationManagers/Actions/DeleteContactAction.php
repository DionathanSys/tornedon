<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers\Actions;

use App\Models\Contact;
use App\Services\Contact\ContactService;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Log;

final class DeleteContactAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalHeading('Excluir Contato')
            ->modalDescription('Tem certeza que deseja excluir este contato? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (Contact $record): bool {
                Log::debug('Iniciando exclusão de contato via RelationManager', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'contact_id' => $record->id,
                ]);

                $service = new ContactService();
                $result = $service->delete($record, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    return false;
                }

                notify::success(message: $service->getMessageUser());
                return $result;
            });
    }
}
