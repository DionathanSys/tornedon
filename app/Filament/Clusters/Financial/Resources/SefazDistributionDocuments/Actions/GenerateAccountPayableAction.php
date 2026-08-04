<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\GenerateSefazDistributionPayableAction;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;

class GenerateAccountPayableAction
{
    public static function make(): Action
    {
        return Action::make('generateAccountPayable')
            ->label('Gerar conta a pagar')
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->modalWidth('lg')
            ->modalHeading('Gerar conta a pagar pelo DF-e')
            ->modalDescription('O XML será usado apenas para criar o contas a pagar. A nota de entrada poderá ser importada depois e reaproveitará este financeiro.')
            ->visible(fn (SefazDistributionDocument $record): bool => $record->full_xml_available
                && $record->account_payable_id === null
                && $record->partner_id !== null
                && $record->import_status !== ImportStatus::IGNORED)
            ->schema(fn (SefazDistributionDocument $record): array => self::formSchema($record))
            ->action(function (Action $action, SefazDistributionDocument $record, array $data): void {
                try {
                    $payable = app(GenerateSefazDistributionPayableAction::class)
                        ->execute($record, $data, (int) Auth::id());

                    Notification::make()
                        ->title('Conta a pagar gerada')
                        ->body("Conta a pagar #{$payable->id} gerada a partir do DF-e.")
                        ->success()
                        ->send();
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Falha ao gerar conta a pagar')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    private static function formSchema(SefazDistributionDocument $record): array
    {
        $baseDate = $record->issued_at ?? now();
        $totalFormatted = $record->total_amount !== null
            ? 'R$ '.number_format((float) $record->total_amount, 2, ',', '.')
            : 'valor do XML';

        return [
            Select::make('payment_method')
                ->label('Método de pagamento')
                ->options(PaymentMethod::toSelectArray())
                ->default(PaymentMethod::BANK_SLIP->value)
                ->native(false)
                ->required()
                ->columnSpanFull(),

            Select::make('payment_condition')
                ->label('Condição de pagamento')
                ->options(Condition::toGroupedSelectArray())
                ->default(Condition::CASH->value)
                ->native(false)
                ->live()
                ->required()
                ->afterStateUpdated(function (?string $state, Set $set) use ($baseDate): void {
                    if (! $state) {
                        return;
                    }

                    $set('due_date', Carbon::parse($baseDate)->addDays(Condition::from($state)->days())->format('Y-m-d'));
                })
                ->columnSpanFull(),

            DatePicker::make('due_date')
                ->label('Primeiro vencimento')
                ->displayFormat('d/m/Y')
                ->default(Carbon::parse($baseDate)->format('Y-m-d'))
                ->required()
                ->helperText('As demais parcelas serão calculadas a partir desta data, quando houver parcelamento.')
                ->columnSpanFull(),

            TextInput::make('description')
                ->label('Descrição')
                ->placeholder("DF-e NF #{$record->document_number}")
                ->helperText("Valor base: {$totalFormatted}")
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }
}
