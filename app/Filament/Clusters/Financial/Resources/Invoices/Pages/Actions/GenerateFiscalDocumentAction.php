<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\VolumeSpecies;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use App\Services\Partner\PartnerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class GenerateFiscalDocumentAction
{
    public static function make(): Action
    {
        return Action::make('generateFiscalDocument')
            ->label('Gerar Documento Fiscal')
            ->icon(Heroicon::DocumentText)
            ->color('success')
            ->modalHeading('Gerar Documento Fiscal')
            ->modalDescription('Um documento fiscal será criado a partir dos itens desta fatura. Preencha os dados obrigatórios abaixo.')
            ->modalSubmitActionLabel('Gerar')
            ->visible(function (Invoice $record): bool {
                return $record->fiscalDocuments()->doesntExist()
                    && $record->requisitions()->exists();
            })
            ->schema(function (): array {
                return [
                    Section::make('Dados do Documento Fiscal')
                        ->schema([
                            Select::make('operation_nature')
                                ->label('Natureza da Operação')
                                ->options(OperationNature::toSelectArray())
                                ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                                ->columnSpanFull()
                                ->searchable()
                                ->required(),

                            Select::make('operation_type')
                                ->label('Tipo de Operação')
                                ->options(OperationType::toSelectArray())
                                ->default(OperationType::SAIDA->value)
                                ->required(),

                            Select::make('issue_purpose')
                                ->label('Finalidade de Emissão')
                                ->options(IssuePurpose::toSelectArray())
                                ->default(IssuePurpose::NORMAL->value)
                                ->required(),
                        ])->columns(3),

                    Section::make('Destinatário')
                        ->schema([
                            Toggle::make('is_final_consumer')
                                ->label('Consumidor Final')
                                ->default(true),

                            Select::make('buyer_presence_indicator')
                                ->label('Presença do Comprador')
                                ->options(BuyerPresenceIndicator::toSelectArray())
                                ->default(BuyerPresenceIndicator::PRESENCIAL->value)
                                ->required(),
                        ])->columns(2),

                    Section::make('Frete')
                        ->columnSpanFull()
                        ->schema([
                            Select::make('freight_modality')
                                ->label('Modalidade de Frete')
                                ->options(FreightModality::toSelectArray())
                                ->default(FreightModality::SEM_FRETE->value)
                                ->required()
                                ->columnSpanFull(),

                            Select::make('carrier_id')
                                ->label('Transportador')
                                ->searchable()
                                ->getSearchResultsUsing(
                                    fn (string $search): array => (new PartnerService())
                                        ->searchForSelect($search, Filament::getTenant()->id, 'carrier')
                                )
                                ->getOptionLabelUsing(
                                    fn ($value): ?string => (new PartnerService())
                                        ->getLabelForSelect((int) $value)
                                )
                                ->columnSpanFull(),

                            Fieldset::make('Volumes')
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('volume_quantidade')
                                        ->label('Quantidade')
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(1),

                                    Select::make('volume_especie')
                                        ->label('Espécie')
                                        ->options(VolumeSpecies::toSelectArray())
                                        ->searchable()
                                        ->default(VolumeSpecies::VOLUME->value),

                                    TextInput::make('volume_peso_liquido')
                                        ->label('Peso Líquido (kg)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.001),

                                    TextInput::make('volume_peso_bruto')
                                        ->label('Peso Bruto (kg)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step(0.001),
                                ])->columns(4),
                        ])->columns(2),

                    Section::make('Datas')
                        ->schema([
                            DatePicker::make('issued_at')
                                ->label('Data de Emissão')
                                ->default(now())
                                ->required(),

                            DatePicker::make('movement_at')
                                ->label('Data de Movimentação')
                                ->default(now())
                                ->required(),
                        ])->columns(2),
                ];
            })
            ->action(function (Invoice $record, array $data): void {
                $freightData = [
                    'modalidade_frete' => $data['freight_modality'],
                ];

                if (! empty($data['carrier_id'])) {
                    $freightData['transportador'] = [
                        'id' => $data['carrier_id'],
                    ];
                }

                if (! empty($data['volume_quantidade']) || ! empty($data['volume_especie'])) {
                    $volume = [];

                    if (! empty($data['volume_quantidade'])) {
                        $volume['quantidade'] = (int) $data['volume_quantidade'];
                    }
                    if (! empty($data['volume_especie'])) {
                        $volume['especie'] = $data['volume_especie'];
                    }
                    if (isset($data['volume_peso_liquido'])) {
                        $volume['peso_liquido'] = (float) $data['volume_peso_liquido'];
                    }
                    if (isset($data['volume_peso_bruto'])) {
                        $volume['peso_bruto'] = (float) $data['volume_peso_bruto'];
                    }

                    $freightData['volumes'] = [$volume];
                }

                $fiscalData = [
                    'document_type'             => DocumentModel::NFE->value,
                    'operation_nature'          => $data['operation_nature'],
                    'operation_type'            => $data['operation_type'],
                    'issue_purpose'             => $data['issue_purpose'],
                    'is_final_consumer'         => $data['is_final_consumer'] ?? true,
                    'buyer_presence_indicator'  => $data['buyer_presence_indicator'],
                    'issued_at'                => $data['issued_at'],
                    'movement_at'              => $data['movement_at'],
                    'freight_data'             => $freightData,
                ];

                $userId = Auth::id();

                $service = app(InvoiceService::class);
                $fiscalDocument = $service->createFiscalDocument($record, $fiscalData, $userId);

                if ($service->hasError() || $fiscalDocument === null) {
                    Log::error('GenerateFiscalDocumentAction: Erro ao gerar documento fiscal', [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $service->getErrorCode(),
                        'message'    => $service->getMessage(),
                        'invoice_id' => $record->id,
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );
                    return;
                }

                Log::info('GenerateFiscalDocumentAction: Documento fiscal gerado com sucesso', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $record->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                notify::success('Documento fiscal gerado com sucesso.');
            });
    }
}
