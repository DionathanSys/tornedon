<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Domain\DTO\FiscalDocument\FiscalDocumentItemSourceDTO;
use App\Enum\Tax\IssExigibility;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Tables\ServiceTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use App\Models\Service;
use App\Services\FiscalDocumentItem\FiscalDocumentItemResolverService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class SchemaFormItemsNfse
{
    public static function make(bool $disableQuantity = false, bool $showServiceLookup = true, bool $showValues = true): array
    {
        return [
            Section::make('Serviço')
                ->columnSpanFull()
                ->schema([
                    Hidden::make('service_id')
                        ->visible(! $showServiceLookup),

                    Grid::make([
                        'default' => 1,
                        'md' => 5,
                    ])
                        ->visible($showServiceLookup)
                        ->schema([
                            TextInput::make('service_code_lookup')
                                ->label('Cód.')
                                ->dehydrated(false)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get, $state): null => self::syncServiceByCode($set, $get, $state))
                                ->autocomplete(false)
                                ->columnSpan(1),
                            Select::make('service_lookup_id')
                                ->label('Busca Simples')
                                ->searchable()
                                ->relationship('service', 'name', function ($query) {
                                    $query->where('services.company_id', Filament::getTenant()->id);
                                })
                                ->getOptionLabelFromRecordUsing(fn (Service $record): string => trim("[{$record->service_code}] {$record->name}"))
                                ->dehydrated(false)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get, $state): null => self::syncServiceById($set, $get, $state))
                                ->columnSpan(3),
                            AutoSubmitModalTableSelect::make('service_lookup_modal')
                                ->label('Busca avançada')
                                ->saved(false)
                                ->relationship('service', 'service_code')
                                ->tableConfiguration(ServiceTable::class)
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label('Selecionar')
                                        ->modalHeading('Buscar Serviço')
                                        ->modalSubmitActionLabel('Confirmar seleção')
                                        ->slideOver(false)
                                        ->modalWidth(Width::SevenExtraLarge)
                                )
                                ->afterStateUpdated(fn (Set $set, Get $get, $state): null => self::syncServiceById($set, $get, $state))
                                ->columnSpan(1),
                            TextInput::make('service_name_lookup')
                                ->label('Nome do serviço')
                                ->readOnly()
                                ->columnSpanFull()
                                ->dehydrated(false),
                        ])
                        ->columnSpanFull(),

                    Select::make('service_id')
                        ->label('Serviço do item fiscal')
                        ->visible($showServiceLookup)
                        ->searchable()
                        ->relationship('service', 'name', function ($query) {
                            $query->where('services.company_id', Filament::getTenant()->id);
                        })
                        ->getOptionLabelFromRecordUsing(fn (Service $record): string => trim("[{$record->service_code}] {$record->name}"))
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get, $state): null => self::syncServiceById($set, $get, $state))
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->label('Discriminação do Serviço')
                        ->required()
                        ->maxLength(2000)
                        ->rows(4)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Str::upper($state) : null)
                        ->columnSpanFull(),
                ]),

            Section::make('Valores')
                ->visible($showValues)
                ->columnSpanFull()
                ->schema([
                    ItemValueGroup::make([
                        'totalAmountField' => 'total_price',
                        'disableQuantity' => $disableQuantity,
                        'preserveDiscountOnValueChange' => true,
                    ]),
                ]),

            Section::make('Dados fiscais do serviço')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    TextInput::make('municipal_tax_code')
                        ->label('Código Tributação')
                        ->maxLength(20),
                    TextInput::make('nbs_code')
                        ->label('NBS')
                        ->helperText('A NFS-e Nacional exige NBS com 9 dígitos.')
                        ->maxLength(9),
                    TextInput::make('cnae_code')
                        ->label('CNAE')
                        ->maxLength(7),
                    TextInput::make('iss_rate')
                        ->label('Alíquota ISS (%)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(100),
                    TextInput::make('iss_amount')
                        ->label('Valor ISS')
                        ->numeric()
                        ->inputMode('decimal')
                        ->prefix('R$'),
                    Select::make('iss_exigibility')
                        ->label('Exigibilidade ISS')
                        ->options(IssExigibility::toSelectArray())
                        ->native(false),
                    Toggle::make('iss_withheld')
                        ->label('ISS Retido')
                        ->inline(false)
                        ->default(false),
                ]),

            Textarea::make('additional_information')
                ->label('Informações Adicionais do Item')
                ->rows(2)
                ->maxLength(2000)
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Str::upper($state) : null)
                ->columnSpanFull()
                ->columnStart(1),
        ];
    }

    private static function syncServiceByCode(Set $set, Get $get, mixed $serviceCode): null
    {
        self::syncSelectedService($set, $get, self::findServiceByCode($serviceCode));

        return null;
    }

    private static function syncServiceById(Set $set, Get $get, mixed $serviceId): null
    {
        $service = filled($serviceId)
            ? Service::query()
                ->whereKey((int) $serviceId)
                ->where('company_id', Filament::getTenant()->id)
                ->first()
            : null;

        self::syncSelectedService($set, $get, $service);

        return null;
    }

    private static function syncSelectedService(Set $set, Get $get, ?Service $service): void
    {
        $set('service_id', $service?->id);
        $set('service_lookup_id', $service?->id);
        $set('service_lookup_modal', $service?->id);
        $set('service_code_lookup', $service?->service_code);
        $set('service_name_lookup', $service?->name);

        if (! $service) {
            $set('unit_price', null);
            $set('municipal_tax_code', null);
            $set('nbs_code', null);
            $set('cnae_code', null);
            $set('iss_rate', null);
            $set('iss_amount', null);
            $set('iss_exigibility', null);

            ItemValueGroup::recalculate($get, $set, totalAmountField: 'total_price');

            return;
        }

        self::resolveItem($set, $get, $service->id, Filament::getTenant()->id);
    }

    private static function findServiceByCode(mixed $serviceCode): ?Service
    {
        $serviceCode = trim((string) $serviceCode);

        if ($serviceCode === '') {
            return null;
        }

        $normalizedCode = self::normalizeServiceCode($serviceCode);

        return Service::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where(function ($query) use ($serviceCode, $normalizedCode): void {
                $query->where('service_code', $serviceCode);

                if ($normalizedCode !== $serviceCode) {
                    $query->orWhere('service_code', $normalizedCode);
                }
            })
            ->orderByRaw('CASE WHEN service_code = ? THEN 0 ELSE 1 END', [$serviceCode])
            ->first();
    }

    private static function normalizeServiceCode(string $serviceCode): string
    {
        return ctype_digit($serviceCode)
            ? Str::padLeft($serviceCode, 5, '0')
            : $serviceCode;
    }

    /**
     * Resolve os dados do serviço via serviço especialista e preenche o formulário.
     */
    public static function resolveItem(Set $set, Get $get, int $serviceId, int $companyId): void
    {
        $dto = app(FiscalDocumentItemResolverService::class)
            ->resolveForService($serviceId, $companyId);

        if (! $dto) {
            return;
        }

        self::applyDto($set, $get, $dto);
    }

    /**
     * Aplica os valores do DTO nos campos do formulário.
     */
    private static function applyDto(Set $set, Get $get, FiscalDocumentItemSourceDTO $dto): void
    {
        $set('description', $dto->name);
        $set('unit_price', $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('municipal_tax_code', $dto->serviceCode);
        $set('nbs_code', $dto->nbsCode);
        $set('cnae_code', $dto->cnaeCode);
        $set('iss_rate', $dto->issRate);
        $set('iss_amount', null);
        $set('iss_exigibility', $dto->issExigibility);

        ItemValueGroup::recalculate($get, $set, totalAmountField: 'total_price');
    }
}
