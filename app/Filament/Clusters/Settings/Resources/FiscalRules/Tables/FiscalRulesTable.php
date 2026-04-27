<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Tables;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\StateTaxIndicator;
use App\Enum\Tax\TaxRegime;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class FiscalRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('fiscalProfile.id')
                    ->label('Perfil Fiscal')
                    ->prefix('#')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('operation_nature')
                    ->label('Natureza da Operação')
                    ->badge()
                    ->color(fn ($state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn ($state): string => $state?->description() ?? (string) $state)
                    ->searchable(),
                TextColumn::make('tax_regime')
                    ->label('Regime')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->description() ?? (string) $state)
                    ->searchable(),
                TextColumn::make('cfop')
                    ->label('CFOP')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('ncm_prefix')
                    ->label('Prefixo NCM')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('has_st')
                    ->label('ST')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('recipient_type')
                    ->label('Tipo Destinatário')
                    ->formatStateUsing(fn (?string $state): string => $state ? (StateTaxIndicator::tryFrom($state)?->description() ?? $state) : '-')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_interestadual')
                    ->label('Interestadual')
                    ->boolean(),
                TextColumn::make('product_origin')
                    ->label('Origem Produto')
                    ->searchable(),
                IconColumn::make('is_final_consumer')
                    ->label('Consumidor Final')
                    ->boolean(),
                TextColumn::make('cst_icms')
                    ->label('CST ICMS')
                    ->searchable(),
                TextColumn::make('csosn')
                    ->searchable(),
                TextColumn::make('cst_pis')
                    ->label('CST PIS')
                    ->searchable(),
                TextColumn::make('cst_cofins')
                    ->label('CST COFINS')
                    ->searchable(),
                TextColumn::make('cst_ipi')
                    ->label('CST IPI')
                    ->searchable(),
                TextColumn::make('aliquota_icms')
                    ->label('Aliq. ICMS')
                    ->numeric()
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('aliquota_pis')
                    ->label('Aliq. PIS')
                    ->numeric()
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('aliquota_cofins')
                    ->label('Aliq. COFINS')
                    ->numeric()
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('aliquota_ipi')
                    ->label('Aliq. IPI')
                    ->numeric()
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('valid_from')
                    ->label('Válida de')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label('Válida até')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizada em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueLabel('Apenas ativas')
                    ->falseLabel('Apenas inativas')
                    ->default(true)
                    ->native(false),
                SelectFilter::make('operation_nature')
                    ->label('Natureza da Operação')
                    ->options(OperationNature::toSelectArray())
                    ->multiple()
                    ->native(false),
                SelectFilter::make('tax_regime')
                    ->label('Regime Tributário')
                    ->options(TaxRegime::toSelectArray())
                    ->multiple()
                    ->native(false),
                SelectFilter::make('fiscal_profile_id')
                    ->label('Perfil Fiscal')
                    ->relationship('fiscalProfile', 'id')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Filter::make('validity')
                    ->label('Vigência')
                    ->schema([
                        Select::make('status')
                            ->label('Situação')
                            ->options([
                                'current' => 'Vigentes hoje',
                                'future' => 'Futuras',
                                'expired' => 'Expiradas',
                                'no_validity' => 'Sem vigência',
                            ])
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $today = now()->toDateString();
                        $status = $data['status'] ?? null;

                        return match ($status) {
                            'current' => $query
                                ->where(function (Builder $builder) use ($today): void {
                                    $builder->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today);
                                })
                                ->where(function (Builder $builder) use ($today): void {
                                    $builder->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today);
                                }),
                            'future' => $query->whereDate('valid_from', '>', $today),
                            'expired' => $query->whereDate('valid_until', '<', $today),
                            'no_validity' => $query->whereNull('valid_from')->whereNull('valid_until'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Ativar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(function ($record): void {
                                $record->update([
                                    'is_active' => true,
                                    'updated_by' => Auth::id(),
                                ]);
                            });
                        }),
                    BulkAction::make('deactivate')
                        ->label('Desativar')
                        ->icon('heroicon-o-no-symbol')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(function ($record): void {
                                $record->update([
                                    'is_active' => false,
                                    'updated_by' => Auth::id(),
                                ]);
                            });
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
