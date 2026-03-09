<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\FiscalDocument\OperationNature;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\OperationRule;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class OperationRulesPage extends Page implements Forms\Contracts\HasForms, HasTable
{
    use Forms\Concerns\InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected string $view = 'filament.clusters.settings.pages.operation-rules';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Regras por Operação';

    protected static ?string $title = 'Regras Fiscais por Operação';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $companyId = Filament::getTenant()?->id;

        return $table
            ->query(
                OperationRule::query()
                    ->where('company_id', $companyId)
                    ->orderBy('operation_nature')
            )
            ->columns([
                Tables\Columns\TextColumn::make('operation_nature')
                    ->label('Natureza da Operação')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_cfop')
                    ->label('CFOP Padrão')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('cfop_exceptions')
                    ->label('Exceções por NCM')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) . ' exceção(ões)' : '—')
                    ->color('gray'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Ativa'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nova Regra')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Criar Regra por Operação')
                    ->modalWidth('2xl')
                    ->schema($this->ruleFormSchema())
                    ->mutateDataUsing(function (array $data): array {
                        $data['company_id'] = Filament::getTenant()?->id;
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Editar Regra por Operação')
                    ->modalWidth('2xl')
                    ->schema($this->ruleFormSchema())
                    ->mutateDataUsing(function (array $data): array {
                        $data['updated_by'] = auth()->id();

                        return $data;
                    }),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('Nenhuma regra configurada')
            ->emptyStateDescription('Crie uma regra para cada natureza de operação que deseja utilizar. A regra define o CFOP a ser aplicado nos itens da nota.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    protected function ruleFormSchema(): array
    {
        return [
            Section::make('Operação')
                ->schema([
                    Grid::make(2)->schema([
                        Forms\Components\Select::make('operation_nature')
                            ->label('Natureza da Operação')
                            ->options(OperationNature::toSelectArray())
                            ->searchable()
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('default_cfop')
                            ->label('CFOP Padrão')
                            ->required()
                            ->maxLength(4)
                            ->minLength(4)
                            ->placeholder('5102')
                            ->helperText('Aplicado a todos os produtos sem exceção específica.'),
                    ]),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Regra ativa')
                        ->default(true),
                ]),

            Section::make('Exceções por NCM')
                ->description('Defina CFOPs diferentes para produtos com NCM específico. O prefixo basta — "2710" cobre qualquer NCM que comece com "2710".')
                ->schema([
                    Forms\Components\Repeater::make('cfop_exceptions')
                        ->label('')
                        ->schema([
                            Grid::make(2)->schema([
                                Forms\Components\TextInput::make('ncm_prefix')
                                    ->label('Prefixo NCM')
                                    ->required()
                                    ->placeholder('2710')
                                    ->helperText('Ex: "2710" para óleos de petróleo'),

                                Forms\Components\TextInput::make('cfop')
                                    ->label('CFOP')
                                    ->required()
                                    ->maxLength(4)
                                    ->minLength(4)
                                    ->placeholder('5405'),
                            ]),
                        ])
                        ->addActionLabel('Adicionar exceção')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(fn ($record) => empty($record?->cfop_exceptions)),
        ];
    }
}
