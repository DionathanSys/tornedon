<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryPayableAction;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryStockAction;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            ->modalDescription('Confirme a entrada para gerar as movimentações de estoque. A conta a pagar pode ser gerada agora ou em um fechamento posterior.')
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
                        $userId = (int) Auth::id();
                        $stockProcessor = app(ProcessFiscalEntryStockAction::class);
                        $stockResult = $stockProcessor->execute($record, $userId);
                        $payableResult = [
                            'payables' => 0,
                            'errors' => [],
                        ];

                        if ((bool) ($data['generate_account_payable_now'] ?? false)) {
                            $payableProcessor = app(GenerateFiscalEntryPayableAction::class);
                            $payableResult = $payableProcessor->execute($record, $data, $userId);
                        }

                        $result = [
                            'stock_movements' => $stockResult['stock_movements'],
                            'payables' => $payableResult['payables'],
                            'errors' => [...$stockResult['errors'], ...$payableResult['errors']],
                        ];

                        // Marca a nota como confirmada
                        $record->update([
                            'confirmed'    => true,
                            'confirmed_at' => now(),
                            'confirmed_by' => $userId,
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
                            $msg = $result['payables'] > 0
                                ? "Nota confirmada! {$result['stock_movements']} movimentação(ões) de estoque e {$result['payables']} conta(s) a pagar geradas."
                                : "Nota confirmada! {$result['stock_movements']} movimentação(ões) de estoque processadas. Nenhuma conta a pagar foi gerada.";
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
            Toggle::make('generate_account_payable_now')
                ->label('Gerar conta a pagar agora?')
                ->default(false)
                ->live()
                ->columnSpanFull(),

            Select::make('payment_method')
                ->label('Método de Pagamento')
                ->options(PaymentMethod::toSelectArray())
                ->native(false)
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->columnSpanFull(),

            Select::make('payment_condition')
                ->label('Condição de Pagamento')
                ->options(Condition::toGroupedSelectArray())
                ->native(false)
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
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
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->columnSpanFull(),

            DatePicker::make('due_date')
                ->label('Primeiro Vencimento')
                ->displayFormat('d/m/Y')
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->helperText('Data base para o primeiro vencimento. As demais parcelas serão calculadas a partir desta data.')
                ->default(now()->format('Y-m-d'))
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->columnSpanFull(),

            TextInput::make('description')
                ->label('Descrição (opcional)')
                ->placeholder("Nota de Entrada #{$record->document_number}")
                ->helperText("Valor total da nota: {$totalFormatted}")
                ->maxLength(255)
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->columnSpanFull(),
        ];
    }
}
