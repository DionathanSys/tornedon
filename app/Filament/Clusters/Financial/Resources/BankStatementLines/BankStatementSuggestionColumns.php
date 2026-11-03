<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementLines;

use App\Models\BankStatementLine;
use Filament\Tables\Columns\TextColumn;

final class BankStatementSuggestionColumns
{
    public static function make(): array
    {
        return [
            TextColumn::make('metadata.suggestions.0.label')
                ->label('Melhor sugestão')
                ->wrap()
                ->placeholder('-')
                ->tooltip(fn (BankStatementLine $record): ?string => data_get($record->metadata, 'suggestions.0.reason'))
                ->toggleable(),
            TextColumn::make('suggestion_confidence')
                ->label('Confiança')
                ->badge()
                ->placeholder('-')
                ->getStateUsing(fn (BankStatementLine $record): ?string => $record->suggestionsStatus() === 'ready'
                    ? data_get($record->metadata, 'suggestions.0.assessment.confidence') : null)
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'high' => 'Alta',
                    'medium' => 'Média',
                    default => 'Baixa',
                })
                ->color(fn (?string $state): string => match ($state) {
                    'high' => 'success',
                    'medium' => 'warning',
                    default => 'gray',
                })
                ->tooltip(fn (BankStatementLine $record): string => data_get($record->metadata, 'suggestions.0.assessment.ambiguous')
                    ? 'Há alternativas com pontuação próxima. Confira a correspondência.'
                    : 'Classificação por valor, data e identificação; não é uma probabilidade de acerto.')
                ->toggleable(),
            TextColumn::make('suggestions_count')
                ->label('Sugestões')
                ->badge()
                ->getStateUsing(fn (BankStatementLine $record): string => match ($record->suggestionsStatus()) {
                    'error' => 'Erro ao buscar',
                    'stale' => 'Desatualizadas',
                    'empty' => 'Sem correspondência',
                    default => (string) count($record->suggestions()),
                })
                ->color(fn (BankStatementLine $record): string => match ($record->suggestionsStatus()) {
                    'error' => 'danger',
                    'stale' => 'warning',
                    'empty' => 'gray',
                    default => count($record->suggestions()) > 1 ? 'info' : 'success',
                })
                ->tooltip(fn (BankStatementLine $record): ?string => match ($record->suggestionsStatus()) {
                    'error' => 'A última atualização falhou. Tente atualizar as sugestões novamente.',
                    'stale' => 'Atualize as sugestões para consultar os critérios atuais e os registros disponíveis.',
                    'empty' => 'A última busca foi concluída sem encontrar correspondências.',
                    default => null,
                })
                ->toggleable(),
        ];
    }
}
