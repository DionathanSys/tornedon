<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Actions;

use App\Enum\FiscalDocument\OperationType;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\Type;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\FiscalDocument;
use App\Models\RemittanceAsset;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\FiscalDocumentServiceOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateServiceOrderFromEntryAction
{
    public static function make(): Action
    {
        return Action::make('createServiceOrderFromEntry')
            ->label('Criar ordem de serviço')
            ->icon(Heroicon::ClipboardDocumentList)
            ->color('primary')
            ->modalWidth(Width::ExtraLarge)
            ->modalHeading('Criar ordem de serviço a partir da nota')
            ->visible(fn (FiscalDocument $record): bool => dd($record->operation_type, OperationType::ENTRADA->value, $record->operation_type === OperationType::ENTRADA->value))
            ->schema([
                Tabs::make('createServiceOrderFromEntryTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Dados iniciais')
                            ->schema([
                                Section::make('Itens disponíveis')
                                    ->schema([
                                        CheckboxList::make('remittance_asset_ids')
                                            ->label('Equipamentos disponíveis para a OS')
                                            ->options(fn (FiscalDocument $record): array => self::buildAssetOptions($record))
                                            ->helperText(fn (FiscalDocument $record): ?string => self::hasAvailableAssets($record)
                                                ? null
                                                : 'Vincule ao menos um equipamento aos itens da nota para criar a ordem de serviço.')
                                            ->required()
                                            ->columns(1)
                                            ->live()
                                            ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                                                $selected = collect($state ?? [])->filter()->values();
                                                $currentPrimary = $get('primary_remittance_asset_id');

                                                if ($selected->count() === 1) {
                                                    $set('primary_remittance_asset_id', (int) $selected->first());

                                                    return;
                                                }

                                                if ($currentPrimary !== null && ! $selected->contains((string) $currentPrimary) && ! $selected->contains((int) $currentPrimary)) {
                                                    $set('primary_remittance_asset_id', null);
                                                }
                                            }),
                                        Select::make('primary_remittance_asset_id')
                                            ->label('Equipamento principal da OS')
                                            ->options(fn (Get $get, FiscalDocument $record): array => self::buildPrimaryAssetOptions($record, $get('remittance_asset_ids') ?? []))
                                            ->native(false)
                                            ->required(),
                                    ]),
                                Section::make('Dados iniciais da OS')
                                    ->schema([
                                        DatePicker::make('order_date')
                                            ->label('Data da ordem')
                                            ->default(now())
                                            ->required(),
                                        Select::make('priority')
                                            ->label('Prioridade')
                                            ->options(Priority::toSelectArray())
                                            ->default(Priority::NORMAL->value)
                                            ->native(false)
                                            ->required(),
                                        Select::make('type')
                                            ->label('Tipo')
                                            ->options(Type::toSelectArray())
                                            ->default(Type::MAINTENANCE->value)
                                            ->native(false)
                                            ->required(),
                                        Toggle::make('open_service_order')
                                            ->label('Abrir OS após criar')
                                            ->default(true)
                                            ->inline(false),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Observações')
                            ->schema([
                                Section::make('Anotações')
                                    ->schema([
                                        Textarea::make('customer_observations')
                                            ->label('Observações Cliente')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Textarea::make('general_observations')
                                            ->label('Observações gerais')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Textarea::make('items_received')
                                            ->label('Itens recebidos')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ])
            ->action(function (FiscalDocument $record, array $data): void {
                if (! self::hasAvailableAssets($record)) {
                    notify::error(message: 'Vincule ao menos um equipamento aos itens da nota antes de criar a ordem de serviço.');

                    return;
                }

                $service = app(FiscalDocumentServiceOrderService::class);
                $serviceOrder = $service->createFromEntryDocument($record, $data, Auth::id());

                if ($service->hasError() || $serviceOrder === null) {
                    Log::warning('CreateServiceOrderFromEntryAction: falha ao criar OS a partir da nota', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'fiscal_document_id' => $record->id,
                        'message' => $service->getMessage(),
                        'error_code' => $service->getErrorCode(),
                    ]);

                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return;
                }

                notify::success($service->getMessageUser());

                if (($data['open_service_order'] ?? true) === true) {
                    redirect(ServiceOrderResource::getUrl('edit', ['record' => $serviceOrder]));
                }
            });
    }

    private static function hasAvailableAssets(FiscalDocument $record): bool
    {
        return $record->remittanceAssets()
            ->whereNotNull('equipment_id')
            ->exists();
    }

    private static function buildAssetOptions(FiscalDocument $record): array
    {
        return $record->remittanceAssets()
            ->with(['equipment', 'fiscalDocumentItem'])
            ->whereNotNull('equipment_id')
            ->get()
            ->mapWithKeys(fn (RemittanceAsset $asset): array => [
                $asset->id => self::formatAssetLabel($asset),
            ])
            ->all();
    }

    private static function buildPrimaryAssetOptions(FiscalDocument $record, array $selectedIds): array
    {
        $selected = collect($selectedIds)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($selected === []) {
            return [];
        }

        return $record->remittanceAssets()
            ->with('equipment', 'fiscalDocumentItem')
            ->whereIn('id', $selected)
            ->get()
            ->mapWithKeys(fn (RemittanceAsset $asset): array => [
                $asset->id => self::formatAssetLabel($asset),
            ])
            ->all();
    }

    private static function formatAssetLabel(RemittanceAsset $asset): string
    {
        $parts = array_filter([
            $asset->equipment?->name,
            $asset->serial_number ? 'Serie '.$asset->serial_number : null,
            $asset->fiscalDocumentItem?->description,
            'Qtde. '.number_format((float) $asset->received_quantity, 4, ',', '.'),
        ]);

        return implode(' | ', $parts);
    }
}
