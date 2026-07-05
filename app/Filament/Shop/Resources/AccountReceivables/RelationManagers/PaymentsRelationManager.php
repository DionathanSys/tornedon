<?php

namespace App\Filament\Shop\Resources\AccountReceivables\RelationManagers;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\DeletePaymentAction;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions\EditPaymentAction;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Recebimentos';

    protected static ?string $modelLabel = 'Recebimento';

    protected static ?string $pluralModelLabel = 'Recebimentos';

    protected static string|BackedEnum|null $icon = Heroicon::Banknotes;

    #[On('refresh-payments')]
    public function refreshPayments(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
            ])
            ->recordTitleAttribute('id')
            ->columns([
                Split::make([
                    Stack::make([
                        Grid::make([
                            'default' => 2,
                        ])->schema([
                            TextColumn::make('installment.sequence_number')
                                ->label('Parcela')
                                ->badge(),
                            TextColumn::make('payment_date')
                                ->label('Data')
                                ->date('d/m/Y')
                                ->alignEnd(),
                        ]),
                        TextColumn::make('amount')
                            ->label('Valor recebido')
                            ->money('BRL')
                            ->weight('bold'),
                    ]),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('financialAccount.name')
                            ->label('Conta')
                            ->placeholder('-'),
                        TextColumn::make('description')
                            ->label('Descrição')
                            ->placeholder('-')
                            ->wrap(),
                        TextColumn::make('notes')
                            ->label('Observações')
                            ->placeholder('-')
                            ->wrap(),
                    ])->space(1),
                ])->collapsible(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->recordActions([
                EditPaymentAction::make()
                    ->iconButton()
                    ->after(function (PaymentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-payments');
                        $livewire->dispatch('refresh-installments');
                    }),
                DeletePaymentAction::make()
                    ->iconButton()
                    ->after(function (PaymentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-payments');
                        $livewire->dispatch('refresh-installments');
                    }),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Sem recebimentos');
    }
}
