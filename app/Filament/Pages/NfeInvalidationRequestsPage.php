<?php

namespace App\Filament\Pages;

use App\Models\NfeInvalidationRequest;
use App\Models\User;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\ProcessNfeInvalidationRequestAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class NfeInvalidationRequestsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected string $view = 'filament.pages.nfe-invalidation-requests-page';

    protected static ?string $navigationLabel = 'Inutilizações NF-e';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Inutilização de numeração NF-e';

    public ?NfeInvalidationRequest $requestRecord = null;

    public array $requests = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canManageFiscalSequences();
    }

    public function mount(): void
    {
        $requestId = (int) request()->query('request');

        if ($requestId > 0) {
            $this->requestRecord = NfeInvalidationRequest::query()
                ->with(['company', 'fiscalDocument', 'requestedBy', 'processedBy'])
                ->findOrFail($requestId);

            abort_unless($this->canProcessRequest(), 403);
        }

        $this->requests = NfeInvalidationRequest::query()
            ->with(['company', 'fiscalDocument', 'requestedBy', 'processedBy'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (NfeInvalidationRequest $request): array => [
                'id' => $request->id,
                'company' => $request->company?->name,
                'serie' => $request->serie,
                'number_start' => $request->number_start,
                'number_end' => $request->number_end,
                'status' => $request->status,
                'requested_by' => $request->requestedBy?->name,
                'processed_by' => $request->processedBy?->name,
                'processed_at' => $request->processed_at?->format('d/m/Y H:i'),
                'url' => static::getUrl(['request' => $request->id]),
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('back_to_list')
                ->label('Voltar para lista')
                ->icon('heroicon-o-arrow-left')
                ->visible(fn (): bool => $this->requestRecord !== null)
                ->url(static::getUrl()),
            Action::make('process')
                ->label('Inutilizar agora')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Confirmar inutilização de numeração')
                ->modalDescription('A ação enviará a inutilização para a API fiscal com a justificativa informada.')
                ->form([
                    Textarea::make('justification')
                        ->label('Justificativa')
                        ->required()
                        ->default(fn (): string => (string) $this->requestRecord?->justification)
                        ->rows(4)
                        ->maxLength(255),
                ])
                ->visible(fn (): bool => $this->requestRecord !== null)
                ->disabled(fn (): bool => ! $this->requestRecord?->isPending())
                ->action(function (array $data): void {
                    $service = app(ProcessNfeInvalidationRequestAction::class);
                    $ok = $service->execute($this->requestRecord, (int) Auth::id(), $data['justification']);

                    $this->requestRecord?->refresh();

                    $this->requests = NfeInvalidationRequest::query()
                        ->with(['company', 'fiscalDocument', 'requestedBy', 'processedBy'])
                        ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                        ->orderByDesc('created_at')
                        ->get()
                        ->map(fn (NfeInvalidationRequest $request): array => [
                            'id' => $request->id,
                            'company' => $request->company?->name,
                            'serie' => $request->serie,
                            'number_start' => $request->number_start,
                            'number_end' => $request->number_end,
                            'status' => $request->status,
                            'requested_by' => $request->requestedBy?->name,
                            'processed_by' => $request->processedBy?->name,
                            'processed_at' => $request->processed_at?->format('d/m/Y H:i'),
                            'url' => static::getUrl(['request' => $request->id]),
                        ])
                        ->all();

                    if (! $ok) {
                        notify::error(message: $service->getMessage());

                        return;
                    }

                    notify::success(message: $service->getMessage());
                }),
        ];

        return $actions;
    }

    public function canProcessRequest(): bool
    {
        $user = Auth::user();

        if (! $user || ! $this->requestRecord) {
            return false;
        }

        return $user instanceof User && ($user->canManageFiscalSequences()
            || (int) $this->requestRecord->requested_by === (int) $user->id);
    }
}
