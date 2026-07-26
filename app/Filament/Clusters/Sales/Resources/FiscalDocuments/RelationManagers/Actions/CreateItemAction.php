<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfe;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\FiscalDocument\Actions\RecalculateFiscalDocumentTaxTotalsAction;
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
            ->visible(fn (RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->isNfeSent())
            ->modalHeading('Adicionar Item à Nota Fiscal')
            ->schema(fn (RelationManager $livewire): array => SchemaFormItemsNfe::make(
                showTaxesTab: SchemaFormItemsNfe::shouldShowTaxesTab($livewire->getOwnerRecord())
            ))
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $fiscalDocument = $livewire->getOwnerRecord();

                if (filled($fiscalDocument->invoice_id)) {
                    notify::error(message: 'Itens de documentos fiscais originados por fatura não podem ser adicionados manualmente.');

                    return null;
                }

                $data['fiscal_document_id'] = $fiscalDocument->id;

                Log::debug('Criando item de nota fiscal via RelationManager', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'data' => $data,
                ]);

                $service = new FiscalDocumentItemService;
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

                $document = $livewire->getOwnerRecord()->fresh();
                $record->refresh();
                $hasManualTaxes = is_array($record->tax_data)
                    && is_array(data_get($record->tax_data, 'imposto'))
                    && data_get($record->tax_data, 'imposto') !== [];

                try {
                    if (! $hasManualTaxes) {
                        $decisions = app(ResolveFiscalContextAction::class)
                            ->execute($document, [$record]);

                        if (! empty($decisions)) {
                            (new PersistFiscalSnapshotAction)->execute($document, $decisions);
                        }
                    }

                    app(RecalculateFiscalDocumentTaxTotalsAction::class)->execute($document->fresh());
                } catch (\Exception $e) {
                    Log::error('CreateFiscalDocument (Sales): Erro ao resolver contexto fiscal', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'fiscal_document_id' => $document->id,
                        'error' => $e->getMessage(),
                    ]);

                    notify::error(message: 'Documento criado, mas houve um erro ao calcular os impostos: '.$e->getMessage());
                }

                Log::debug('Item de nota fiscal criado com sucesso', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'item_id' => $record->id,
                ]);

                notify::success(message: 'Impostos calculados com sucesso!');

            })
            ->successNotification(null);
    }
}
