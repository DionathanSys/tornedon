<?php

namespace App\Filament\Shared\Actions;

use App\Models\Company;
use App\Services\DataReplication\ReplicationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ReplicateToCompaniesAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Replicar para outras empresas')
            ->icon(Heroicon::ArrowUturnRight)
            ->color('warning')
            ->schema([
                Select::make('target_company_ids')
                    ->label('Empresas de destino')
                    ->options(function (Model $record) {
                        // Obter empresas às quais o usuário pode ter acesso
                        $currentUser = Auth::user();
                        $userCompanies = $currentUser->companies()->pluck('companies.id');

                        // Excluir a empresa atual se aplicável
                        $currentCompanyId = $record->company_id ?? $currentUser->current_company_id;

                        return Company::whereIn('id', $userCompanies)
                            ->where('id', '!=', $currentCompanyId)
                            ->pluck('name', 'id');
                    })
                    ->required()
                    ->minItems(1),
            ])
            ->action(fn (Model $record, array $data) => $this->handleReplication($record, $data['target_company_ids']))
            ->modalHeading('Replicar Registro')
            ->modalSubmitActionLabel('Replicar')
            ->modalCancelActionLabel('Cancelar');
    }

    /**
     * Processa a replicação
     */
    protected function handleReplication(Model $record, array $targetCompanyIds): void
    {
        try {
            $service = app(ReplicationService::class);
            $result = $service->replicate($record, $targetCompanyIds);

            $successCount = count($result['successful']);
            $failureCount = count($result['failed']);

            if ($successCount > 0) {
                Notification::make()
                    ->title('Replicação concluída')
                    ->body("Registros replicados com sucesso para {$successCount} empresa(s).")
                    ->success()
                    ->send();
            }

            if ($failureCount > 0) {
                $failureDetails = implode(
                    "\n",
                    array_map(
                        fn ($f) => "Empresa ID {$f['company_id']}: {$f['error']}",
                        $result['failed']
                    )
                );

                Notification::make()
                    ->title('Replicação com erros')
                    ->body("Falha em {$failureCount} empresa(s):\n{$failureDetails}")
                    ->warning()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erro na replicação')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
