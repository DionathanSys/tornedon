<?php

namespace Tests\Unit\Services\Financial;

use App\Models\CardPaymentProfile;
use App\Services\Financial\CardReceivableCalculatorService;
use Tests\TestCase;

class CardReceivableCalculatorServiceTest extends TestCase
{
    public function test_calculates_fee_net_and_expected_settlement_date(): void
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

        $service = new CardReceivableCalculatorService();
        $result = $service->calculateFromProfile($profile, 1000, '2026-05-04');

        $this->assertSame(1000.0, $result->grossAmount);
        $this->assertSame(35.30, $result->feeAmount);
        $this->assertSame(964.70, $result->netAmount);
        $this->assertSame(30, $result->settlementDays);
        $this->assertSame('2026-06-03', $result->expectedSettlementDate);
        $this->assertSame(10, $result->snapshot['profile_id']);
        $this->assertSame('Master Cielo D+30', $result->snapshot['name']);
    }
}
