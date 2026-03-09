<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\FiscalOperationType;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\FiscalProfile;
use App\Models\FiscalRule;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FiscalRulesPage extends Page implements Forms\Contracts\HasForms, HasTable
{
    use Forms\Concerns\InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected string $view = 'filament.clusters.settings.pages.fiscal-rules';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Regras Fiscais';

    protected static ?string $title = 'Regras Fiscais Customizadas';

    protected static ?int $navigationSort = 9;

    public static function canAccess(): bool
    {
        return true; // TODO: restringir por permissão (Shield)
    }

    protected function getActiveVersionId(): ?int
    {
        $companyId = Filament::getTenant()?->id;
        $profile = FiscalProfile::where('company_id', $companyId)->first();

        return $profile?->getActiveVersion()?->id;
    }

    public function table(Table $table): Table
    {
        $versionId = $this->getActiveVersionId();

        return $table
            ->query(
                FiscalRule::query()
                    ->when($versionId, fn (Builder $q) => $q->where('fiscal_profile_version_id', $versionId))
                    ->when(!$versionId, fn (Builder $q) => $q->whereRaw('1 = 0'))
            )
            ->defaultSort('priority', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome da Regra')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('operation_type')
                    ->label('Tipo de Operação')
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\ToggleColumn::make('is_enabled')
                    ->label('Ativa'),

                Tables\Columns\TextColumn::make('valid_from')
                    ->label('Vigência Início')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_to')
                    ->label('Vigência Fim')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nova Regra')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Criar Regra Fiscal')
                    ->modalWidth('4xl')
                    ->form(fn () => $this->ruleFormSchema())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['fiscal_profile_version_id'] = $this->getActiveVersionId();

                        return $data;
                    })
                    ->visible(fn () => $this->getActiveVersionId() !== null),
            ])
            ->actions([
                EditAction::make()
                    ->modalHeading('Editar Regra Fiscal')
                    ->modalWidth('4xl')
                    ->form(fn () => $this->ruleFormSchema()),

                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('Nenhuma regra fiscal configurada')
            ->emptyStateDescription(
                $versionId
                    ? 'Crie regras customizadas para sobrescrever os padrões do regime tributário.'
                    : 'Configure primeiro o Perfil Fiscal antes de criar regras.'
            )
            ->emptyStateIcon('heroicon-o-funnel');
    }

    protected function ruleFormSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome da Regra')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Ex: ICMS isento para exportação')
                        ->columnSpan(2),

                    Forms\Components\Select::make('operation_type')
                        ->label('Tipo de Operação')
                        ->native(false)
                        ->options(FiscalOperationType::toSelectArray())
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('priority')
                        ->label('Prioridade')
                        ->numeric()
                        ->required()
                        ->default(100)
                        ->helperText('Quanto menor, maior a prioridade.')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('valid_from')
                        ->label('Início da Vigência')
                        ->native(false)
                        ->default(now())
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('valid_to')
                        ->label('Fim da Vigência')
                        ->native(false)
                        ->columnSpan(1),

                    Forms\Components\Toggle::make('is_enabled')
                        ->label('Ativa')
                        ->default(true)
                        ->columnSpan(2),
                ]),

            \Filament\Schemas\Components\Section::make('Condições')
                ->description('Defina as condições (AND) que devem ser atendidas para esta regra se aplicar. Valores JSON.')
                ->schema([
                    Forms\Components\KeyValue::make('conditions')
                        ->label('')
                        ->keyLabel('Campo')
                        ->valueLabel('Valor / Lista')
                        ->keyPlaceholder('Ex: recipientUf, productNcm, operationType')
                        ->valuePlaceholder('Ex: SP ou ["SP","RJ"]')
                        ->helperText('Campo → contexto da operação. Valor → string exata ou lista JSON ["valor1","valor2"] para OR.')
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            \Filament\Schemas\Components\Section::make('Resultado (Override)')
                ->description('Valores fiscais que serão aplicados quando a regra casar. Campos em branco não sobrescrevem.')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            Forms\Components\TextInput::make('result.cfop')
                                ->label('CFOP')
                                ->maxLength(5),

                            Forms\Components\TextInput::make('result.cst_icms')
                                ->label('CST ICMS')
                                ->maxLength(3),

                            Forms\Components\TextInput::make('result.csosn')
                                ->label('CSOSN')
                                ->maxLength(4),

                            Forms\Components\TextInput::make('result.aliquota_icms')
                                ->label('Alíquota ICMS (%)')
                                ->numeric()
                                ->step(0.01),

                            Forms\Components\TextInput::make('result.reducao_base_icms')
                                ->label('Redução Base ICMS (%)')
                                ->numeric()
                                ->step(0.01),

                            Forms\Components\TextInput::make('result.cst_pis')
                                ->label('CST PIS')
                                ->maxLength(3),

                            Forms\Components\TextInput::make('result.aliquota_pis')
                                ->label('Alíquota PIS (%)')
                                ->numeric()
                                ->step(0.0001),

                            Forms\Components\TextInput::make('result.cst_cofins')
                                ->label('CST COFINS')
                                ->maxLength(3),

                            Forms\Components\TextInput::make('result.aliquota_cofins')
                                ->label('Alíquota COFINS (%)')
                                ->numeric()
                                ->step(0.0001),

                            Forms\Components\TextInput::make('result.cst_ipi')
                                ->label('CST IPI')
                                ->maxLength(3),

                            Forms\Components\TextInput::make('result.aliquota_ipi')
                                ->label('Alíquota IPI (%)')
                                ->numeric()
                                ->step(0.01),
                        ]),
                ])
                ->collapsible(),

            Forms\Components\Textarea::make('notes')
                ->label('Observações')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }
}
