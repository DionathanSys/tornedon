<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementLines;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\BankStatementImportResource;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\CreateManualMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\IgnoreStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileMovementAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcilePayableInstallmentAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReconcileReceivableInstallmentAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReopenIgnoredStatementLineAction;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions\ReverseStatementLineReconciliationAction;
use App\Filament\Clusters\Financial\Resources\BankStatementLines\Pages\ListBankStatementLines;
use App\Models\BankStatementLine;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BankStatementLineResource extends Resource
{
    protected static ?string $model = BankStatementLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Bars3BottomLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Item de extrato';

    protected static ?string $pluralModelLabel = 'Itens de extrato';

    protected static ?int $navigationSort = 7;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('description')->label('Descrição')->searchable()->wrap()->limit(60),
                TextColumn::make('amount')->label('Valor')->money('BRL')->sortable(),
                TextColumn::make('reconciliation_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('cashMovement.description')->label('Movimento vinculado')->placeholder('-')->limit(40),
            ])
            ->groups([
                Group::make('import.file_name')
                    ->label('Importação')
                    ->getTitleFromRecordUsing(fn (BankStatementLine $record): string => sprintf(
                        '%s | %s',
                        $record->import?->file_name ?? 'Arquivo sem nome',
                        $record->import?->imported_at?->format('d/m/Y H:i') ?? '-',
                    ))
                    ->collapsible(),
            ])
            ->defaultGroup('import.file_name')
            ->defaultSort('transaction_date', 'desc')
            ->recordUrl(fn (BankStatementLine $record): string => BankStatementImportResource::getUrl('view', [
                'tenant' => Filament::getTenant(),
                'record' => $record->bank_statement_import_id,
            ]))
            ->recordActions([
                ReconcileMovementAction::make()->iconButton(),
                ReconcilePayableInstallmentAction::make()->iconButton(),
                ReconcileReceivableInstallmentAction::make()->iconButton(),
                CreateManualMovementAction::make()->iconButton(),
                IgnoreStatementLineAction::make()->iconButton(),
                ReopenIgnoredStatementLineAction::make()->iconButton(),
                ReverseStatementLineReconciliationAction::make()->iconButton(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['cashMovement', 'import']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatementLines::route('/'),
        ];
    }
}
