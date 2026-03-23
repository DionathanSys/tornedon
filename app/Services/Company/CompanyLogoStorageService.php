<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CompanyLogoStorageService
{
    use HandlesServiceResponse;

    public function disk(): string
    {
        return 'public';
    }

    public function directoryFor(int $companyId): string
    {
        return "logos/{$companyId}";
    }

    /**
     * Persiste o path da logo na empresa e remove o arquivo antigo (se existir e for diferente).
     */
    public function save(Company $company, ?string $newPath): bool
    {
        $this->resetResponse();

        try {
            $oldPath = $company->logo_path;

            $company->logo_path = $newPath;
            $company->save();

            if (filled($oldPath) && $oldPath !== $newPath) {
                $this->deleteFile($oldPath);
            }

            $this->setSuccess('Logo atualizada com sucesso.');

            return true;
        } catch (Throwable $exception) {
            $this->setError(
                message: 'Nao foi possivel salvar a logo.',
                errors: [$exception->getMessage()]
            );

            return false;
        }
    }

    /**
     * Remove o arquivo de logo do storage e limpa o campo na empresa.
     */
    public function delete(Company $company): bool
    {
        $this->resetResponse();

        try {
            $path = $company->logo_path;

            $company->logo_path = null;
            $company->save();

            if (filled($path)) {
                $this->deleteFile($path);
            }

            $this->setSuccess('Logo removida com sucesso.');

            return true;
        } catch (Throwable $exception) {
            $this->setError(
                message: 'Nao foi possivel remover a logo.',
                errors: [$exception->getMessage()]
            );

            return false;
        }
    }

    private function deleteFile(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        try {
            if (Storage::disk($this->disk())->exists($path)) {
                Storage::disk($this->disk())->delete($path);
            }
        } catch (Throwable) {
            // Ignora falhas de limpeza para nao impedir o fluxo principal.
        }
    }
}
