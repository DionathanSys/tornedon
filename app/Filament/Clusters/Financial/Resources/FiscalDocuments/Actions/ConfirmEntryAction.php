<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryAction;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ConfirmEntryAction
{
    public static function make(): Action
    {
        return Action::make('confirmEntry')
            ->label('Confirmar Entrada')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalWidth('lg')
            ->modalHeading('Confirmar Nota de Entrada')
            ->modalDescription('Defina o método e a condição de pagamento. As contas a pagar e as movimentações de estoque serão geradas automaticamente.')
            ->visible(fn(FiscalDocument $record): bool => ! $record->confirmed)
            ->schema(fn(FiscalDocument $record): array => self::buildFormSchema($record))
            ->action(function (Action $action, FiscalDocument $record, array $data): void {
                // Bloqueia reprocessamento
                if ($record->accountPayables()->exists() || $record->confirmed) {
                    notify::error(message: 'Esta nota já foi confirmada e processada. Nenhuma alteração foi realizada.');
                    return;
                }

                Log::debug('ConfirmEntryAction: Iniciando processamento da nota de entrada', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $record->id,
                    'data'               => $data,
                    'user_id'            => Auth::id(),
                ]);

                try {
                    DB::transaction(function () use ($record, $data) {
                        $processor = app(ProcessFiscalEntryAction::class);
                        $result = $processor->execute($record, $data, Auth::id());

                        // Marca a nota como confirmada
                        $record->update([
                            'confirmed'    => true,
                            'confirmed_at' => now(),
                            'confirmed_by' => Auth::id(),
                        ]);

                        Log::info('ConfirmEntryAction: Processamento concluído', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'fiscal_document_id' => $record->id,
                            'stock_movements'    => $result['stock_movements'],
                            'payables'           => $result['payables'],
                            'errors'             => $result['errors'],
                        ]);

                        if (! empty($result['errors'])) {
                            $warningMsg = 'Nota confirmada com alertas: ' . implode('; ', $result['errors']);
                            notify::warning(message: $warningMsg);
                        } else {
                            $msg = "Nota confirmada! {$result['stock_movements']} movimentação(ões) de estoque e {$result['payables']} conta(s) a pagar geradas.";
                            notify::success($msg);
                        }
                    });
                } catch (\Exception $e) {
                    Log::error('ConfirmEntryAction: Erro ao processar nota de entrada', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_id' => $record->id,
                        'exception'          => $e->getMessage(),
                        'trace'              => $e->getTraceAsString(),
                    ]);

                    notify::error(message: 'Erro ao confirmar nota: ' . $e->getMessage());
                    $action->halt();
                }
            });
    }

    private static function buildFormSchema(FiscalDocument $record): array
    {
        // Pré-calcula o total da nota a partir dos itens
        $record->loadMissing('items');
        $totalAmount = $record->items->sum(fn($i) => (float) $i->total_price);
        $totalFormatted = 'R$ ' . number_format($totalAmount, 2, ',', '.');

        return [
            Select::make('payment_method')
                ->label('Método de Pagamento')
                ->options(PaymentMethod::toSelectArray())
                ->native(false)
                ->required()
                ->columnSpanFull(),

            Select::make('payment_condition')
                ->label('Condição de Pagamento')
                ->options(Condition::toGroupedSelectArray())
                ->native(false)
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, \Filament\Schemas\Components\Utilities\Set $set) use ($record): void {
                    if (! $state) {
                        return;
                    }
                    $condition = Condition::from($state);
                    $baseDate  = $record->issued_at ?? now();
                    $days      = $condition->days();
                    $set('due_date', Carbon::parse($baseDate)->addDays($days)->format('Y-m-d'));
                })
                ->columnSpanFull(),

            DatePicker::make('due_date')
                ->label('Primeiro Vencimento')
                ->displayFormat('d/m/Y')
                ->required()
                ->helperText('Data base para o primeiro vencimento. As demais parcelas serão calculadas a partir desta data.')
                ->default(now()->format('Y-m-d'))
                ->columnSpanFull(),

            TextInput::make('description')
                ->label('Descrição (opcional)')
                ->placeholder("Nota de Entrada #{$record->document_number}")
                ->helperText("Valor total da nota: {$totalFormatted}")
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }
}
