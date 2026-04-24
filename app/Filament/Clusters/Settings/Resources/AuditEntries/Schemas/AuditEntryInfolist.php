<?php

namespace App\Filament\Clusters\Settings\Resources\AuditEntries\Schemas;

use App\Models\AuditEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class AuditEntryInfolist
{
    /**
     * @var array<int, AuditEntry|null>
     */
    private static array $detailCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextEntry::make('summary')
                    ->label('Resumo')
                    ->weight(FontWeight::Bold)
                    ->columnSpanFull(),
                TextEntry::make('event')
                    ->label('Evento')
                    ->columnSpan(1),
                TextEntry::make('action_label')
                    ->label('Ação')
                    ->columnSpan(1),
                TextEntry::make('entity_label')
                    ->label('Entidade')
                    ->columnSpan(1),
                TextEntry::make('auditable_id')
                    ->label('Registro')
                    ->columnSpan(1),
                TextEntry::make('source_label')
                    ->label('Origem'),
                TextEntry::make('actor_name')
                    ->label('Ator')
                    ->placeholder('Sistema')
                    ->columnStart(1)
                    ->columnSpan(1),
                TextEntry::make('occurred_at')
                    ->label('Ocorrido em')
                    ->dateTime('d/m/Y H:i:s'),
                self::jsonRepeatableEntry('diff', 'Diferenças'),
                self::jsonRepeatableEntry('metadata', 'Metadados'),
                self::jsonRepeatableEntry('before', 'Antes'),
                self::jsonRepeatableEntry('after', 'Depois'),

            ]);
    }

    private static function jsonRepeatableEntry(string $field, string $label): RepeatableEntry
    {
        return RepeatableEntry::make($field)
            ->label($label)
            ->columnSpanFull()
            ->state(function ($record) use ($field): array {
                $detailRecord = self::loadDetailRecord((int) $record->id);
                $data = $detailRecord?->{$field};

                if (empty($data) || ! is_array($data)) {
                    return [];
                }

                return collect($data)
                    ->map(fn(mixed $value, string $key): array => [
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

    private static function loadDetailRecord(int $recordId): ?AuditEntry
    {
        if (! array_key_exists($recordId, self::$detailCache)) {
            self::$detailCache[$recordId] = AuditEntry::query()->find($recordId);
        }

        return self::$detailCache[$recordId];
    }
}
