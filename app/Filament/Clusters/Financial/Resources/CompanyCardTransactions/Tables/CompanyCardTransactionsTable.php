<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCardTransactions\Tables;

use App\Models\CompanyCreditCard;
use App\Models\Partner;
use App\Notification\NotifyService as notify;
use App\Services\CompanyCard\CompanyCardTransactionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CompanyCardTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('companyCreditCard.name')
                    ->label('Cartão')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('vendor.name')
                    ->label('Fornecedor')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('current_installment')
                    ->label('Parcela')
                    ->formatStateUsing(fn ($state, $record) => sprintf('%s/%s', $state, $record->installments)),
                TextColumn::make('statement_reference_month')
                    ->label('Competência')
                    ->date('m/Y'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-'),
            ])
            ->toolbarActions([
                Action::make('registrar_compra_cartao')
                    ->label('Registrar Compra no Cartão')
                    ->color('success')
                    ->schema([
                        Select::make('company_credit_card_id')
                            ->label('Cartão')
                            ->options(fn (): array => CompanyCreditCard::query()
                                ->where('company_id', Filament::getTenant()->id)
                                ->where('active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false),
                        DatePicker::make('transaction_date')
                            ->label('Data da Compra')
                            ->required()
                            ->default(now()->format('Y-m-d')),
                        TextInput::make('description')
                            ->label('Descrição')
                            ->required()
                            ->maxLength(255),
                        Select::make('vendor_id')
                            ->label('Fornecedor')
                            ->options(fn (): array => Partner::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        TextInput::make('amount')
                            ->label('Valor')
                            ->numeric()
                            ->required(),
                        TextInput::make('installments')
                            ->label('Parcelas')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $service = app(CompanyCardTransactionService::class);

                        $created = $service->createManual([
                            'company_id' => Filament::getTenant()->id,
                            'company_credit_card_id' => $data['company_credit_card_id'],
                            'transaction_date' => $data['transaction_date'],
                            'description' => $data['description'],
                            'vendor_id' => $data['vendor_id'] ?? null,
                            'amount' => $data['amount'],
                            'installments' => $data['installments'],
                        ], (int) Auth::id());

                        if ($service->hasError() || $created === null) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                            return;
                        }

                        notify::success(sprintf('%d transação(ões) de cartão registradas com sucesso.', count($created)));
                    }),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
