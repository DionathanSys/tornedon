<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\Actions\SendInvoiceEmailAction as SendInvoiceEmailServiceAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class SendInvoiceEmailAction
{
    public static function make(): Action
    {
        return Action::make('sendInvoiceEmail')
            ->label('Enviar e-mail')
            ->icon(Heroicon::Envelope)
            ->color('info')
            ->modalHeading('Enviar e-mail da fatura')
            ->modalDescription('O e-mail será enviado para os destinatários configurados no vínculo empresa-cliente, com os anexos fiscais e operacionais disponíveis.')
            ->modalSubmitActionLabel('Enviar')
            ->visible(fn (Invoice $record): bool => $record->fiscalDocuments()->get()->contains(
                fn ($fiscalDocument): bool => ($fiscalDocument->isNfe() && $fiscalDocument->isNfeAuthorized())
                    || ($fiscalDocument->isNfse() && $fiscalDocument->isNfseAuthorized())
            ))
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
                $service = app(SendInvoiceEmailServiceAction::class);
                $sent = $service->execute(
                    invoice: $record,
                    subject: (string) ($data['subject'] ?? ''),
                    body: (string) ($data['body'] ?? ''),
                    userId: (int) Auth::id(),
                );

                if (! $sent || $service->hasError()) {
                    Log::error('SendInvoiceEmailAction UI: erro ao enviar e-mail da fatura', [
                        'invoice_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                        'errors' => $service->getErrors(),
                    ]);

                    notify::error(
                        message: $service->getMessage() ?: 'Não foi possível enviar o e-mail da fatura.',
                        errorCode: $service->getErrorCode()
                    );

                    $action->halt();

                    return;
                }

                notify::success('E-mail da fatura enviado com sucesso.');
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
