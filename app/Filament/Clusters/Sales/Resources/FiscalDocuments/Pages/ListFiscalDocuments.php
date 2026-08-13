<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Enum\FiscalDocument\NfeStatus;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsPdfAction;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\Actions\ExportOutputFiscalDocumentsXmlAction;
use App\Notification\NotifyService as notify;
use App\Services\Fiscal\NfeConfigService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badgeColor('gray'),
            NfeStatus::QUEUED->value => Tab::make('Na Fila')
                ->modifyQueryUsing(fn (Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::QUEUED))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::QUEUED)->count())
                ->badgeColor(NfeStatus::QUEUED->color()),
            NfeStatus::PENDING->value => Tab::make('Pendente')
                ->modifyQueryUsing(fn (Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::PENDING))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::PENDING)->count())
                ->badgeColor(NfeStatus::PENDING->color()),
            NfeStatus::IN_PROCESSING->value => Tab::make('Em Processamento')
                ->modifyQueryUsing(fn (Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::IN_PROCESSING))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::IN_PROCESSING)->count())
                ->badgeColor(NfeStatus::IN_PROCESSING->color()),
            NfeStatus::REJECTED->value => Tab::make('Rejeitada')
                ->modifyQueryUsing(fn (Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::REJECTED))
                ->badge(static::applyFiscalStatusFilter(static::getResource()::getEloquentQuery(), NfeStatus::REJECTED)->count())
                ->badgeColor(NfeStatus::REJECTED->color()),
            NfeStatus::AUTHORIZED->value => Tab::make('Autorizada')
                ->modifyQueryUsing(fn (Builder $query): Builder => static::applyFiscalStatusFilter($query, NfeStatus::AUTHORIZED)),

        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return NfeStatus::PENDING->value;
    }

    protected static function applyFiscalStatusFilter(Builder $query, NfeStatus $status): Builder
    {
        return $query->where(function (Builder $query) use ($status): void {
            $query
                ->where('nfe_status', $status->value)
                ->orWhere('nfse_status', $status->value);

            if ($status === NfeStatus::PENDING) {
                $query->orWhere(function (Builder $q): void {
                    $q->whereNull('nfe_status')
                        ->whereNull('nfse_status');
                });
            }
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->manualNfePreviewAction(),
            ExportOutputFiscalDocumentsAction::make(),
            ExportOutputFiscalDocumentsPdfAction::make(),
            ExportOutputFiscalDocumentsXmlAction::make(),
        ];
    }

    private function manualNfePreviewAction(): Action
    {
        return Action::make('manual_nfe_preview')
            ->label('Preview por Payload')
            ->icon(Heroicon::Eye)
            ->color('gray')
            ->modalHeading('Preview de NF-e por payload manual')
            ->modalDescription('Cole o payload JSON pronto da NF-e. O payload será enviado apenas para preview e não será salvo.')
            ->schema([
                CodeEditor::make('payload')
                    ->label('Payload JSON')
                    ->language(Language::Json)
                    ->required()
                    ->columnSpanFull(),
            ])
            ->modalSubmitActionLabel('Gerar preview')
            ->modalWidth('7xl')
            ->visible(fn (): bool => (bool) Auth::user()?->is_admin)
            ->action(function (array $data) {
                $payload = json_decode((string) ($data['payload'] ?? ''), true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($payload)) {
                    notify::error('Payload inválido', 'Informe um JSON válido para gerar o preview da NF-e.');

                    return null;
                }

                $tenant = Filament::getTenant();

                if ($tenant === null) {
                    notify::error('Empresa não selecionada', 'Selecione uma empresa antes de gerar o preview.');

                    return null;
                }

                try {
                    $companyId = (int) $tenant->id;
                    $configService = app(NfeConfigService::class);
                    $sdk = new \CloudDfe\SdkPHP\Nfe($configService->buildSdkParams($companyId));
                    $resp = $sdk->preview($payload);

                    if (! ($resp->sucesso ?? false) || empty($resp->pdf ?? null)) {
                        $message = $resp->mensagem ?? 'Erro ao gerar preview da NF-e.';

                        notify::error('Falha ao gerar preview', $message);

                        Log::warning('ListFiscalDocuments: falha no preview manual de NF-e', [
                            'company_id' => $companyId,
                            'codigo' => $resp->codigo ?? null,
                            'mensagem' => $message,
                            'erros' => (array) ($resp->erros ?? []),
                        ]);

                        return null;
                    }

                    $pdf = base64_decode((string) $resp->pdf, true);

                    if ($pdf === false) {
                        notify::error('Preview inválido', 'A API retornou um PDF em formato inválido.');

                        return null;
                    }

                    return response()->streamDownload(
                        fn () => print $pdf,
                        'preview-nfe.pdf',
                        ['Content-Type' => 'application/pdf'],
                        'inline',
                    );
                } catch (\Throwable $e) {
                    Log::error('ListFiscalDocuments: exceção no preview manual de NF-e', [
                        'company_id' => $tenant->id ?? null,
                        'exception' => $e->getMessage(),
                    ]);

                    notify::error('Erro ao gerar preview', $e->getMessage());

                    return null;
                }
            });
    }
}
