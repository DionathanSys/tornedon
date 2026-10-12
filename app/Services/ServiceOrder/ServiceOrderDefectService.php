<?php

namespace App\Services\ServiceOrder;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use App\Support\ServiceOrderOperationalSheet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceOrderDefectService
{
    public function sync(ServiceOrder $order, array $defects, int $userId): void
    {
        $order = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);
        ServiceOrderOperationalSheet::validatePayload(['defects' => $defects], $order->company_id, $order->equipment_id, $order->customer_id);
        if (! in_array($order->status, [State::OPEN, State::CLOSED], true)) {
            throw ValidationException::withMessages(['defects' => 'Esta OS não permite alterar a ficha operacional.']);
        }

        $data = Validator::make(['defects' => $defects], [
            'defects' => ['array', 'max:100'],
            'defects.*' => ['array:id,report'],
            'defects.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('service_order_defects', 'id')->where('service_order_id', $order->id)],
            'defects.*.report' => ['required', 'string', 'max:10000'],
        ])->validate()['defects'];

        $before = $order->defects()->get()->map(fn ($defect): array => $defect->only(['id', 'report', 'diagnosis', 'solution', 'status', 'position']))->all();
        $ids = [];
        foreach (array_values($data) as $position => $attributes) {
            $id = $attributes['id'] ?? null;
            unset($attributes['id']);
            $attributes += ['position' => $position, 'updated_by' => $userId];
            if ($id) {
                $defect = $order->defects()->findOrFail($id);
                $defect->update($attributes);
            } else {
                $defect = $order->defects()->create($attributes + ['created_by' => $userId]);
            }
            $ids[] = $defect->id;
        }

        $order->defects()->whereNotIn('id', $ids)->delete();
        $order->unsetRelation('defects');
        app(AuditRecorder::class)->recordModelEvent(
            $order,
            'service_order.operational_sheet.updated',
            "Ficha operacional da OS #{$order->number} atualizada",
            ['defects' => $before],
            ['defects' => $order->defects->map(fn ($defect): array => $defect->only(['id', 'report', 'diagnosis', 'solution', 'status', 'position']))->all()],
            $userId,
        );
    }
}
