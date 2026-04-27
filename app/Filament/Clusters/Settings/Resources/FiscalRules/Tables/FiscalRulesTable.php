<?php

namespace App\Filament\Clusters\Settings\Resources\FiscalRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FiscalRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->searchable(),
                TextColumn::make('fiscalProfile.id')
                    ->searchable(),
                TextColumn::make('operation_nature')
                    ->badge()
                    ->searchable(),
                TextColumn::make('tax_regime')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_interestadual')
                    ->boolean(),
                TextColumn::make('product_origin')
                    ->searchable(),
                IconColumn::make('has_st')
                    ->boolean(),
                TextColumn::make('ncm_prefix')
                    ->searchable(),
                TextColumn::make('recipient_type')
                    ->searchable(),
                IconColumn::make('is_final_consumer')
                    ->boolean(),
                TextColumn::make('cfop')
                    ->searchable(),
                TextColumn::make('cst_icms')
                    ->searchable(),
                TextColumn::make('csosn')
                    ->searchable(),
                TextColumn::make('cst_pis')
                    ->searchable(),
                TextColumn::make('cst_cofins')
                    ->searchable(),
                TextColumn::make('cst_ipi')
                    ->searchable(),
                TextColumn::make('aliquota_icms')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('aliquota_pis')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('aliquota_cofins')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('aliquota_ipi')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('priority')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->date()
                    ->sortable(),
                TextColumn::make('created_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
