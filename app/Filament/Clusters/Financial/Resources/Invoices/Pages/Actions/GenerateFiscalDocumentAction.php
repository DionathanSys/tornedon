<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages\Actions;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfseDescriptionMode;
use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\VolumeSpecies;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\Invoice;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use App\Services\Partner\PartnerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class GenerateFiscalDocumentAction
{
    public static function make(DocumentModel $documentType): Action
    {
        $isNfse = $documentType === DocumentModel::NFSE;
        $actionName = $isNfse ? 'generateNfseDocument' : 'generateNfeDocument';

        return Action::make($actionName)
            ->label($isNfse ? 'Gerar NFS-e' : 'Gerar NF-e')
            ->icon(Heroicon::DocumentPlus)
            ->color('primary')
            ->modalHeading($isNfse ? 'Gerar NFS-e' : 'Gerar NF-e')
            ->modalDescription('Um documento fiscal será criado a partir dos itens desta fatura. Preencha os dados obrigatórios abaixo.')
            ->modalSubmitActionLabel('Gerar')
            ->disabled(fn (Invoice $record): bool => $record->fiscalDocuments()->where('document_type', $documentType->value)->exists())
            ->schema(function (Invoice $record) use ($documentType, $isNfse): array {
                $invoiceService = app(InvoiceService::class);
                $defaultDescription = $isNfse
                    ? $invoiceService->buildNfseItemDescription($record, NfseDescriptionMode::AUTO->value)
                    : null;

                return [
                    Section::make('Dados do Documento Fiscal')
                        ->schema([
                            Select::make('document_type')
                                ->label('Tipo de Documento')
                                ->options([$documentType->value => $documentType->description()])
                                ->default($documentType->value)
                                ->native(false)
                                ->required()
                                ->disabled(),

                            Select::make('nfse_model')
                                ->label('Modelo NFS-e')
                                ->options(NfseModel::toSelectArray())
                                ->default(NfseModel::MUNICIPAL->value)
                                ->native(false)
                                ->required()
                                ->visible($isNfse),

                            Select::make('operation_nature')
                                ->label('Natureza da Operação')
                                ->options(OperationNature::toSelectArray())
                                ->default(OperationNature::VENDA_DENTRO_ESTADO->value)
                                ->columnSpanFull()
                                ->searchable()
                                ->required(! $isNfse)
                                ->visible(! $isNfse),

                            Select::make('operation_type')
                                ->label('Tipo de Operação')
                                ->options(OperationType::toSelectArray())
                                ->default(OperationType::SAIDA->value)
                                ->required(! $isNfse)
                                ->visible(! $isNfse),

                            Select::make('issue_purpose')
                                ->label('Finalidade de Emissão')
                                ->options(IssuePurpose::toSelectArray())
                                ->default(IssuePurpose::NORMAL->value)
                                ->required(! $isNfse)
                                ->visible(! $isNfse),
                        ])->columns(3),

                    Section::make('Descrição da NFS-e')
                        ->schema([
                            Select::make('nfse_description_mode')
                                ->label('Regra da descrição automática')
                                ->options(NfseDescriptionMode::toSelectArray())
                                ->default(NfseDescriptionMode::AUTO->value)
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set) use ($record, $invoiceService): void {
                                    $set('nfse_item_description', $invoiceService->buildNfseItemDescription($record, (string) $state));
                                }),

                            Textarea::make('nfse_item_description')
                                ->label('Descrição do item da NFS-e')
                                ->default($defaultDescription)
                                ->rows(4)
                                ->live()
                                ->maxLength(2000)
                                ->required(),

                            Placeholder::make('nfse_description_counter')
                                ->label('Contador')
                                ->content(fn (Get $get): string => mb_strlen((string) ($get('nfse_item_description') ?? '')) . '/2000 caracteres'),
                        ])
                        ->columns(1)
                        ->visible($isNfse),

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
                        ])
                        ->columns(2)
                        ->visible(! $isNfse),

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
                        ])
                        ->columns(2)
                        ->visible(! $isNfse),

                    Section::make('Datas')
                        ->schema([
                            DatePicker::make('issued_at')
                                ->label('Data de Emissão')
                                ->default(now())
                                ->required(),

                            DatePicker::make('movement_at')
                                ->label('Data de Movimentação')
                                ->default(now())
                                ->required(! $isNfse)
                                ->visible(! $isNfse),

                            Toggle::make('open_document_after_confirm')
                                ->label('Abrir documento após confirmar?')
                                ->default(true),
                        ])->columns(2),
                ];
            })
            ->action(function (Invoice $record, array $data) use ($documentType, $isNfse): void {
                if ($record->fiscalDocuments()->where('document_type', $documentType->value)->exists()) {
                    notify::warning('Já existe um documento deste tipo para esta fatura.');
                    return;
                }

                if ($isNfse) {
                    $fiscalData = [
                        'document_type' => DocumentModel::NFSE->value,
                        'nfse_model' => $data['nfse_model'],
                        'issued_at' => $data['issued_at'],
                        'nfse_item_description' => $data['nfse_item_description'] ?? null,
                        'nfse_description_mode' => $data['nfse_description_mode'] ?? NfseDescriptionMode::AUTO->value,
                    ];
                } else {
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
                        'document_type' => DocumentModel::NFE->value,
                        'operation_nature' => $data['operation_nature'],
                        'operation_type' => $data['operation_type'],
                        'issue_purpose' => $data['issue_purpose'],
                        'is_final_consumer' => $data['is_final_consumer'] ?? true,
                        'buyer_presence_indicator' => $data['buyer_presence_indicator'],
                        'issued_at' => $data['issued_at'],
                        'movement_at' => $data['movement_at'],
                        'freight_data' => $freightData,
                    ];
                }

                $userId = Auth::id();

                $service = app(InvoiceService::class);
                $fiscalDocument = $service->createFiscalDocument($record, $fiscalData, $userId);

                if ($service->hasError() || $fiscalDocument === null) {
                    Log::error('GenerateFiscalDocumentAction: Erro ao gerar documento fiscal', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'error_code' => $service->getErrorCode(),
                        'message' => $service->getMessage(),
                        'invoice_id' => $record->id,
                    ]);

                    notify::error(
                        message: $service->getMessageUser(),
                        errorCode: $service->getErrorCode()
                    );
                    return;
                }

                Log::info('GenerateFiscalDocumentAction: Documento fiscal gerado com sucesso', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $record->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                notify::success('Documento fiscal gerado com sucesso.');

                if (($data['open_document_after_confirm'] ?? false) === true) {
                    redirect(FiscalDocumentResource::getUrl('edit', ['record' => $fiscalDocument]));
                }
            });
    }
}
