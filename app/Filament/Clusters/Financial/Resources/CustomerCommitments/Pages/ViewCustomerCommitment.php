<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments\Pages;

use App\Filament\Clusters\Financial\Resources\CustomerCommitments\Actions\CommitmentReviewActions;
use App\Filament\Clusters\Financial\Resources\CustomerCommitments\CustomerCommitmentResource;
use App\Services\Commitments\CommitmentMatchEngine;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerCommitment extends ViewRecord
{
    protected static string $resource = CustomerCommitmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CommitmentReviewActions::refresh(), CommitmentReviewActions::approve(), CommitmentReviewActions::correct(),
            CommitmentReviewActions::unlink(), CommitmentReviewActions::ignore(), CommitmentReviewActions::ignore(reopen: true),
            Action::make('original_pdf')->label('Consultar PDF original')->openUrlInNewTab()
                ->visible(fn () => $this->record->importFile->currentAttachments()->exists())
                ->url(fn () => $this->record->importFile->currentAttachments()->first()?->preview_url),
        ];
    }

    public function matchResult(): array
    {
        return app(CommitmentMatchEngine::class)->analyze($this->getRecord());
    }
}
