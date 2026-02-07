<?php

namespace App\Services\Address\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Address;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DeleteAddress
{
    public function __construct(
        private Address $address,
        private int $deletedBy,
    ) {}

    public function execute(): bool
    {
        try {
            DB::beginTransaction();

            // Validações de negócio
            $this->validateDeletion();

            // Exclui o endereço
            $result = $this->address->delete();

            DB::commit();

            Log::info('Endereço excluído com sucesso', [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'address_id'    => $this->address->id,
                'partner_id'    => $this->address->partner_id,
                'company_id'    => $this->address->company_id,
                'deleted_by'    => $this->deletedBy,
            ]);

            return $result;
        } catch (QueryException $e) {
            DB::rollBack();

            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Erro de query ao excluir endereço',
                'error'     => $e->getMessage(),
                'error_code' => $e->getCode(),
                'address_id' => $this->address->id,
            ]);

            throw new DomainValidationException([
                'address' => ['Não foi possível excluir o endereço. Verifique se não há vínculos ativos.'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateDeletion(): void
    {
        // Valida se o usuário tem vínculo com a mesma empresa do endereço
        $user = User::find($this->deletedBy);

        if (!$user) {
            throw new DomainValidationException([
                'user' => ['Usuário não encontrado.'],
            ]);
        }

        $hasCompanyAccess = $user->companies()
            ->where('companies.id', $this->address->company_id)
            ->exists();

        if (!$hasCompanyAccess) {
            throw new DomainValidationException([
                'address' => ['Você não tem permissão para excluir este endereço. O endereço pertence a uma empresa diferente.'],
            ]);
        }
    }
}
