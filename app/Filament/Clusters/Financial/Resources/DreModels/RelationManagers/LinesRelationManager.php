<?php

namespace App\Filament\Clusters\Financial\Resources\DreModels\RelationManagers;

use App\Enum\Financial\DreDisplaySign;
use App\Enum\Financial\DreLineType;
use App\Enum\Financial\DreOperation;
use App\Models\ChartAccount;
use App\Models\DreLine;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Linhas da DRE';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Linha')
                ->columns(['sm' => 1, 'md' => 4, 'lg' => 8])
                ->schema([
                    TextInput::make('code')
                        ->label('Código')
                        ->maxLength(255)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['md' => 3, 'lg' => 6]),
                    Select::make('parent_id')
                        ->label('Linha Pai')
                        ->options(fn (): array => $this->lineOptions())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    Select::make('line_type')
                        ->label('Tipo')
                        ->options(DreLineType::toSelectArray())
                        ->default(DreLineType::ACCOUNT_GROUP->value)
                        ->required()
                        ->native(false)
                        ->live()
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    Select::make('operation')
                        ->label('Operação')
                        ->options(DreOperation::toSelectArray())
                        ->default(DreOperation::ADD->value)
                        ->required()
                        ->native(false)
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    Select::make('display_sign')
                        ->label('Sinal Visual')
                        ->options(DreDisplaySign::toSelectArray())
                        ->default(DreDisplaySign::NATURAL->value)
                        ->required()
                        ->native(false)
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    Select::make('display_depth')
                        ->label('Detalhamento')
                        ->options([
                            0 => 'Somente total',
                            1 => 'Mostrar 1 nível',
                            2 => 'Mostrar 2 níveis',
                            3 => 'Mostrar 3 níveis',
                        ])
                        ->placeholder('Completo')
                        ->native(false)
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    TextInput::make('sort_order')
                        ->label('Ordem')
                        ->numeric()
                        ->default(0)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    Toggle::make('is_bold')
                        ->label('Negrito')
                        ->inline(false)
                        ->default(false)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                    Toggle::make('is_visible')
                        ->label('Visível')
                        ->inline(false)
                        ->default(true)
                        ->columnSpan(['md' => 1, 'lg' => 2]),
                ]),
            Section::make('Contas do Plano')
                ->description('Vincule contas para linhas do tipo Grupo de contas. Os descendentes são incluídos por padrão no cálculo.')
                ->visible(fn (Get $get): bool => $get('line_type') === DreLineType::ACCOUNT_GROUP->value)
                ->schema([
                    CheckboxList::make('chart_accounts')
                        ->label('Contas')
                        ->options(fn (): array => ChartAccount::optionsForCompany(Filament::getTenant()->id))
                        ->columns(2)
                        ->bulkToggleable()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (CheckboxList $component, ?DreLine $record): void {
                            if (! $record) {
                                return;
                            }

                            $component->state($record->chartAccounts()->pluck('chart_accounts.id')->all());
                        }),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('Ordem')->sortable()->alignCenter(),
                TextColumn::make('code')->label('Código')->placeholder('-')->searchable(),
                TextColumn::make('name')->label('Linha')->searchable(),
                TextColumn::make('line_type')->label('Tipo')->formatStateUsing(fn ($state): string => $state?->description() ?? '-')->badge(),
                TextColumn::make('operation')->label('Operação')->formatStateUsing(fn ($state): string => $state?->description() ?? '-'),
                IconColumn::make('is_visible')->label('Visível')->boolean()->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make()->after(fn (DreLine $record, array $data): mixed => $this->syncChartAccounts($record, $data)),
            ])
            ->recordActions([
                EditAction::make()->after(fn (DreLine $record, array $data): mixed => $this->syncChartAccounts($record, $data)),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    private function syncChartAccounts(DreLine $record, array $data): void
    {
        $accountIds = array_values(array_filter((array) ($data['chart_accounts'] ?? [])));
        $record->chartAccounts()->sync(collect($accountIds)->mapWithKeys(fn (int|string $id): array => [(int) $id => ['include_descendants' => true]])->all());
        $record->dreModel->refreshStructureHash();
    }

    private function lineOptions(): array
    {
        return $this->getOwnerRecord()
            ->lines()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (DreLine $line): array => [$line->id => trim(($line->code ? $line->code.' - ' : '').$line->name)])
            ->all();
    }
}
