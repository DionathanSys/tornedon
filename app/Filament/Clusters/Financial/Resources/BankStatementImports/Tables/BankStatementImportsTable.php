<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Actions\ImportOfxAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankStatementImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('imported_at')
                    ->label('Importado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('metadata.institution_name')
                    ->label('Banco')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('line_count')
                    ->label('Linhas')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
            ])
            ->toolbarActions([
                ImportOfxAction::make(),
            ])
            ->defaultSort('imported_at', 'desc');
    }
}
