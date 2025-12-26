<?php 

namespace App\Services\Partner;

use App\Models\Partner;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class PartnerService
{
    use HandlesServiceResponse;

    public function registerPartner(array $data): ?Partner
    {
        try {
            $action = new Actions\CreatePartner();
            $result = $action->execute($data);

            if($action->hasError()) {
                ds($action->getErrors())->label('Error creating partner in ' . __METHOD__ . '@' . __LINE__);
                $this->setError(
                    message: 'Erro ao registrar parceiro',
                    errors: $action->getErrors()
                );
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message'   => 'Erro ao registrar parceiro',
                    'errors'    => $action->getErrors(),
                ]);
                return null;
            }

            $this->associatePartnerCompany($result->id, $data['company_id']);

            ds($result)->label('resultado service retorno');
            return $result;

        } catch (\Exception $e) {
            return null;
        }
    }

    public function associatePartnerCompany(int $partnerId, int $companyId): void
    {
        ds('testet'.__METHOD__."@".__LINE__);
        Partner::query()
            ->find($partnerId)
            ->companies()
            ->syncWithoutDetaching([$companyId]);
        return;
    }
}