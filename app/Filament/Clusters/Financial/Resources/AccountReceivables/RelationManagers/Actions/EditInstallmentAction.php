<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Models\AccountReceivableInstallment;
use App\Models\FinancialCategory;
use App\Services\AccountReceivable\AccountReceivableService;
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
                Select::make('financial_category_id')
                    ->label('Categoria Financeira')
                    ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'receivable'))
                    ->searchable()
                    ->preload()
                    ->native(false),
                Textarea::make('notes')
                    ->label('Observacoes')
                    ->rows(3),
            ])
            ->fillForm(fn (AccountReceivableInstallment $record): array => [
                'due_date' => $record->due_date?->format('Y-m-d'),
                'financial_category_id' => $record->financial_category_id,
                'notes' => $record->notes,
            ])
            ->action(function (AccountReceivableInstallment $record, array $data): void {
                $service = app(AccountReceivableService::class);
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
