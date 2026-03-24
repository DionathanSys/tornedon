<?php

namespace App\Filament\RelationManagers;

use App\Enums\AttachmentType;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Notifications\Notification;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Anexos';

    protected static ?string $modelLabel = 'Anexo';

    public function form(Schema $schema): Schema
    {
        $owner = $this->getOwnerRecord();
        // Option to filter allowed types if the trait provides the method
        $allowedTypes = method_exists($owner, 'allowedAttachmentTypes') 
            ? $owner->allowedAttachmentTypes() 
            : array_column(AttachmentType::cases(), 'value');

        // Create options array
        $typeOptions = collect(AttachmentType::cases())
            ->filter(fn ($case) => in_array($case->value, $allowedTypes))
            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
            ->toArray();

        return $schema
            ->components([
                Select::make('type')
                    ->label('Tipo')
                    ->options($typeOptions)
                    ->required()
                    ->default(AttachmentType::GENERIC->value),
                    
                FileUpload::make('file')
                    ->label('Arquivo(s)')
                    ->storeFiles(false)
                    ->required()
                    ->multiple()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->current())
            ->columns([
                TextColumn::make('original_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?AttachmentType $state): string => $state ? $state->getLabel() : '-'),
                TextColumn::make('version')
                    ->label('Versão')
                    ->alignCenter(),
                TextColumn::make('size_bytes')
                    ->label('Tamanho')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : '-')
                    ->alignEnd(),
                TextColumn::make('uploader.name')
                    ->label('Enviado por')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data, string $model): ?Attachment {
                        $service = app(AttachmentService::class);
                        $owner = $this->getOwnerRecord();
                        $type = $data['type'];
                        
                        $files = is_array($data['file']) ? $data['file'] : [$data['file']];
                        $lastAttachment = null;
                        
                        foreach ($files as $file) {
                            if ($file instanceof TemporaryUploadedFile) {
                                $lastAttachment = $service->upload($owner, $file, $type);
                            }
                        }
                        
                        // Action expects the created model instance
                        return $lastAttachment;
                    })
                    ->successNotificationTitle('Anexo(s) enviado(s) com sucesso'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Attachment $record): string => $record->url, shouldOpenInNewTab: true),
                DeleteAction::make()
                    ->using(function (Attachment $record) {
                        $service = app(AttachmentService::class);
                        $service->delete($record);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
