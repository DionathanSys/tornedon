<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateStorageToR2Command extends Command
{
    protected $signature = 'storage:migrate-to-r2 {--dry-run : Exibe as alteracoes sem copiar arquivos} {--delete-source : Remove arquivos locais depois de confirmar a copia}';

    protected $description = 'Migra anexos e logos de empresas para o Cloudflare R2.';

    /**
     * @var array{migrated:int,skipped:int,missing:int,failed:int}
     */
    private array $attachments = [
        'migrated' => 0,
        'skipped' => 0,
        'missing' => 0,
        'failed' => 0,
    ];

    /**
     * @var array{migrated:int,skipped:int,missing:int,failed:int}
     */
    private array $logos = [
        'migrated' => 0,
        'skipped' => 0,
        'missing' => 0,
        'failed' => 0,
    ];

    public function handle(): int
    {
        $targetDisk = 'r2';

        if (config('uploads.logo_disk') !== $targetDisk) {
            $this->warn("COMPANY_LOGO_DISK esta configurado como '".config('uploads.logo_disk')."'; os logos serao migrados para '{$targetDisk}'.");
        }

        Attachment::query()
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use ($targetDisk): void {
                foreach ($attachments as $attachment) {
                    $this->migrateAttachment($attachment, $targetDisk);
                }
            });

        Company::query()
            ->whereNotNull('logo_path')
            ->where('logo_path', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($companies) use ($targetDisk): void {
                foreach ($companies as $company) {
                    $this->migrateLogo($company, $targetDisk);
                }
            });

        $this->table(
            ['Tipo', 'Migrados', 'Ignorados', 'Ausentes', 'Falhos'],
            [
                ['Anexos', ...array_values($this->attachments)],
                ['Logos', ...array_values($this->logos)],
            ],
        );

        return $this->attachments['failed'] + $this->logos['failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function migrateAttachment(Attachment $attachment, string $targetDisk): void
    {
        $sourceDisk = $attachment->disk;

        if ($sourceDisk === $targetDisk && Storage::disk($targetDisk)->exists($attachment->path)) {
            $this->attachments['skipped']++;

            return;
        }

        if (! $this->copy($sourceDisk, $targetDisk, $attachment->path, "anexo {$attachment->id}")) {
            return;
        }

        if (! $this->option('dry-run')) {
            $attachment->update(['disk' => $targetDisk]);
        }

        $this->attachments['migrated']++;
    }

    private function migrateLogo(Company $company, string $targetDisk): void
    {
        $path = (string) $company->logo_path;
        $sourceDisk = 'public';

        if (Storage::disk($targetDisk)->exists($path)) {
            $this->logos['skipped']++;

            if ($this->option('delete-source') && ! $this->option('dry-run') && Storage::disk($sourceDisk)->exists($path)) {
                Storage::disk($sourceDisk)->delete($path);
            }

            return;
        }

        if (! $this->copy($sourceDisk, $targetDisk, $path, "logo da empresa {$company->id}")) {
            return;
        }

        $this->logos['migrated']++;
    }

    private function copy(string $sourceDisk, string $targetDisk, string $path, string $label): bool
    {
        if (! Storage::disk($sourceDisk)->exists($path)) {
            $this->warn("Ausente: {$label} ({$sourceDisk}:{$path})");
            $this->incrementMissing($label);

            return false;
        }

        if ($this->option('dry-run')) {
            $this->line("Migraria: {$label} ({$sourceDisk}:{$path} -> {$targetDisk}:{$path})");

            return true;
        }

        $stream = null;

        try {
            $stream = Storage::disk($sourceDisk)->readStream($path);

            if (! is_resource($stream) || ! Storage::disk($targetDisk)->writeStream($path, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('Nao foi possivel gravar o arquivo no R2.');
            }

            if ($this->option('delete-source') && $sourceDisk !== $targetDisk) {
                Storage::disk($sourceDisk)->delete($path);
            }

            return true;
        } catch (Throwable $exception) {
            $this->error("Falha ao migrar {$label}: {$exception->getMessage()}");
            Log::error('Falha ao migrar arquivo para R2.', [
                'label' => $label,
                'source_disk' => $sourceDisk,
                'target_disk' => $targetDisk,
                'path' => $path,
                'exception' => $exception,
            ]);
            $this->incrementFailed($label);

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function incrementMissing(string $label): void
    {
        str_starts_with($label, 'anexo')
            ? $this->attachments['missing']++
            : $this->logos['missing']++;
    }

    private function incrementFailed(string $label): void
    {
        str_starts_with($label, 'anexo')
            ? $this->attachments['failed']++
            : $this->logos['failed']++;
    }
}
