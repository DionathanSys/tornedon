<?php

namespace App\Services\ServiceOrder\States;

use App\Services\ServiceOrder\Events\ServiceOrderCanceled;
use App\Services\ServiceOrder\Events\ServiceOrderClosed;

class OpenState extends ServiceOrderState
{
    public function close(): void
    {

        $this->ordem->update([
            'status' => 'encerrada',
            'encerrada_em' => now(),
        ]);

        event(new ServiceOrderClosed($this->ordem));
    }

    public function cancel(): void
    {

        event(new ServiceOrderCanceled($this->ordem));
    }

    public function name(): string
    {
        return 'aberta';
    }
}
