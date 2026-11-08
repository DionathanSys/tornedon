<?php

namespace App\Filament\Bpo\Resources\FiscalDocuments\Pages;

use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Concerns\SecuresBpoActions;
use App\Filament\Bpo\Resources\OutgoingFiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Actions\ConfigurePurchaseReturnSettlementAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Support\Actions\FiscalDocumentRecordActions;
use App\Models\FiscalDocument;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Database\Eloquent\Model;

class EditOutgoingDocument extends EditFiscalDocument
{
    use HasWorkbenchHeader;
    use SecuresBpoActions;

    protected static string $resource = OutgoingFiscalDocumentResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-record';

    public static function editable(FiscalDocument $record): bool
    {
        return ! $record->isOperationalHistory() && ! $record->confirmed && ! $record->canceled
            && ! ($record->isNfse() ? $record->blocksNfseResubmission() : $record->blocksNfeResubmission());
    }

    protected function getFormActions(): array
    {
        return static::editable($this->getRecord()) ? parent::getFormActions() : [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(static::editable($record->fresh()), 403);
        $data['company_id'] = $record->company_id;
        $data['operation_type'] = $record->operation_type->value;

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getHeaderActions(): array
    {
        $actions = [Action::make('back')->label('Voltar aos documentos')->url(static::getResource()::getUrl())];
        foreach (FiscalDocumentRecordActions::make() as $action) {
            $ability = match ($action->getName()) {
                'emitir', 'emitir_nfse' => 'issue', 'cancelar_nfe', 'cancelar_nfse' => 'cancel',
                'consultar', 'consultar_nfse', 'corrigir_nfe', 'tratar_pendencia_fiscal' => 'update', default => 'view',
            };
            if (in_array($action->getName(), ['preview', 'preview_nfse'], true)) {
                $action->modalSubmitAction(false)->action(null);
            }
            $actions[] = $this->secureBpoAction($action, 'fiscal_documents.'.$ability)->after(fn () => $this->refreshFiscalDocumentState());
        }
        $actions[] = $this->secureBpoAction(ConfigurePurchaseReturnSettlementAction::make(), 'fiscal_documents.update');

        return [$this->getSaveFormAction()->formId('form')->label('Salvar alterações')->visible(fn () => static::editable($this->getRecord())),
            Action::make('refresh_state')->label('Atualizar situação')->icon('heroicon-o-arrow-path')->action(fn () => $this->refreshFiscalDocumentState()),
            ActionGroup::make($actions)->label('Ações fiscais')->button()];
    }
}
