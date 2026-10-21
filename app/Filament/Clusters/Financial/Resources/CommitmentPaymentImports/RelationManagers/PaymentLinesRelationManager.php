<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\RelationManagers;

use App\Enum\Commitment\PaymentLineStatus;
use App\Filament\Clusters\Financial\Resources\CommitmentPaymentImports\Actions\CommitmentPaymentActions;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class PaymentLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentLines';

    protected static ?string $title = 'Conciliação dos pagamentos';

    public function mount(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
        abort_unless($this->getOwnerRecord()->type->value === 'payments', 404);
        parent::mount();
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->getOwnerRecord());
    }

    public function table(Table $table): Table
    {
        return $table->poll('10s')->defaultSort('position')->recordUrl(null)
            ->modifyQueryUsing(fn ($query) => $query->where('commitment_payment_lines.company_id', Filament::getTenant()->id)
                ->where('commitment_payment_lines.customer_id', $this->getOwnerRecord()->customer_id)
                ->with(['importFile', 'commitment.billing.invoice', 'receipt']))
            ->columns([
                TextColumn::make('position')->label('Linha')->sortable(),
                TextColumn::make('commitment_number')->label('Empenho')->searchable()->description(fn ($record) => 'Exercício '.$record->fiscal_year),
                TextColumn::make('patrimony')->label('Patrimônio')->searchable()->placeholder('—'),
                TextColumn::make('fiscal_document_number')->label('NFS-e')->searchable()->placeholder('—'),
                TextColumn::make('fiscal_document_date')->label('Emissão da nota')->date('d/m/Y')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_date')->label('Pagamento no relatório')->date('d/m/Y')->sortable(),
                TextColumn::make('amount')->label('Valor pago')->money('BRL', locale: 'pt_BR'),
                TextColumn::make('status')->label('Conciliação')->badge()->formatStateUsing(fn ($state) => $state->description())->color(fn ($state) => $state->color()),
                TextColumn::make('match_diagnostics.balance_cents')->label('Saldo na última busca')->money('BRL', divideBy: 100, locale: 'pt_BR')->placeholder('—'),
                TextColumn::make('commitment.billing.invoice.invoice_number')->label('Fatura')->placeholder('—'),
                TextColumn::make('match_diagnostics.reason')->label('Diagnóstico')->wrap()->limit(120)->placeholder('Use Buscar vínculos.'),
                TextColumn::make('page_start')->label('Página')->toggleable(),
                TextColumn::make('matched_at')->label('Última busca')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('status')->label('Conciliação')->options(collect(PaymentLineStatus::cases())->mapWithKeys(fn ($state) => [$state->value => $state->description()])->all()),
            ])->recordActions([
                ActionGroup::make([CommitmentPaymentActions::review(), CommitmentPaymentActions::refresh(), CommitmentPaymentActions::receive(),
                    CommitmentPaymentActions::receive(linkExisting: true), CommitmentPaymentActions::correct(), CommitmentPaymentActions::pdf(),
                    CommitmentPaymentActions::ignore(), CommitmentPaymentActions::ignore(reopen: true)])->icon(Heroicon::Bars3)->label('Ações do pagamento'),
            ], RecordActionsPosition::BeforeCells)->recordActionsAlignment('start')
            ->headerActions([CommitmentPaymentActions::refreshAll()])->toolbarActions([CommitmentPaymentActions::bulkReceive()]);
    }
}
