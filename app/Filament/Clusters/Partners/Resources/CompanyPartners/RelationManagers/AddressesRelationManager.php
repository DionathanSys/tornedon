<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers;

use App\Filament\Clusters\Partners\Resources\Addresses\Components\AddressComponent;
use App\Models\Address;
use App\Services\Address\AddressService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Endereços';

    protected static ?string $modelLabel = 'Endereço';

    protected static string|BackedEnum|null $icon = Heroicon::MapPin;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('street')
                    ->label('Logradouro')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('neighborhood')
                    ->label('Bairro')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('city')
                    ->label('Cidade')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Estado')
                    ->searchable(),
                TextColumn::make('postal_code')
                    ->label('CEP')
                    ->searchable()
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Novo Endereço')
                    ->schema(AddressComponent::make())
                    ->using(function (array $data): ?Model {
                        $service = new AddressService();
                        $address = $service->create($this->getOwnerRecord()->id, $data, Auth::id());

                        if ($service->hasError()) {
                            notify::error(message: $service->getMessageUser());
                            return null;
                        }

                        notify::success(message: $service->getMessageUser());

                        return $address;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(AddressComponent::make())
                    ->using(function (Address $record, array $data): ?Model {
                        $service = new AddressService();
                        $address = $service->update($record, $data, Auth::id());

                        if ($service->hasError()) {
                            notify::error(message: $service->getMessageUser());
                            return null;
                        }

                        notify::success(message: $service->getMessageUser());

                        return $address;
                    }),
                DeleteAction::make()
                    ->using(function (Address $record): bool {
                        $service = new AddressService();
                        $result = $service->delete($record, Auth::id());

                        if ($service->hasError()) {
                            notify::error(message: $service->getMessageUser());
                            return false;
                        }

                        notify::success(message: $service->getMessageUser());

                        return $result;
                    }),
            ])
            ->toolbarActions([
            ])
            ->defaultSort('created_at', 'desc');
    }
}
