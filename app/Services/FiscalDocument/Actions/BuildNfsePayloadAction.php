<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Resolvers\NfsePayloadBuilderResolver;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher: resolve o builder de payload NFS-e correto
 * com base no modelo e na cidade efetiva de emissão.
 */
class BuildNfsePayloadAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            $resolver = app(NfsePayloadBuilderResolver::class);
            $resolutionKey = $resolver->resolveKey($fiscalDocument);
            $builder = $resolver->resolve($fiscalDocument);

            if ($builder === null) {
                $this->setError("Nenhum builder NFS-e encontrado para a chave de resolução {$resolutionKey}.");
                return null;
            }

            $payload = $builder->build($fiscalDocument);

            if ($payload === null) {
                $this->setError(method_exists($builder, 'getMessage') ? $builder->getMessage() : 'Falha ao montar payload da NFS-e.');
                return null;
            }

            $this->setSuccess();
            return $payload;

        } catch (\Exception $e) {
            $this->setError('Erro ao montar payload NFS-e: ' . $e->getMessage());

            Log::error('BuildNfsePayloadAction: erro', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'resolution_key'     => $resolutionKey ?? null,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
