<?php

namespace App\Filament\Clusters\Financial\Resources\CustomerCommitments\Actions;

use App\Models\CustomerCommitment;
use App\Services\Commitments\ApproveCommitmentMatchService;
use App\Services\Commitments\CommitmentMatchEngine;
use App\Services\Commitments\ReviewCommitmentService;
use App\Services\Commitments\SuggestCommitmentMatchesService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class CommitmentReviewActions
{
    public static function refresh(): Action
    {
        return Action::make('refresh_matches')->label('Buscar OS novamente')->icon('heroicon-o-arrow-path')->authorize('update')
            ->visible(fn (CustomerCommitment $record) => ! in_array($record->status->value, ['approved', 'ignored'], true))
            ->action(function (CustomerCommitment $record): void {
                app(SuggestCommitmentMatchesService::class)->refresh(Filament::getTenant()->id, $record->id, Auth::id());
                $record->refresh();
                Notification::make()->title('Busca concluída')->body(data_get($record->match_diagnostics, 'reason'))->success()->send();
            });
    }

    public static function approve(): Action
    {
        return Action::make('approve_match')->label('Aprovar vínculo com OS')->icon('heroicon-o-check-circle')->authorize('update')
            ->visible(fn (CustomerCommitment $record) => ! $record->billing()->exists() && $record->status->value !== 'ignored')
            ->modalDescription('A primeira opção é a OS elegível mais antiga pela data de abertura. A aprovação reserva a OS para este empenho.')
            ->schema([
                Select::make('service_order_id')->label('OS compatível')->native(false)->searchable()->required()
                    ->options(function (CustomerCommitment $record): array {
                        $result = app(CommitmentMatchEngine::class)->analyze($record);

                        return collect($result['candidates'])->where('eligible', true)->mapWithKeys(fn ($row) => [$row['service_order_id'] => $row['number'].' — abertura '.\Carbon\Carbon::parse($row['order_date'])->format('d/m/Y').' — R$ '.number_format($row['total_amount_cents'] / 100, 2, ',', '.')])->all();
                    })
                    ->default(fn (CustomerCommitment $record) => app(CommitmentMatchEngine::class)->analyze($record)['eligible_ids'][0] ?? null),
            ])
            ->action(function (CustomerCommitment $record, array $data): void {
                app(ApproveCommitmentMatchService::class)->approve(Filament::getTenant()->id, $record->id, (int) $data['service_order_id'], Auth::id());
                $record->refresh();
                Notification::make()->title('Vínculo aprovado')->body('A OS está reservada para este empenho.')->success()->send();
            });
    }

    public static function correct(): Action
    {
        return Action::make('correct_extraction')->label('Revisar dados extraídos')->icon('heroicon-o-pencil-square')->authorize('update')
            ->visible(fn (CustomerCommitment $record) => ! $record->billing()->exists() && ! in_array($record->status->value, ['approved', 'ignored'], true))
            ->fillForm(fn (CustomerCommitment $record) => [
                'number' => $record->number, 'fiscal_year' => $record->fiscal_year, 'issued_at' => $record->issued_at->toDateString(),
                'patrimony' => $record->patrimony, 'object' => $record->object, 'amount' => $record->amount,
                'retained_amount' => $record->retained_amount, 'net_amount' => $record->net_amount,
            ])->schema([
                TextInput::make('number')->label('Número do empenho')->required()->maxLength(40),
                TextInput::make('fiscal_year')->label('Exercício')->numeric()->required(),
                DatePicker::make('issued_at')->label('Emissão')->required(),
                TextInput::make('patrimony')->label('Número do patrimônio')->required()->maxLength(60),
                Textarea::make('object')->label('Objeto')->required()->maxLength(10000),
                TextInput::make('amount')->label('Valor bruto')->numeric()->minValue(0.01)->step(0.01)->prefix('R$')->required(),
                TextInput::make('retained_amount')->label('Valor retido')->numeric()->minValue(0)->step(0.01)->prefix('R$')->required(),
                TextInput::make('net_amount')->label('Valor líquido')->numeric()->minValue(0)->step(0.01)->prefix('R$')->required(),
                Checkbox::make('acknowledge_issues')->label('Conferi no PDF original e confirmo os dados, incluindo as pendências da extração')
                    ->visible(fn (CustomerCommitment $record) => filled(data_get($record->importFile->extracted_data, 'issues'))),
                Textarea::make('reason')->label('Motivo da revisão / confirmação')->required()->minLength(3)->maxLength(2000),
            ])->action(function (CustomerCommitment $record, array $data): void {
                app(ReviewCommitmentService::class)->correct(Filament::getTenant()->id, $record->id, Auth::id(), $data);
                $record->refresh();
                Notification::make()->title('Dados revisados e sugestões atualizadas')->success()->send();
            });
    }

    public static function unlink(): Action
    {
        return Action::make('unlink_order')->label('Desvincular OS')->color('warning')->authorize('update')
            ->visible(fn (CustomerCommitment $record) => $record->billing?->invoice_id === null && $record->billing !== null)
            ->requiresConfirmation()->schema([Textarea::make('reason')->label('Motivo')->required()->minLength(3)->maxLength(2000)])
            ->action(function (CustomerCommitment $record, array $data): void {
                app(ApproveCommitmentMatchService::class)->unlink(Filament::getTenant()->id, $record->id, Auth::id(), $data['reason']);
                $record->refresh();
                Notification::make()->title('OS liberada e sugestões atualizadas')->success()->send();
            });
    }

    public static function ignore(bool $reopen = false): Action
    {
        return Action::make($reopen ? 'reopen' : 'ignore')->label($reopen ? 'Reabrir empenho' : 'Ignorar empenho')->color('gray')->authorize('update')
            ->visible(fn (CustomerCommitment $record) => ! $record->billing()->exists() && ($reopen ? $record->status->value === 'ignored' : ! in_array($record->status->value, ['approved', 'ignored'], true)))
            ->schema([Textarea::make('reason')->label('Motivo')->required()->minLength(3)->maxLength(2000)])
            ->action(function (CustomerCommitment $record, array $data) use ($reopen): void {
                $service = app(ReviewCommitmentService::class);
                $reopen ? $service->reopen(Filament::getTenant()->id, $record->id, Auth::id(), $data['reason'])
                    : $service->ignore(Filament::getTenant()->id, $record->id, Auth::id(), $data['reason']);
                $record->refresh();
                Notification::make()->title($reopen ? 'Empenho reaberto' : 'Empenho ignorado')->success()->send();
            });
    }
}
