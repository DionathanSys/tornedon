<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Domain\DTO\FiscalDocument\FiscalDocumentItemSourceDTO;
use App\Enum\Tax\IssExigibility;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectService;
use App\Services\FiscalDocumentItem\FiscalDocumentItemResolverService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class SchemaFormItemsNfse
{
    public static function make(): array
    {
        return [
            ModalSelectService::make('service_id')
                ->label('Selecionar Serviço')
                ->afterStateUpdated(function ($state, Set $set) {
                    if (! $state) {
                        return;
                    }

                    self::resolveItem($set, $state, Filament::getTenant()->id);
                })
                ->columnSpanFull(),

            Textarea::make('description')
                ->label('Discriminação do Serviço')
                ->required()
                ->maxLength(2000)
                ->rows(3)
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Str::upper($state) : null)
                ->columnSpanFull(),

            Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('municipal_tax_code')
                        ->label('Código Serviço (LC 116)')
                        ->maxLength(10),
                    TextInput::make('nbs_code')
                        ->label('Código NBS')
                        ->maxLength(10),
                    TextInput::make('cnae_code')
                        ->label('CNAE')
                        ->maxLength(10),
                ]),

            ItemValueGroup::make([
                'totalAmountField' => 'total_price',
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

            Textarea::make('additional_information')
                ->label('Informações Adicionais do Item')
                ->rows(2)
                ->maxLength(2000)
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Str::upper($state) : null)
                ->columnSpanFull(),
        ];
    }

    /**
     * Resolve os dados do serviço via serviço especialista e preenche o formulário.
     */
    public static function resolveItem(Set $set, int $serviceId, int $companyId): void
    {
        $dto = app(FiscalDocumentItemResolverService::class)
            ->resolveForService($serviceId, $companyId);

        if (! $dto) {
            return;
        }

        self::applyDto($set, $dto);
    }

    /**
     * Aplica os valores do DTO nos campos do formulário.
     */
    private static function applyDto(Set $set, FiscalDocumentItemSourceDTO $dto): void
    {
        $set('description', $dto->name);
        $set('unit_price', $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('total_price', $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('municipal_tax_code', $dto->serviceCode);
        $set('nbs_code', $dto->nbsCode);
        $set('cnae_code', $dto->cnaeCode);
        $set('iss_rate', $dto->issRate);
        $set('iss_exigibility', $dto->issExigibility);
    }
}
