<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers;

use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\CreateNfseItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\EditItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\EditNfseItemAction;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        $isNfse = $this->getOwnerRecord()->isNfse();

        return $table
            ->recordTitleAttribute('item_number')
            ->heading($isNfse ? 'Serviços da NFS-e' : 'Itens da Nota Fiscal')
            ->columns([
                TextColumn::make('item_number')
                    ->label('Nº')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                // NF-e columns
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->limit(40)
                    ->visible(! $isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('ncm_code')
                    ->label('NCM')
                    ->searchable()
                    ->visible(! $isNfse)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cfop_code')
                    ->label('CFOP')
                    ->searchable()
                    ->visible(! $isNfse)
                    ->toggleable(isToggledHiddenByDefault: true),

                // NFS-e columns
                TextColumn::make('service.name')
                    ->label('Serviço')
                    ->searchable()
                    ->limit(40)
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('service_code')
                    ->label('Cód. Serviço')
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                // Common columns
                TextColumn::make('description')
                    ->label('Descrição')
                    ->description(fn (FiscalDocumentItem $item) => Str::upper($item->additional_information))
                    ->wrap()
                    ->lineClamp(5)
                    ->searchable()
                    // ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: ! $isNfse),

                TextColumn::make('unit_of_measure')
                    ->label('Un.')
                    ->visible(! $isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(4, ',', '.')
                    ->sortable()
                    ->summarize(Sum::make('quantity')->label('TT Qtde.'))
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('unit_price')
                    ->label('Vlr. Unitário')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('total_price')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make('total_price')->label('TT Total')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: false),

                // NFS-e ISS columns
                TextColumn::make('iss_rate')
                    ->label('ISS %')
                    ->numeric(2, ',', '.')
                    ->suffix('%')
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('iss_amount')
                    ->label('ISS Valor')
                    ->money('BRL')
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('iss_withheld')
                    ->label('ISS Retido')
                    ->boolean()
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                // NF-e toggleable columns
                TextColumn::make('discount_amount')
                    ->label('Desconto')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse)
                    ->summarize(Sum::make('discount_amount')->label('TT Desconto')->money('BRL', 100)),

                TextColumn::make('freight_amount')
                    ->label('Frete')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse),

                TextColumn::make('insurance_amount')
                    ->label('Seguro')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse),

                TextColumn::make('other_expenses_amount')
                    ->label('Outras Desp.')
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse),

                TextColumn::make('created_at')
                    ->label('Criado Em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderableColumns()
            ->filters([])
            ->headerActions([])
            ->recordActions([
                // NF-e actions
                EditItemAction::make()
                    ->iconButton()
                    ->visible(fn () => ! $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
                DeleteItemAction::make()
                    ->iconButton()
                    ->visible(fn () => ! $isNfse && $this->getOwnerRecord()->status == Status::PENDING),

                // NFS-e actions
                EditNfseItemAction::make()
                    ->iconButton()
                    ->visible(fn () => $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
                DeleteItemAction::make('deleteNfseItem')
                    ->iconButton()
                    ->visible(fn () => $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                // NF-e create
                CreateItemAction::make()
                    ->visible(fn () => ! $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
                // NFS-e create
                CreateNfseItemAction::make()
                    ->visible(fn () => $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
            ])
            ->emptyStateDescription($isNfse
                ? 'Adicione serviços à NFS-e para que sejam exibidos aqui.'
                : 'Adicione itens à nota fiscal para que sejam exibidos aqui.'
            );
    }
}
