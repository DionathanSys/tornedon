<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Filament\Forms\Components\PaymentConditionFields;
use App\Models\CostCenter;
use App\Models\FinancialAccount;
use App\Models\ResultCenter;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\GenerateSefazDistributionPayableAction;
use App\Services\Fiscal\Sefaz\SefazDistributionPayablePlan;
use App\Support\Financial\InstallmentSchedule;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GenerateAccountPayableAction
{
    public static function make(): Action
    {
        return Action::make('generateAccountPayable')
            ->label('Gerar conta a pagar')->icon('heroicon-o-banknotes')->color('warning')
            ->modalWidth('5xl')->modalHeading('Gerar conta a pagar pelo DF-e')
            ->modalDescription('Revise os valores e vencimentos. Uma conta já vinculada à nota será reaproveitada, preservando suas parcelas e baixas.')
            ->authorize(fn (SefazDistributionDocument $record): bool => PayableAccess::allows($record))
            ->visible(fn (SefazDistributionDocument $record): bool => $record->full_xml_available
                && $record->account_payable_id === null && $record->partner_id !== null && $record->import_status !== ImportStatus::IGNORED)
            ->fillForm(function (SefazDistributionDocument $record): array {
                $source = app(SefazDistributionPayablePlan::class)->source($record);
                $rows = $source['installments'];
                $amount = $rows ? round(array_sum(array_column($rows, 'due_amount')), 2) : $source['fiscal_amount'];

                return [
                    'payment_method' => $source['payment_method'],
                    'payment_condition' => Condition::CASH->value,
                    'due_date' => ($record->issued_at ?? now())->toDateString(),
                    'due_amount' => $amount,
                    'installments_source' => $rows ? 'xml' : 'condition',
                    'installments' => $rows ?: [['due_date' => ($record->issued_at ?? now())->toDateString(), 'due_amount' => $amount]],
                    'description' => "DF-e NF #{$record->document_number}",
                    'is_effective' => true,
                ];
            })
            ->schema(fn (SefazDistributionDocument $record): array => self::formSchema($record))
            ->action(function (Action $action, SefazDistributionDocument $record, array $data): void {
                try {
                    $payable = app(GenerateSefazDistributionPayableAction::class)->execute($record, $data, (int) Auth::id());
                    $record->refresh();
                    Notification::make()->title('Conta a pagar disponível')
                        ->body("Conta a pagar #{$payable->id} vinculada ao DF-e.")
                        ->actions([Action::make('open')->label('Abrir conta a pagar')
                            ->url(AccountPayableResource::getUrl('view', ['record' => $payable]))])
                        ->success()->send();
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    Notification::make()->title('Falha ao gerar conta a pagar')->body($exception->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    private static function formSchema(SefazDistributionDocument $record): array
    {
        $source = app(SefazDistributionPayablePlan::class)->source($record);
        $conditionMode = fn (Get $get): bool => $get('installments_source') === 'condition';

        return [
            Section::make('Dados financeiros')->columns(2)->schema([
                TextEntry::make('fiscal_amount')->label('Total fiscal do XML')
                    ->state($source['fiscal_amount'])->money('BRL'),
                TextEntry::make('billing_amount')->label('Valor líquido da fatura no XML')
                    ->state($source['billing_amount'])->money('BRL')->placeholder('Não informado'),
                TextInput::make('due_amount')->label('Valor total da conta')->numeric()->step('0.01')->minValue(0.01)->prefix('R$')->required()->live(onBlur: true),
                Select::make('payment_method')->label('Forma de pagamento')->options(PaymentMethod::toSelectArray())->native(false)->required(),
                Textarea::make('amount_difference_reason')->label('Justificativa da diferença em relação ao total fiscal')->maxLength(1000)
                    ->helperText('Obrigatória quando o valor da conta difere do total fiscal.')->columnSpanFull(),
                TextInput::make('description')->label('Descrição')->maxLength(255)->columnSpanFull(),
                SelectFinancialCategory::make('financial_category_id', 'payable')->label('Categoria financeira'),
                Select::make('cost_center_id')->label('Centro de custo')->options(fn () => CostCenter::optionsForCompany(Filament::getTenant()->id))->searchable()->native(false),
                Select::make('result_center_id')->label('Centro de resultado')->options(fn () => ResultCenter::optionsForCompany(Filament::getTenant()->id))->searchable()->native(false),
                TextInput::make('bank_slip_number')->label('Número do boleto')->maxLength(100),
            ]),
            Section::make('Parcelas')->schema([
                Select::make('installments_source')->label('Como montar as parcelas')->options([
                    'xml' => 'Usar duplicatas do XML', 'condition' => 'Calcular por condição de pagamento', 'manual' => 'Informar manualmente',
                ])->disableOptionWhen(fn (string $value): bool => $value === 'xml' && $source['installments'] === [])
                    ->required()->native(false)->live()->afterStateUpdated(function (string $state, Set $set) use ($record): void {
                        $set('installments', $state === 'xml' ? app(SefazDistributionPayablePlan::class)->source($record)['installments'] : []);
                    }),
                Section::make('Condição de pagamento')->visible($conditionMode)->columns(2)->schema([
                        Select::make('payment_condition')->label('Condição')->options(Condition::toGroupedSelectArray())->native(false)->live()->required()
                            ->afterStateUpdated(function (?string $state, Set $set) use ($record): void {
                                if ($state) {
                                    $set('due_date', Carbon::parse($record->issued_at ?? now())->addDays(Condition::from($state)->days())->toDateString());
                                    $set('installments', []);
                                }
                            }),
                        PaymentConditionFields::make()->afterStateUpdated(fn (Set $set) => $set('installments', [])),
                        DatePicker::make('due_date')->label('Primeiro vencimento')->displayFormat('d/m/Y')
                            ->visible(fn (Get $get): bool => $get('payment_condition') !== Condition::CUSTOM->value)->required()
                            ->afterStateUpdated(fn (Set $set) => $set('installments', []))->live(),
                        Select::make('installment_due_mode')->label('Regra de vencimento')->options([
                            InstallmentSchedule::CUSTOM_INTERVAL_DAYS => 'Intervalo da condição',
                            InstallmentSchedule::FIXED_DAY_OF_MONTH => 'Dia fixo do mês',
                    ])->placeholder('Intervalo da condição')->native(false)->live()
                            ->afterStateUpdated(fn (Set $set) => $set('installments', [])),
                        TextInput::make('installment_fixed_day')->label('Dia fixo')->integer()->minValue(1)->maxValue(31)
                            ->visible(fn (Get $get): bool => $get('installment_due_mode') === InstallmentSchedule::FIXED_DAY_OF_MONTH)
                            ->required()->live()->afterStateUpdated(fn (Set $set) => $set('installments', [])),
                        Actions::make([Action::make('calculateInstallments')->label('Calcular / atualizar parcelas')
                            ->action(function (Get $get, Set $set) use ($record): void {
                                $set('installments', app(SefazDistributionPayablePlan::class)->calculate($record, [
                                    'due_amount' => $get('due_amount'), 'due_date' => $get('due_date'),
                                    'payment_condition' => $get('payment_condition'), 'payment_condition_details' => $get('payment_condition_details'),
                                    'installment_due_mode' => $get('installment_due_mode'), 'installment_fixed_day' => $get('installment_fixed_day'),
                                ]));
                            })])->key('calculate_installment_actions')->columnSpanFull(),
                    ]),
                Repeater::make('installments')->label('Revise os valores e vencimentos')->minItems(1)->maxItems(999)->required()
                    ->addActionLabel('Adicionar parcela')
                    ->reorderable(false)->columns(4)->live()->schema([
                            DatePicker::make('due_date')->label('Vencimento')->displayFormat('d/m/Y')->required(),
                            TextInput::make('due_amount')->label('Valor')->numeric()->step('0.01')->minValue(0.01)->prefix('R$')->required(),
                            TextInput::make('description')->label('Descrição')->maxLength(255),
                            Textarea::make('notes')->label('Observações')->rows(1),
                    ]),
                TextEntry::make('installments_total')->label('Soma das parcelas')->money('BRL')
                    ->state(fn (Get $get): float => round(collect($get('installments') ?? [])->sum(fn ($row) => (float) ($row['due_amount'] ?? 0)), 2)),
            ]),
            Section::make('Pagamento automático')->collapsed()->collapsible()->columns(2)->schema([
                Toggle::make('is_effective')->label('Conta efetivada')->live(),
                Toggle::make('auto_register_payment_on_due_date')->label('Registrar pagamento no vencimento')->live()
                    ->visible(fn (Get $get): bool => (bool) $get('is_effective')),
                Select::make('auto_payment_financial_account_id')->label('Conta financeira do pagamento automático')
                    ->options(fn () => FinancialAccount::optionsForCompany(Filament::getTenant()->id))->searchable()->native(false)
                    ->required()->visible(fn (Get $get): bool => (bool) $get('is_effective') && (bool) $get('auto_register_payment_on_due_date')),
            ]),
        ];
    }
}
