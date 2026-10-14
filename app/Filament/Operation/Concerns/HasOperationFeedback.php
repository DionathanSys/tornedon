<?php

namespace App\Filament\Operation\Concerns;

use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;

trait HasOperationFeedback
{
    use Toast;

    protected function serviceFailed(object $service, string $prefix = 'data'): void
    {
        Log::error('Operation: '.$service->getMessage(), ['component' => static::class, 'error_code' => $service->getErrorCode(), 'errors' => $service->getErrors()]);
        foreach ($service->getErrors() ?? [] as $field => $messages) {
            foreach ((array) $messages as $message) {
                $this->addError($prefix.'.'.$field, $message);
            }
        }
        $this->error($service->getMessageUser() ?: 'Não foi possível concluir a operação.');
    }
}
