<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\Pages;

use App\Filament\Clusters\Financial\Resources\CommitmentImports\CommitmentImportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCommitmentImport extends ViewRecord
{
    protected static string $resource = CommitmentImportResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('refresh')->label('Atualizar progresso')->icon('heroicon-o-arrow-path')->action(fn () => $this->record->refresh())];
    }
}
