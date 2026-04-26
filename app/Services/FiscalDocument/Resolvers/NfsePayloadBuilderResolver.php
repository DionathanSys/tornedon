<?php

namespace App\Services\FiscalDocument\Resolvers;

use App\Enum\FiscalDocument\NfseModel;
use App\Models\FiscalDocument;
use App\Services\FiscalDocument\Contracts\NfsePayloadBuilder;
use Illuminate\Support\Facades\App;

class NfsePayloadBuilderResolver
{
    public function __construct(
        private readonly NfseEmissionCityResolver $cityResolver,
    ) {
    }

    public function resolve(FiscalDocument $document): ?NfsePayloadBuilder
    {
        $key = $this->resolveKey($document);
        $map = config('nfse_builders', []);

        $builderClass = $map[$key] ?? null;

        if ($builderClass === null && $this->resolveModel($document) === NfseModel::MUNICIPAL->value) {
            $builderClass = $map['municipal:default'] ?? null;
            $key = $builderClass !== null ? 'municipal:default' : $key;
        }

        if ($builderClass === null) {
            return null;
        }

        $builder = App::make($builderClass);

        if (! $builder instanceof NfsePayloadBuilder) {
            return null;
        }

        return $builder->supports($document) ? $builder : null;
    }

    public function resolveKey(FiscalDocument $document): string
    {
        $model = $this->resolveModel($document);

        if ($model === NfseModel::NACIONAL->value) {
            return 'nacional:default';
        }

        $cityCode = $this->cityResolver->resolve($document);

        return $cityCode !== null
            ? 'municipal:' . $cityCode
            : 'municipal:default';
    }

    private function resolveModel(FiscalDocument $document): string
    {
        $model = $document->nfse_model;

        if ($model instanceof NfseModel) {
            return $model->value;
        }

        return trim((string) $model);
    }
}
