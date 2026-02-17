<?php

namespace App\Services\Service\Actions;

use App\Models\Service;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteServiceAction
{
    use HandlesActionResponse;

    public function __construct(
        private Service $service,
    ) {}

    /**
     * Exclui (soft delete) um serviço.
     *
     * @return bool
     */
    public function execute(): bool
    {
        try {
            Log::debug('Iniciando exclusão (soft delete) de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
            ]);

            if (! $this->validateCanDelete()) {
                return false;
            }

            $result = $this->service->delete();

            Log::info('Serviço excluído (soft delete) com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Não é possível excluir este serviço pois ele possui vínculos com outros registros',
                    ['service' => ['Serviço vinculado a outros registros']]
                );
            } else {
                $this->setError('Erro ao excluir serviço');
            }

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'service_id' => $this->service->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um serviço.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        try {
            Log::debug('Iniciando exclusão permanente de serviço', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
                'name'       => $this->service->name,
            ]);

            if (! $this->validateCanForceDelete()) {
                return false;
            }

            $result = $this->service->forceDelete();

            Log::info('Serviço excluído permanentemente com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'service_id' => $this->service->id,
            ]);

            $this->setSuccess();
            return $result;

        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->setError(
                    'Não é possível excluir permanentemente este serviço pois ele possui vínculos com outros registros',
                    ['service' => ['Serviço vinculado a outros registros']]
                );
            } else {
                $this->setError('Erro ao excluir permanentemente serviço');
            }

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'service_id' => $this->service->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente serviço');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }
    }

    /**
     * Valida se o serviço pode ser excluído (soft delete).
     */
    private function validateCanDelete(): bool
    {
        if ($this->service->trashed()) {
            $this->setError(
                'Este serviço já está excluído',
                ['service' => ['Serviço já excluído']]
            );

            Log::warning('Tentativa de excluir serviço já excluído', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        // Verifica se existem ordens de serviço vinculadas
        $hasServiceOrders = DB::table('service_order_items')
            ->where('service_id', $this->service->id)
            ->exists();

        if ($hasServiceOrders) {
            $this->setError(
                'Não é possível excluir este serviço pois existem ordens de serviço vinculadas a ele',
                ['service' => ['Serviço possui ordens de serviço vinculadas']]
            );

            Log::warning('Exclusão de serviço bloqueada: ordens de serviço vinculadas', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        // Verifica se existem itens de cotação vinculados
        $hasQuoteItems = DB::table('quote_items')
            ->where('service_id', $this->service->id)
            ->exists();

        if ($hasQuoteItems) {
            $this->setError(
                'Não é possível excluir este serviço pois existem cotações vinculadas a ele',
                ['service' => ['Serviço possui cotações vinculadas']]
            );

            Log::warning('Exclusão de serviço bloqueada: cotações vinculadas', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        Log::debug('Validação de exclusão de serviço aprovada', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'service_id' => $this->service->id,
        ]);

        return true;
    }

    /**
     * Valida se o serviço pode ser excluído permanentemente.
     */
    private function validateCanForceDelete(): bool
    {
        if (! $this->service->trashed()) {
            $this->setError(
                'O serviço deve estar excluído antes de ser removido permanentemente',
                ['service' => ['Serviço não está excluído']]
            );

            Log::warning('Tentativa de forceDelete em serviço não excluído', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        // Verifica se existem ordens de serviço vinculadas
        $hasServiceOrders = DB::table('service_order_items')
            ->where('service_id', $this->service->id)
            ->exists();

        if ($hasServiceOrders) {
            $this->setError(
                'Não é possível excluir permanentemente este serviço pois existem ordens de serviço vinculadas a ele',
                ['service' => ['Serviço possui ordens de serviço vinculadas']]
            );

            Log::warning('ForceDelete de serviço bloqueado: ordens de serviço vinculadas', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        // Verifica se existem itens de cotação vinculados
        $hasQuoteItems = DB::table('quote_items')
            ->where('service_id', $this->service->id)
            ->exists();

        if ($hasQuoteItems) {
            $this->setError(
                'Não é possível excluir permanentemente este serviço pois existem cotações vinculadas a ele',
                ['service' => ['Serviço possui cotações vinculadas']]
            );

            Log::warning('ForceDelete de serviço bloqueado: cotações vinculadas', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'service_id' => $this->service->id,
            ]);

            return false;
        }

        Log::debug('Validação de exclusão permanente de serviço aprovada', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'service_id' => $this->service->id,
        ]);

        return true;
    }
}
