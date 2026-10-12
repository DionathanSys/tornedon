<?php

namespace App\Services\ServiceOrder\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use App\Services\ServiceOrder\ServiceOrderDefectService;
use App\Services\ServiceOrder\Validators\ServiceOrderValidator;
use App\Support\ServiceOrderOperationalSheet;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private ServiceOrder $serviceOrder,
    ) {}

    /**
     * Atualiza uma ordem de serviço existente.
     */
    public function execute(array $data): ?ServiceOrder
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($this->serviceOrder);

            Log::debug('Iniciando atualização de ordem de serviço', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'service_order_id' => $this->serviceOrder->id,
                'user_id' => $this->updatedBy,
                'data' => $data,
            ]);

            $validated = ServiceOrderValidator::validateUpdate(
                $data,
                $this->serviceOrder->id,
                $this->serviceOrder->company_id
            );

            if (ServiceOrderOperationalSheet::enabled($this->serviceOrder->company_id)
                && array_key_exists('equipment_id', $validated)
                && $validated['equipment_id'] != $this->serviceOrder->equipment_id
                && ! array_key_exists('entry_mileage', $validated)) {
                $validated['entry_mileage'] = null;
            }

            // Remove campos imutáveis
            ServiceOrderOperationalSheet::validatePayload(
                $validated,
                $this->serviceOrder->company_id,
                array_key_exists('equipment_id', $validated) ? $validated['equipment_id'] : $this->serviceOrder->equipment_id,
                $validated['customer_id'] ?? $this->serviceOrder->customer_id,
            );
            unset($validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            DB::transaction(function () use ($validated): void {
                if (array_intersect(['defects', 'entry_mileage'], array_keys($validated))) {
                    $current = ServiceOrder::query()->lockForUpdate()->findOrFail($this->serviceOrder->id);
                    if (! in_array($current->status, [State::OPEN, State::CLOSED], true)) {
                        throw ValidationException::withMessages(['defects' => 'Esta OS não permite alterar a ficha operacional.']);
                    }
                }
                if (array_key_exists('defects', $validated)) {
                    app(ServiceOrderDefectService::class)->sync($this->serviceOrder, $validated['defects'], $this->updatedBy);
                }
                unset($validated['defects']);
                $this->serviceOrder->update($validated);
            });
            $this->serviceOrder->refresh();

            $audit->recordModelEvent(
                $this->serviceOrder,
                'service_order.updated',
                "Ordem de serviço #{$this->serviceOrder->number} atualizada",
                $before,
                $audit->snapshot($this->serviceOrder),
                $this->updatedBy,
            );

            Log::info('Ordem de serviço atualizada com sucesso', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'service_order_id' => $this->serviceOrder->id,
                'number' => $this->serviceOrder->number,
                'user_id' => $this->updatedBy,
            ]);

            $this->setSuccess();

            return $this->serviceOrder;
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
                'errors' => $e->errors(),
                'data' => $data,
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar ordem de serviço no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
                'message_error' => $e->getMessage(),
                'data' => $data,
                'user_id' => $this->updatedBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'service_order_id' => $this->serviceOrder->id,
                'message_error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $this->updatedBy,
            ]);

            return null;
        }
    }
}
