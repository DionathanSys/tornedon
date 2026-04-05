<?php

namespace App\Filament\Clusters\Settings\Resources\EmailDispatches\Tables;

use App\Enum\Email\DocumentNotificationType;
use App\Enum\Email\EmailDispatchStatus;
use App\Models\EmailDispatch;
use App\Services\Email\DocumentNotificationService;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmailDispatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('document_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
                TextColumn::make('document_id')
                    ->label('Documento')
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Evento')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state)
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('attempts')
                    ->label('Tentativas')
                    ->sortable(),
                TextColumn::make('provider_message_id')
                    ->label('Provider Msg ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_message')
                    ->label('Erro')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sent_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(EmailDispatchStatus::toSelectArray())
                    ->native(false)
                    ->multiple(),
                SelectFilter::make('document_type')
                    ->label('Tipo')
                    ->options(DocumentNotificationType::toSelectArray())
                    ->native(false)
                    ->multiple(),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Detalhes')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (EmailDispatch $record): string => "Detalhes do envio #{$record->id}")
                    ->modalSubmitAction(false)
                    ->infolist([
                        TextEntry::make('document_type')
                            ->label('Tipo de documento')
                            ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('document_id')
                            ->label('Documento relacionado'),
                        TextEntry::make('event')
                            ->label('Evento')
                            ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
                        TextEntry::make('status')
                            ->label('Status')
                            ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
                        TextEntry::make('to')
                            ->label('Destinatários (TO)')
                            ->formatStateUsing(fn ($state) => is_array($state) ? implode('; ', $state) : '-'),
                        TextEntry::make('cc')
                            ->label('Destinatários (CC)')
                            ->formatStateUsing(fn ($state) => is_array($state) ? implode('; ', $state) : '-'),
                        TextEntry::make('bcc')
                            ->label('Destinatários (BCC)')
                            ->formatStateUsing(fn ($state) => is_array($state) ? implode('; ', $state) : '-'),
                        TextEntry::make('subject')
                            ->label('Assunto'),
                        TextEntry::make('rendered_subject')
                            ->label('Assunto renderizado')
                            ->placeholder('-'),
                        TextEntry::make('rendered_body')
                            ->label('Corpo renderizado')
                            ->html()
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('provider')
                            ->label('Provider')
                            ->placeholder('-'),
                        TextEntry::make('attempts')
                            ->label('Tentativas'),
                        TextEntry::make('created_at')
                            ->label('Criado em')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('sent_at')
                            ->label('Enviado em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('last_error_at')
                            ->label('Última falha em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-'),
                        TextEntry::make('error_message')
                            ->label('Mensagem de erro')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        KeyValueEntry::make('provider_payload')
                            ->label('Payload do provider')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        KeyValueEntry::make('attachments_manifest')
                            ->label('Manifesto de anexos')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('attachments_hash')
                            ->label('Hash dos anexos')
                            ->placeholder('-'),
                    ]),
                Action::make('retry')
                    ->label('Reenviar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(function (EmailDispatch $record): bool {
                        return in_array(
                            $record->status?->value ?? (string) $record->status,
                            [
                                EmailDispatchStatus::FAILED->value,
                                EmailDispatchStatus::DEAD_LETTER->value,
                                EmailDispatchStatus::CANCELLED->value,
                            ],
                            true
                        );
                    })
                    ->requiresConfirmation()
                    ->action(function (EmailDispatch $record): void {
                        app(DocumentNotificationService::class)->requeue($record);

                        Notification::make()
                            ->title('Reenvio enfileirado')
                            ->success()
                            ->send();
                    }),
                Action::make('delete_dispatch')
                    ->label('Excluir')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => (bool) Auth::user()?->is_admin)
                    ->requiresConfirmation()
                    ->modalHeading('Excluir registro de envio')
                    ->modalDescription('Esta ação remove permanentemente o dispatch e anexos locais relacionados.')
                    ->action(function (EmailDispatch $record): void {
                        foreach (($record->attachments_manifest ?? []) as $attachment) {
                            $path = data_get($attachment, 'path');
                            if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
                                Storage::disk('local')->delete($path);
                            }
                        }

                        Log::warning('EmailDispatch excluído manualmente por administrador', [
                            'email_dispatch_id' => $record->id,
                            'company_id' => $record->company_id,
                            'deleted_by' => Auth::id(),
                        ]);

                        $record->delete();

                        Notification::make()
                            ->title('Registro de envio excluído')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
