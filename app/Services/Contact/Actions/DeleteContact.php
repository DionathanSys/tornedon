<?php

namespace App\Services\Contact\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteContact
{
    public function __construct(
        private Contact $contact,
        private int $deletedBy,
    ) {}

    public function execute(): bool
    {
        try {
            DB::beginTransaction();

            // Validações de negócio
            $this->validateDeletion();

            // Exclui o contato
            $result = $this->contact->delete();

            DB::commit();

            Log::info('Contato excluído com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'contact_id'         => $this->contact->id,
                'company_partner_id' => $this->contact->company_partner_id,
                'deleted_by'         => $this->deletedBy,
            ]);

            return $result;
        } catch (QueryException $e) {
            DB::rollBack();

            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'    => 'Erro de query ao excluir contato',
                'error'      => $e->getMessage(),
                'error_code' => $e->getCode(),
                'contact_id' => $this->contact->id,
            ]);

            throw new DomainValidationException([
                'contact' => ['Não foi possível excluir o contato. Verifique se não há vínculos ativos.'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateDeletion(): void
    {
        // Valida se o usuário tem vínculo com a mesma empresa do contato
        $user = User::find($this->deletedBy);

        if (!$user) {
            throw new DomainValidationException([
                'user' => ['Usuário não encontrado.'],
            ]);
        }

        // Carrega o companyPartner para obter o company_id
        $companyId = $this->contact->companyPartner->company_id;

        $hasCompanyAccess = $user->companies()
            ->where('companies.id', $companyId)
            ->exists();

        if (!$hasCompanyAccess) {
            throw new DomainValidationException([
                'contact' => ['Você não tem permissão para excluir este contato. O contato pertence a uma empresa diferente.'],
            ]);
        }
    }
}
