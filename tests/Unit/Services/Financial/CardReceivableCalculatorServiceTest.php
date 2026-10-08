<?php

namespace Tests\Unit\Services\Financial;

use App\Models\CardPaymentProfile;
use App\Services\Financial\CardReceivableCalculatorService;
use Tests\TestCase;

class CardReceivableCalculatorServiceTest extends TestCase
{
    public function test_calculates_settlement_date_without_applying_legacy_fees(): void
    {
        $profile = new CardPaymentProfile([
            'id' => 10,
            'name' => 'Master Cielo D+30',
            'brand' => 'Mastercard',
            'acquirer' => 'Cielo',
            'fee_percent' => 3.50,
            'fee_fixed' => 0.30,
            'settlement_days' => 30,
            'active' => true,
        ]);

        $service = new CardReceivableCalculatorService;
        $result = $service->calculateFromProfile($profile, 1000, '2026-05-04');

        $this->assertSame(1000.0, $result->grossAmount);
        $this->assertSame(0.0, $result->feeAmount);
        $this->assertSame(1000.0, $result->netAmount);
        $this->assertArrayNotHasKey('fee_percent', $result->snapshot);
        $this->assertSame(30, $result->settlementDays);
        $this->assertSame('2026-06-03', $result->expectedSettlementDate);
        $this->assertSame(10, $result->snapshot['profile_id']);
        $this->assertSame('Master Cielo D+30', $result->snapshot['name']);
    }
}
