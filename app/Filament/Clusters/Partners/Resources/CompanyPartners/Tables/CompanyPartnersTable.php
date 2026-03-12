<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Tables;

use App\Enum\Partner\Type;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions\EditCompanyPartnerAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class CompanyPartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('partner.name')
                    ->label('Parceiro')
                    ->sortable(),
                TextColumn::make('partner.document_number')
                    ->label('CPF/CNPJ')
                    ->searchable(),
                TextColumn::make('invoice_threshold')
                    ->label('Vlr. Mín Fatura')
                    ->money('BRL')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Editado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('type')
                    ->label('Tipo de Parceiro')
                    ->schema([
                        Select::make('type')
                            ->label('Tipo de Parceiro')
                            ->native(false)
                            ->options(Type::toSelectArray())
                            ->default(Type::CUSTOMER->value)
                            ->placeholder('Selecione um tipo'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['type'] ?? null,
                            fn($q) => $q->whereJsonContains('type', $data['type'])
                        );
                    }),
                Filter::make('is_active')
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton(),
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ])->size(Size::Small),
                CreateAction::make()
                    ->label('Parceiro')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ]);
    }
}
