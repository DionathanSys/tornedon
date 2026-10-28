<?php

namespace App\Filament\Clusters\Hr\Resources\HrPeople;

use App\Filament\Clusters\Hr\HrCluster;
use App\Filament\Clusters\Hr\HrForm;
use App\Filament\Clusters\Hr\Resources\HrPeople\Pages\CreateHrPerson;
use App\Filament\Clusters\Hr\Resources\HrPeople\Pages\EditHrPerson;
use App\Filament\Clusters\Hr\Resources\HrPeople\Pages\ManageHrPeople;
use App\Filament\Clusters\Hr\Resources\HrPeople\Pages\ViewHrPerson;
use App\Filament\Concerns\AuthorizesHrResource;
use App\Models\CompanyPartner;
use App\Models\HrPerson;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class HrPersonResource extends Resource
{
    use AuthorizesHrResource;

    protected static ?string $model = HrPerson::class;

    protected static ?string $cluster = HrCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'Funcionário/sócio';

    protected static ?string $pluralModelLabel = 'Funcionários e sócios';

    protected static string $hrModule = 'hr_people';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Vínculo com a empresa')->description('Identificação, vínculo e remuneração de referência.')->columnSpanFull()->columns(['default' => 1, 'lg' => 2])->schema([
                Select::make('company_partner_id')->label('Pessoa cadastrada')->required()->searchable()
                    ->native(false)->columnSpanFull()
                    ->options(fn () => CompanyPartner::query()->where('company_id', Filament::getTenant()->id)->with('partner')->get()->filter(fn ($link) => $link->partner)->mapWithKeys(fn ($link) => [$link->id => $link->partner->name.' — '.$link->partner->document_number]))
                    ->helperText('Cadastre a pessoa em Parceiros antes de criar o vínculo de RH.'),
                CheckboxList::make('roles')->label('Tipo de vínculo')->options(['employee' => 'Funcionário', 'shareholder' => 'Sócio'])->required()->default(['employee'])->columns(2)->columnSpanFull(),
                TextInput::make('position')->label('Cargo/função')->maxLength(255),
                HrForm::money('base_amount')->label('Valor-base de referência')->default(0),
                DatePicker::make('started_at')->label('Entrada/admissão'),
                DatePicker::make('ended_at')->label('Saída/desligamento'),
                Toggle::make('is_active')->label('Vínculo ativo')->default(true)->columnSpanFull(),
            ]),
            Section::make('Classificação financeira padrão')->description('Usada nos lançamentos que não possuírem uma classificação específica.')->columnSpanFull()->columns(['default' => 1, 'lg' => 2])->schema(HrForm::classifications()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('companyPartner.partner.name')->label('Pessoa')->searchable(),
            TextColumn::make('roles')->label('Vínculos')->badge()->formatStateUsing(fn ($state) => ['employee' => 'Funcionário', 'shareholder' => 'Sócio'][$state] ?? $state),
            TextColumn::make('position')->label('Cargo/função'),
            TextColumn::make('base_amount')->label('Valor-base')->money('BRL')->visible(fn () => static::allowed('view')),
            IconColumn::make('is_active')->label('Ativo')->boolean(),
        ])->filters([TernaryFilter::make('is_active')->label('Ativo')])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            DeleteAction::make(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHrPeople::route('/'),
            'create' => CreateHrPerson::route('/create'),
            'view' => ViewHrPerson::route('/{record}'),
            'edit' => EditHrPerson::route('/{record}/edit'),
        ];
    }
}
