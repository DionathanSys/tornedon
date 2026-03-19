<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class SaveFiscalDocumentErrorAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, ?string $message, array $data = []): bool
    {
        try {
            $errors = $fiscalDocument->errors_messages ?? [];

            $entry = array_filter([
                'at'       => now()->toDateTimeString(),
                'mensagem' => $message ?? 'Erro desconhecido',
                'acao'     => $data['acao'] ?? null,
                'codigo'   => $data['codigo'] ?? null,
                'erros'    => $data['erros'] ?? null,
                'contexto' => $data['contexto'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== []);

            $errors[] = $entry;

            $fiscalDocument->update([
                'errors_messages' => $errors,
            ]);

            $this->setSuccess();

            return true;
        } catch (\Exception $e) {
            $this->setError('Erro ao persistir erro no documento fiscal.');

            Log::error('SaveFiscalDocumentErrorAction: excecao ao persistir erro', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'message'            => $message,
                'data'               => $data,
                'exception'          => $e->getMessage(),
            ]);

            return false;
        }
    }
}
