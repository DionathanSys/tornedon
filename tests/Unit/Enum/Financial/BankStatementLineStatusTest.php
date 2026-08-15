<?php

namespace Tests\Unit\Enum\Financial;

use App\Enum\Financial\BankStatementLineStatus;
use Tests\TestCase;

class BankStatementLineStatusTest extends TestCase
{
    public function test_only_pending_lines_can_receive_a_new_financial_resolution(): void
    {
        $this->assertTrue(BankStatementLineStatus::PENDING->canResolve());
        $this->assertFalse(BankStatementLineStatus::RECONCILED->canResolve());
        $this->assertFalse(BankStatementLineStatus::IGNORED->canResolve());
        $this->assertFalse(BankStatementLineStatus::NEEDS_REVIEW->canResolve());
        $this->assertFalse(BankStatementLineStatus::REVERSED->canResolve());
    }

    public function test_it_allows_only_documented_reconciliation_state_transitions(): void
    {
        $this->assertTrue(BankStatementLineStatus::PENDING->canTransitionTo(BankStatementLineStatus::RECONCILED));
        $this->assertTrue(BankStatementLineStatus::PENDING->canTransitionTo(BankStatementLineStatus::IGNORED));
        $this->assertTrue(BankStatementLineStatus::RECONCILED->canTransitionTo(BankStatementLineStatus::NEEDS_REVIEW));
        $this->assertTrue(BankStatementLineStatus::RECONCILED->canTransitionTo(BankStatementLineStatus::REVERSED));
        $this->assertTrue(BankStatementLineStatus::IGNORED->canTransitionTo(BankStatementLineStatus::PENDING));
        $this->assertTrue(BankStatementLineStatus::NEEDS_REVIEW->canTransitionTo(BankStatementLineStatus::RECONCILED));
        $this->assertTrue(BankStatementLineStatus::REVERSED->canTransitionTo(BankStatementLineStatus::PENDING));

        $this->assertFalse(BankStatementLineStatus::RECONCILED->canTransitionTo(BankStatementLineStatus::IGNORED));
        $this->assertFalse(BankStatementLineStatus::IGNORED->canTransitionTo(BankStatementLineStatus::RECONCILED));
        $this->assertFalse(BankStatementLineStatus::REVERSED->canTransitionTo(BankStatementLineStatus::RECONCILED));
        $this->assertFalse(BankStatementLineStatus::PENDING->canTransitionTo(BankStatementLineStatus::REVERSED));
    }
}
