<?php

namespace App\Observers;

use App\Models\Requisition;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;
use BackedEnum;

class RequisitionObserver
{
    public function updated(Requisition $requisition): void
    {
        if (! $requisition->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('requisition', (int) $requisition->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForRequisitionStatusChange(
            $requisition,
            $this->normalizeStatus($requisition->getOriginal('status')),
            $this->normalizeStatus($requisition->status),
        );
    }

    private function normalizeStatus(mixed $status): string
    {
        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return (string) $status;
    }
}
