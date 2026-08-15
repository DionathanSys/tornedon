<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\FinancialCategory;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ReconcileBankStatementImport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = BankStatementImportResource::class;

    protected string $view = 'filament.clusters.financial.resources.bank-statement-imports.pages.reconcile-bank-statement-import';

    protected static ?string $title = 'Conciliação';

    public string $statusFilter = 'pending';

    public string $search = '';

    public ?int $selectedLineId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->loadMissing('financialAccount');
    }

    public function getHeading(): string|Htmlable
    {
        return 'Conciliação do extrato';
    }

    public function getSubheading(): ?string
    {
        $account = $this->getRecord()->financialAccount?->name ?? 'Conta financeira';
        $reference = $this->getRecord()->reference ?: $this->getRecord()->file_name ?: 'Importação OFX';

        return sprintf('%s • %s', $account, $reference);
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportOfxAction::make(),
            Action::make('back_to_view')
                ->label('Visão padrão')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url($this->getResourceUrl('view')),
            Action::make('back_to_list')
                ->label('Voltar')
                ->icon('heroicon-o-arrow-left')
                ->url($this->getResourceUrl('index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }

    public function getLinesProperty(): Collection
    {
        return BankStatementLine::query()
            ->with('cashMovement')
            ->where('bank_statement_import_id', $this->getRecord()->getKey())
            ->where('company_id', $this->getRecord()->company_id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    public function getFilteredLinesProperty(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return $this->lines
            ->when($this->statusFilter !== 'all', fn (Collection $lines) => $lines->where('reconciliation_status.value', $this->statusFilter))
            ->when($term !== '', function (Collection $lines) use ($term): Collection {
                return $lines->filter(function (BankStatementLine $line) use ($term): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $line->description,
                        $line->document_number,
                        $line->cashMovement?->description,
                        data_get($line->metadata, 'suggestions.0.label'),
                    ])));

                    return str_contains($haystack, $term);
                });
            })
            ->values();
    }

    public function getStatusCountsProperty(): array
    {
        return [
            'all' => $this->lines->count(),
            'pending' => $this->lines->where('reconciliation_status.value', 'pending')->count(),
            'reconciled' => $this->lines->where('reconciliation_status.value', 'reconciled')->count(),
            'ignored' => $this->lines->where('reconciliation_status.value', 'ignored')->count(),
        ];
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = in_array($status, ['all', 'pending', 'reconciled', 'ignored'], true) ? $status : 'pending';
    }

    public function refreshSuggestions(int $lineId): void
    {
        $line = $this->resolveLine($lineId);
        app(ResolveBankStatementLineService::class)->refreshSuggestions($line);

        Notification::make()
            ->title('Sugestões atualizadas.')
            ->success()
            ->send();
    }

    public function reconcileSuggestion(int $lineId): void
    {
        $line = $this->resolveLine($lineId);
        $suggestion = $line->suggestions()[0] ?? null;

        if (! is_array($suggestion)) {
            Notification::make()
                ->title('Esta linha ainda não possui sugestão para conciliar.')
                ->warning()
                ->send();

            return;
        }

        $service = app(ResolveBankStatementLineService::class);
        $resolved = match ($suggestion['origin_type'] ?? null) {
            'cash_movement' => $service->reconcileWithCashMovement($line, (int) $suggestion['origin_id'], auth()->id()),
            'account_payable_installment' => $service->reconcileWithPayableInstallment($line, (int) $suggestion['origin_id'], [
                'payment_date' => $line->transaction_date?->toDateString(),
                'notes' => $line->description,
            ], auth()->id()),
            'account_receivable_installment' => $service->reconcileWithReceivableInstallment($line, (int) $suggestion['origin_id'], [
                'payment_date' => $line->transaction_date?->toDateString(),
                'notes' => $line->description,
            ], auth()->id()),
            default => null,
        };

        if ($service->hasError() || $resolved === null) {
            Notification::make()
                ->title($service->getMessageUser() ?: 'Não foi possível conciliar a sugestão.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($service->getMessage() ?: 'Linha conciliada com a melhor sugestão.')
            ->success()
            ->send();
    }

    public function openLineAction(string $action, int $lineId): void
    {
        $this->selectedLineId = $this->resolveLine($lineId)->id;
        $this->mountAction($action);
    }

    public function reconcileMovementAction(): Action
    {
        return Action::make('reconcileMovement')
            ->label('Vincular movimento')
            ->icon('heroicon-o-link')
            ->color('info')
            ->slideOver()
            ->modalHeading(fn (): string => 'Vincular movimento da linha '.$this->selectedLine()?->id)
            ->schema(fn (Schema $schema) => $schema->components([
                Select::make('cash_movement_id')
                    ->label('Movimento financeiro')
                    ->options(fn (): array => $this->movementOptionsForSelectedLine())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(),
            ]))
            ->action(function (array $data): void {
                $line = $this->selectedLine();

                if (! $line) {
                    return;
                }

                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithCashMovement($line, (int) $data['cash_movement_id'], auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao conciliar com movimento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Linha conciliada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(fn () => $this->resetSelectedLine());
    }

    public function reconcilePayableInstallmentAction(): Action
    {
        return Action::make('reconcilePayableInstallment')
            ->label('Baixar conta a pagar')
            ->icon('heroicon-o-arrow-down-circle')
            ->color('warning')
            ->slideOver()
            ->modalHeading(fn (): string => 'Baixar conta a pagar da linha '.$this->selectedLine()?->id)
            ->schema(fn (Schema $schema) => $schema
                ->columns(2)
                ->components([
                    Select::make('installment_id')
                        ->label('Parcela')
                        ->options(fn (): array => $this->payableOptionsForSelectedLine())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->columnSpanFull(),
                    DatePicker::make('payment_date')
                        ->label('Data do pagamento')
                        ->default(fn () => $this->selectedLine()?->transaction_date)
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->default(0),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->default(0),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->default(0),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->default(fn (): ?string => $this->selectedLine()?->description)
                        ->columnSpanFull(),
                ]))
            ->action(function (array $data): void {
                $line = $this->selectedLine();

                if (! $line) {
                    return;
                }

                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithPayableInstallment($line, (int) $data['installment_id'], $data, auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao baixar parcela a pagar.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Parcela baixada e linha conciliada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(fn () => $this->resetSelectedLine());
    }

    public function reconcileReceivableInstallmentAction(): Action
    {
        return Action::make('reconcileReceivableInstallment')
            ->label('Baixar conta a receber')
            ->icon('heroicon-o-arrow-up-circle')
            ->color('success')
            ->slideOver()
            ->modalHeading(fn (): string => 'Baixar conta a receber da linha '.$this->selectedLine()?->id)
            ->schema(fn (Schema $schema) => $schema
                ->columns(2)
                ->components([
                    Select::make('installment_id')
                        ->label('Parcela')
                        ->options(fn (): array => $this->receivableOptionsForSelectedLine())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->columnSpanFull(),
                    DatePicker::make('payment_date')
                        ->label('Data do recebimento')
                        ->default(fn () => $this->selectedLine()?->transaction_date)
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->default(0),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->default(0),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->default(0),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->default(fn (): ?string => $this->selectedLine()?->description)
                        ->columnSpanFull(),
                ]))
            ->action(function (array $data): void {
                $line = $this->selectedLine();

                if (! $line) {
                    return;
                }

                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithReceivableInstallment($line, (int) $data['installment_id'], $data, auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao baixar parcela a receber.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Parcela baixada e linha conciliada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(fn () => $this->resetSelectedLine());
    }

    public function createManualMovementAction(): Action
    {
        return Action::make('createManualMovement')
            ->label('Criar movimento')
            ->icon('heroicon-o-plus-circle')
            ->color('gray')
            ->slideOver()
            ->modalHeading(fn (): string => 'Criar movimento para a linha '.$this->selectedLine()?->id)
            ->schema(fn (Schema $schema) => $schema->components([
                Select::make('financial_category_id')
                    ->label('Categoria financeira')
                    ->options(fn (): array => FinancialCategory::optionsForCompany($this->selectedLine()?->company_id, 'cash_movement'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(),
                DatePicker::make('transaction_date')
                    ->label('Data do movimento')
                    ->default(fn () => $this->selectedLine()?->transaction_date)
                    ->required(),
                TextInput::make('description')
                    ->label('Descrição')
                    ->default(fn (): ?string => $this->selectedLine()?->description)
                    ->required(),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3),
            ]))
            ->action(function (array $data): void {
                $line = $this->selectedLine();

                if (! $line) {
                    return;
                }

                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->createManualMovement($line, $data, auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao criar movimento manual.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Movimento manual criado e conciliado com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(fn () => $this->resetSelectedLine());
    }

    public function ignoreStatementLineAction(): Action
    {
        return Action::make('ignoreStatementLine')
            ->label('Ignorar')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->modalHeading(fn (): string => 'Ignorar linha '.$this->selectedLine()?->id)
            ->schema(fn (Schema $schema) => $schema->components([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->rows(3),
            ]))
            ->action(function (array $data): void {
                $line = $this->selectedLine();

                if (! $line) {
                    return;
                }

                $service = app(ResolveBankStatementLineService::class);
                $ignored = $service->ignore($line, auth()->id(), $data['reason'] ?? null);

                if ($service->hasError() || $ignored === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao ignorar linha.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Linha ignorada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(fn () => $this->resetSelectedLine());
    }

    protected function resetSelectedLine(): void
    {
        $this->selectedLineId = null;
        $this->record = $this->getRecord()->fresh(['financialAccount']);
    }

    protected function selectedLine(): ?BankStatementLine
    {
        if (! $this->selectedLineId) {
            return null;
        }

        return $this->resolveLine($this->selectedLineId);
    }

    protected function resolveLine(int $lineId): BankStatementLine
    {
        return BankStatementLine::query()
            ->with('cashMovement')
            ->where('bank_statement_import_id', $this->getRecord()->getKey())
            ->where('company_id', $this->getRecord()->company_id)
            ->findOrFail($lineId);
    }

    protected function movementOptionsForSelectedLine(): array
    {
        $line = $this->selectedLine();

        if (! $line) {
            return [];
        }

        $suggestions = collect($line->suggestions())
            ->where('origin_type', 'cash_movement')
            ->mapWithKeys(fn (array $suggestion) => [
                (int) $suggestion['origin_id'] => "{$suggestion['label']} [score {$suggestion['score']}]",
            ]);

        $nearbyMovements = CashMovement::query()
            ->where('company_id', $line->company_id)
            ->where('financial_account_id', $line->financial_account_id)
            ->whereDate('transaction_date', '>=', $line->transaction_date?->copy()->subDays(10)->toDateString())
            ->whereDate('transaction_date', '<=', $line->transaction_date?->copy()->addDays(10)->toDateString())
            ->whereDoesntHave('statementLines', fn ($query) => $query->where('id', '!=', $line->id))
            ->orderByDesc('transaction_date')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (CashMovement $movement) => [
                $movement->id => sprintf(
                    '%s | %s | R$ %s',
                    $movement->transaction_date?->format('d/m/Y'),
                    $movement->description,
                    number_format((float) $movement->amount, 2, ',', '.')
                ),
            ]);

        return $suggestions->union($nearbyMovements)->toArray();
    }

    protected function payableOptionsForSelectedLine(): array
    {
        $line = $this->selectedLine();

        if (! $line) {
            return [];
        }

        $suggestions = collect($line->suggestions())
            ->where('origin_type', 'account_payable_installment')
            ->mapWithKeys(fn (array $suggestion) => [
                (int) $suggestion['origin_id'] => "{$suggestion['label']} [score {$suggestion['score']}]",
            ]);

        $openInstallments = AccountPayableInstallment::query()
            ->with('accountPayable.supplier')
            ->where('company_id', $line->company_id)
            ->where('balance_amount', '>', 0)
            ->orderBy('due_date')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (AccountPayableInstallment $installment) => [
                $installment->id => sprintf(
                    'AP %s | %s | %s | R$ %s',
                    $installment->sequence_number,
                    $installment->accountPayable?->supplier?->name ?? 'Sem fornecedor',
                    $installment->due_date?->format('d/m/Y'),
                    number_format((float) $installment->balance_amount, 2, ',', '.')
                ),
            ]);

        return $suggestions->union($openInstallments)->toArray();
    }

    protected function receivableOptionsForSelectedLine(): array
    {
        $line = $this->selectedLine();

        if (! $line) {
            return [];
        }

        $suggestions = collect($line->suggestions())
            ->where('origin_type', 'account_receivable_installment')
            ->mapWithKeys(fn (array $suggestion) => [
                (int) $suggestion['origin_id'] => "{$suggestion['label']} [score {$suggestion['score']}]",
            ]);

        $openInstallments = AccountReceivableInstallment::query()
            ->with('accountReceivable.customer')
            ->where('company_id', $line->company_id)
            ->where('balance_amount', '>', 0)
            ->orderBy('due_date')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (AccountReceivableInstallment $installment) => [
                $installment->id => sprintf(
                    'AR %s | %s | %s | R$ %s',
                    $installment->sequence_number,
                    $installment->accountReceivable?->customer?->name ?? 'Sem cliente',
                    $installment->due_date?->format('d/m/Y'),
                    number_format((float) $installment->balance_amount, 2, ',', '.')
                ),
            ]);

        return $suggestions->union($openInstallments)->toArray();
    }
}
