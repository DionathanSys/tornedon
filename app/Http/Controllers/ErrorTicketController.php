<?php

namespace App\Http\Controllers;

use App\Services\ErrorTicket\Actions\CreateErrorTicket;
use Illuminate\Http\Request;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ErrorTicketController extends Controller
{
    public function create(Request $request)
    {

        Log::alert(__METHOD__ . '@' . __LINE__, [
            'message'    => 'Requisição recebida para criação de ticket de erro',
            'request'    => $request->all(),
        ]);
        
        $errorCode = $request->input('error_code');
        $title = $request->input('title');
        $message = $request->input('message');

        if (!$errorCode) {
            return response()->json([
                'success' => false,
                'message' => 'Código de erro não fornecido',
            ], 400);
        }

        $action = new CreateErrorTicket();
        $ticket = $action->execute($errorCode, [
            'title'   => $title,
            'message' => $message,
        ]);

        if ($action->hasError()) {
            Notification::make()
                ->title('Erro ao criar ticket')
                ->body('Não foi possível criar o ticket de erro')
                ->danger()
                ->send();

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível criar o ticket de erro',
            ], 500);
        }

        Notification::make()
            ->title('Ticket criado')
            ->body("Ticket #{$ticket->id} criado com sucesso. Ref: {$errorCode}")
            ->success()
            ->send();

        return response()->json([
            'success' => true,
            'ticket_id' => $ticket->id,
            'error_code' => $errorCode,
        ]);
    }
}
