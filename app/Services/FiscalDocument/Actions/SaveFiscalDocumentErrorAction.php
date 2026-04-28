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

            $baseMessage = $message ?? 'Erro desconhecido';

            if (!empty($data['erros']) && is_array($data['erros'])) {
                foreach ($data['erros'] as $key => $erroItem) {
                    $erroData = is_object($erroItem) ? (array) $erroItem : $erroItem;
                    
                    if (is_array($erroData) && (isset($erroData['campo']) || isset($erroData['erro']) || isset($erroData['descricao']) || isset($erroData['detalhes']))) {
                        $campo     = $erroData['campo'] ?? 'N/A';
                        $erroMsg   = $erroData['erro'] ?? 'N/A';
                        $descricao = $erroData['descricao'] ?? 'N/A';
                        $detalhe   = $erroData['detalhes'] ?? 'N/A';

                        $formattedMessage = "{$baseMessage}\nCampo: {$campo}\nErro: {$erroMsg}\nDescrição: {$descricao}\nDetalhe: {$detalhe}";

                        $entry = array_filter([
                            'at'       => now()->toDateTimeString(),
                            'mensagem' => $formattedMessage,
                            'acao'     => $data['acao'] ?? null,
                            'codigo'   => $data['codigo'] ?? null,
                            'erros'    => $erroData,
                            'contexto' => $data['contexto'] ?? null,
                        ], static fn ($value): bool => $value !== null && $value !== []);

                        $errors[] = $entry;
                    } else {
                        $messages = is_array($erroData) ? $erroData : [$erroData];
                        $campo = is_string($key) ? $key : 'N/A';
                        
                        foreach ($messages as $msg) {
                            $msgString = is_scalar($msg) ? (string) $msg : json_encode($msg, JSON_UNESCAPED_UNICODE);
                            $formattedMessage = "{$baseMessage}\nCampo: {$campo}\nErro: {$msgString}";
                            
                            $entry = array_filter([
                                'at'       => now()->toDateTimeString(),
                                'mensagem' => $formattedMessage,
                                'acao'     => $data['acao'] ?? null,
                                'codigo'   => $data['codigo'] ?? null,
                                'erros'    => $msgString,
                                'contexto' => $data['contexto'] ?? null,
                            ], static fn ($value): bool => $value !== null && $value !== []);

                            $errors[] = $entry;
                        }
                    }
                }
            } else {
                $entry = array_filter([
                    'at'       => now()->toDateTimeString(),
                    'mensagem' => $baseMessage,
                    'acao'     => $data['acao'] ?? null,
                    'codigo'   => $data['codigo'] ?? null,
                    'erros'    => $data['erros'] ?? null,
                    'contexto' => $data['contexto'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== []);

                $errors[] = $entry;
            }

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
