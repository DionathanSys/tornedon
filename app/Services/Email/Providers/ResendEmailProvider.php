<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;
use App\Services\Email\DTO\EmailMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ResendEmailProvider implements EmailProviderInterface
{
    public function send(EmailMessage $message): void
    {
        $apiKey = config('services.resend.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('Chave da Resend não configurada.');
        }

        $fromEmail = $message->fromEmail ?: (string) config('mail.from.address');
        $fromName = $message->fromName ?: (string) config('mail.from.name');

        if ($fromEmail === '') {
            throw new RuntimeException('Remetente do e-mail não configurado.');
        }

        $payload = [
            'from' => trim($fromName) !== ''
                ? "{$fromName} <{$fromEmail}>"
                : $fromEmail,
            'to' => array_values($message->to),
            'subject' => $message->subject,
            'html' => $message->html,
        ];

        if ($message->text !== null && $message->text !== '') {
            $payload['text'] = $message->text;
        }

        if ($message->cc !== []) {
            $payload['cc'] = array_values($message->cc);
        }

        if ($message->attachments !== []) {
            $payload['attachments'] = array_map(
                fn ($attachment): array => [
                    'filename' => $attachment->filename,
                    'content' => $attachment->contentBase64,
                    'type' => $attachment->mimeType,
                ],
                $message->attachments
            );
        }

        $response = Http::timeout(20)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post(
                (string) config('email_notifications.resend.endpoint', 'https://api.resend.com/emails'),
                $payload
            );

        if ($response->failed()) {
            throw new RuntimeException('Erro ao enviar e-mail via Resend: ' . $response->body());
        }
    }
}

