<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Actions;

use App\Enum\FiscalDocument\PurchaseReturnSettlementMode;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\ProcessAuthorizedPurchaseReturnAction;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ConfigurePurchaseReturnSettlementAction
{
    public static function make(): Action
    {
        return Action::make('configurePurchaseReturnSettlement')
            ->label('Configurar devolução')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color('warning')
            ->visible(fn (FiscalDocument $record): bool => $record->isPurchaseReturn())
            ->fillForm(fn (FiscalDocument $record): array => [
                'mode' => data_get($record->return_financial_data, 'mode'),
                'replacement_due_date' => data_get($record->return_financial_data, 'replacement_due_date'),
                'replacement_description' => data_get($record->return_financial_data, 'replacement_description'),
                'notes' => data_get($record->return_financial_data, 'notes'),
            ])
            ->schema([
                Select::make('mode')
                    ->label('Tratamento financeiro')
                    ->options(PurchaseReturnSettlementMode::toSelectArray())
                    ->native(false)
                    ->required()
                    ->live(),
                DatePicker::make('replacement_due_date')
                    ->label('Vencimento do novo boleto')
                    ->displayFormat('d/m/Y')
                    ->required(fn (callable $get): bool => ($get('mode') ?? null) === PurchaseReturnSettlementMode::REPLACE_PAYABLE->value)
                    ->visible(fn (callable $get): bool => ($get('mode') ?? null) === PurchaseReturnSettlementMode::REPLACE_PAYABLE->value),
                Textarea::make('replacement_description')
                    ->label('Descrição do novo boleto')
                    ->rows(2)
                    ->visible(fn (callable $get): bool => ($get('mode') ?? null) === PurchaseReturnSettlementMode::REPLACE_PAYABLE->value),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3),
            ])
            ->action(function (FiscalDocument $record, array $data): void {
                $record->update([
                    'return_financial_data' => [
                        'mode' => $data['mode'],
                        'replacement_due_date' => $data['replacement_due_date'] ?? null,
                        'replacement_description' => $data['replacement_description'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'configured_at' => now()->toAtomString(),
                        'configured_by' => Auth::id(),
                    ],
                    'return_financial_processed_at' => null,
                    'return_financial_processed_by' => null,
                ]);

                if (! $record->isNfeAuthorized()) {
                    notify::success('Configuração da devolução salva. O processamento financeiro ocorrerá após a autorização da NF-e.');
                    return;
                }

                $processor = app(ProcessAuthorizedPurchaseReturnAction::class);
                $result = $processor->execute($record->fresh(), (int) Auth::id());

                if ($result['errors'] !== []) {
                    notify::warning(message: 'Configuração salva com alertas: ' . implode('; ', $result['errors']));
                    return;
                }

                if ($result['warnings'] !== []) {
                    notify::warning(message: 'Devolução processada com alertas: ' . implode('; ', $result['warnings']));
                    return;
                }

                notify::success('Configuração salva e devolução processada com sucesso.');
            });
    }
}
