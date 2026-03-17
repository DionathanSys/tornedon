<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfse;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateNfseItemAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make('createNfseItem')
            ->label('Adicionar Serviço')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->isNfse()
                && ! $livewire->getOwnerRecord()->nfseSent()
            )
            ->modalHeading('Adicionar Serviço à NFS-e')
            ->schema(SchemaFormItemsNfse::make())
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $fiscalDocument = $livewire->getOwnerRecord();
                $data['fiscal_document_id'] = $fiscalDocument->id;
                $data['unit_of_measure'] = 'UN';

                // Garante que iss_exigibility seja sempre string
                if (isset($data['iss_exigibility']) && ! is_null($data['iss_exigibility'])) {
                    $data['iss_exigibility'] = (string) $data['iss_exigibility'];
                }

                Log::debug('Criando item NFS-e via RelationManager', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'data'               => $data,
                ]);

                $service = new FiscalDocumentItemService();
                $item = $service->create($data, Auth::id());

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
