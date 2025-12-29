<?php

namespace App\Notification;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class NotifyService
{
    protected Collection|EloquentCollection $recipients;

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

    public function sendToDatabase(Collection|User|array|int|null $users = null): void
    {
        $targets = $this->resolveRecipients($users) ?? $this->recipients;

        Notification::make()
            ->title($this->title)
            ->body($this->message)
            ->status($this->level)
            ->sendToDatabase($targets);
    }

    public function sendToast(): void
    {
        Notification::make()
            ->title($this->title)
            ->body($this->message)
            ->status($this->level)
            ->send();
    }

    /* ==============================
     |  Static factories
     |==============================*/

    public static function success(
        string $title = 'Sucesso',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null
    ): void {

        if ($toDatabase && $users === null) {
            throw new \InvalidArgumentException(
                'Database notifications require at least one recipient.'
            );
        }

        self::dispatc('success', $title, $message, $toDatabase, $users);
    }

    public static function error(
        string $title = 'Falha durante processamento',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null
    ): void {

        if ($toDatabase && $users === null) {
            throw new \InvalidArgumentException(
                'Database notifications require at least one recipient.'
            );
        }

        self::dispatc('danger', $title, $message, $toDatabase, $users);
    }

    public static function warning(
        string $title = 'Alerta',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null
    ): void {

        if ($toDatabase && $users === null) {
            throw new \InvalidArgumentException(
                'Database notifications require at least one recipient.'
            );
        }

        self::dispatc('warning', $title, $message, $toDatabase, $users);
    }

    public static function info(
        string $title = 'Info',
        string $message = '',
        bool $toDatabase = false,
        Collection|User|array|int|null $users = null
    ): void {

        if ($toDatabase && $users === null) {
            $toDatabase = false;
        }

        self::dispatc('info', $title, $message, $toDatabase, $users);
    }

    public static function debug(string $title = 'Debug', string $message = ''): void
    {
        $admins = User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->get();

        (new self('info', $title, $message, $admins))
            ->sendToDatabase();
    }

    /* ==============================
     |  Internal helpers
     |==============================*/

    protected static function dispatc(
        string $level,
        string $title,
        string $message,
        bool $toDatabase,
        Collection|User|array|int|null $users
    ): void {
        $service = new self($level, $title, $message);

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
