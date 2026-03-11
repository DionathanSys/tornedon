<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Product\Unit;
use App\Enum\Quote\Status;
use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\EditQuote;
use App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\ItemsRelationManager;
use App\Models\CompanyPreference;
use App\Models\Quote;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Illuminate\Support\Str;
use Leandrocfe\FilamentPtbrFormFields\Money;

class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 6,
                'lg' => 12,
            ])
            ->components([
                Group::make([
                    FileUpload::make('technical_drawings')
                        ->label('Desenhos Técnicos')
                        ->columnSpanFull()
                        ->multiple()
                        ->downloadable()
                        ->openable()
                        ->acceptedFileTypes(['application/pdf', 'image/*', 'application/dwg', 'application/dxf'])
                        ->maxSize(10240)
                        ->helperText('Formatos aceitos: PDF, imagens, DWG, DXF. Máximo 10MB por arquivo.'),
                    FileUpload::make('specifications')
                        ->label('Especificações')
                        ->columnSpanFull()
                        ->multiple()
                        ->downloadable()
                        ->openable()
                        ->acceptedFileTypes(['application/pdf', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/*'])
                        ->maxSize(10240),
                    FileUpload::make('photos')
                        ->label('Fotos')
                        ->columnSpanFull()
                        ->multiple()
                        ->downloadable()
                        ->openable()
                        ->image()
                        ->imageEditor()
                        ->maxSize(5120),
                ])
                    ->visible(false),
                Section::make('Informações do Orçamento')
                    ->columns([
                        'sm' => 1,
                        'md' => 6,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('quote_number')
                            ->label('Número')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->visibleOn('edit'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->badge(fn($state) => match ($state) {
                                Status::DRAFT => 'secondary',
                                Status::SENT => 'primary',
                                Status::APPROVED => 'success',
                                Status::REJECTED => 'danger',
                                Status::EXPIRED => 'warning',
                                default => 'secondary',
                            })
                            ->formatStateUsing(fn($state) => Str::upper($state->description()))
                            ->visibleOn('edit'),
                        DatePicker::make('valid_until')
                            ->label('Válido até')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->minDate(now())
                            ->default(now()->addDays(CompanyPreference::get(key: 'default_quote_validity_days', default: 30)))
                            ->disabled(fn($record, $operation) => $operation === 'edit' ? !$record?->state()?->canEdit() : false)
                            ->required(),
                        SelectPartner::make('customer_id', 'customer')
                            ->label('Cliente')
                            ->columnSpan(['md' => 2, 'lg' => 6])
                            ->disabledOn('edit'),
                        Callout::make('Orçamento recusado/cancelado')
                            ->columnSpanFull()
                            ->danger()
                            ->color(Color::Red)
                            ->description(fn($record) => $record->rejected_reason)
                            ->visible(fn($get) => $get('status') === Status::REJECTED),
                    ]),
                Section::make('Observações')
                    ->columnSpanFull()
                    ->collapsible()
                    ->persistCollapsed()
                    ->disabled(fn($record, $operation) => $operation === 'edit' ? !$record?->state()?->canEdit() : false)
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->schema([
                        Textarea::make('description')
                            ->label('Descrição')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->rows(2)
                            ->maxLength(1000),
                        Textarea::make('observations')
                            ->label('Observações Internas')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->rows(2)
                            ->maxLength(1000),
                        Textarea::make('customer_observations')
                            ->label('Observações do Cliente')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->rows(2)
                            ->maxLength(1000),
                    ]),
                Section::make('Pagamento')
                    ->columnSpanFull()
                    ->collapsible()
                    ->disabled(fn($record, $operation) => $operation === 'edit' ? !$record?->state()?->canEdit() : false)
                    ->persistCollapsed()
                    ->columns([
                        'sm' => 1,
                        'md' => 3,
                        'lg' => 6,
                    ])
                    ->schema([
                        Select::make('payment_method')
                            ->label('Forma de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(PaymentMethod::toSelectArray())
                            ->native(false)
                            ->searchable()
                            ->default(fn() => CompanyPreference::getDefaultPaymentMethod(Filament::getTenant()->id)),
                        Select::make('payment_condition')
                            ->label('Condição de Pagamento')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(PaymentCondition::toGroupedSelectArray())
                            ->native(false)
                            ->searchable()
                            ->default(fn() => CompanyPreference::getDefaultPaymentCondition(Filament::getTenant()->id)),
                    ]),
                Livewire::make(ItemsRelationManager::class, fn(Quote $record) => [
                    'ownerRecord' => $record,
                    'pageClass' => EditQuote::class,
                ])
                    ->visibleOn('edit')
                    ->columnSpanFull(),

            ]);
    }
}
