<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\SendNfeAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNfeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número máximo de tentativas antes de falhar definitivamente.
     */
    public int $tries = 3;

    public int $timeout = 900;

    /**
     * Tempo de espera entre tentativas (segundos).
     */
    public array $backoff = [30, 60, 120];

    public function __construct(
        private int $fiscalDocumentId,
        private int $userId,
        private ?string $serie = null,
        private ?string $operationNature = null,
    ) {}

    public function handle(): void
    {
        $doc = FiscalDocument::find($this->fiscalDocumentId);

        if (! $doc) {
            Log::error('SendNfeJob: FiscalDocument não encontrado', [
                'fiscal_document_id' => $this->fiscalDocumentId,
            ]);

            notify::error(
                title: 'Falha ao processar emissão da NF-e',
                message: 'Não foi possível localizar o documento fiscal para concluir a emissão.',
                toDatabase: true,
                users: $this->userId
            );

            return;
        }

        $action = new SendNfeAction($this->userId);
        $result = $action->execute($doc, $this->serie, $this->operationNature);

        if (! $result) {
            Log::error('SendNfeJob: falha no envio da NF-e', [
                'fiscal_document_id' => $this->fiscalDocumentId,
                'erro' => $action->getMessage(),
                'tentativa' => $this->attempts(),
            ]);

            // Não re-tenta em erros de validação (5001/5002) — falha imediata
            if (str_contains($action->getMessage(), 'validação')) {
                $this->fail(new \RuntimeException($action->getMessage()));
            }

            return;
        }

        Log::info('SendNfeJob: concluído com sucesso', [
            'fiscal_document_id' => $this->fiscalDocumentId,
        ]);

        notify::success(
            title: 'NF-e processada com sucesso',
            message: 'A emissão assíncrona foi concluída.',
            toDatabase: true,
            users: $this->userId
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendNfeJob: job falhou definitivamente', [
            'fiscal_document_id' => $this->fiscalDocumentId,
            'exception' => $exception->getMessage(),
        ]);

        // Registra no documento
        $doc = FiscalDocument::find($this->fiscalDocumentId);
        if ($doc) {
            $errors = $doc->errors_messages ?? [];
            $errors[] = [
                'at' => now()->toDateTimeString(),
                'job' => 'SendNfeJob',
                'mensagem' => $exception->getMessage(),
            ];
            $doc->update(['errors_messages' => $errors]);
        }

        notify::error(
            title: 'Falha ao emitir NF-e',
            message: 'A emissão assíncrona falhou. Verifique a aba de erros do documento para mais detalhes.',
            toDatabase: true,
            users: $this->userId
        );
    }
}
