<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages\Actions\GenerateWarrantyRemittanceFiscalDocumentAction;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Schemas\WarrantyClaimForm;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\WarrantyClaimResource;
use App\Notification\NotifyService as notify;
use App\Services\WarrantyClaim\WarrantyClaimService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditWarrantyClaim extends EditRecord
{
    protected static string $resource = WarrantyClaimResource::class;

    public function form(Schema $schema): Schema
    {
        return WarrantyClaimForm::configure($schema);
    }

    public function getSubheading(): ?string
    {
        return 'Garantia # '.$this->record->number;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(WarrantyClaimService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error('EditWarrantyClaim: erro ao atualizar garantia', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'warranty_claim_id' => $record->id,
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'error_code' => $service->getErrorCode(),
            ]);

            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            throw new \Exception($service->getMessage());
        }

        return $updated;
    }

    protected function getHeaderActions(): array
    {
        return [
            GenerateWarrantyRemittanceFiscalDocumentAction::make(),
            Action::make('openRemittanceFiscalDocument')
                ->label('Abrir NF de garantia')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->record->remittanceFiscalDocument !== null)
                ->url(fn (): ?string => $this->record->remittanceFiscalDocument
                    ? FiscalDocumentResource::getUrl('edit', ['record' => $this->record->remittanceFiscalDocument])
                    : null)
                ->openUrlInNewTab(),
            DeleteAction::make(),
        ];
    }
}
