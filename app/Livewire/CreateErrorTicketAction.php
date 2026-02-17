<?php

namespace App\Livewire;

use App\Services\ErrorTicket\Actions\CreateErrorTicket;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\Attributes\On;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Auth;

class CreateErrorTicketAction extends Component
{
    #[On('createErrorTicket')]
    public function createTicket($errorCode, $title = null, $message = null)
    {
        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message'    => 'Evento recebido para criação de ticket de erro',
            'error_code' => $errorCode,
        ]);

        $action = new CreateErrorTicket();
        $ticket = $action->execute($errorCode, [
            'title'   => $title,
            'message' => $message,
        ]);

        if ($action->hasError()) {
            notify::error(
                message: 'Não foi possível criar o ticket de erro',
            );
            return;
        }

        //TODO: Incluir ID do usuário junto dos parâmetros para envio da notificação
        notify::success(
            message: "Ticket #{$ticket->id} criado com sucesso.",
            toDatabase: true,
            users: Auth::user(),
        );
    }

    public function render()
    {
        return view('livewire.create-error-ticket-action');
    }
}
