<?php 

namespace App\Services\Partner;

use App\Traits\HandlesServiceResponse;

class PartnerService
{
    use HandlesServiceResponse;

    public function registerPartner(array $data): void
    {
        try {
            $action = new Actions\CreatePartner();
            $action->execute($data);

            if(! $action->isSuccess()) {
            
            }

            

        } catch (\Exception $e) {
        }
    }

    public function linkPartnerCompany(int $partnerId, int $companyId): void
    {
        // Logic to link a partner with a company
    }
}