<?php

namespace App\Services\Quote;

use App\Enum\Quote\Status;
use App\Models\ProductionOrder;
use App\Models\Quote;
use App\Services\Quote\Actions\ApproveQuote;
use App\Services\Quote\Actions\ConvertToProductionOrder;
use App\Services\Quote\Actions\CreateQuote;
use App\Services\Quote\Actions\DeleteQuoteAction;
use App\Services\Quote\Actions\RejectQuote;
use App\Services\Quote\Actions\ReopenQuoteAction;
use App\Services\Quote\Actions\RestoreQuoteAction;
use App\Services\Quote\Actions\SendForApproval;
use App\Services\Quote\Actions\UpdateQuoteAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuoteService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Consultas
     |==============================*/

    /**
     * Lista todos os orçamentos de uma empresa.
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        Log::debug('QuoteService: Listando orçamentos', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
        ]);

        $query = Quote::where('company_id', $companyId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('quote_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->with([
            'partner',
            'company',
            'items',
            'productionOrder',
            'createdBy',
            'approvedBy',
        ])->orderBy('created_at', 'desc')->get();
    }

    /**
     * Busca um orçamento pelo ID.
     */
    public function find(int $id, ?int $companyId = null): ?Quote
    {
        Log::debug('QuoteService: Buscando orçamento', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'quote_id'   => $id,
            'company_id' => $companyId,
        ]);

        $query = Quote::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->with([
            'partner',
            'company',
            'items.product',
            'productionOrder',
            'createdBy',
            'approvedBy',
        ])->first();
    }

    /* ==============================
     |  Operações de Escrita
     |==============================*/

    /**
     * Cria um novo orçamento.
     */
    public function create(array $data, int $createdBy): ?Quote
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateQuote($createdBy);
                $quote = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Orçamento criado com sucesso');

                Log::info('QuoteService: Orçamento criado com sucesso', [
                    'metodo'       => __METHOD__ . '@' . __LINE__,
                    'quote_id'     => $quote->id,
                    'quote_number' => $quote->quote_number,
                ]);

                return $quote;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    /**
     * Atualiza um orçamento existente.
     */
    public function update(Quote $quote, array $data, int $updatedBy): ?Quote
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote, $data, $updatedBy) {
                $action = new UpdateQuoteAction($updatedBy, $quote);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Orçamento atualizado com sucesso');

                Log::info('QuoteService: Orçamento atualizado com sucesso', [
                    'metodo'       => __METHOD__ . '@' . __LINE__,
                    'quote_id'     => $quote->id,
                    'quote_number' => $quote->quote_number,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um orçamento.
     */
    public function delete(Quote $quote): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote) {
                $action = new DeleteQuoteAction($quote);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return false;
                }

                $this->setSuccess('Orçamento excluído com sucesso');

                Log::info('QuoteService: Orçamento excluído com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $quote->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um orçamento (force delete).
     */
    public function forceDelete(Quote $quote): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote) {
                $action = new DeleteQuoteAction($quote);
                $result = $action->forceDelete();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Orçamento excluído permanentemente com sucesso');

                Log::info('QuoteService: Orçamento excluído permanentemente', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $quote->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir permanentemente o orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Restaura um orçamento excluído (soft delete).
     */
    public function restore(Quote $quote): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote) {
                $action = new RestoreQuoteAction($quote);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Orçamento restaurado com sucesso');

                Log::info('QuoteService: Orçamento restaurado com sucesso', [
                    'metodo'   => __METHOD__ . '@' . __LINE__,
                    'quote_id' => $quote->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao restaurar orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /* ==============================
     |  Transições de Estado
     |==============================*/

    /**
     * Envia o orçamento para aprovação (draft → sent).
     */
    public function sendForApproval(Quote $quote, int $userId): ?Quote
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote, $userId) {
                $action = new SendForApproval($userId);
                $result = $action->execute($quote);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Orçamento enviado para aprovação');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao enviar orçamento para aprovação');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Aprova um orçamento (sent → approved).
     */
    public function approve(Quote $quote, int $approvedBy): ?Quote
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote, $approvedBy) {
                $action = new ApproveQuote($approvedBy);
                $result = $action->execute($quote);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Orçamento aprovado com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao aprovar orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Rejeita um orçamento.
     */
    public function reject(Quote $quote, string $reason, int $rejectedBy): ?Quote
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote, $reason, $rejectedBy) {
                $action = new RejectQuote($rejectedBy);
                $result = $action->execute($quote, $reason);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Orçamento rejeitado');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao rejeitar orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Reabre um orçamento (rejected|expired → draft).
     */
    public function reopen(Quote $quote, int $userId): ?Quote
    {
        $this->resetResponse();

        Log::debug('QuoteService: Iniciando processo de reabertura de orçamento', [
            'metodo'   => __METHOD__ . '@' . __LINE__,
            'quote_id' => $quote->id,
            'user_id'  => $userId,
            'key'      => 'reopen_quote_action',
        ]);

        try {
            return DB::transaction(function () use ($quote, $userId) {
                $action = new ReopenQuoteAction($userId);
                $result = $action->execute($quote);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Orçamento reaberto com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao reabrir orçamento');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Converte um orçamento aprovado em Ordem de Produção.
     */
    public function convertToProductionOrder(Quote $quote, array $data, int $createdBy): ?ProductionOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($quote, $data, $createdBy) {
                $action = new ConvertToProductionOrder($createdBy);
                $productionOrder = $action->execute($quote, $data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('QuoteService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'quote_id'   => $quote->id,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de Produção criada com sucesso');

                Log::info('QuoteService: Ordem de Produção criada a partir do orçamento', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'quote_id'            => $quote->id,
                    'production_order_id' => $productionOrder->id,
                ]);

                return $productionOrder;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao converter orçamento em Ordem de Produção');

            Log::error('QuoteService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'quote_id'   => $quote->id,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
