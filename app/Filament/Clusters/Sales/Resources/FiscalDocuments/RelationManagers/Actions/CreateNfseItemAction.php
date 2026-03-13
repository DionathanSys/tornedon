<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Tax\IssExigibility;
use App\Models\Service;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CreateNfseItemAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make('createNfseItem')
            ->label('Adicionar Serviço')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()->isNfse()
                && ! $livewire->getOwnerRecord()->nfseSent()
            )
            ->modalHeading('Adicionar Serviço à NFS-e')
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
                    ->live()
                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set) {
                        if (! $state) {
                            return;
                        }

                        $service = Service::find($state);
                        if (! $service) {
                            return;
                        }

                        $set('description', $service->name);
                        $set('unit_price', $service->price ? (float) $service->price / 100 : null);
                        $set('service_code', $service->service_code);
                        $set('nbs_code', $service->nbs_code);
                        $set('iss_rate', $service->tax_rate ? (float) $service->tax_rate : null);
                        $set('iss_exigibility', $service->iss_exigibility?->value);
                    })
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Discriminação do Serviço')
                    ->required()
                    ->maxLength(2000)
                    ->rows(3)
                    ->dehydrateStateUsing(fn (string $state): ?string => $state ? Str::upper($state) : null)
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
                            ->required()
                            ->default(1),
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
                            ->default(false),
                    ]),

                Toggle::make('included_in_total')
                    ->label('Inclui no Total')
                    ->default(true),

                Textarea::make('additional_information')
                    ->label('Informações Adicionais do Item')
                    ->rows(2)
                    ->maxLength(500)
                    ->dehydrateStateUsing(fn (string $state): ?string => $state ? Str::upper($state) : null)
                    ->columnSpanFull(),
            ])
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $fiscalDocument = $livewire->getOwnerRecord();
                $data['fiscal_document_id'] = $fiscalDocument->id;
                $data['unit_of_measure'] = 'UN';

                // Garante que iss_exigibility seja sempre string
                if (isset($data['iss_exigibility']) && ! is_null($data['iss_exigibility'])) {
                    $data['iss_exigibility'] = (string) $data['iss_exigibility'];
                }

                Log::debug('Criando item NFS-e via RelationManager', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'data'               => $data,
                ]);

                $service = new FiscalDocumentItemService();
                $item = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessage());
                return $item;
            })
            ->successNotification(null);
    }
}
