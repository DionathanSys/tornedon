<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use App\Models\FinancialAccount;
use App\Services\Financial\BankStatement\ImportBankStatementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ImportOfxAction
{
    public static function make(): Action
    {
        return Action::make('import_ofx')
            ->label('Importar OFX')
            ->icon('heroicon-o-arrow-up-tray')
            ->schema(fn (Schema $schema) => $schema
                ->components([
                    Select::make('financial_account_id')
                        ->label('Conta Financeira')
                        ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                        ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),
                    FileUpload::make('file')
                        ->label('Arquivo OFX')
                        ->storeFiles(false)
                        ->helperText('Selecione um arquivo com extensão .ofx.')
                        ->required(),
                ]))
            ->action(function (array $data): void {
                /** @var TemporaryUploadedFile|null $file */
                $file = $data['file'] ?? null;

                if (! $file instanceof TemporaryUploadedFile) {
                    Notification::make()
                        ->title('Selecione um arquivo OFX válido.')
                        ->danger()
                        ->send();

                    return;
                }

                $originalName = (string) $file->getClientOriginalName();
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if ($extension !== 'ofx') {
                    Notification::make()
                        ->title('Selecione um arquivo OFX com extensão .ofx.')
                        ->danger()
                        ->send();

                    return;
                }

                $contents = file_get_contents($file->getRealPath());

                if (! is_string($contents) || ! str_contains(strtoupper($contents), '<OFX>')) {
                    Notification::make()
                        ->title('O arquivo enviado não possui um conteúdo OFX válido.')
                        ->danger()
                        ->send();

                    return;
                }

                $service = app(ImportBankStatementService::class);
                $import = $service->importFromString(
                    Filament::getTenant()->id,
                    (int) $data['financial_account_id'],
                    $contents,
                    $originalName,
                    Auth::id(),
                );

                if ($service->hasError() || $import === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao importar OFX.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Extrato OFX importado com sucesso.')
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label('Abrir conciliação')
                            ->url(BankStatementImportResource::getUrl('reconcile', [
                                'record' => $import,
                                'tenant' => Filament::getTenant(),
                            ])),
                    ])
                    ->send();
            });
    }
}
