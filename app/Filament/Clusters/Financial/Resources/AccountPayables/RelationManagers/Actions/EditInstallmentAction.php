<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Models\AccountPayableInstallment;
use App\Models\CostCenter;
use App\Models\ResultCenter;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

final class EditInstallmentAction
{
    public static function make(): Action
    {
        return Action::make('edit_installment')
            ->label('Editar parcela')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->schema([
                DatePicker::make('due_date')
                    ->label('Vencimento')
                    ->required(),
                DatePicker::make('competence_date')
                    ->label('Competência')
                    ->helperText('Data econômica usada nos relatórios por competência.'),
                SelectFinancialCategory::make('financial_category_id', 'payable')
                    ->label('Categoria Financeira')
                    ->placeholder('Selecione uma categoria'),
                Select::make('cost_center_id')
                    ->label('Centro de Custo')
                    ->options(fn (): array => CostCenter::optionsForCompany(Filament::getTenant()->id))
                    ->searchable()
                    ->preload()
                    ->native(false),
                Select::make('result_center_id')
                    ->label('Centro de Resultado')
                    ->options(fn (): array => ResultCenter::optionsForCompany(Filament::getTenant()->id))
                    ->searchable()
                    ->preload()
                    ->native(false),
                Textarea::make('description')
                    ->label('Descrição da Parcela')
                    ->rows(2)
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3),
            ])
            ->fillForm(fn (AccountPayableInstallment $record): array => [
                'due_date' => $record->due_date?->format('Y-m-d'),
                'competence_date' => $record->competence_date?->format('Y-m-d'),
                'financial_category_id' => $record->financial_category_id,
                'cost_center_id' => $record->cost_center_id,
                'result_center_id' => $record->result_center_id,
                'description' => $record->description,
                'notes' => $record->notes,
            ])
            ->action(function (AccountPayableInstallment $record, array $data): void {
                $service = app(AccountPayableService::class);
                $updated = $service->updateInstallment($record, $data);

                if ($service->hasError() || $updated === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao atualizar parcela.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Parcela atualizada com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
