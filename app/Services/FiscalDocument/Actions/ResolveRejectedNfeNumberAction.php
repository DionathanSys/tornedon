<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType;
use App\Models\FiscalDocument;
use App\Models\NfeSequence;
use App\Models\User;
use App\Filament\Pages\NfeInvalidationRequestsPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class ResolveRejectedNfeNumberAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument, string $serie, ?int $userId = null): bool
    {
        try {
            $currentNumber = (int) preg_replace('/\D/', '', (string) ($fiscalDocument->document_number ?? ''));

            if ($currentNumber < 1) {
                Log::info('ResolveRejectedNfeNumberAction: NF-e rejeitada sem número atual. O próximo número será atribuído no envio.', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'serie' => $serie,
                    'company_id' => $fiscalDocument->company_id,
                ]);
                $this->setSuccess();
                return true;
            }

            $highestSubsequentNumber = (int) (FiscalDocument::query()
                ->where('company_id', $fiscalDocument->company_id)
                ->where('document_series', $serie)
                ->where('document_type', DocumentModel::NFE->value)
                ->where('operation_type', OperationType::SAIDA->value)
                ->whereKeyNot($fiscalDocument->id)
                ->whereNotNull('document_number')
                ->pluck('document_number')
                ->map(fn ($number): int => (int) preg_replace('/\D/', '', (string) $number))
                ->filter(fn (int $number): bool => $number > 0)
                ->max() ?? 0);

            if ($highestSubsequentNumber <= $currentNumber) {
                if ($fiscalDocument->document_series !== $serie) {
                    $fiscalDocument->update([
                        'document_series' => $serie,
                    ]);
                }

                Log::info('ResolveRejectedNfeNumberAction: NF-e rejeitada permanece com o número atual para reenvio.', [
                    'fiscal_document_id' => $fiscalDocument->id,
                    'serie' => $serie,
                    'company_id' => $fiscalDocument->company_id,
                ]);
                $this->setSuccess();
                return true;
            }

            $nextNumber = NfeSequence::peekNextNumber((int) $fiscalDocument->company_id, $serie);

            $fiscalDocument->update([
                'document_number' => (string) $nextNumber,
                'document_series' => $serie,
                'nfe_sequence_id' => null,
            ]);

            if ($userId !== null) {
                $request = app(CreateNfeInvalidationRequestAction::class)->execute(
                    $fiscalDocument,
                    $serie,
                    $currentNumber,
                    $userId,
                    $nextNumber,
                );

                $recipient = User::query()->find($userId);

                if ($request !== null && $recipient !== null) {
                    Notification::make()
                        ->title('Número NF-e pendente de inutilização')
                        ->body(sprintf(
                            'A NF-e rejeitada foi renumerada de %d para %d. Clique para abrir a inutilização da série %s.',
                            $currentNumber,
                            $nextNumber,
                            $serie,
                        ))
                        ->warning()
                        ->actions([
                            Action::make('open_nfe_invalidation')
                                ->label('Abrir inutilização')
                                ->url(NfeInvalidationRequestsPage::getUrl(parameters: ['record' => $request->id])),
                        ])
                        ->sendToDatabase([$recipient, User::query()->find(1)]);
                }
            }

            Log::info('ResolveRejectedNfeNumberAction: NF-e rejeitada renumerada para reenvio', [
                'fiscal_document_id' => $fiscalDocument->id,
                'old_number' => $currentNumber,
                'new_number' => $nextNumber,
                'serie' => $serie,
                'company_id' => $fiscalDocument->company_id,
            ]);

            $this->setSuccess('NF-e rejeitada recebeu novo número para reenvio.');
            return true;
        } catch (\Throwable $e) {
            $this->setError('Erro ao resolver número da NF-e rejeitada: ' . $e->getMessage());

            Log::error('ResolveRejectedNfeNumberAction: erro ao resolver número da rejeitada', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'serie' => $serie,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
