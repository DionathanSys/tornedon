<?php

namespace App\Filament\Support\Actions;

use App\Models\FiscalDocument;
use App\Services\FiscalDocument\FiscalCancellationPolicy;
use App\Services\FiscalDocument\FiscalDocumentIssueService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class ManageFiscalDocumentIssueAction
{
    public static function make(): Action
    {
        return Action::make('tratar_pendencia_fiscal')
            ->label('Tratar pendência fiscal')->icon('heroicon-o-wrench-screwdriver')->color('warning')
            ->visible(function (FiscalDocument $record, $livewire): bool {
                $administrator = method_exists($livewire, 'canManageFiscalIssues')
                    ? $livewire->canManageFiscalIssues()
                    : app(FiscalDocumentIssueService::class)->canManage(Filament::auth()->user(), $record);
                if (! $administrator) {
                    return false;
                }
                $blocked = $record->hasOpenFiscalIssue()
                    || (($record->isNfeAuthorized() || $record->isNfseAuthorized()) && ! app(FiscalCancellationPolicy::class)->evaluate($record)['allowed']);

                return $blocked;
            })
            ->modalHeading('Tratamento fiscal pelo administrador da empresa')
            ->modalDescription('Registre o procedimento adotado e seus comprovantes. Informar um cancelamento externo não altera a situação fiscal: a confirmação deve chegar por consulta ou webhook. Manter a nota encerra apenas a pendência administrativa.')
            ->schema([
                Textarea::make('current_issue')->label('Pendência / histórico')->disabled()->dehydrated(false)
                    ->default(function (FiscalDocument $record): string {
                        $issue = $record->fiscalIssues()->latest('id')->first();
                        if (! $issue) {
                            return app(FiscalCancellationPolicy::class)->evaluate($record)['message'] ?? '';
                        }

                        return $issue->message."\n\n".collect($issue->history)->map(fn (array $entry): string => ($entry['at'] ?? '').' — '.($entry['event'] ?? '').': '.($entry['notes'] ?? $entry['message'] ?? '').(empty($entry['reference']) ? '' : ' | Referência: '.$entry['reference']))->implode("\n");
                    })->rows(6),
                Select::make('resolution')->label('Encaminhamento')->required()->native(false)->options([
                    'follow_up' => 'Registrar andamento / manter pendente',
                    'external_cancellation_reported' => 'Cancelamento externo solicitado/obtido — aguardar confirmação fiscal',
                    'keep_document' => 'Manter a nota — encerrar a pendência administrativa',
                ])->default('follow_up'),
                Textarea::make('notes')->label('Justificativa / procedimento adotado')->required()->minLength(15)->maxLength(5000)->rows(4),
                TextInput::make('reference')->label('Protocolo / referência externa')->maxLength(255),
                Select::make('evidence_attachment_id')->label('Comprovante já anexado à nota')->native(false)
                    ->default(fn (FiscalDocument $record) => $record->fiscalIssues()->latest('id')->value('evidence_attachment_id'))
                    ->options(fn (FiscalDocument $record): array => $record->attachments()->where('company_id', $record->company_id)->pluck('original_name', 'id')->all()),
                FileUpload::make('evidence')->label('Anexar novo comprovante')->storeFiles(false)->maxSize(10240)
                    ->acceptedFileTypes(['application/pdf', 'application/xml', 'text/xml', 'text/plain', 'image/jpeg', 'image/png'])
                    ->helperText('PDF, XML, TXT, JPG ou PNG, até 10 MB. O arquivo ficará nos anexos protegidos da nota.'),
            ])
            ->action(function (FiscalDocument $record, array $data): void {
                abort_unless((int) $record->company_id === (int) Filament::getTenant()?->id, 403);
                app(FiscalDocumentIssueService::class)->manage($record, Filament::auth()->user(), $data, $data['evidence'] ?? null);
                Notification::make()->success()->title('Tratamento fiscal registrado')->body('A situação fiscal da nota permanece condicionada ao retorno do órgão emissor.')->send();
            });
    }
}
