<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas;

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
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
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
                Tabs::make('quote_tabs')
                    ->columnSpanFull()
                    ->persistTab('quote_tabs')
                    ->tabs([
                        Tabs\Tab::make('Dados do Orçamento')
                            ->columns([
                                'sm' => 1,
                                'md' => 6,
                                'lg' => 12,
                            ])
                            ->schema([
                                TextEntry::make('quote_number')
                                    ->label('Número')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->visibleOn('edit'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->formatStateUsing(fn($state) => Str::upper($state->description()))
                                    ->visibleOn('edit'),
                                DatePicker::make('valid_until')
                                    ->label('Válido até')
                                    ->columnSpan(['md' => 1, 'lg' => 2])
                                    ->minDate(now())
                                    ->default(now()->addDays(CompanyPreference::get(key: 'default_quote_validity_days', default: 30)))
                                    ->required(),
                                SelectPartner::make('partner_id', 'customer')
                                    ->label('Cliente')
                                    ->columnSpan(['md' => 2, 'lg' => 6])
                                    ->disabledOn('edit'),
                                Textarea::make('description')
                                    ->label('Descrição')
                                    ->columnSpan(['md' => 6, 'lg' => 12])
                                    ->rows(2)
                                    ->maxLength(1000),
                                Textarea::make('observations')
                                    ->label('Observações Internas')
                                    ->columnSpan(['md' => 3, 'lg' => 6])
                                    ->rows(2)
                                    ->maxLength(1000),
                                Textarea::make('customer_observations')
                                    ->label('Observações do Cliente')
                                    ->columnSpan(['md' => 3, 'lg' => 6])
                                    ->rows(2)
                                    ->maxLength(1000),
                            ]),
                        Tabs\Tab::make('Anexos')
                            ->hidden()
                            ->schema([
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
                            ]),
                        Tabs\Tab::make('Itens do Orçamento')
                            ->visibleOn('edit')
                            ->schema([
                                Livewire::make(ItemsRelationManager::class, fn(Quote $record) => [
                                    'ownerRecord' => $record,
                                    'pageClass' => EditQuote::class,
                                ])
                                    ->columnSpanFull(),
                            ]),

                    ]),


            ]);
    }
}
