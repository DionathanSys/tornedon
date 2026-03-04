<?php

namespace App\Jobs;

use App\Enum\FiscalDocument\NfeStatus;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\ConsultNfeAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ConsultNfeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Controle de tentativas feito manualmente abaixo

    private const MAX_POLLING_ATTEMPTS = 5;

    public function __construct(
        private int $fiscalDocumentId,
        private int $userId,
        private int $tentativa = 1,
    ) {}

    public function handle(): void
    {
        $doc = FiscalDocument::find($this->fiscalDocumentId);

        if (! $doc) {
            Log::error('ConsultNfeJob: FiscalDocument não encontrado', [
                'fiscal_document_id' => $this->fiscalDocumentId,
            ]);
            return;
        }

        // Se webhook já atualizou o status, não precisa consultar
        if ($doc->nfe_status !== NfeStatus::EM_PROCESSAMENTO) {
            Log::info('ConsultNfeJob: status já atualizado (provavelmente via webhook)', [
                'fiscal_document_id' => $this->fiscalDocumentId,
                'nfe_status'         => $doc->nfe_status?->value,
            ]);
            return;
        }

        $action = new ConsultNfeAction();
        $result = $action->execute($doc);

        $doc->refresh();

        // Ainda em processamento — reagendar se não excedeu limite
        if ($doc->nfe_status === NfeStatus::EM_PROCESSAMENTO) {
            if ($this->tentativa < self::MAX_POLLING_ATTEMPTS) {
                $delay = $this->tentativa * 15; // 15s, 30s, 45s, 60s

                Log::info('ConsultNfeJob: ainda em processamento, reagendando', [
                    'fiscal_document_id' => $this->fiscalDocumentId,
                    'tentativa'          => $this->tentativa,
                    'proximo_em'         => $delay . 's',
                ]);

                dispatch(new self($this->fiscalDocumentId, $this->userId, $this->tentativa + 1))
                    ->delay(now()->addSeconds($delay));
            } else {
                Log::warning('ConsultNfeJob: máximo de tentativas atingido, aguardando webhook', [
                    'fiscal_document_id' => $this->fiscalDocumentId,
                    'tentativas'         => self::MAX_POLLING_ATTEMPTS,
                ]);
            }

            return;
        }

        Log::info('ConsultNfeJob: status final obtido', [
            'fiscal_document_id' => $this->fiscalDocumentId,
            'nfe_status'         => $doc->nfe_status?->value,
        ]);
    }
}
