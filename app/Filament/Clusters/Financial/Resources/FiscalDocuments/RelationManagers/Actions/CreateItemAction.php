<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfe;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateItemAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Adicionar Item')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->modalHeading('Adicionar Item à Nota de Entrada')
            ->schema(SchemaFormItemsNfe::make())
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $fiscalDocument = $livewire->getOwnerRecord();

                $data['fiscal_document_id'] = $fiscalDocument->id;

                Log::debug('Criando item de nota de entrada (Financial) via RelationManager', [
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
