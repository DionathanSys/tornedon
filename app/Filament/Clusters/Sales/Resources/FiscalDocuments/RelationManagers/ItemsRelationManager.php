<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers;

use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\CreateNfseItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\EditItemAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions\EditNfseItemAction;
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
        $itemsLocked = $this->itemsAreLocked();

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
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('cfop_code')
                    ->label('CFOP')
                    ->searchable()
                    ->visible(! $isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                // NFS-e columns
                TextColumn::make('service.name')
                    ->label('Serviço')
                    ->searchable()
                    ->limit(40)
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('service_code')
                    ->label('Cód. Serviço Nacional')
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('nbs_code')
                    ->label('NBS')
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('cnae_code')
                    ->label('CNAE')
                    ->visible($isNfse)
                    ->toggleable(isToggledHiddenByDefault: true),

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

                TextColumn::make('ibs_estadual_value')
                    ->label('IBS UF')
                    ->state(fn (FiscalDocumentItem $item): float => (float) data_get($item->tax_data, 'imposto.ibs_cbs.grupo_ibs_cbs.ibs_estadual.valor', 0))
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse),

                TextColumn::make('ibs_municipal_value')
                    ->label('IBS Mun.')
                    ->state(fn (FiscalDocumentItem $item): float => (float) data_get($item->tax_data, 'imposto.ibs_cbs.grupo_ibs_cbs.ibs_municipal.valor', 0))
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse),

                TextColumn::make('ibs_total_value')
                    ->label('IBS Total')
                    ->state(fn (FiscalDocumentItem $item): float => (float) data_get($item->tax_data, 'imposto.ibs_cbs.grupo_ibs_cbs.valor_total_ibs', 0))
                    ->money('BRL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(! $isNfse),

                TextColumn::make('cbs_value')
                    ->label('CBS')
                    ->state(fn (FiscalDocumentItem $item): float => (float) data_get($item->tax_data, 'imposto.ibs_cbs.grupo_ibs_cbs.cbs.valor', 0))
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
                    ->visible(fn () => ! $itemsLocked && ! $isNfse && $this->getOwnerRecord()->status == Status::PENDING),

                // NFS-e actions
                EditNfseItemAction::make()
                    ->iconButton()
                    ->visible(fn () => $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
                DeleteItemAction::make('deleteNfseItem')
                    ->iconButton()
                    ->visible(fn () => ! $itemsLocked && $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => ! $itemsLocked),
                ])
                    ->visible(fn () => ! $itemsLocked),
                // NF-e create
                CreateItemAction::make()
                    ->visible(fn () => ! $itemsLocked && ! $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
                // NFS-e create
                CreateNfseItemAction::make()
                    ->visible(fn () => ! $itemsLocked && $isNfse && $this->getOwnerRecord()->status == Status::PENDING),
            ])
            ->emptyStateDescription($isNfse
                ? 'Adicione serviços à NFS-e para que sejam exibidos aqui.'
                : 'Adicione itens à nota fiscal para que sejam exibidos aqui.'
            );
    }

    private function itemsAreLocked(): bool
    {
        return filled($this->getOwnerRecord()->invoice_id);
    }
}
