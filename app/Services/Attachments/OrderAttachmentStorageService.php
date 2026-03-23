<?php

namespace App\Services\Attachments;

use App\Models\OrderAttachment;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OrderAttachmentStorageService
{
    use HandlesServiceResponse;

    public function defaultDisk(): string
    {
        return (string) config('attachments.default_disk', 'local');
    }

    public function directoryFor(Model $attachable): string
    {
        $companyId = data_get($attachable, 'company_id');

        return sprintf(
            'attachments/%s/%s/%s',
            $companyId,
            $this->typeDirectoryFor($attachable),
            $attachable->getKey(),
        );
    }

    public function create(Model $attachable, array $data, ?int $userId = null): ?OrderAttachment
    {
        $this->resetResponse();

        try {
            $payload = $this->normalizePayload($data, $userId);

            $attachment = $attachable->attachments()->create($payload);

            $this->setSuccess('Anexo enviado com sucesso.');

            return $attachment;
        } catch (Throwable $exception) {
            $this->deleteFile(
                $data['disk'] ?? $this->defaultDisk(),
                $data['path'] ?? null,
            );

            $this->setError(
                message: 'Nao foi possivel salvar o anexo.',
                errors: [$exception->getMessage()]
            );

            return null;
        }
    }

    public function update(OrderAttachment $attachment, array $data, ?int $userId = null): ?OrderAttachment
    {
        $this->resetResponse();

        $oldDisk = $attachment->disk;
        $oldPath = $attachment->path;

        try {
            $payload = $this->normalizePayload(
                data: $data,
                userId: $userId,
                currentAttachment: $attachment,
            );

            if (($payload['disk'] ?? null) === $oldDisk && ($payload['path'] ?? null) === $oldPath) {
                $payload['uploaded_by'] = $attachment->uploaded_by;
            }

            $attachment->fill($payload);
            $attachment->save();

            if ($oldDisk !== $attachment->disk || $oldPath !== $attachment->path) {
                $this->deleteFile($oldDisk, $oldPath);
            }

            $this->setSuccess('Anexo atualizado com sucesso.');

            return $attachment->refresh();
        } catch (Throwable $exception) {
            $newDisk = $data['disk'] ?? $attachment->disk ?? $this->defaultDisk();
            $newPath = $data['path'] ?? null;

            if (filled($newPath) && ($newDisk !== $oldDisk || $newPath !== $oldPath)) {
                $this->deleteFile($newDisk, $newPath);
            }

            $this->setError(
                message: 'Nao foi possivel atualizar o anexo.',
                errors: [$exception->getMessage()]
            );

            return null;
        }
    }

    public function delete(OrderAttachment $attachment): bool
    {
        $this->resetResponse();

        try {
            $deleted = (bool) $attachment->delete();

            if (! $deleted) {
                $this->setError('Nao foi possivel remover o anexo.');

                return false;
            }

            $this->setSuccess('Anexo removido com sucesso.');

            return true;
        } catch (Throwable $exception) {
            $this->setError(
                message: 'Nao foi possivel remover o anexo.',
                errors: [$exception->getMessage()]
            );

            return false;
        }
    }

    public function removeStoredFile(OrderAttachment $attachment): void
    {
        $this->deleteFile($attachment->disk, $attachment->path);
    }

    public function makeStoredFilename(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $basename = (string) Str::uuid();

        return filled($extension) ? "{$basename}.{$extension}" : $basename;
    }

    private function normalizePayload(array $data, ?int $userId = null, ?OrderAttachment $currentAttachment = null): array
    {
        $disk = (string) ($data['disk'] ?? $currentAttachment?->disk ?? $this->defaultDisk());
        $path = (string) ($data['path'] ?? $currentAttachment?->path ?? '');

        if ($path === '') {
            throw new \InvalidArgumentException('O caminho do arquivo e obrigatorio.');
        }

        $originalName = (string) ($data['original_name'] ?? $currentAttachment?->original_name ?? basename($path));

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $this->resolveMimeType($disk, $path),
            'size_bytes' => $this->resolveSize($disk, $path),
            'uploaded_by' => $userId ?? $currentAttachment?->uploaded_by,
        ];
    }

    private function resolveMimeType(string $disk, string $path): ?string
    {
        try {
            return Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }

    private function resolveSize(string $disk, string $path): ?int
    {
        try {
            $size = Storage::disk($disk)->size($path);

            return is_numeric($size) ? (int) $size : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function deleteFile(?string $disk, ?string $path): void
    {
        if (blank($disk) || blank($path)) {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (Throwable) {
            // Ignora falhas de limpeza para nao impedir o fluxo principal.
        }
    }

    private function typeDirectoryFor(Model $attachable): string
    {
        return match (true) {
            $attachable instanceof \App\Models\ServiceOrder => 'service-orders',
            $attachable instanceof \App\Models\ProductionOrder => 'production-orders',
            default => Str::kebab(class_basename($attachable)),
        };
    }
}
