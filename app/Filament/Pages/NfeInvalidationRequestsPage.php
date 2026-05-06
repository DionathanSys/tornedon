<?php

namespace App\Filament\Pages;

use App\Models\NfeInvalidationRequest;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\Actions\ProcessNfeInvalidationRequestAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class NfeInvalidationRequestsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected string $view = 'filament.pages.nfe-invalidation-requests-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Inutilização de numeração NF-e';

    public ?NfeInvalidationRequest $requestRecord = null;

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function mount(): void
    {
        $requestId = (int) request()->query('request');

        abort_unless($requestId > 0, 404);

        $this->requestRecord = NfeInvalidationRequest::query()
            ->with(['company', 'fiscalDocument', 'requestedBy', 'processedBy'])
            ->findOrFail($requestId);

        abort_unless($this->canProcessRequest(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
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
                ->disabled(fn (): bool => ! $this->requestRecord?->isPending())
                ->action(function (array $data): void {
                    $service = app(ProcessNfeInvalidationRequestAction::class);
                    $ok = $service->execute($this->requestRecord, (int) Auth::id(), $data['justification']);

                    $this->requestRecord?->refresh();

                    if (! $ok) {
                        notify::error(message: $service->getMessage());
                        return;
                    }

                    notify::success(message: $service->getMessage());
                }),
        ];
    }

    public function canProcessRequest(): bool
    {
        $user = Auth::user();

        if (! $user || ! $this->requestRecord) {
            return false;
        }

        return (bool) $user->is_admin || (int) $this->requestRecord->requested_by === (int) $user->id;
    }
}
