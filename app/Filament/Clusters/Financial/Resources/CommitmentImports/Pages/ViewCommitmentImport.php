<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages;

use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCommitmentImport extends ViewRecord
{
    protected static string $resource = CommitmentImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Atualizar progresso')->icon('heroicon-o-arrow-path')->action(fn () => $this->record->refresh()),
            Action::make('commitments')->label('Conciliar empenhos')->visible(fn () => $this->record->type->value === 'commitments')
                ->url(fn () => CustomerCommitmentResource::getUrl('index', ['filters' => ['import_id' => ['value' => $this->record->id]]])),
        ];
    }
}
