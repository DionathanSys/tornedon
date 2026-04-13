<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

final class SendInvoiceEmailAction
{
    public static function make(): Action
    {
        return Action::make('sendInvoiceEmail')
            ->label('Enviar e-mail')
            ->icon(Heroicon::Envelope)
            ->color('info')
            ->modalHeading('Enviar e-mail da fatura')
            ->modalDescription('O envio será processado em segundo plano. O job consultará os documentos fiscais na API e anexará os retornos disponíveis.')
            ->modalSubmitActionLabel('Enviar')
            ->visible(fn (Invoice $record): bool => $record->fiscalDocuments()->exists())
            ->schema([
                TextInput::make('subject')
                    ->label('Assunto')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (Invoice $record): string => self::defaultSubject($record)),

                Textarea::make('body')
                    ->label('Mensagem')
                    ->required()
                    ->rows(8)
                    ->default(fn (Invoice $record): string => self::defaultBody($record)),
            ])
            ->action(function (Action $action, Invoice $record, array $data): void {
                if (! $record->fiscalDocuments()->exists()) {
                    notify::error('A fatura não possui documento fiscal vinculado para envio.');
                    $action->halt();
                    return;
                }

                SendInvoiceEmailJob::dispatch(
                    invoiceId: (int) $record->id,
                    subject: (string) ($data['subject'] ?? ''),
                    body: (string) ($data['body'] ?? ''),
                    userId: (int) Auth::id(),
                );

                notify::success('Envio do e-mail enfileirado com sucesso.');
            });
    }

    private static function defaultSubject(Invoice $invoice): string
    {
        $number = $invoice->invoice_number ?: $invoice->id;

        return "Documentos da fatura {$number}";
    }

    private static function defaultBody(Invoice $invoice): string
    {
        $number = $invoice->invoice_number ?: $invoice->id;
        $customer = $invoice->customer?->name ?: 'cliente';

        return implode(PHP_EOL . PHP_EOL, [
            "Olá, {$customer}.",
            "Segue em anexo a documentação da fatura {$number}, incluindo o PDF da fatura, os arquivos fiscais e os documentos operacionais vinculados.",
            'Em caso de dúvidas, permaneço à disposição.',
        ]);
    }
}
