<?php

namespace App\Filament\Clusters\Settings\Resources\AuditEntries\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class AuditEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('summary')
                    ->label('Resumo')
                    ->weight(FontWeight::Bold)
                    ->columnSpanFull(),
                TextEntry::make('event')
                    ->label('Evento'),
                TextEntry::make('action_label')
                    ->label('Ação'),
                TextEntry::make('entity_label')
                    ->label('Entidade'),
                TextEntry::make('auditable_id')
                    ->label('Registro'),
                TextEntry::make('actor_name')
                    ->label('Ator')
                    ->placeholder('Sistema'),
                TextEntry::make('source_label')
                    ->label('Origem'),
                TextEntry::make('occurred_at')
                    ->label('Ocorrido em')
                    ->dateTime('d/m/Y H:i:s'),
                self::jsonRepeatableEntry('before', 'Antes'),
                self::jsonRepeatableEntry('after', 'Depois'),
                self::jsonRepeatableEntry('diff', 'Diferenças'),
                self::jsonRepeatableEntry('metadata', 'Metadados'),
            ]);
    }

    private static function jsonRepeatableEntry(string $field, string $label): RepeatableEntry
    {
        return RepeatableEntry::make($field)
            ->label($label)
            ->columnSpanFull()
            ->state(function ($record) use ($field): array {
                $data = $record->{$field};

                if (empty($data) || ! is_array($data)) {
                    return [];
                }

                return collect($data)
                    ->map(fn (mixed $value, string $key): array => [
                        'field' => $key,
                        'value' => is_array($value)
                            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : (string) $value,
                    ])
                    ->values()
                    ->all();
            })
            ->table([
                TableColumn::make('Campo'),
                TableColumn::make('Valor'),
            ])
            ->schema([
                TextEntry::make('field')
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('value')
                    ->wrap(),
            ]);
    }
}
