<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\Schemas;

use App\Models\CommitmentImportFile;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

class ImportFileDetailsSchema
{
    public static function components(): array
    {
        return [
            Section::make('Arquivo e processamento')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('original_name')->label('Arquivo')->state(fn (CommitmentImportFile $record) => $record->original_name),
                TextEntry::make('processing_status')->label('Situação')->badge()
                    ->state(fn (CommitmentImportFile $record) => $record->status->description())
                    ->color(fn (CommitmentImportFile $record) => $record->status->color()),
                TextEntry::make('parser_version')->label('Versão do leitor')->placeholder('—')->state(fn (CommitmentImportFile $record) => $record->parser_version),
                TextEntry::make('processed_at')->label('Processado em')->dateTime('d/m/Y H:i')->placeholder('—')->state(fn (CommitmentImportFile $record) => $record->processed_at),
                TextEntry::make('last_error')->label('Último erro')->color('danger')->columnSpanFull()
                    ->state(fn (CommitmentImportFile $record) => $record->last_error)->visible(fn (CommitmentImportFile $record) => filled($record->last_error)),
            ]),
            Section::make('Pendências da extração')->columnSpanFull()->visible(fn (CommitmentImportFile $record) => filled(data_get($record->extracted_data, 'issues')))->schema([
                TextEntry::make('extraction_issues')->hiddenLabel()->color('warning')->listWithLineBreaks()
                    ->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'issues', [])),
            ]),
            Section::make('Empenho')->columnSpanFull()->visible(fn (CommitmentImportFile $record) => filled(data_get($record->extracted_data, 'number')))
                ->columns(['default' => 1, 'md' => 3])->schema([
                    TextEntry::make('commitment_number')->label('Número / exercício')
                        ->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'number').'/'.data_get($record->extracted_data, 'fiscal_year')),
                    TextEntry::make('patrimony')->label('Patrimônio')->placeholder('Não identificado')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'patrimony')),
                    TextEntry::make('issued_at')->label('Emissão')->date('d/m/Y')->placeholder('—')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'issued_at')),
                    TextEntry::make('amount_cents')->label('Valor bruto')->money('BRL', divideBy: 100, locale: 'pt_BR')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'amount_cents')),
                    TextEntry::make('retained_amount_cents')->label('Valor retido')->money('BRL', divideBy: 100, locale: 'pt_BR')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'retained_amount_cents')),
                    TextEntry::make('net_amount_cents')->label('Valor líquido')->money('BRL', divideBy: 100, locale: 'pt_BR')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'net_amount_cents')),
                    TextEntry::make('object')->label('Objeto')->columnSpanFull()->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'object')),
                    RepeatableEntry::make('commitment_items')->label('Itens do empenho')->columnSpanFull()
                        ->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'items', []))
                        ->table([TableColumn::make('Descrição'), TableColumn::make('Complemento'), TableColumn::make('Valor')])
                        ->schema([
                            TextEntry::make('description'),
                            TextEntry::make('complement')->placeholder('—'),
                            TextEntry::make('amount_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                        ]),
                ]),
            Section::make('Pagamentos extraídos')->columnSpanFull()->visible(fn (CommitmentImportFile $record) => filled(data_get($record->extracted_data, 'lines')))
                ->columns(['default' => 1, 'md' => 2])->schema([
                    TextEntry::make('declared_total_cents')->label('Total declarado')->money('BRL', divideBy: 100, locale: 'pt_BR')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'declared_total_cents')),
                    TextEntry::make('extracted_total_cents')->label('Total extraído')->money('BRL', divideBy: 100, locale: 'pt_BR')->state(fn (CommitmentImportFile $record) => data_get($record->extracted_data, 'extracted_total_cents')),
                    RepeatableEntry::make('payment_lines')->hiddenLabel()->columnSpanFull()
                        ->state(fn (CommitmentImportFile $record) => array_map(fn (array $line) => [
                            ...$line,
                            'commitment_reference' => $line['commitment_number'].'/'.$line['fiscal_year'],
                            'document_numbers' => implode(', ', array_column($line['fiscal_documents'], 'number')),
                            'pages' => $line['page_start'].'–'.$line['page_end'],
                        ], data_get($record->extracted_data, 'lines', [])))
                        ->table([
                            TableColumn::make('Empenho'), TableColumn::make('Pagamento'), TableColumn::make('Patrimônio'),
                            TableColumn::make('Nota(s)'), TableColumn::make('Valor'), TableColumn::make('Páginas'), TableColumn::make('Pendências'),
                        ])->schema([
                            TextEntry::make('commitment_reference'),
                            TextEntry::make('payment_date')->date('d/m/Y'),
                            TextEntry::make('patrimony')->placeholder('—'),
                            TextEntry::make('document_numbers')->placeholder('—'),
                            TextEntry::make('amount_cents')->money('BRL', divideBy: 100, locale: 'pt_BR'),
                            TextEntry::make('pages'),
                            TextEntry::make('issues')->listWithLineBreaks()->placeholder('—')->color('warning'),
                        ]),
                ]),
            Section::make('Texto original extraído')->columnSpanFull()->collapsible()->collapsed()
                ->visible(fn (CommitmentImportFile $record) => filled($record->extracted_text))->schema([
                    Textarea::make('extracted_text')->hiddenLabel()->readOnly()->dehydrated(false)->rows(12)
                        ->extraInputAttributes(['wrap' => 'soft', 'aria-label' => 'Texto original extraído']),
                ]),
        ];
    }
}
