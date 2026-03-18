<?php

namespace Tests\Unit\Models;

use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyPartner;
use Tests\TestCase;

class AddressTest extends TestCase
{
    public function test_returns_true_when_address_matches_company_address(): void
    {
        $companyPartner = $this->makeCompanyPartner([
            'street' => 'Rua A',
            'number' => '123',
            'complement' => 'Sala 1',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'zip_code' => '01001-000',
            'city_code' => '3550308',
        ]);

        $address = new Address([
            'street' => 'Rua A',
            'number' => '123',
            'complement' => 'Sala 1',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'postal_code' => '01001-000',
            'city_code' => '3550308',
            'country' => 'Brasil',
        ]);
        $address->setRelation('companyPartner', $companyPartner);

        $this->assertTrue($address->same_as_company_address);
        $this->assertTrue($address->toArray()['same_as_company_address']);
    }

    public function test_returns_false_when_address_differs_from_company_address(): void
    {
        $companyPartner = $this->makeCompanyPartner([
            'street' => 'Rua A',
            'number' => '123',
            'complement' => 'Sala 1',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'zip_code' => '01001-000',
        ]);

        $address = new Address([
            'street' => 'Rua B',
            'number' => '999',
            'city' => 'Campinas',
            'state' => 'SP',
            'postal_code' => '13010-000',
            'country' => 'Brasil',
        ]);
        $address->setRelation('companyPartner', $companyPartner);

        $this->assertFalse($address->same_as_company_address);
    }

    private function makeCompanyPartner(array $companyAddress): CompanyPartner
    {
        $company = new Company([
            'address' => $companyAddress,
        ]);

        $companyPartner = new CompanyPartner([
            'type' => ['customer'],
            'invoice_threshold' => 0,
            'is_active' => true,
        ]);

        $companyPartner->setRelation('company', $company);

        return $companyPartner;
    }
}
