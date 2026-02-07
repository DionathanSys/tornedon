<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use App\Enum;
use App\Filament\Clusters\Partners\Resources\Addresses\Actions\CreateAddressAction;
use App\Filament\Clusters\Partners\Resources\Addresses\Actions\DeleteAddressAction;
use App\Filament\Clusters\Partners\Resources\Addresses\Actions\EditAddressAction;
use App\Filament\Clusters\Partners\Resources\Addresses\AddressResource;
use App\Filament\Clusters\Partners\Resources\Addresses\Components\AddressComponent;
use App\Filament\Clusters\Partners\Resources\Contacts\Actions\CreateContactAction;
use App\Filament\Clusters\Partners\Resources\Contacts\Actions\DeleteContactAction;
use App\Filament\Clusters\Partners\Resources\Contacts\Actions\EditContactAction;
use App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions\UpdatePartner;
use App\Filament\Clusters\Partners\Resources\Components\DocumentNumberInput;
use App\Models\CompanyPartner;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Leandrocfe\FilamentPtbrFormFields\Document;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CompanyPartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('partner_exists')
                    ->visibleOn('create'),
                Hidden::make('partner_id')
                    ->visibleOn('create'),
                Section::make('Parceiro')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->disabled(fn(Get $get): bool => $get('partner_exists') ?? false)
                    ->description(
                        fn(Get $get): string => ($get('partner_exists') ?? false)
                            ? 'Cadastro do Parceiro ' . ($get('name') ?? '')
                            : ''
                    )
                    ->collapsible()
                    ->persistCollapsed()
                    ->compact()
                    ->afterHeader([
                        UpdatePartner::make()
                    ])
                    ->schema([
                        Select::make('document_type')
                            ->label('Tipo de Doc.')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options([
                                'cpf' => 'CPF',
                                'cnpj' => 'CNPJ',
                            ])
                            ->default('cnpj')
                            ->native(false)
                            ->required()
                            ->afterStateUpdatedJs(<<<'JS'
                                $set('document_number', null)
                            JS),
                        DocumentNumberInput::make(),
                        TextInput::make('name')
                            ->label('Nome')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->required(),
                        TextInput::make('state_tax_id')
                            ->label('Inscrição Estadual')
                            ->placeholder('Não definido')
                            ->live()
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->autocomplete(false)
                            ->numeric(),
                        TextInput::make('municipal_tax_id')
                            ->label('Inscrição Municipal')
                            ->placeholder('Não definido')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric(),
                        Select::make('state_tax_indicator')
                            ->label('Indicador IE')
                            ->columnSpanFull()
                            ->options(Enum\Tax\StateTaxIndicator::toSelectArray())
                            ->native(false),
                    ]),
                Section::make('Configurações da Empresa')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->description('Dados de vínculo entre Empresa e Parceiro')
                    ->compact()
                    ->schema([
                        Select::make('company_partner.type')
                            ->label('Tipo')
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->options(Enum\Partner\Type::toSelectArray())
                            ->native(false)
                            ->multiple()
                            ->default(Enum\Partner\Type::CUSTOMER->value)
                            ->required(),
                        Money::make('company_partner.invoice_threshold')
                            ->label('Vlr. Mín p/ Fatura')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->default(0),
                        Toggle::make('company_partner.is_active')
                            ->label('Ativo')
                            ->columnSpan(2)
                            ->inline(false)
                            ->default(true)
                            ->required(),
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
                    ->persistCollapsed()
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('addresses')
                            ->hiddenLabel()
                            ->label('Registros')
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
                                        fn($record) =>
                                        Schema::start([
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
                    ->persistCollapsed()
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('contacts')
                            ->hiddenLabel()
                            ->label('Registros')
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
                                        fn($record) =>
                                        Schema::start([
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
                                    ->columnSpan(['md' => 1, 'lg' => 1]),
                                TextEntry::make('mobile')
                                    ->label('Celular')
                                    ->placeholder('-')
                                    ->icon(Heroicon::DevicePhoneMobile)
                                    ->columnSpan(['md' => 1, 'lg' => 1]),
                                TextEntry::make('notify')
                                    ->label('Recebe Notificações')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state ? 'Sim' : 'Não')
                                    ->color(fn($state) => $state ? 'success' : 'gray')
                                    ->columnSpan(['md' => 1, 'lg' => 2]),
                                TextEntry::make('is_active')
                                    ->label('Ativo')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $state ? 'Ativo' : 'Inativo')
                                    ->color(fn($state) => $state ? 'success' : 'danger')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ,
                            ]))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
