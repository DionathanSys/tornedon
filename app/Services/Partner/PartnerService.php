<?php 

namespace App\Services\Partner;

use App\Models\Partner;
use App\Traits\HandlesServiceResponse;

class PartnerService
{
    use HandlesServiceResponse;

    public function registerPartner(array $data): ?Partner
    {
        try {
            $action = new Actions\CreatePartner();
            $result = $action->execute($data);

            if($action->hasError()) {
                $this->setError(
                    message: 'Erro ao registrar parceiro',
                    errors: $action->getErrors()
                );
                return null;
            }

            $this->associatePartnerCompany($result->id, $data['company_id']);

            return $result;

        } catch (\Exception $e) {
            return null;
        }
    }

    public function associatePartnerCompany(int $partnerId, int $companyId): void
    {
        Partner::query()
            ->find($partnerId)
            ->companies()
            ->syncWithoutDetaching([$companyId]);
    }
}