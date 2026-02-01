<?php

namespace App\Services\ErrorTicket\Actions;

use App\Enum\Ticket;
use App\Models\ErrorTicket;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Filament\Facades\Filament;

class CreateErrorTicket
{
    use HandlesActionResponse;

    public function execute(string $errorCode, ?array $additionalData = []): ?ErrorTicket
    {
        try {
            // Verificar se já existe um ticket para este error_code
            $existingTicket = ErrorTicket::where('error_code', $errorCode)->first();
            
            if ($existingTicket) {
                $this->setSuccess();
                return $existingTicket;
            }

            $tenant = Filament::getTenant();
            
            $data = [
                'error_code'  => $errorCode,
                'title'       => $additionalData['title'] ?? 'Erro no sistema',
                'message'     => $additionalData['message'] ?? null,
                'context'     => $additionalData['context'] ?? [
                    'user_agent' => request()->userAgent(),
                    'ip'         => request()->ip(),
                    'timestamp'  => now()->toDateTimeString(),
                ],
                'url'         => $additionalData['url'] ?? request()->fullUrl(),
                'user_agent'  => request()->userAgent(),
                'status'      => Ticket\Status::OPEN,
                'priority'    => $additionalData['priority'] ?? Ticket\Priority::MEDIUM,
                'user_id'     => Auth::id(),
                'company_id'  => $tenant?->id,
            ];

            $ticket = ErrorTicket::create($data);

            Log::info(__METHOD__ . '@' . __LINE__, [
                'message'    => 'Ticket de erro criado com sucesso',
                'ticket_id'  => $ticket->id,
                'error_code' => $errorCode,
            ]);

            $this->setSuccess();
            return $ticket;
            
        } catch (\Exception $e) {
            $this->setError('Erro ao criar ticket', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro ao criar ticket de erro',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
