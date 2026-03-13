<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Tax\IssExigibility;
use App\Models\FiscalDocumentItem;
use App\Models\Service;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditNfseItemAction
{
    public static function make(): EditAction
    {
        return EditAction::make('editNfseItem')
            ->label('Editar')
            ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->isNfse()
                && ! $livewire->getOwnerRecord()->nfseSent()
            )
            ->schema([
                Select::make('service_id')
                    ->label('Serviço')
                    ->options(fn (RelationManager $livewire) => Service::where('company_id', $livewire->getOwnerRecord()->company_id)
                        ->where('is_active', true)
                        ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Discriminação do Serviço')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3)
                    ->columnSpanFull(),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('service_code')
                            ->label('Código Serviço (LC 116)')
                            ->maxLength(10),
                        TextInput::make('nbs_code')
                            ->label('Código NBS')
                            ->maxLength(10),
                        TextInput::make('cnae_code')
                            ->label('CNAE')
                            ->maxLength(10),
                    ]),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->numeric()
                            ->required(),
                        TextInput::make('unit_price')
                            ->label('Valor Unitário')
                            ->numeric()
                            ->required()
                            ->prefix('R$'),
                        TextInput::make('total_price')
                            ->label('Valor Total')
                            ->numeric()
                            ->required()
                            ->prefix('R$'),
                    ]),

                Group::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('iss_rate')
                            ->label('Alíquota ISS (%)')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->maxValue(100),
                        Select::make('iss_exigibility')
                            ->label('Exigibilidade ISS')
                            ->options(IssExigibility::toSelectArray())
                            ->native(false),
                        Toggle::make('iss_withheld')
                            ->label('ISS Retido')
                            ->inline(false)
                            ->default(false),
                    ]),

                Toggle::make('included_in_total')
                    ->label('Inclui no Total')
                    ->default(true),

                Textarea::make('additional_information')
                    ->label('Informações Adicionais do Item')
                    ->rows(2)
                    ->maxLength(500)
                    ->autocapitalize('words')
                    ->columnSpanFull(),
            ])
            ->using(function (FiscalDocumentItem $record, array $data): ?Model {
                Log::debug('Atualizando item NFS-e via RelationManager', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data'    => $data,
                ]);

                // Garante que iss_exigibility seja sempre string
                if (isset($data['iss_exigibility']) && ! is_null($data['iss_exigibility'])) {
                    $data['iss_exigibility'] = (string) $data['iss_exigibility'];
                }

                $service = new FiscalDocumentItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessage());
                return $item;
            });
    }
}
