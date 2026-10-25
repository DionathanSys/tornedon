<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyTeamInvitation extends Notification
{
    public function __construct(public string $companyName, public string $invitationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Convite para '.$this->companyName)
            ->line('Você recebeu um convite para acessar '.$this->companyName.' no Tornedon.')
            ->action('Consultar e aceitar convite', $this->invitationUrl)
            ->line('O convite é pessoal, vinculado ao seu e-mail e válido por 7 dias.');
    }
}
