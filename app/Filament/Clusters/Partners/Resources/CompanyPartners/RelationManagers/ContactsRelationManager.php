<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers;

use App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers\Actions\CreateContactAction;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers\Actions\DeleteContactAction;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers\Actions\EditContactAction;
use App\Models\Contact;
use App\Services\Contact\ContactService;
use BackedEnum;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contatos';

    protected static ?string $modelLabel = 'Contato';

    protected static string|BackedEnum|null $icon = Heroicon::AtSymbol;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('E-mail copiado!')
                    ->icon(Heroicon::Envelope),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable()
                    ->placeholder('-')
                    ->icon(Heroicon::Phone),
                TextColumn::make('mobile')
                    ->label('Celular')
                    ->searchable()
                    ->placeholder('-')
                    ->icon(Heroicon::DevicePhoneMobile),
                IconColumn::make('notify')
                    ->label('Notifica')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateContactAction::make(),
            ])
            ->recordActions([
                EditContactAction::make()
                    ->iconButton(),
                DeleteContactAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->using(function (Contact $record): bool {
                        $service = new ContactService();
                        return $service->delete($record, Auth::id());
                    }),
            ])
            ->defaultSort('email', 'asc');
    }
}
