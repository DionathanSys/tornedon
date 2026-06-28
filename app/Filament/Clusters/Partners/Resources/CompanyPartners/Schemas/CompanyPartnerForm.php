<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas;

use App\Enum;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Filament\Clusters\Partners\Resources\Addresses\Actions\CreateAddressAction;
use App\Filament\Clusters\Partners\Resources\Addresses\Actions\DeleteAddressAction;
use App\Filament\Clusters\Partners\Resources\Addresses\Actions\EditAddressAction;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions\ImportCnpjData;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions\UpdatePartner;
use App\Filament\Clusters\Partners\Resources\Components\DocumentNumberInput;
use App\Filament\Clusters\Partners\Resources\Contacts\Actions\CreateContactAction;
use App\Filament\Clusters\Partners\Resources\Contacts\Actions\DeleteContactAction;
use App\Filament\Clusters\Partners\Resources\Contacts\Actions\EditContactAction;
use App\Models\Company;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CompanyPartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('has_valid_address')
                    ->visibleOn('edit'),
                Hidden::make('partner_exists')
                    ->visibleOn('create'),
                Hidden::make('partner_id')
                    ->visibleOn('create'),
                Callout::make('Endereço inválido')
                    ->columnSpanFull()
                    ->danger()
                    ->description('Este parceiro não possui um endereço válido. Cadastre ou atualize um endereço para evitar problemas em documentos fiscais.')
                    ->visible(fn(Get $get, $operation): bool => ! ($get('has_valid_address') ?? false) && $operation === 'edit'),
                Section::make('Parceiro')
                    ->columns([
                        'sm' => 1,
                        'md' => 5,
                        'lg' => 9,
                    ])
                    ->columnSpanFull()
                    ->description(
                        fn(Get $get): string => ($get('partner_exists') ?? false)
                            ? 'Cadastro do Parceiro ' . ($get('name') ?? '')
                            : ''
                    )
                    ->collapsible()
                    ->persistCollapsed()
                    ->compact()
                    ->afterHeader([
                        ActionGroup::make([
                            UpdatePartner::make(),
                            ImportCnpjData::make(),
                        ])->buttonGroup(),
                    ])
                    ->schema([
                        //TODO: Implementar junto com Callout
                        // Hidden::make('alert_partner_exists'),
                        Select::make('document_type')
                            ->label('Tipo de Doc.')

                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options([
                                'cpf' => 'CPF',
                                'cnpj' => 'CNPJ',
                            ])
                            ->default('cnpj')
                            ->native(false)
                            ->disabledOn('edit')
                            ->afterStateUpdatedJs(<<<'JS'
                                $set('document_number', null)
                            JS),
                        DocumentNumberInput::make()
                            ->disabledOn('edit'),
                        //TODO: Implementar Callout
                        // Callout::make()
                        //     ->description('Parceiro já cadastrado')
                        //     ->info()
                        //     ->visible(fn(Get $get): bool => ($get('alert_partner_exists') ?? false)),
                        TextInput::make('name')
                            ->label('Nome')
                            ->autocomplete(false)
                            ->maxLength(60)
                            ->validationMessages([
                                'max' => 'O nome do parceiro deve ter no máximo 60 caracteres.',
                            ])
                            ->columnSpan(['md' => 3, 'lg' => 5])
                            ->disabledOn('edit'),
                        TextInput::make('state_tax_id')
                            ->label('Inscricao Estadual')
                            ->placeholder('Não definido')
                            ->live()
                            ->columnStart(1)
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->autocomplete(false)
                            ->numeric()
                            ->disabledOn('edit'),
                        TextInput::make('municipal_tax_id')
                            ->label('Inscricao Municipal')
                            ->placeholder('Não definido')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->numeric()
                            ->disabledOn('edit'),
                        Select::make('state_tax_indicator')
                            ->label('Indicador IE')
                            ->columnSpanFull()
                            ->options(Enum\Tax\StateTaxIndicator::toSelectArray())
                            ->native(false)
                            ->default(Enum\Tax\StateTaxIndicator::NAO_CONTRIBUINTE->value)
                            ->disabledOn('edit')
                            ->columnSpan(['md' => 3, 'lg' => 5]),
                    ]),

                Section::make('Configurações da Empresa')
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 8,])
                    ->columnSpanFull()
                    ->description('Dados de vinculo entre Empresa e Parceiro')
                    ->compact()
                    ->schema([
                        Grid::make()
                            ->columns(['sm' => 1, 'md' => 10, 'lg' => 12])
                            ->columnSpanFull()
                            ->schema([
                                Money::make('company_partner.invoice_threshold')
                                    ->label('Vlr. Min p/ Fatura')
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                                    ->default(0),
                                Money::make('company_partner.customer_discount_percentage')
                                    ->label('Desconto Cliente (%)')
                                    ->suffix('%')
                                    ->prefix(null)
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->formatStateUsing(fn($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                                    ->default(0),
                                Select::make('company_partner.payment_method')
                                    ->label('Método de Pagamento')
                                    ->options(PaymentMethod::toSelectArray())
                                    ->columnSpan(['md' => 2, 'lg' => 3])
                                    ->native(false)
                                    ->searchable()
                                    ->placeholder('Usar preferências'),
                                Select::make('company_partner.payment_condition')
                                    ->label('Condição de Pagamento')
                                    ->options(PaymentCondition::toGroupedSelectArray())
                                    ->columnSpan(['md' => 2, 'lg' => 3])
                                    ->native(false)
                                    ->searchable()
                                    ->placeholder('Usar preferências'),
                                Toggle::make('company_partner.is_active')
                                    ->label('Ativo')
                                    ->columnSpan(2)
                                    ->inline(false)
                                    ->default(true)
                                    ->required(),
                            ]),
                        CheckboxList::make('company_partner.type')
                            ->label('Tipo')
                            ->columnStart(1)
                            ->columnSpanFull()
                            ->options(Enum\Partner\Type::toSelectArray())
                            ->columns(4)
                            ->default([Enum\Partner\Type::CUSTOMER->value])
                            ->required(),

                    ]),

                Section::make('Configurações de Notificações')
                    ->columns(['sm' => 1, 'md' => 4, 'lg' => 8,])
                    ->columnSpanFull()
                    ->collapsed()
                    ->persistCollapsed()
                    ->compact()
                    ->schema([
                        Grid::make()
                            ->columns(['sm' => 1, 'md' => 6, 'lg' => 12])
                            ->columnSpanFull()
                            ->schema([
                                Toggle::make('company_partner.notify_service_order_closed')
                                    ->label('OS Encerrada')
                                    ->inline(false)
                                    ->default(false)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                Toggle::make('company_partner.notify_requisition_closed')
                                    ->label('Requisição Encerrada')
                                    ->inline(false)
                                    ->default(false)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                Toggle::make('company_partner.notify_production_order_closed')
                                    ->label('OP Encerrada')
                                    ->inline(false)
                                    ->default(false)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                Toggle::make('company_partner.notify_invoice_confirmed')
                                    ->label('Fatura Confirmada')
                                    ->inline(false)
                                    ->default(false)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                Toggle::make('company_partner.notify_fiscal_document_confirmed')
                                    ->label('NF Confirmada')
                                    ->inline(false)
                                    ->default(false)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                            ]),
                        Callout::make()
                            ->description('Apenas preencha os campos abaixo se deseja enviar emails para endereços diferentes do contato principal cadastrado')
                            ->warning()
                            ->columnSpanFull(),
                        Grid::make()
                            ->columns(['sm' => 1, 'md' => 6, 'lg' => 12])
                            ->columnSpanFull()
                            ->schema([
                                Textarea::make('company_partner.email_to_override')
                                    ->label('TO Override')
                                    ->rows(1)
                                    ->placeholder('cliente@exemplo.com;compras@exemplo.com')
                                    ->helperText('Opcional: separador por ; ou ,')
                                    ->columnSpan(['md' => 2, 'lg' => 4]),
                                Textarea::make('company_partner.email_cc_override')
                                    ->label('CC Override')
                                    ->rows(1)
                                    ->placeholder('financeiro@exemplo.com')
                                    ->helperText('Opcional: separador por ; ou ,')
                                    ->columnSpan(['md' => 2, 'lg' => 4]),
                                Textarea::make('company_partner.email_bcc_override')
                                    ->label('BCC Override')
                                    ->rows(1)
                                    ->placeholder('auditoria@exemplo.com')
                                    ->helperText('Opcional: separador por ; ou ,')
                                    ->columnSpan(['md' => 2, 'lg' => 4]),
                            ]),
                    ]),
                Section::make('Replicar para outras Empresas')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->description('Copiar este parceiro para outras empresas')
                    ->visibleOn('create')
                    ->compact()
                    ->schema([
                        CheckboxList::make('target_company_ids')
                            ->label('Empresas de destino')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->helperText('Selecione as empresas para as quais deseja copiar este parceiro')
                            ->options(function () {
                                $currentUser = Auth::user();
                                $userCompanies = $currentUser->companies->pluck('companies.id');
                                $currentCompanyId = Filament::getTenant()->id;

                                return Company::whereIn('id', $userCompanies)
                                    ->where('id', '!=', $currentCompanyId)
                                    ->pluck('name', 'id');
                            })
                            ->columns(2),
                    ]),
                Section::make()
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->description('Endereço(s) do Parceiro')
                    ->afterHeader([
                        CreateAddressAction::make(),
                    ])
                    ->collapsible()
                    ->visibleOn(['edit', 'view'])
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('addresses')
                            ->hiddenLabel()
                            ->columns([
                                'sm' => 1,
                                'md' => 2,
                                'lg' => 4,
                            ])
                            ->schema(fn(Schema $schema) => $schema->components([
                                TextEntry::make('id')
                                    ->label('ID')
                                    ->placeholder('-')
                                    ->hidden(),
                                TextEntry::make('full_address')
                                    ->label('Endereço Completo')
                                    ->placeholder('-')
                                    ->columnSpanFull()
                                    ->belowContent(
                                        fn($record) => Schema::start([
                                            EditAddressAction::make()
                                                ->arguments(['address_id' => $record?->id]),
                                            DeleteAddressAction::make()
                                                ->arguments(['address_id' => $record?->id]),
                                        ])
                                    ),
                            ]))
                            ->columnSpanFull(),
                    ]),
                Section::make()
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->description('Contato(s) do Parceiro')
                    ->afterHeader([
                        CreateContactAction::make(),
                    ])
                    ->collapsible()
                    ->visibleOn(['edit', 'view'])
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('contacts')
                            ->hiddenLabel()
                            ->columns([
                                'sm' => 1,
                                'md' => 6,
                                'lg' => 8,
                            ])
                            ->schema(fn(Schema $schema) => $schema->components([
                                TextEntry::make('id')
                                    ->label('ID')
                                    ->placeholder('-')
                                    ->hidden(),
                                TextEntry::make('email')
                                    ->label('E-mail')
                                    ->placeholder('-')
                                    ->icon(Heroicon::Envelope)
                                    ->columnSpan(['md' => 2, 'lg' => 2])
                                    ->belowContent(
                                        fn($record) => Schema::start([
                                            EditContactAction::make()
                                                ->arguments(['contact_id' => $record?->id]),
                                            DeleteContactAction::make()
                                                ->arguments(['contact_id' => $record?->id]),
                                        ]),
                                    ),
                                TextEntry::make('phone')
                                    ->label('Telefone')
                                    ->placeholder('-')
                                    ->icon(Heroicon::Phone)
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                TextEntry::make('mobile')
                                    ->label('Celular')
                                    ->placeholder('-')
                                    ->icon(Heroicon::DevicePhoneMobile)
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                TextEntry::make('notify')
                                    ->label('Recebe Notificações')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state ? 'Sim' : 'Não')
                                    ->color(fn($state) => $state ? 'success' : 'gray')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->visible(fn(Get $get, $record) => $record->is_active),
                                TextEntry::make('is_active')
                                    ->label('Ativo')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state ? 'Ativo' : 'Inativo')
                                    ->color(fn($state) => $state ? 'success' : 'danger')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->hidden(fn(Get $get, $record) => $record->is_active),
                            ]))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
