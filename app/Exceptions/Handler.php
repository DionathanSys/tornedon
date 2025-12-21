<?php

namespace App\Exceptions;

use Throwable;
use Filament\Notifications\Notification;
use App\Domain\Exceptions\DomainException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->renderable(function (DomainException $e) {

            Notification::make()
                ->title('Ação não permitida')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return back();
        });
    }
}
