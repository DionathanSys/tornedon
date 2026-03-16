<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Product\Origin;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfe;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class CreateItemAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Adicionar Item')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn(RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->nfeSent())
            ->modalHeading('Adicionar Item à Nota Fiscal')
            ->schema(SchemaFormItemsNfe::make())
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $fiscalDocument = $livewire->getOwnerRecord();

                $data['fiscal_document_id'] = $fiscalDocument->id;

                Log::debug('Criando item de nota fiscal via RelationManager', [
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
            ->after(function (?FiscalDocumentItem $record, RelationManager $livewire) {
                if (! $record) {
                    return;
                }

                $document = $livewire->getOwnerRecord();

                try {
                    $decisions = app(ResolveFiscalContextAction::class)
                        ->execute($document, [$record]);

                    if (! empty($decisions)) {
                        (new PersistFiscalSnapshotAction())->execute($document, $decisions);
                    }
                } catch (\Exception $e) {
                    Log::error('CreateFiscalDocument (Sales): Erro ao resolver contexto fiscal', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_id' => $document->id,
                        'error'              => $e->getMessage(),
                    ]);

                    notify::error(message: 'Documento criado, mas houve um erro ao calcular os impostos: ' . $e->getMessage());
                }

                Log::debug('Item de nota fiscal criado com sucesso', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                ]);

                notify::success(message: 'Impostos calculados com sucesso!');

            })
            ->successNotification(null);
    }
}
