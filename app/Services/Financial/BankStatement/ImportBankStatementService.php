<?php

namespace App\Services\Financial\BankStatement;

use App\Domain\DTO\Financial\OfxStatementHeaderDTO;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\FinancialAccount;
use App\Services\Financial\BankStatement\Contracts\BankOfxNormalizerInterface;
use App\Services\Financial\BankStatement\Contracts\OfxStatementParserInterface;
use App\Services\Financial\BankStatement\Normalizers\BradescoOfxNormalizer;
use App\Services\Financial\BankStatement\Normalizers\SicoobOfxNormalizer;
use App\Services\Financial\BankStatement\Normalizers\SicrediOfxNormalizer;
use App\Services\Financial\BankStatement\Parsers\GenericOfxStatementParser;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportBankStatementService
{
    use HandlesServiceResponse;

    /**
     * @param  array<int, BankOfxNormalizerInterface>|null  $normalizers
     */
    public function __construct(
        private readonly OfxStatementParserInterface $parser = new GenericOfxStatementParser(),
        private readonly SuggestBankStatementMatchesService $suggestService = new SuggestBankStatementMatchesService(),
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
                    'financial_account_id' => ['Conta financeira invalida para a empresa informada.'],
                ]);
            }

            $parsed = $this->parser->parse($contents);
            $header = $this->resolveNormalizer($parsed['header'])->normalizeHeader($parsed['header']);
            $transactions = collect($parsed['transactions'])
                ->map(fn ($transaction) => $this->resolveNormalizer($header)->normalizeTransaction($transaction))
                ->values();

            if ($transactions->isEmpty()) {
                throw ValidationException::withMessages([
                    'file' => ['O OFX nao possui transacoes compativeis com a importacao.'],
                ]);
            }

            $reference = $header->reference();
            $fileHash = hash('sha256', $contents);

            $import = DB::transaction(function () use (
                $account,
                $header,
                $transactions,
                $fileName,
                $fileHash,
                $reference,
                $userId
            ) {
                $import = BankStatementImport::query()->firstOrNew([
                    'company_id' => $account->company_id,
                    'financial_account_id' => $account->id,
                    'source' => 'ofx',
                    'reference' => $reference,
                ]);

                $import->fill([
                    'file_name' => $fileName,
                    'status' => 'completed',
                    'imported_at' => now(),
                    'line_count' => $transactions->count(),
                    'metadata' => [
                        ...$header->toArray(),
                        'file_hash' => $fileHash,
                    ],
                    'created_by' => $import->created_by ?? $userId,
                ]);
                $import->save();

                $import->lines()->delete();

                foreach ($transactions as $transaction) {
                    BankStatementLine::create([
                        'bank_statement_import_id' => $import->id,
                        'company_id' => $account->company_id,
                        'financial_account_id' => $account->id,
                        'transaction_date' => $transaction->transactionDate,
                        'amount' => $transaction->amount,
                        'balance_amount' => null,
                        'description' => $transaction->description,
                        'external_id' => $transaction->externalId ?? $transaction->lineHash(),
                        'document_number' => $transaction->documentNumber,
                        'reconciliation_status' => 'pending',
                        'metadata' => [
                            ...$transaction->toArray(),
                            'bank' => $header->institutionName,
                        ],
                    ]);
                }

                foreach ($import->lines()->get() as $line) {
                    $this->suggestService->suggestForLine($line);
                }

                return $import->fresh('lines');
            });

            $this->setSuccess('Extrato OFX importado com sucesso.', [
                'bank_statement_import_id' => $import->id,
            ]);

            return $import;
        } catch (ValidationException $e) {
            $this->setError('Falha ao importar OFX.', $e->errors(), 422);

            return null;
        } catch (\Throwable $e) {
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
            new BradescoOfxNormalizer(),
            new SicoobOfxNormalizer(),
            new SicrediOfxNormalizer(),
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
            'file' => ['Banco OFX nao homologado para importacao automatica. Suporte atual: Bradesco, Sicoob e Sicredi.'],
        ]);
    }
}
