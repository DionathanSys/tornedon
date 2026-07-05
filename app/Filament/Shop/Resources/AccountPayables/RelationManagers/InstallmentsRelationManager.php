<?php

namespace App\Filament\Shop\Resources\AccountPayables\RelationManagers;

use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\DeleteInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\EditInstallmentAction;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions\RegisterInstallmentPaymentAction;
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

class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Parcelas';

    protected static ?string $modelLabel = 'Parcela';

    protected static ?string $pluralModelLabel = 'Parcelas';

    protected static string|BackedEnum|null $icon = Heroicon::QueueList;

    #[On('refresh-installments')]
    public function refreshInstallments(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
            ])
            ->recordTitleAttribute('sequence_number')
            ->columns([
                Split::make([
                    Stack::make([
                        Grid::make([
                            'default' => 2,
                        ])->schema([
                            TextColumn::make('sequence_number')
                                ->label('Parcela')
                                ->badge(),
                            TextColumn::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                                ->color(fn ($state) => $state?->color() ?? 'gray')
                                ->alignEnd(),
                        ]),
                        TextColumn::make('due_amount')
                            ->label('Valor atual')
                            ->money('BRL')
                            ->weight('bold'),
                    ]),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        TextColumn::make('due_date')
                            ->label('Vencimento')
                            ->date('d/m/Y'),
                        TextColumn::make('paid_amount')
                            ->label('Valor pago')
                            ->money('BRL'),
                        TextColumn::make('paid_date')
                            ->label('Pago em')
                            ->date('d/m/Y')
                            ->placeholder('-'),
                        TextColumn::make('description')
                            ->label('Descrição')
                            ->placeholder('-')
                            ->wrap(),
                    ])->space(1),
                ])->collapsible(),
            ])
            ->defaultSort('sequence_number')
            ->recordActions([
                RegisterInstallmentPaymentAction::make()->iconButton(),
                EditInstallmentAction::make()
                    ->iconButton()
                    ->after(function (InstallmentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-installments');
                    }),
                DeleteInstallmentAction::make()
                    ->iconButton()
                    ->after(function (InstallmentsRelationManager $livewire) {
                        $livewire->dispatch('refresh-installments');
                        $livewire->dispatch('refresh-payments');
                    }),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterContent)
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Sem parcelas');
    }
}
