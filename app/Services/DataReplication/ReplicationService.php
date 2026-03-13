<?php

namespace App\Services\DataReplication;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\Partner;
use App\Services\DataReplication\Handlers\EquipmentReplicationHandler;
use App\Services\DataReplication\Handlers\PartnerReplicationHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReplicationService
{
    /**
     * Replica um modelo para múltiplas empresas
     *
     * @param Model $source
     * @param array $targetCompanyIds
     * @param string $type ('partner' ou 'equipment')
     * @return array Resultado da replicação [successful => [], failed => []]
     *
     * @throws InvalidArgumentException
     */
    public function replicate(Model $source, array $targetCompanyIds, string $type = 'auto'): array
    {
        // Auto-detectar tipo se não especificado
        if ($type === 'auto') {
            $type = $this->detectType($source);
        }

        // Validar tipo
        if (!in_array($type, ['partner', 'equipment'])) {
            throw new InvalidArgumentException("Tipo inválido: {$type}. Use 'partner' ou 'equipment'.");
        }

        // Validar dados de origem
        $this->validateSourceData($source, $type);

        // Validar empresas alvo
        if (empty($targetCompanyIds)) {
            throw new InvalidArgumentException('Nenhuma empresa alvo especificada.');
        }

        $targetCompanyIds = array_unique($targetCompanyIds);
        $companies = Company::whereIn('id', $targetCompanyIds)->get();
        if ($companies->count() !== count($targetCompanyIds)) {
            throw new InvalidArgumentException('Uma ou mais empresas alvo não existem.');
        }

        // Obter handler apropriado
        $handler = $this->getHandler($type);

        // Replicar com transação
        return DB::transaction(function () use ($handler, $source, $targetCompanyIds) {
            return $handler->handle($source, $targetCompanyIds);
        });
    }

    /**
     * Valida os dados de origem
     */
    protected function validateSourceData(Model $source, string $type): void
    {
        if ($source->trashed()) {
            throw new InvalidArgumentException('Não é possível replicar um registro deletado.');
        }

        if ($type === 'partner' && !($source instanceof Partner)) {
            throw new InvalidArgumentException('Modelo de source deve ser Partner para tipo "partner".');
        }

        if ($type === 'equipment' && !($source instanceof Equipment)) {
            throw new InvalidArgumentException('Modelo de source deve ser Equipment para tipo "equipment".');
        }
    }

    /**
     * Detecta o tipo automaticamente
     */
    protected function detectType(Model $source): string
    {
        if ($source instanceof Partner) {
            return 'partner';
        }

        if ($source instanceof Equipment) {
            return 'equipment';
        }

        throw new InvalidArgumentException("Tipo de modelo não suportado: " . get_class($source));
    }

    /**
     * Obtém o handler apropriado para o tipo
     */
    protected function getHandler(string $type): PartnerReplicationHandler|EquipmentReplicationHandler
    {
        return match ($type) {
            'partner' => app(PartnerReplicationHandler::class),
            'equipment' => app(EquipmentReplicationHandler::class),
        };
    }
}
