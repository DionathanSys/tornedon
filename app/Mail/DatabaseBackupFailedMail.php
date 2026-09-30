<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DatabaseBackupFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $connectionName,
        public readonly string $failureMessage,
        public readonly string $failedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Falha no backup do banco de dados',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.database-backup-failed',
        );
    }
}
