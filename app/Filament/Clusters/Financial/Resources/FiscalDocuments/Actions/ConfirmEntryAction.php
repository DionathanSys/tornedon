<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Enum\FiscalDocument\Status;
use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\CompanyCreditCard;
use App\Models\FiscalDocument;
use App\Models\FinancialCategory;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryCardTransactionAction;
use App\Services\FiscalDocument\Actions\GenerateFiscalEntryPayableAction;
use App\Services\FiscalDocument\Actions\ProcessFiscalEntryStockAction;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
            ->label('Confirmar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalWidth('lg')
            ->modalHeading('Confirmar Documento Fiscal')
            ->modalDescription('Confirme a entrada para gerar as movimentações de estoque. O financeiro pode ser gerado agora como conta a pagar ou como lançamento de cartão corporativo.')
            ->visible(fn(FiscalDocument $record): bool => ! $record->confirmed)
            ->schema(fn(FiscalDocument $record): array => self::buildFormSchema($record))
            ->action(function (Action $action, FiscalDocument $record, array $data): void {
                // Bloqueia reprocessamento
                if ($record->accountPayables()->exists() || $record->confirmed) {
                    notify::error(title: 'Falha ao confirmar', message: 'Este documento fiscal já gerou movimentações financeiras.');
                    return;
                }

                try {
                    DB::transaction(function () use ($record, $data) {
                        $userId = (int) Auth::id();
                        $stockProcessor = app(ProcessFiscalEntryStockAction::class);
                        $stockResult = $stockProcessor->execute($record, $userId);
                        $payableResult = [
                            'payables' => 0,
                            'errors' => [],
                        ];
                        $cardResult = [
                            'transactions' => 0,
                            'errors' => [],
                        ];

                        if ((bool) ($data['generate_account_payable_now'] ?? false)) {
                            if (($data['payment_method'] ?? null) === PaymentMethod::CREDIT_CARD->value) {
                                $cardProcessor = app(GenerateFiscalEntryCardTransactionAction::class);
                                $cardResult = $cardProcessor->execute($record, $data, $userId);
                            } else {
                                $payableProcessor = app(GenerateFiscalEntryPayableAction::class);
                                $payableResult = $payableProcessor->execute($record, $data, $userId);
                            }
                        }

                        $result = [
                            'stock_movements' => $stockResult['stock_movements'],
                            'payables' => $payableResult['payables'],
                            'card_transactions' => $cardResult['transactions'],
                            'errors' => [...$stockResult['errors'], ...$payableResult['errors'], ...$cardResult['errors']],
                        ];

                        // Marca a nota como confirmada
                        $record->update([
                            'status'       => Status::CONFIRMED->value,
                            'pending'      => false,
                            'confirmed'    => true,
                            'confirmed_at' => now(),
                            'confirmed_by' => $userId,
                            'updated_by'   => $userId,
                        ]);

                        Log::info('ConfirmEntryAction: Processamento concluído', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'fiscal_document_id' => $record->id,
                            'stock_movements'    => $result['stock_movements'],
                            'payables'           => $result['payables'],
                            'card_transactions'  => $result['card_transactions'],
                            'errors'             => $result['errors'],
                        ]);

                        if (! empty($result['errors'])) {
                            $warningMsg = 'Nota confirmada com alertas: ' . implode('; ', $result['errors']);
                            notify::warning(message: $warningMsg);
                        } else {
                            if ($result['card_transactions'] > 0) {
                                $msg = "Nota confirmada! {$result['stock_movements']} movimentação(ões) de estoque e {$result['card_transactions']} lançamento(s) no cartão corporativo gerados.";
                            } elseif ($result['payables'] > 0) {
                                $msg = "Nota confirmada! {$result['stock_movements']} movimentação(ões) de estoque e {$result['payables']} conta(s) a pagar geradas.";
                            } else {
                                $msg = "Nota confirmada! {$result['stock_movements']} movimentação(ões) de estoque processadas. Nenhum lançamento financeiro foi gerado.";
                            }

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
                ->live()
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->columnSpanFull(),

            Select::make('company_credit_card_id')
                ->label('Cartão Corporativo')
                ->options(fn () => CompanyCreditCard::query()
                    ->where('company_id', $record->company_id)
                    ->where('active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->toArray())
                ->native(false)
                ->searchable()
                ->preload()
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) === PaymentMethod::CREDIT_CARD->value)
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) === PaymentMethod::CREDIT_CARD->value)
                ->helperText('Será usado para registrar as transações e compor a fatura do cartão.')
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
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) !== PaymentMethod::CREDIT_CARD->value)
                ->helperText('Data base para o primeiro vencimento. As demais parcelas serão calculadas a partir desta data.')
                ->default(now()->format('Y-m-d'))
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) !== PaymentMethod::CREDIT_CARD->value)
                ->columnSpanFull(),

            DatePicker::make('card_transaction_date')
                ->label('Data da Compra no Cartão')
                ->displayFormat('d/m/Y')
                ->default($record->movement_at?->format('Y-m-d') ?? $record->issued_at?->format('Y-m-d') ?? now()->format('Y-m-d'))
                ->required(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) === PaymentMethod::CREDIT_CARD->value)
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) === PaymentMethod::CREDIT_CARD->value)
                ->helperText('Usada para definir competência e fatura do cartão corporativo.')
                ->columnSpanFull(),

            TextInput::make('description')
                ->label('Descrição (opcional)')
                ->placeholder("Nota de Entrada #{$record->document_number}")
                ->helperText("Valor total da nota: {$totalFormatted}")
                ->maxLength(255)
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false))
                ->columnSpanFull(),

            Select::make('category_id')
                ->label('Categoria Financeira')
                ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'payable'))
                ->searchable()
                ->preload()
                ->native(false)
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) === PaymentMethod::CREDIT_CARD->value)
                ->columnSpanFull(),

            TextInput::make('cost_center_id')
                ->label('Centro de Custo (ID)')
                ->numeric()
                ->visible(fn (callable $get): bool => (bool) ($get('generate_account_payable_now') ?? false)
                    && ($get('payment_method') ?? null) === PaymentMethod::CREDIT_CARD->value)
                ->columnSpanFull(),
        ];
    }
}
