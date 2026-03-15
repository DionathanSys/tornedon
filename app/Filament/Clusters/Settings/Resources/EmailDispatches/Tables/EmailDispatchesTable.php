<?php

namespace App\Filament\Clusters\Settings\Resources\EmailDispatches\Tables;

use App\Enum\Email\DocumentNotificationType;
use App\Enum\Email\EmailDispatchStatus;
use App\Models\EmailDispatch;
use App\Services\Email\DocumentNotificationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            ])
            ->toolbarActions([]);
    }
}

