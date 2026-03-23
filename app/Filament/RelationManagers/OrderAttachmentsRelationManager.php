<?php

namespace App\Filament\RelationManagers;

use App\Models\OrderAttachment;
use App\Notification\NotifyService as notify;
use App\Services\Attachments\OrderAttachmentStorageService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class OrderAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Anexos';

    protected static ?string $modelLabel = 'Anexo';

    protected static string|BackedEnum|null $icon = Heroicon::PaperClip;

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->getAttachmentFormComponents());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Anexos')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('mime_type')
                    ->label('Tipo')
                    ->toggleable(),
                TextColumn::make('size_bytes')
                    ->label('Tamanho')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : '-')
                    ->alignEnd(),
                TextColumn::make('uploadedBy.name')
                    ->label('Enviado por')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Novo Anexo')
                    ->icon(Heroicon::Plus)
                    ->schema($this->getAttachmentFormComponents())
                    ->using(function (array $data): ?OrderAttachment {
                        $service = app(OrderAttachmentStorageService::class);
                        $attachment = $service->create($this->getOwnerRecord(), $data, Auth::id());

                        if ($service->hasError()) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return null;
                        }

                        notify::success(message: $service->getMessageUser());

                        return $attachment;
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Baixar')
                    ->icon(Heroicon::ArrowDownTray)
                    ->url(fn (OrderAttachment $record): string => route('order-attachments.download', $record), shouldOpenInNewTab: true),
                EditAction::make()
                    ->schema($this->getAttachmentFormComponents(false))
                    ->using(function (OrderAttachment $record, array $data): ?OrderAttachment {
                        $service = app(OrderAttachmentStorageService::class);
                        $attachment = $service->update($record, $data, Auth::id());

                        if ($service->hasError()) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return null;
                        }

                        notify::success(message: $service->getMessageUser());

                        return $attachment;
                    }),
                DeleteAction::make()
                    ->using(function (OrderAttachment $record): bool {
                        $service = app(OrderAttachmentStorageService::class);
                        $deleted = $service->delete($record);

                        if ($service->hasError()) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return false;
                        }

                        notify::success(message: $service->getMessageUser());

                        return $deleted;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateDescription('Envie arquivos para esta ordem para que eles aparecam aqui.');
    }

    private function getAttachmentFormComponents(bool $isCreate = true): array
    {
        $service = app(OrderAttachmentStorageService::class);
        $ownerRecord = $this->getOwnerRecord();
        $disk = $service->defaultDisk();
        $directory = $service->directoryFor($ownerRecord);

        return [
            FileUpload::make('path')
                ->label('Arquivo')
                ->disk($disk)
                ->directory($directory)
                ->storeFileNamesIn('original_name')
                ->getUploadedFileNameForStorageUsing(
                    fn (TemporaryUploadedFile $file): string => $service->makeStoredFilename($file->getClientOriginalName())
                )
                ->helperText('Envie um arquivo por vez.')
                ->required($isCreate)
                ->columnSpanFull(),
        ];
    }
}
