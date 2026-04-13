<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankStatementImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Importação OFX')
                    ->collapsible()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('financialAccount.name')
                            ->label('Conta Financeira'),
                        TextEntry::make('file_name')
                            ->label('Arquivo'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->description() ?? (string) $state),
                        TextEntry::make('line_count')
                            ->label('Linhas'),
                        TextEntry::make('metadata.institution_name')
                            ->label('Banco')
                            ->placeholder('-'),
                        TextEntry::make('metadata.branch_id')
                            ->label('Agencia')
                            ->placeholder('-'),
                        TextEntry::make('metadata.account_id')
                            ->label('Conta OFX')
                            ->placeholder('-'),
                        TextEntry::make('metadata.statement_start')
                            ->label('Inicio')
                            ->date('d/m/Y')
                            ->placeholder('-'),
                        TextEntry::make('metadata.statement_end')
                            ->label('Fim')
                            ->date('d/m/Y')
                            ->placeholder('-'),
                    ])
                    ->columns(6),
            ]);
    }
}
