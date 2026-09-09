<?php

namespace App\Notification;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class NotifyService
{
    protected Collection|EloquentCollection $recipients;

    protected ?string $errorCode = null;

    public function __construct(
        protected string $level,
        protected string $title,
        protected string $message,
        Collection|EloquentCollection|null $recipients = null
    ) {
        $this->recipients = $recipients ?? User::query()
            ->where('is_active', true)
            ->get();
    }

    /* ==============================
     |  Public API
     |==============================*/

    public function withErrorCode(?string $errorCode): self
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    public function sendToDatabase(Collection|User|array|int|null $users = null): void
    {
        $targets = $this->resolveRecipients($users) ?? $this->recipients;

        $notification = Notification::make()
            ->title($this->title)
            ->body($this->message)
            ->status($this->level);

        if ($this->errorCode) {
            $notification->actions([
                Action::make('create_ticket')
                    ->label('Reportar Erro')
                    ->icon('heroicon-o-ticket')
                    ->color('warning')
                    ->dispatch('createErrorTicket', [
                        'errorCode' => $this->errorCode,
                        'title' => $this->title,
                        'message' => $this->message,
                    ]),
            ]);
        }

        $notification->sendToDatabase($targets);
    }

    public function sendToast(): void
    {
        $notification = Notification::make()
            ->title($this->title)
            ->body($this->message)
            ->status($this->level);

        if ($this->errorCode) {
            $notification->actions([
                Action::make('create_ticket')
                    ->label('Reportar')
                    ->icon('heroicon-o-ticket')
                    ->color('warning')
                    ->dispatch('createErrorTicket', [
                        'errorCode' => $this->errorCode,
                        'title' => $this->title,
                        'message' => $this->message,
                    ]),
            ]);
        }

        $notification->send();
    }

    /* ==============================
     |  Static factories
     |==============================*/

    public static function success(
        string $title = 'Sucesso',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null,
        ?string $errorCode = null
    ): void {

        if ($toDatabase && $users === null) {
            throw new \InvalidArgumentException(
                'Database notifications require at least one recipient.'
            );
        }

        self::dispatch('success', $title, $message, $toDatabase, $users, $errorCode);
    }

    public static function error(
        string $title = 'Falha durante processamento',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null,
        ?string $errorCode = null
    ): void {

        if ($toDatabase && $users === null) {
            throw new \InvalidArgumentException(
                'Database notifications require at least one recipient.'
            );
        }

        self::dispatch('danger', $title, $message, $toDatabase, $users, $errorCode);
    }

    public static function warning(
        string $title = 'Alerta',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null,
        ?string $errorCode = null
    ): void {

        if ($toDatabase && $users === null) {
            throw new \InvalidArgumentException(
                'Database notifications require at least one recipient.'
            );
        }

        self::dispatch('warning', $title, $message, $toDatabase, $users, $errorCode);
    }

    public static function info(
        string $title = 'Info',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null,
        ?string $errorCode = null
    ): void {

        if ($toDatabase && $users === null) {
            $toDatabase = false;
        }

        self::dispatch('info', $title, $message, $toDatabase, $users, $errorCode);
    }

    public static function debug(string $title = 'Debug', string $message = ''): void
    {
        $admins = User::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereIn('management_role', ['super_admin', 'management_admin'])
                    ->orWhere(function ($legacyQuery): void {
                        $legacyQuery
                            ->whereNull('management_role')
                            ->where('is_admin', true);
                    });
            })
            ->get();

        (new self('info', $title, $message, $admins))
            ->sendToDatabase();
    }

    /* ==============================
     |  Internal helpers
     |==============================*/

    protected static function dispatch(
        string $level,
        string $title,
        string $message,
        bool $toDatabase,
        Collection|User|array|int|null $users,
        ?string $errorCode = null
    ): void {
        $service = new self($level, $title, $message);

        if ($errorCode) {
            $service->withErrorCode($errorCode);
        }

        if ($toDatabase) {
            $service->sendToDatabase($users);
        }

        $service->sendToast();
    }

    protected function resolveRecipients(Collection|User|array|int|null $users): Collection|User|null
    {
        if ($users instanceof Collection || $users instanceof User) {
            return $users;
        }

        if (is_int($users)) {
            return User::find($users);
        }

        if (is_array($users)) {
            return User::whereIn('id', $users)->get();
        }

        return null;
    }
}
