<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Actions\SendNfseAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNfseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private int     $fiscalDocumentId,
        private int     $userId,
        private ?string $serie = null,
    ) {}

    public function handle(): void
    {
        $doc = FiscalDocument::find($this->fiscalDocumentId);

        if (! $doc) {
            Log::error('SendNfseJob: FiscalDocument não encontrado', [
                'fiscal_document_id' => $this->fiscalDocumentId,
            ]);
            return;
        }

        $action = new SendNfseAction($this->userId);
        $result = $action->execute($doc, $this->serie);

        if (! $result) {
            Log::error('SendNfseJob: falha no envio da NFS-e', [
                'fiscal_document_id' => $this->fiscalDocumentId,
                'erro'               => $action->getMessage(),
                'tentativa'          => $this->attempts(),
            ]);

            // Não re-tenta em erros de validação (5001/5002) — falha imediata
            if (str_contains($action->getMessage() ?? '', 'validação')) {
                $this->fail(new \RuntimeException($action->getMessage()));
            }

            return;
        }

        Log::info('SendNfseJob: concluído com sucesso', [
            'fiscal_document_id' => $this->fiscalDocumentId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendNfseJob: job falhou definitivamente', [
            'fiscal_document_id' => $this->fiscalDocumentId,
            'exception'          => $exception->getMessage(),
        ]);

        $doc = FiscalDocument::find($this->fiscalDocumentId);
        if ($doc) {
            $errors   = $doc->errors_messages ?? [];
            $errors[] = [
                'at'       => now()->toDateTimeString(),
                'job'      => 'SendNfseJob',
                'mensagem' => $exception->getMessage(),
            ];
            $doc->update(['errors_messages' => $errors]);
        }
    }
}
