<?php

namespace App\Services\Financial\BankStatement;

use App\Domain\DTO\Financial\OfxStatementHeaderDTO;
use App\Domain\DTO\Financial\OfxTransactionDTO;
use App\Enum\Financial\BankStatementLineStatus;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\FinancialAccount;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\BankStatement\Contracts\BankOfxNormalizerInterface;
use App\Services\Financial\BankStatement\Contracts\OfxStatementParserInterface;
use App\Services\Financial\BankStatement\Normalizers\BradescoOfxNormalizer;
use App\Services\Financial\BankStatement\Normalizers\InterOfxNormalizer;
use App\Services\Financial\BankStatement\Normalizers\SicoobOfxNormalizer;
use App\Services\Financial\BankStatement\Normalizers\SicrediOfxNormalizer;
use App\Services\Financial\BankStatement\Parsers\GenericOfxStatementParser;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ImportBankStatementService
{
    use HandlesServiceResponse;

    /**
     * @param  array<int, BankOfxNormalizerInterface>|null  $normalizers
     */
    public function __construct(
        private readonly OfxStatementParserInterface $parser = new GenericOfxStatementParser,
        private readonly SuggestBankStatementMatchesService $suggestService = new SuggestBankStatementMatchesService,
        private readonly BankStatementTransactionKeyFactory $transactionKeyFactory = new BankStatementTransactionKeyFactory,
        private readonly ?array $normalizers = null,
    ) {}

    public function importFromString(
        int $companyId,
        int $financialAccountId,
        string $contents,
        string $fileName,
        ?int $userId = null,
    ): ?BankStatementImport {
        $this->resetResponse();

        try {
            $account = FinancialAccount::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find($financialAccountId);

            if (! $account) {
                throw ValidationException::withMessages([
                    'financial_account_id' => ['Conta financeira inválida para a empresa informada.'],
                ]);
            }

            $parsed = $this->parser->parse($contents);
            $header = $this->resolveNormalizer($parsed['header'])->normalizeHeader($parsed['header']);
            $transactions = collect($parsed['transactions'])
                ->map(fn ($transaction) => $this->resolveNormalizer($header)->normalizeTransaction($transaction))
                ->values();

            if ($transactions->isEmpty()) {
                throw ValidationException::withMessages([
                    'file' => ['O OFX não possui transações compatíveis com a importação.'],
                ]);
            }

            $reference = $header->reference();
            $fileHash = hash('sha256', $contents);
            $statementTransactions = $transactions
                ->map(fn ($transaction): array => [
                    'transaction' => $transaction,
                    'transaction_key' => $this->transactionKeyFactory->make($transaction),
                    'source_payload_hash' => $transaction->lineHash(),
                ])
                ->values();

            $duplicateKeys = $statementTransactions
                ->pluck('transaction_key')
                ->duplicates()
                ->values();

            if ($duplicateKeys->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'file' => ['O OFX possui transações duplicadas com a mesma chave de identificação.'],
                ]);
            }

            $import = DB::transaction(function () use (
                $account,
                $header,
                $statementTransactions,
                $fileName,
                $fileHash,
                $reference,
                $userId
            ) {
                $audit = app(AuditRecorder::class);
                $import = BankStatementImport::query()
                    ->where('company_id', $account->company_id)
                    ->where('financial_account_id', $account->id)
                    ->where('source', 'ofx')
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if (! $import) {
                    $import = new BankStatementImport([
                        'company_id' => $account->company_id,
                        'financial_account_id' => $account->id,
                        'source' => 'ofx',
                        'reference' => $reference,
                    ]);
                }

                $import->fill([
                    'file_name' => $fileName,
                    'status' => 'completed',
                    'imported_at' => now(),
                    'line_count' => $statementTransactions->count(),
                    'metadata' => [
                        ...$header->toArray(),
                        'file_hash' => $fileHash,
                    ],
                    'created_by' => $import->created_by ?? $userId,
                ]);
                $import->save();

                $run = $import->runs()->create([
                    'file_hash' => $fileHash,
                    'file_name' => $fileName,
                    'status' => 'pending',
                    'started_at' => now(),
                    'created_by' => $userId,
                ]);
                $existingLines = $import->lines()
                    ->lockForUpdate()
                    ->get();
                $linesByTransactionKey = $existingLines
                    ->filter(fn (BankStatementLine $line): bool => filled($line->transaction_key))
                    ->keyBy('transaction_key');
                $linesByExternalId = $existingLines
                    ->filter(fn (BankStatementLine $line): bool => filled($line->external_id))
                    ->mapWithKeys(fn (BankStatementLine $line): array => [
                        $this->transactionKeyFactory->externalIdKey($line->external_id) => $line,
                    ]);
                $seenLineIds = [];
                $linesToSuggest = collect();
                $summary = [
                    'created' => 0,
                    'updated' => 0,
                    'preserved' => 0,
                    'needs_review' => 0,
                    'missing_from_file' => 0,
                ];

                foreach ($statementTransactions as $statementTransaction) {
                    $transaction = $statementTransaction['transaction'];
                    $transactionKey = $statementTransaction['transaction_key'];
                    $sourcePayloadHash = $statementTransaction['source_payload_hash'];
                    $line = $linesByTransactionKey->get($transactionKey)
                        ?? $linesByExternalId->get($transactionKey);

                    if (! $line) {
                        $line = BankStatementLine::create([
                            ...$this->lineAttributes($transaction, $header, $transactionKey, $sourcePayloadHash),
                            'bank_statement_import_id' => $import->id,
                            'company_id' => $account->company_id,
                            'financial_account_id' => $account->id,
                            'last_seen_import_run_id' => $run->id,
                            'reconciliation_status' => 'pending',
                        ]);
                        $summary['created']++;
                        $linesToSuggest->push($line);
                        $seenLineIds[] = $line->id;

                        continue;
                    }

                    $seenLineIds[] = $line->id;
                    $status = $line->reconciliation_status;
                    $currentPayloadHash = $line->source_payload_hash
                        ?? data_get($line->metadata, 'line_hash');
                    $hasChanged = ! hash_equals((string) $currentPayloadHash, $sourcePayloadHash);

                    if (! $hasChanged) {
                        $line->update([
                            'transaction_key' => $transactionKey,
                            'last_seen_import_run_id' => $run->id,
                            'source_payload_hash' => $sourcePayloadHash,
                        ]);
                        $summary['preserved']++;

                        continue;
                    }

                    if ($status?->canResolve()) {
                        $line->update([
                            ...$this->lineAttributes($transaction, $header, $transactionKey, $sourcePayloadHash, $line),
                            'last_seen_import_run_id' => $run->id,
                            'needs_review_at' => null,
                            'review_reason' => null,
                        ]);
                        $summary['updated']++;
                        $linesToSuggest->push($line->fresh());

                        continue;
                    }

                    if ($status?->canTransitionTo(BankStatementLineStatus::NEEDS_REVIEW)) {
                        $line->update([
                            ...$this->lineAttributes($transaction, $header, $transactionKey, $sourcePayloadHash, $line),
                            'last_seen_import_run_id' => $run->id,
                            'reconciliation_status' => 'needs_review',
                            'needs_review_at' => now(),
                            'review_reason' => 'Dados bancários divergentes na reimportação.',
                        ]);
                        $summary['needs_review']++;

                        continue;
                    }

                    $line->update([
                        ...$this->lineAttributes($transaction, $header, $transactionKey, $sourcePayloadHash, $line),
                        'last_seen_import_run_id' => $run->id,
                    ]);
                    $summary['updated']++;
                }

                foreach ($existingLines->whereNotIn('id', $seenLineIds) as $line) {
                    $status = $line->reconciliation_status;

                    if (! $status?->canTransitionTo(BankStatementLineStatus::NEEDS_REVIEW)) {
                        continue;
                    }

                    $line->update([
                        'reconciliation_status' => 'needs_review',
                        'needs_review_at' => now(),
                        'review_reason' => 'Linha não encontrada na reimportação.',
                    ]);
                    $summary['missing_from_file']++;
                    $summary['needs_review']++;
                }

                foreach ($linesToSuggest as $line) {
                    $this->suggestService->suggestForLine($line);
                }

                $run->update([
                    'status' => 'completed',
                    'summary' => $summary,
                    'completed_at' => now(),
                ]);
                $import->refresh();
                $audit->recordModelEvent(
                    $import,
                    'bank_statement_import.imported',
                    "Extrato {$fileName} importado",
                    null,
                    $audit->snapshot($import),
                    $userId,
                    null,
                    [
                        'reference' => $reference,
                        'line_count' => $statementTransactions->count(),
                        'financial_account_id' => $account->id,
                        'bank_statement_import_run_id' => $run->id,
                        'summary' => $summary,
                    ],
                );

                return $import->fresh(['lines', 'runs']);
            });

            $this->setSuccess('Extrato OFX importado com sucesso.', [
                'bank_statement_import_id' => $import->id,
            ]);

            return $import;
        } catch (ValidationException $e) {
            Log::warning('Falha de validacao ao importar OFX.', [
                'company_id' => $companyId,
                'financial_account_id' => $financialAccountId,
                'file_name' => $fileName,
                'errors' => $e->errors(),
            ]);

            $this->setError('Falha ao importar OFX.', $e->errors(), 422);

            return null;
        } catch (\Throwable $e) {
            Log::error('Erro ao importar OFX.', [
                'company_id' => $companyId,
                'financial_account_id' => $financialAccountId,
                'file_name' => $fileName,
                'exception' => $e,
            ]);

            $this->setError('Erro ao importar OFX.', [
                'exception' => [$e->getMessage()],
            ]);

            return null;
        }
    }

    /**
     * @return array<int, BankOfxNormalizerInterface>
     */
    private function availableNormalizers(): array
    {
        return $this->normalizers ?? [
            new BradescoOfxNormalizer,
            new InterOfxNormalizer,
            new SicoobOfxNormalizer,
            new SicrediOfxNormalizer,
        ];
    }

    private function resolveNormalizer(OfxStatementHeaderDTO $header): BankOfxNormalizerInterface
    {
        foreach ($this->availableNormalizers() as $normalizer) {
            if ($normalizer->supports($header)) {
                return $normalizer;
            }
        }

        throw ValidationException::withMessages([
            'file' => ['Banco OFX nao homologado para importacao automatica. Suporte atual: Bradesco, Banco Inter, Sicoob e Sicredi.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineAttributes(
        OfxTransactionDTO $transaction,
        OfxStatementHeaderDTO $header,
        string $transactionKey,
        string $sourcePayloadHash,
        ?BankStatementLine $line = null,
    ): array {
        return [
            'transaction_date' => $transaction->transactionDate,
            'amount' => $transaction->amount,
            'balance_amount' => null,
            'description' => $transaction->description,
            'external_id' => $transaction->externalId ?? $transaction->lineHash(),
            'transaction_key' => $transactionKey,
            'document_number' => $transaction->documentNumber,
            'source_payload_hash' => $sourcePayloadHash,
            'metadata' => array_merge($line?->metadata ?? [], [
                ...$transaction->toArray(),
                'bank' => $header->institutionName,
            ]),
        ];
    }
}
