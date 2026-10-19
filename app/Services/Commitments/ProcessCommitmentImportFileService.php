<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\ProcessingStatus;
use App\Models\CommitmentImportFile;
use App\Models\Company;
use App\Models\CustomerCommitment;
use App\Services\Audit\AuditRecorder;
use App\Services\Commitments\DTO\CommitmentData;
use App\Services\Commitments\DTO\PaymentReportData;
use App\Services\Commitments\Pdf\ChapecoCommitmentParser;
use App\Services\Commitments\Pdf\ChapecoPaymentReportParser;
use App\Services\Commitments\Pdf\PdfReadingException;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProcessCommitmentImportFileService
{
    public function process(int $fileId, int $companyId, int $customerId, int $userId, string $token): void
    {
        $file = DB::transaction(function () use ($fileId, $companyId, $customerId, $token): ?CommitmentImportFile {
            $file = CommitmentImportFile::forCustomer($companyId, $customerId)->lockForUpdate()->findOrFail($fileId);
            if ($file->status !== ProcessingStatus::QUEUED || $file->processing_token !== $token) {
                return null;
            }
            $file->update(['status' => ProcessingStatus::PROCESSING, 'processing_token' => $token, 'processing_started_at' => now(), 'attempts' => $file->attempts + 1, 'last_error' => null]);

            return $file;
        });
        if (! $file) {
            return;
        }

        try {
            app(RefreshCommitmentImportStatus::class)->execute($file->commitment_import_id);
            $company = app(CommitmentImportAccess::class)->authorize($companyId, $customerId, $userId);
            $attachment = $file->currentAttachments()->where('type', $file->allowedAttachmentTypes()[0])->first();
            if (! $attachment || $attachment->disk !== 'local' || (int) $attachment->company_id !== $companyId) {
                throw new PdfReadingException('PDF original indisponível para processamento.');
            }
            $path = Storage::disk($attachment->disk)->path($attachment->path);
            if (! is_file($path) || hash_file('sha256', $path) !== $file->sha256) {
                throw new PdfReadingException('O PDF original está ausente ou não corresponde ao arquivo importado.');
            }
            $pdf = app(PdfTextExtractor::class)->extract($path);
            $issuerDocument = $file->customer->document_number;
            if ($file->type === ImportType::COMMITMENTS) {
                $data = app(ChapecoCommitmentParser::class)->parse($pdf, $issuerDocument, $company->document_number);
                $version = ChapecoCommitmentParser::VERSION;
            } else {
                $data = app(ChapecoPaymentReportParser::class)->parse($pdf, $issuerDocument, $company->name);
                $version = ChapecoPaymentReportParser::VERSION;
            }

            DB::transaction(function () use ($fileId, $companyId, $customerId, $token, $data, $pdf, $version, $userId): void {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $file = CommitmentImportFile::forCustomer($companyId, $customerId)->lockForUpdate()->findOrFail($fileId);
                if ($file->processing_token !== $token || $file->status !== ProcessingStatus::PROCESSING) {
                    return; // An explicit recovery superseded this worker.
                }
                if ($file->hasProtectedResults()) {
                    throw new PdfReadingException('A leitura não pode sobrescrever dados já revisados ou vinculados.');
                }
                $result = $data->toArray();
                if ($data instanceof CommitmentData) {
                    $duplicate = CustomerCommitment::forCustomer($companyId, $customerId)->where('number', $data->number)->where('fiscal_year', $data->fiscalYear)->where('commitment_import_file_id', '!=', $fileId)->first();
                    if ($duplicate) {
                        $result['issues'][] = 'Este empenho já foi importado em outro arquivo. O registro existente foi preservado.';
                        $result['existing_commitment_id'] = $duplicate->id;
                    } else {
                        $this->saveCommitment($file, $data);
                    }
                    $needsReview = $result['issues'] !== [];
                    $totals = ['line_count' => 1, 'declared_total' => $data->amountCents / 100, 'extracted_total' => $data->amountCents / 100];
                } else {
                    $this->savePayments($file, $data);
                    $needsReview = $data->requiresReview();
                    $totals = ['line_count' => count($data->lines), 'declared_total' => $data->declaredTotalCents === null ? null : $data->declaredTotalCents / 100, 'extracted_total' => $data->extractedTotalCents() / 100];
                }
                $file->update([
                    ...$totals, 'status' => $needsReview ? ProcessingStatus::NEEDS_REVIEW : ProcessingStatus::PROCESSED,
                    'extracted_text' => $pdf->text(), 'extracted_data' => $result, 'page_count' => count($pdf->pages),
                    'parser_version' => $version, 'processed_at' => now(), 'last_error' => null, 'processing_token' => null,
                ]);
                app(AuditRecorder::class)->recordModelEvent($file, 'commitment.file_processed', 'PDF de empenhos processado.', actorUserId: $userId, metadata: ['parser_version' => $version, 'requires_review' => $needsReview]);
            });
        } catch (Throwable $exception) {
            $message = match (true) {
                $exception instanceof PdfReadingException => $exception->getMessage(),
                $exception instanceof ValidationException => $exception->validator->errors()->first(),
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403 => 'O usuário não possui mais acesso financeiro ativo à empresa.',
                default => 'Falha interna ao processar o PDF. Consulte o log e tente reprocessar.',
            };
            $this->fail($fileId, $companyId, $customerId, $token, $message, $userId);
            if (! $exception instanceof PdfReadingException && ! $exception instanceof ValidationException) {
                report($exception);
            }
        } finally {
            app(RefreshCommitmentImportStatus::class)->execute($file->commitment_import_id);
        }
    }

    public function fail(int $fileId, int $companyId, int $customerId, string $token, string $message, ?int $userId = null): void
    {
        $importId = DB::transaction(function () use ($fileId, $companyId, $customerId, $token, $message, $userId): ?int {
            $file = CommitmentImportFile::forCustomer($companyId, $customerId)->lockForUpdate()->find($fileId);
            if (! $file || $file->processing_token !== $token || ! in_array($file->status, [ProcessingStatus::QUEUED, ProcessingStatus::PROCESSING], true)) {
                return null;
            }
            $file->update(['status' => ProcessingStatus::FAILED, 'last_error' => $message, 'processing_token' => null]);
            app(AuditRecorder::class)->recordModelEvent($file, 'commitment.file_failed', 'Falha ao processar PDF de empenhos.', actorUserId: $userId, metadata: ['error' => $message]);

            return $file->commitment_import_id;
        });
        if ($importId) {
            app(RefreshCommitmentImportStatus::class)->execute($importId);
        }
    }

    private function saveCommitment(CommitmentImportFile $file, CommitmentData $data): void
    {
        $record = $file->commitments()->first() ?? new CustomerCommitment;
        $record->fill([
            'company_id' => $file->company_id, 'customer_id' => $file->customer_id, 'commitment_import_file_id' => $file->id,
            'number' => $data->number, 'fiscal_year' => $data->fiscalYear, 'issued_at' => $data->issuedAt,
            'patrimony' => $data->patrimony, 'object' => $data->object,
            'amount' => $data->amountCents / 100, 'retained_amount' => $data->retainedAmountCents / 100,
            'net_amount' => $data->netAmountCents / 100, 'page' => $data->page, 'original_data' => $data->toArray(),
        ])->save();
    }

    private function savePayments(CommitmentImportFile $file, PaymentReportData $data): void
    {
        foreach ($data->lines as $line) {
            $document = count($line->fiscalDocuments) === 1 ? $line->fiscalDocuments[0] : null;
            $file->paymentLines()->updateOrCreate(['position' => $line->position], [
                'company_id' => $file->company_id, 'customer_id' => $file->customer_id,
                'page_start' => $line->pageStart, 'page_end' => $line->pageEnd,
                'commitment_number' => $line->commitmentNumber, 'fiscal_year' => $line->fiscalYear,
                'patrimony' => $line->patrimony, 'fiscal_document_number' => $document['number'] ?? null,
                'fiscal_document_date' => $document['issued_at'] ?? null, 'payment_date' => $line->paymentDate,
                'amount' => $line->amountCents / 100, 'object' => $line->object, 'original_data' => $line->toArray(),
            ]);
        }
        $file->paymentLines()->where('position', '>', count($data->lines))->delete();
    }
}
