<?php

namespace App\Filament\Management\Resources\Companies\RelationManagers;

use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

abstract class SequenceRelationManager extends RelationManager
{
    protected static bool $allowMultiple = false;

    protected static ?string $title = 'Sequência';

    protected static ?string $modelLabel = 'Sequência';

    protected static ?string $pluralModelLabel = 'Sequências';

    protected static string|BackedEnum|null $icon = Heroicon::Hashtag;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canManageFiscalSequences()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('last_number')
                ->label('Último número utilizado')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('last_number')
            ->columns(static::getSequenceColumns())
            ->headerActions([
                CreateAction::make()
                    ->label('Criar sequência')
                    ->visible(fn (): bool => static::$allowMultiple || ! $this->sequenceExists()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Alterar')
                    ->iconButton(),
            ])
            ->toolbarActions([])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Nenhuma sequência registrada')
            ->emptyStateDescription('A sequência será criada automaticamente ao utilizar a entidade.');
    }

    /**
     * @return array<int, TextColumn>
     */
    protected static function getSequenceColumns(): array
    {
        return [
            static::lastNumberColumn(),
            static::nextNumberColumn(),
            static::updatedAtColumn(),
        ];
    }

    protected static function lastNumberColumn(): TextColumn
    {
        return TextColumn::make('last_number')
            ->label('Último número')
            ->numeric()
            ->sortable()
            ->badge();
    }

    protected static function nextNumberColumn(): TextColumn
    {
        return TextColumn::make('next_number')
            ->label('Próximo número')
            ->state(fn (Model $record): int => (int) $record->last_number + 1)
            ->numeric()
            ->badge()
            ->color('success');
    }

    protected static function updatedAtColumn(): TextColumn
    {
        return TextColumn::make('updated_at')
            ->label('Atualizada em')
            ->dateTime('d/m/Y H:i')
            ->sortable();
    }

    protected function sequenceExists(): bool
    {
        return $this->getOwnerRecord()
            ->{static::getRelationshipName()}()
            ->exists();
    }
}
