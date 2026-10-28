<?php

namespace App\Filament\Clusters\Hr\Resources\HrEntries;

use App\Enum\Hr\PaymentNature;
use App\Filament\Clusters\Hr\HrCluster;
use App\Filament\Clusters\Hr\HrForm;
use App\Filament\Clusters\Hr\Resources\HrEntries\Pages\CreateHrEntry;
use App\Filament\Clusters\Hr\Resources\HrEntries\Pages\EditHrEntry;
use App\Filament\Clusters\Hr\Resources\HrEntries\Pages\ManageHrEntries;
use App\Filament\Concerns\AuthorizesHrResource;
use App\Models\HrEntry;
use App\Models\HrPerson;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

class HrEntryResource extends Resource
{
    use AuthorizesHrResource;

    protected static ?string $model = HrEntry::class;

    protected static ?string $cluster = HrCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $modelLabel = 'Lançamento de RH';

    protected static ?string $pluralModelLabel = 'Lançamentos por competência';

    protected static string $hrModule = 'hr_entries';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Competência')->columnSpanFull()->columns(['default' => 1, 'lg' => 2])->schema([
                Select::make('hr_person_id')->label('Funcionário/sócio')->required()->searchable()->live()
                    ->native(false)->columnSpanFull()
                    ->options(fn () => HrPerson::query()->where('company_id', Filament::getTenant()->id)->with('companyPartner.partner')->get()->mapWithKeys(fn ($person) => [$person->id => $person->companyPartner?->partner?->name ?? 'Vínculo #'.$person->id])),
                Select::make('nature')->label('Natureza')->required()->options(PaymentNature::options()),
                DatePicker::make('competence_date')->label('Competência')->displayFormat('m/Y')->helperText('Será normalizada para o primeiro dia do mês.')->required()->default(now()->startOfMonth()),
                DatePicker::make('due_date')->label('Vencimento')->required()->default(now()),
            ]),
            Section::make('Proventos e descontos manuais')->columnSpanFull()->schema([
                Repeater::make('items')->label('Itens da competência')->columns(['default' => 1, 'lg' => 4])->required()->minItems(1)->live()->schema([
                    TextInput::make('description')->label('Descrição')->required()->maxLength(255)->columnSpan(['lg' => 2]),
                    Select::make('type')->label('Tipo')->options(['earning' => 'Provento', 'deduction' => 'Desconto'])->required()->default('earning')->native(false),
                    HrForm::money('amount')->label('Valor')->required(),
                ]),
                Repeater::make('advance_deductions')->label('Abater adiantamentos já pagos')->defaultItems(0)->columns(['default' => 1, 'lg' => 3])->live()->schema([
                    Select::make('entry_id')->label('Adiantamento')->required()->searchable()
                        ->native(false)->columnSpan(['lg' => 2])
                        ->options(fn (Get $get) => HrEntry::query()->where('company_id', Filament::getTenant()->id)->where('hr_person_id', $get('../../hr_person_id'))->where('nature', 'advance')->where('status', 'confirmed')->get()->mapWithKeys(fn ($entry) => [$entry->id => '#'.$entry->id.' — '.$entry->competence_date->format('m/Y').' — pago R$ '.number_format(app(HrService::class)->paidAmount($entry), 2, ',', '.')])),
                    HrForm::money('amount')->label('Valor a abater')->required(),
                ]),
                TextInput::make('preview_net')->label('Líquido previsto')->disabled()->dehydrated(false)
                    ->placeholder(function (Get $get): string {
                        $cents = 0;
                        foreach ($get('items') ?? [] as $item) {
                            $cents += HrService::cents(HrForm::moneyValue($item['amount'] ?? 0)) * (($item['type'] ?? 'earning') === 'earning' ? 1 : -1);
                        }
                        foreach ($get('advance_deductions') ?? [] as $advance) {
                            $cents -= HrService::cents(HrForm::moneyValue($advance['amount'] ?? 0));
                        }

                        return 'R$ '.number_format($cents / 100, 2, ',', '.');
                    }),
                Textarea::make('notes')->label('Observações')->maxLength(10000),
            ]),
            Section::make('Classificação financeira')->columnSpanFull()->columns(['default' => 1, 'lg' => 2])->schema(HrForm::classifications()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Nº')->sortable(),
            TextColumn::make('person.companyPartner.partner.name')->label('Funcionário/sócio')->searchable(),
            TextColumn::make('competence_date')->label('Competência')->date('m/Y')->sortable(),
            TextColumn::make('nature')->label('Natureza')->formatStateUsing(fn ($state) => PaymentNature::options()[$state]),
            TextColumn::make('net_amount')->label('Líquido')->money('BRL'),
            TextColumn::make('paid_amount')->label('Pago')->state(fn (HrEntry $record) => app(HrService::class)->paidAmount($record))->money('BRL'),
            TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => ['draft' => 'Rascunho', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado'][$state]),
            TextColumn::make('account_payable_id')->label('Conta a pagar'),
        ])->filters([
            SelectFilter::make('nature')->label('Natureza')->options(PaymentNature::options()),
            SelectFilter::make('status')->label('Situação')->options(['draft' => 'Rascunho', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado']),
        ])->recordActions([
            ActionGroup::make([
                Action::make('manage')->label('Abrir lançamento')->icon('heroicon-o-document-text')
                    ->url(fn (HrEntry $record) => static::getUrl('edit', ['record' => $record])),
                Action::make('statement')->label('Demonstrativo em PDF')->icon('heroicon-o-printer')
                    ->visible(fn (HrEntry $record) => static::allowed('export', $record))
                    ->url(fn (HrEntry $record) => URL::temporarySignedRoute('hr.statement', now()->addMinutes(10), ['company' => $record->company_id, 'entry' => $record->id]))->openUrlInNewTab(),
                DeleteAction::make(),
            ])->icon('heroicon-o-bars-3')->iconButton()->tooltip('Ações do lançamento'),
        ])->recordUrl(fn (HrEntry $record) => static::getUrl('edit', ['record' => $record]))->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageHrEntries::route('/'), 'create' => CreateHrEntry::route('/create'), 'edit' => EditHrEntry::route('/{record}/edit')];
    }
}
