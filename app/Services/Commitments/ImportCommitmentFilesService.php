<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\ImportType;
use App\Models\Attachment;
use App\Models\CommitmentImport;
use App\Models\CommitmentImportFile;
use App\Models\Company;
use App\Services\Attachments\AttachmentService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ImportCommitmentFilesService
{
    /** @param list<UploadedFile> $uploads */
    public function import(int $companyId, int $customerId, int $userId, ImportType $type, array $uploads): CommitmentImport
    {
        app(CommitmentImportAccess::class)->authorize($companyId, $customerId, $userId);
        Validator::make(['files' => $uploads], ['files' => ['required', 'array', 'min:1', 'max:'.($type === ImportType::PAYMENTS ? 1 : 20)]])->validate();
        $import = CommitmentImport::create(['company_id' => $companyId, 'customer_id' => $customerId, 'type' => $type, 'created_by' => $userId]);
        $summary = [];
        $queuedIds = [];
        foreach ($uploads as $upload) {
            $attachment = null;
            try {
                Validator::make(['file' => $upload], ['file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.intdiv(config('commitments.pdf.max_size_bytes'), 1024)]])->validate();
                $hash = $this->digest($upload);
                $entry = DB::transaction(function () use ($companyId, $customerId, $type, $hash, $import, $upload, $userId, &$attachment): array {
                    Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                    $existing = CommitmentImportFile::forCustomer($companyId, $customerId)->where('type', $type->value)->where('sha256', $hash)->first();
                    if ($existing) {
                        return ['name' => $upload->getClientOriginalName(), 'status' => 'duplicate', 'file_id' => $existing->id, 'original_import_id' => $existing->commitment_import_id];
                    }
                    $file = $import->files()->create([
                        'company_id' => $companyId, 'customer_id' => $customerId, 'type' => $type,
                        'sha256' => $hash, 'original_name' => $upload->getClientOriginalName(),
                    ]);
                    $attachment = app(AttachmentService::class)->upload($file, $upload, $file->allowedAttachmentTypes()[0], ['idempotency_key' => $hash]);
                    $attachment->update(['checksum_sha256' => $hash, 'uploaded_by' => $userId]);
                    app(AuditRecorder::class)->recordModelEvent($file, 'commitment.file_imported', 'PDF de empenhos importado.', actorUserId: $userId);

                    return ['name' => $upload->getClientOriginalName(), 'status' => 'accepted', 'file_id' => $file->id];
                });
                $summary[] = $entry;
                if ($entry['status'] === 'accepted') {
                    $queuedIds[] = $entry['file_id'];
                }
            } catch (Throwable $exception) {
                $this->discard($attachment);
                $message = $exception instanceof ValidationException ? $exception->validator->errors()->first() : 'Falha ao armazenar o PDF. Consulte o log e tente novamente.';
                $summary[] = ['name' => $upload instanceof UploadedFile ? $upload->getClientOriginalName() : 'Arquivo inválido', 'status' => 'rejected', 'error' => $message];
                if (! $exception instanceof ValidationException) {
                    report($exception);
                }
            }
        }
        $import->update(['summary' => $summary]);
        app(RefreshCommitmentImportStatus::class)->execute($import->id);
        foreach ($queuedIds as $fileId) {
            try {
                app(QueueCommitmentImportFile::class)->dispatch(CommitmentImportFile::forCustomer($companyId, $customerId)->findOrFail($fileId), $userId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        app(RefreshCommitmentImportStatus::class)->execute($import->id);

        return $import->refresh();
    }

    private function discard(?Attachment $attachment): void
    {
        if ($attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }

    private function digest(UploadedFile $upload): string
    {
        $stream = $upload instanceof TemporaryUploadedFile ? $upload->readStream() : fopen($upload->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file' => 'Não foi possível ler o PDF enviado.']);
        }
        try {
            $header = fread($stream, 5);
            if ($header !== '%PDF-') {
                throw ValidationException::withMessages(['file' => 'O arquivo enviado não possui uma assinatura PDF válida.']);
            }
            $hash = hash_init('sha256');
            hash_update($hash, $header);
            $bytes = hash_update_stream($hash, $stream, config('commitments.pdf.max_size_bytes'));
            if ($bytes + 5 > config('commitments.pdf.max_size_bytes')) {
                throw ValidationException::withMessages(['file' => 'O PDF excede o tamanho máximo permitido.']);
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
