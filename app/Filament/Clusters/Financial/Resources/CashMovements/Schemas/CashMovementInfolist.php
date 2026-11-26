<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Schemas;

use App\Filament\Clusters\Financial\Resources\CashMovements\Support\MovementHistory;
use App\Models\CashMovement;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CashMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Detalhes do movimento')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('description')->label('Descrição')->columnSpanFull(),
                TextEntry::make('transaction_date')->label('Data')->date('d/m/Y'),
                TextEntry::make('amount')->label('Valor')->money('BRL'),
                TextEntry::make('financialAccount.name')->label('Conta'),
                TextEntry::make('counterparty_label')->label('Parceiro'),
                TextEntry::make('origin_label')->label('Origem'),
                TextEntry::make('tracking_label')->label('De / Para'),
                TextEntry::make('reversed_at')->label('Estornado em')->dateTime('d/m/Y H:i')->placeholder('Não estornado'),
                TextEntry::make('notes')->label('Observações')->placeholder('Sem observações')->columnSpanFull(),
            ]),
            self::history(),
        ]);
    }

    public static function history(): Section
    {
        return Section::make('Origem e histórico')
            ->description('Siga os vínculos até o registro de origem ou abra os movimentos relacionados.')
            ->columnSpanFull()
            ->schema([
                RepeatableEntry::make('movement_history')->hiddenLabel()
                    ->state(fn (CashMovement $record): array => app(MovementHistory::class)->entries($record))
                    ->columns(2)->schema([
                        TextEntry::make('label')->label('Registro')->weight('bold'),
                        TextEntry::make('description')->label('Identificação')->wrap(),
                        TextEntry::make('date')->label('Data')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('amount')->label('Valor')->money('BRL')->placeholder('—'),
                        TextEntry::make('url')->hiddenLabel()
                            ->formatStateUsing(fn () => 'Abrir registro')
                            ->url(fn (?string $state) => $state)
                            ->placeholder('Sem página de consulta disponível')->columnSpanFull(),
                    ]),
            ]);
    }
}
