<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions;

use App\Enum\Commitment\ImportType;
use App\Models\CommitmentImport;
use App\Models\Company;
use App\Services\Commitments\ImportCommitmentFilesService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ImportCommitmentFilesAction
{
    public static function customerOptions(): array
    {
        return Company::findOrFail(Filament::getTenant()->id)->partners()
            ->where('partners.is_active', true)->wherePivot('is_active', true)->orderBy('name')->pluck('name', 'partners.id')->all();
    }

    public static function make(string $resource): Action
    {
        $type = $resource::importType();

        return Action::make('import_pdfs')->label($type === ImportType::COMMITMENTS ? 'Importar empenhos' : 'Importar relatório')
            ->icon('heroicon-o-arrow-up-tray')
            ->authorize('create', CommitmentImport::class)
            ->schema([
                Select::make('customer_id')->label('Cliente')->options(fn () => self::customerOptions())->searchable()->searchDebounce(1500)->required(),
                FileUpload::make('files')->label('PDFs')->multiple()->maxFiles($type === ImportType::PAYMENTS ? 1 : 20)
                    ->acceptedFileTypes(['application/pdf'])->maxSize(20480)->storeFiles(false)->required()
                    ->helperText('O fluxo deve estar habilitado em Configurar cliente. Cada arquivo será processado individualmente.'),
            ])
            ->action(function (array $data, $livewire) use ($type, $resource): void {
                $import = app(ImportCommitmentFilesService::class)->import(Filament::getTenant()->id, (int) $data['customer_id'], Auth::id(), $type, array_values($data['files']));
                $accepted = collect($import->summary)->where('status', 'accepted')->count();
                $duplicates = collect($import->summary)->where('status', 'duplicate')->count();
                $rejected = collect($import->summary)->where('status', 'rejected')->count();
                Notification::make()->title('Importação registrada')->body("Aceitos: {$accepted}. Duplicados: {$duplicates}. Rejeitados: {$rejected}.")->color($rejected ? 'warning' : 'success')->send();
                $livewire->redirect($resource::getUrl('view', ['record' => $import, 'tenant' => Filament::getTenant()]));
            });
    }
}
