<?php

namespace App\Services\Service\Actions;

use App\Models\Service;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestoreServiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private Service $service,
    ) {}

    /**
     * Restaura um serviço excluído (soft delete).
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('Iniciando restauração de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
            ]);

            if (! $this->validateCanRestore()) {
                return false;
            }

            $result = $this->service->restore();

            Log::info('Serviço restaurado com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Já existe um serviço ativo com estas características',
                    ['service' => ['Conflito ao restaurar serviço']]
                );
            } else {
                $this->setError('Erro ao restaurar serviço', ['database' => [$e->getMessage()]]);
            }

            Log::error('Erro de query ao restaurar serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'service_id' => $this->service->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao restaurar serviço', ['error' => [$e->getMessage()]]);

            Log::error('Erro inesperado ao restaurar serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }
    }

    /**
     * Valida se o serviço pode ser restaurado.
     */
    private function validateCanRestore(): bool
    {
        if (! $this->service->trashed()) {
            $this->setError(
                'Este serviço não está excluído',
                ['service' => ['Serviço não está excluído']]
            );

            Log::warning('Tentativa de restaurar serviço que não está excluído', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        // Verifica se já existe um serviço ativo com o mesmo nome na mesma empresa
        $duplicateService = DB::table('services')
            ->where('name', $this->service->name)
            ->where('company_id', $this->service->company_id)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicateService) {
            $this->setError(
                'Já existe um serviço ativo com o nome "' . $this->service->name . '"',
                ['name' => ['Nome já existe para outro serviço ativo']]
            );

            Log::warning('Restauração de serviço bloqueada: nome duplicado', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
                'company_id' => $this->service->company_id,
            ]);

            return false;
        }

        Log::debug('Validação de restauração de serviço aprovada', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'service_id' => $this->service->id,
        ]);

        return true;
    }
}
