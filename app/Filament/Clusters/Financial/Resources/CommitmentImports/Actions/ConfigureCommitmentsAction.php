<?php

namespace App\Filament\Clusters\Financial\Resources\CommitmentImports\Actions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\CommitmentSetting;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Service;
use App\Services\Commitments\CommitmentSettingsService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;

class ConfigureCommitmentsAction
{
    public static function make(): Action
    {
        return Action::make('configure_customer')->label('Configurar cliente')->icon('heroicon-o-cog-6-tooth')
            ->authorize('create', CommitmentSetting::class)
            ->schema([
                Select::make('customer_id')->label('Cliente')->options(fn () => ImportCommitmentFilesAction::customerOptions())->searchable()->searchDebounce(1500)->required()->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $settings = CommitmentSetting::forCustomer(Filament::getTenant()->id, (int) $state)->first();
                        $set('payment_condition_details', $settings?->payment_condition_details);
                        foreach (['is_enabled' => false, 'nfse_service_id' => null, 'payment_method' => null, 'payment_condition' => null, 'financial_category_id' => null, 'financial_account_id' => null, 'nfse_footer_message' => null] as $key => $default) {
                            $set($key, $settings?->getRawOriginal($key) ?? $default);
                        }
                    }),
                Toggle::make('is_enabled')->label('Habilitar importação para este cliente')->default(false),
                Select::make('nfse_service_id')->label('Serviço padrão da NFS-e')->searchable()->searchDebounce(1500)
                    ->options(fn () => Service::where('company_id', Filament::getTenant()->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                Select::make('payment_method')->label('Forma de pagamento')->native(false)->options(collect(Method::cases())->mapWithKeys(fn ($case) => [$case->value => $case->description()])->all()),
                Select::make('payment_condition')->live()->label('Condição de pagamento')->native(false)->searchable()->searchDebounce(1500)->options(collect(Condition::cases())->mapWithKeys(fn ($case) => [$case->value => $case->description()])->all()),
                \App\Filament\Forms\Components\PaymentConditionFields::make(),
                Select::make('financial_category_id')->label('Categoria financeira')->searchable()->searchDebounce(1500)
                    ->options(fn () => FinancialCategory::where('company_id', Filament::getTenant()->id)->where('is_active', true)->where('allow_receivable', true)->orderBy('name')->pluck('name', 'id')),
                Select::make('financial_account_id')->label('Conta financeira de recebimento')->searchable()->searchDebounce(1500)
                    ->options(fn () => FinancialAccount::where('company_id', Filament::getTenant()->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                Textarea::make('nfse_footer_message')->label('Mensagem fixa do rodapé da NFS-e')->maxLength(1800)
                    ->helperText('O faturamento acrescentará patrimônio e número/ano do empenho. O corpo utilizará o nome do serviço.'),
            ])
            ->action(function (array $data): void {
                app(CommitmentSettingsService::class)->save(Filament::getTenant(), (int) $data['customer_id'], Auth::user(), [...$data, 'parser' => 'chapeco']);
                Notification::make()->title('Configuração salva')->success()->send();
            });
    }
}
