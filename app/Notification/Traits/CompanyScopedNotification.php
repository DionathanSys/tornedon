<?php

namespace App\Notification\Traits;

use App\Tenancy\Tenant;
use Illuminate\Notifications\DatabaseNotification;

trait CompanyScopedNotification
{
    protected function storeDatabaseNotification($notifiable, $notification)
    {
        return DatabaseNotification::create([
            'id'            => $notification->id,
            'type'          => get_class($notification),
            'notifiable_id' => $notifiable->getKey(),
            'notifiable_type' => $notifiable->getMorphClass(),
            'data'          => $notification->toArray($notifiable),
            'company_id'    => Tenant::id(),
        ]);
    }
}