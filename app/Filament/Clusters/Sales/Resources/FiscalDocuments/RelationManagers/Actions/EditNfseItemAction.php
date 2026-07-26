<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfse;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditNfseItemAction
{
    public static function make(): EditAction
    {
        return EditAction::make('editNfseItem')
            ->label('Editar')
            ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->isNfse()
                && ! $livewire->getOwnerRecord()->isNfseSent()
            )
            ->schema(fn (RelationManager $livewire): array => SchemaFormItemsNfse::make(
                disableQuantity: filled($livewire->getOwnerRecord()->invoice_id),
            ))
            ->fillForm(function (array $data, FiscalDocumentItem $record): array {
                $data['service_id'] = $record->service_id;
                $data['service_lookup_id'] = $record->service_id;
                $data['service_lookup_modal'] = $record->service_id;
                $data['service_code_lookup'] = $record->service?->service_code;
                $data['service_name_lookup'] = $record->service?->name;
                $data['description'] = $record->description;
                $data['municipal_tax_code'] = $record->municipal_tax_code;
                $data['nbs_code'] = $record->nbs_code;
                $data['cnae_code'] = $record->cnae_code;
                $data['iss_rate'] = $record->iss_rate;
                $data['iss_exigibility'] = $record->iss_exigibility;
                $data['iss_withheld'] = $record->iss_withheld;
                $data['additional_information'] = $record->additional_information;

                return $data;
            })
            ->using(function (FiscalDocumentItem $record, array $data): ?Model {
                Log::debug('Atualizando item NFS-e via RelationManager', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data'    => $data,
                ]);

                // Garante que iss_exigibility seja sempre string
                if (isset($data['iss_exigibility']) && ! is_null($data['iss_exigibility'])) {
                    $data['iss_exigibility'] = (string) $data['iss_exigibility'];
                }

                if (filled($record->fiscalDocument?->invoice_id)) {
                    $data['quantity'] = $record->quantity;
                }

                $service = new FiscalDocumentItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessage());
                return $item;
            })
            ->successNotification(null);
    }
}
