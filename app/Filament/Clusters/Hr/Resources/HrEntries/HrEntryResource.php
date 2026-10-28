<?php

namespace App\Filament\Clusters\Hr\Resources\HrEntries;

use App\Enum\Hr\PaymentNature;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Hr\HrCluster;
use App\Filament\Clusters\Hr\HrForm;
use App\Filament\Clusters\Hr\Resources\HrEntries\Pages\ManageHrEntries;
use App\Filament\Concerns\AuthorizesHrResource;
use App\Models\HrEntry;
use App\Models\HrPerson;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
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
        return $schema->components([
            Section::make('Competência')->columns(2)->schema([
                Select::make('hr_person_id')->label('Funcionário/sócio')->required()->searchable()->live()
                    ->options(fn () => HrPerson::query()->where('company_id', Filament::getTenant()->id)->with('companyPartner.partner')->get()->mapWithKeys(fn ($person) => [$person->id => $person->companyPartner?->partner?->name ?? 'Vínculo #'.$person->id])),
                Select::make('nature')->label('Natureza')->required()->options(PaymentNature::options()),
                DatePicker::make('competence_date')->label('Competência')->displayFormat('m/Y')->helperText('Será normalizada para o primeiro dia do mês.')->required()->default(now()->startOfMonth()),
                DatePicker::make('due_date')->label('Vencimento')->required()->default(now()),
            ]),
            Section::make('Proventos e descontos manuais')->schema([
                Repeater::make('items')->label('Itens')->columns(4)->required()->minItems(1)->live()->schema([
                    TextInput::make('description')->label('Descrição')->required()->maxLength(255)->columnSpan(2),
                    Select::make('type')->label('Tipo')->options(['earning' => 'Provento', 'deduction' => 'Desconto'])->required()->default('earning'),
                    TextInput::make('amount')->label('Valor')->numeric()->minValue(0.01)->step(0.01)->prefix('R$')->required(),
                ]),
                Repeater::make('advance_deductions')->label('Abater adiantamentos já pagos')->defaultItems(0)->columns(2)->live()->schema([
                    Select::make('entry_id')->label('Adiantamento')->required()->searchable()
                        ->options(fn (Get $get) => HrEntry::query()->where('company_id', Filament::getTenant()->id)->where('hr_person_id', $get('../../hr_person_id'))->where('nature', 'advance')->where('status', 'confirmed')->get()->mapWithKeys(fn ($entry) => [$entry->id => '#'.$entry->id.' — '.$entry->competence_date->format('m/Y').' — pago R$ '.number_format(app(HrService::class)->paidAmount($entry), 2, ',', '.')])),
                    TextInput::make('amount')->label('Valor a abater')->numeric()->minValue(0.01)->step(0.01)->required()->prefix('R$'),
                ]),
                TextInput::make('preview_net')->label('Líquido previsto')->disabled()->dehydrated(false)
                    ->placeholder(function (Get $get): string {
                        $cents = 0;
                        foreach ($get('items') ?? [] as $item) {
                            $cents += HrService::cents($item['amount'] ?? 0) * (($item['type'] ?? 'earning') === 'earning' ? 1 : -1);
                        }
                        foreach ($get('advance_deductions') ?? [] as $advance) {
                            $cents -= HrService::cents($advance['amount'] ?? 0);
                        }

                        return 'R$ '.number_format($cents / 100, 2, ',', '.');
                    }),
                Textarea::make('notes')->label('Observações')->maxLength(10000),
            ]),
            Section::make('Classificação financeira')->columns(2)->schema(HrForm::classifications()),
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
            ViewAction::make(),
            EditAction::make()->using(fn (HrEntry $record, array $data) => app(HrService::class)->saveEntry(auth()->user(), Filament::getTenant(), $data, $record)),
            DeleteAction::make(),
            Action::make('confirm')->label('Confirmar e gerar conta')->icon('heroicon-o-check-circle')->requiresConfirmation()
                ->visible(fn (HrEntry $record) => $record->status === 'draft' && static::allowed('approve', $record))
                ->action(function (HrEntry $record): void {
                    app(HrService::class)->confirm(auth()->user(), Filament::getTenant(), $record);
                    Notification::make()->title('Lançamento confirmado')->success()->send();
                }),
            Action::make('cancel')->label('Cancelar')->color('danger')->requiresConfirmation()
                ->visible(fn (HrEntry $record) => $record->status !== 'cancelled' && static::allowed('cancel', $record))
                ->action(fn (HrEntry $record) => app(HrService::class)->cancel(auth()->user(), Filament::getTenant(), $record)),
            Action::make('statement')->label('Demonstrativo')->icon('heroicon-o-document-text')
                ->visible(fn (HrEntry $record) => static::allowed('export', $record))
                ->url(fn (HrEntry $record) => URL::temporarySignedRoute('hr.statement', now()->addMinutes(10), ['company' => $record->company_id, 'entry' => $record->id]))->openUrlInNewTab(),
            Action::make('financial')->label('Conta a pagar')->icon('heroicon-o-banknotes')
                ->visible(fn (HrEntry $record) => $record->accountPayable && Gate::allows('view', $record->accountPayable))
                ->url(fn (HrEntry $record) => AccountPayableResource::getUrl('edit', ['record' => $record->account_payable_id], panel: 'admin', tenant: Filament::getTenant())),
            Action::make('receipt')->label('Emitir recibo')->icon('heroicon-o-printer')
                ->visible(fn (HrEntry $record) => $record->status === 'confirmed' && app(HrAccess::class)->allows(auth()->user(), Filament::getTenant(), 'hr_receipts.issue'))
                ->schema([Select::make('payment_id')->label('Baixa financeira')->required()->options(fn (HrEntry $record) => app(HrService::class)->payments($record)->get()->mapWithKeys(fn ($payment) => [$payment->id => '#'.$payment->id.' — '.$payment->payment_date->format('d/m/Y').' — R$ '.number_format($payment->amount, 2, ',', '.')]))])
                ->action(function (HrEntry $record, array $data): void {
                    $receipt = app(HrService::class)->issueReceipt(auth()->user(), Filament::getTenant(), $record, (int) $data['payment_id']);
                    Notification::make()->title('Recibo #'.$receipt->id.' emitido')->body('Disponível na tela Recibos de RH.')->success()->send();
                }),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageHrEntries::route('/')];
    }
}
