<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\NfseModel;
use App\Models\FiscalDocument;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher: seleciona o builder de payload NFS-e correto
 * com base no modelo (municipal ou nacional) do FiscalDocument.
 */
class BuildNfsePayloadAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): ?array
    {
        try {
            $model = $fiscalDocument->nfse_model;

            $builder = match ($model) {
                NfseModel::MUNICIPAL->value, NfseModel::MUNICIPAL => new BuildNfseMunicipalPayloadAction(),
                NfseModel::NACIONAL->value, NfseModel::NACIONAL  => new BuildNfseNacionalPayloadAction(),
                default => null,
            };

            if ($builder === null) {
                $this->setError("Modelo NFS-e inválido ou não definido: {$model}");
                return null;
            }

            $payload = $builder->execute($fiscalDocument);

            if ($payload === null) {
                $this->setError($builder->getMessage());
                return null;
            }

            $this->setSuccess();
            return $payload;

        } catch (\Exception $e) {
            $this->setError('Erro ao montar payload NFS-e: ' . $e->getMessage());

            Log::error('BuildNfsePayloadAction: erro', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
