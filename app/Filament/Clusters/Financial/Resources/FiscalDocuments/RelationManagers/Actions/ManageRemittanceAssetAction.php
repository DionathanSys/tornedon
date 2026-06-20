<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Enum\Equipment\Type;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\Equipment\EquipmentService;
use App\Services\FiscalDocument\FiscalDocumentRemittanceAssetService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class ManageRemittanceAssetAction
{
    public static function make(): Action
    {
        return Action::make('manageRemittanceAsset')
            ->label('Equipamento')
            ->icon(Heroicon::WrenchScrewdriver)
            ->modalHeading('Cadastrar ou vincular equipamento')
            ->fillForm(fn (FiscalDocumentItem $record): array => [
                'mode' => $record->remittanceAsset?->equipment_id ? 'existing' : 'new',
                'equipment_id' => $record->remittanceAsset?->equipment_id,
                'asset_serial_number' => $record->remittanceAsset?->serial_number,
                'lot_number' => $record->remittanceAsset?->lot_number,
                'received_quantity' => (float) ($record->remittanceAsset?->received_quantity ?? $record->quantity),
                'equipment_name' => $record->remittanceAsset?->equipment?->name,
                'equipment_type' => $record->remittanceAsset?->equipment?->type?->value,
                'placa' => $record->remittanceAsset?->equipment?->placa,
                'mark' => $record->remittanceAsset?->equipment?->mark,
                'model' => $record->remittanceAsset?->equipment?->model,
                'equipment_serial_number' => $record->remittanceAsset?->equipment?->serial_number,
            ])
            ->schema([
                Section::make('Vínculo do equipamento')
                    ->schema([
                        Placeholder::make('owner_name')
                            ->label('Cliente proprietário')
                            ->content(fn (FiscalDocumentItem $record): string => (string) $record->fiscalDocument?->customer?->name),
                        Select::make('mode')
                            ->label('Modo')
                            ->options([
                                'existing' => 'Vincular existente',
                                'new' => 'Cadastrar novo',
                            ])
                            ->default('existing')
                            ->native(false)
                            ->live()
                            ->required(),
                        Select::make('equipment_id')
                            ->label('Equipamento existente')
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('mode') === 'existing')
                            ->required(fn (Get $get): bool => $get('mode') === 'existing')
                            ->getSearchResultsUsing(function (string $search, FiscalDocumentItem $record): array {
                                return app(EquipmentService::class)->searchForSelect(
                                    $search,
                                    Filament::getTenant()->id,
                                    $record->fiscalDocument->customer_id,
                                    20,
                                    ['owner' => false],
                                );
                            })
                            ->getOptionLabelUsing(fn ($value): ?string => filled($value)
                                ? app(EquipmentService::class)->getLabelForSelect((int) $value, ['owner' => false])
                                : null),
                        TextInput::make('equipment_name')
                            ->label('Descrição do equipamento')
                            ->visible(fn (Get $get): bool => $get('mode') === 'new')
                            ->required(fn (Get $get): bool => $get('mode') === 'new')
                            ->maxLength(255),
                        Select::make('equipment_type')
                            ->label('Tipo')
                            ->options(Type::toSelectArray())
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('mode') === 'new')
                            ->required(fn (Get $get): bool => $get('mode') === 'new')
                            ->live(),
                        TextInput::make('placa')
                            ->label('Placa')
                            ->maxLength(7)
                            ->visible(fn (Get $get): bool => $get('mode') === 'new' && in_array($get('equipment_type'), [Type::CAR->value, Type::TRUCK->value], true)),
                        TextInput::make('mark')
                            ->label('Marca')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('mode') === 'new'),
                        TextInput::make('model')
                            ->label('Modelo')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('mode') === 'new'),
                        TextInput::make('equipment_serial_number')
                            ->label('Número de série do equipamento')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('mode') === 'new'),
                    ])
                    ->columns(2),
                Section::make('Dados da remessa')
                    ->schema([
                        TextInput::make('asset_serial_number')
                            ->label('Número de série recebido')
                            ->maxLength(255),
                        TextInput::make('lot_number')
                            ->label('Lote')
                            ->maxLength(255),
                        TextInput::make('received_quantity')
                            ->label('Quantidade recebida')
                            ->numeric()
                            ->minValue(0.0001)
                            ->step('0.0001')
                            ->required(),
                    ])
                    ->columns(3),
            ])
            ->action(function (FiscalDocumentItem $record, array $data, RelationManager $livewire): void {
                $service = app(FiscalDocumentRemittanceAssetService::class);
                $saved = $service->saveForItem($record, $data, Auth::id());

                if ($service->hasError() || $saved === null) {
                    Log::warning('ManageRemittanceAssetAction: falha ao salvar vínculo do equipamento', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'fiscal_document_item_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                    ]);

                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return;
                }

                notify::success($service->getMessageUser());
                $livewire->dispatch('$refresh');
            });
    }
}
