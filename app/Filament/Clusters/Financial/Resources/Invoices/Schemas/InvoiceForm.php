<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Schemas;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Financial\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Clusters\Financial\Resources\Invoices\RelationManagers\FiscalDocumentsRelationManager;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\CompanyPartnerResource;
use App\Models\CompanyPartner;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['sm' => 1, 'md' => 4, 'lg' => 12,])
            ->components([
                Section::make('Dados da Fatura')
                    ->columns(['sm' => 1, 'md' => 6, 'lg' => 12,])
                    ->columnSpanFull()
                    ->schema([
                        Callout::make('Endereço do cliente inválido')
                            ->warning()
                            ->columnSpanFull()
                            ->description('Cliente não possui endereço válido. Atualize o cadastro antes de prosseguir com documentos fiscais.')
                            ->visible(function (?Invoice $record, string $operation): bool {
                                if (! $record instanceof Invoice || $operation !== 'edit') {
                                    return false;
                                }

                                $companyPartner = CompanyPartner::query()
                                    ->where('company_id', $record->company_id)
                                    ->where('partner_id', $record->customer_id)
                                    ->first();

                                if ($companyPartner === null) {
                                    return false;
                                }

                                return ! $companyPartner->hasValidPrimaryAddress();
                            })
                            ->actions([
                                Action::make('edit_company_partner')
                                    ->label('Abrir cadastro do parceiro')
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->url(function (?Invoice $record): ?string {
                                        if (! $record instanceof Invoice) {
                                            return null;
                                        }

                                        $companyPartner = CompanyPartner::query()
                                            ->where('company_id', $record->company_id)
                                            ->where('partner_id', $record->customer_id)
                                            ->first();

                                        if ($companyPartner === null) {
                                            return null;
                                        }

                                        return CompanyPartnerResource::getUrl('edit', ['record' => $companyPartner->id]);
                                    })
                                    ->openUrlInNewTab(),
                            ]),
                        Hidden::make('company_id'),
                        Hidden::make('created_by'),
                        Hidden::make('updated_by'),
                        TextEntry::make('customer.name')
                            ->label('Cliente')
                            ->columnSpan(['md' => 3, 'lg' => 6]),
                        TextEntry::make('createdBy.name')
                            ->label('Criado por')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextEntry::make('created_at')
                            ->label('Data da Criação')
                            ->since()
                            ->dateTooltip('d/m/Y H:i:s')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextEntry::make('invoice_number')
                            ->label('Número da Fatura')
                            ->columnStart(1)
                            ->columnSpan(['md' => 2]),
                        TextEntry::make('invoice_date')
                            ->label('Data da Fatura')
                            ->columnSpan(['md' => 2])
                            ->formatStateUsing(fn(?Invoice $record): string => $record->invoice_date->format('d/m/Y')),
                        TextEntry::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2])
                            ->state(fn(Invoice $record): string => $record->payment_method?->description() ?? 'Não definida'),
                        TextEntry::make('payment_condition')
                            ->label('Condição de Pagamento')
                            ->columnSpan(['md' => 2])
                            ->state(fn(Invoice $record): string => $record->payment_condition?->description() ?? 'Não definida'),
                        TextEntry::make('services_amount')
                            ->label('Valor de Serviços')
                            ->columnStart(1)
                            ->money('BRL')
                            ->tooltip(fn(?Invoice $record): string => 'Total líquido das OS vinculadas.')
                            ->columnSpan(['md' => 2]),
                        TextEntry::make('products_amount')
                            ->label('Valor de Produtos')
                            ->money('BRL')
                            ->tooltip(fn(?Invoice $record): string => 'Total líquido das requisições vinculadas.')
                            ->columnSpan(['md' => 2]),
                        TextEntry::make('discount_amount')
                            ->label('Desconto')
                            ->money('BRL')
                            ->tooltip(fn(?Invoice $record): string => 'Total de descontos da fatura.')
                            ->columnSpan(['md' => 2]),
                        TextEntry::make('total_amount')
                            ->label('Valor Total')
                            ->money('BRL')
                            ->columnSpan(['md' => 2]),
                    ]),
                Section::make('')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->columns(1)
                    ->visible(fn (?Invoice $record): bool => $record->fiscalDocuments()->exists())
                    ->contained(false)
                    ->schema([
                        Livewire::make(FiscalDocumentsRelationManager::class, fn(Invoice $record) => [
                            'ownerRecord' => $record,
                            'pageClass' => EditInvoice::class,
                        ])

                            ->columnSpanFull(),
                    ]),

            ]);
    }
}
