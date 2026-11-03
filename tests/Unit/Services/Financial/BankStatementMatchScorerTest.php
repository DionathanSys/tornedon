<?php

namespace Tests\Unit\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Services\Financial\BankStatement\BankStatementMatchScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankStatementMatchScorerTest extends TestCase
{
    public function test_value_and_date_without_identity_do_not_imply_high_confidence(): void
    {
        $result = app(BankStatementMatchScorer::class)->assess(
            $this->line('PIX PAGTO TRANSFERÊNCIA DÉBITO'),
            $this->movement('Pagamento PIX TED'),
        );

        $this->assertSame(0, $result['points']['text']);
        $this->assertSame(0, $result['points']['counterparty_name']);
        $this->assertSame('medium', $result['confidence']);
    }

    public function test_accents_corporate_suffixes_and_known_abbreviations_are_normalized(): void
    {
        $result = app(BankStatementMatchScorer::class)->assess(
            $this->line('PIX Cia Coml Sao Jose'),
            $this->movement('Operação', new Partner(['name' => 'Companhia Comercial São José LTDA'])),
        );

        $this->assertSame(25, $result['points']['counterparty_name']);
        $this->assertSame('high', $result['confidence']);
        $this->assertContains('Nome da contraparte compatível após normalização.', $result['evidence']);
    }

    public function test_repeated_words_do_not_inflate_the_text_or_name_score(): void
    {
        $line = $this->line('PIX Silva Silva Silva Silva Silva Silva');
        $result = app(BankStatementMatchScorer::class)->assess($line, $this->movement('Silva', new Partner(['name' => 'José Silva'])));

        $this->assertSame(4, $result['points']['text']);
        $this->assertSame(10, $result['points']['counterparty_name']);
        $this->assertNotSame('high', $result['confidence']);
    }

    public function test_common_corporate_words_do_not_identify_different_counterparties(): void
    {
        $result = app(BankStatementMatchScorer::class)->assess(
            $this->line('PIX COMPANHIA COMERCIAL JOAO'),
            $this->movement('Operação', new Partner(['name' => 'Companhia Comercial José'])),
        );

        $this->assertSame(0, $result['points']['counterparty_name']);
        $this->assertNotSame('high', $result['confidence']);
    }

    public static function documentCases(): array
    {
        return [
            'CPF formatado' => ['529.982.247-25', '52998224725', 40],
            'CNPJ formatado' => ['04.252.011/0001-10', '04252011000110', 40],
            'CPF inválido' => ['123.456.789-10', '12345678910', 0],
            'CPF mascarado' => ['***.982.247-**', '52998224725', 0],
            'dígitos repetidos' => ['111.111.111-11', '11111111111', 0],
        ];
    }

    #[DataProvider('documentCases')]
    public function test_only_complete_valid_tax_documents_are_used_as_identity(string $bankDocument, string $document, int $points): void
    {
        $result = app(BankStatementMatchScorer::class)->assess(
            $this->line('PIX '.$bankDocument),
            $this->movement('Operação', new Partner(['name' => 'Empresa Exemplo', 'document_number' => $document])),
        );

        $this->assertSame($points, $result['points']['tax_document']);
        $this->assertSame($points === 40 ? 'high' : 'medium', $result['confidence']);
    }

    public function test_the_company_own_document_is_not_used_to_match_a_counterparty(): void
    {
        $result = app(BankStatementMatchScorer::class)->assess(
            $this->line('PIX 11.222.333/0001-81'),
            $this->movement('Operação', new Partner(['name' => 'Outra Empresa', 'document_number' => '11222333000181'])),
        );

        $this->assertSame(0, $result['points']['tax_document']);
        $this->assertSame([], $result['conflicts']);
        $this->assertNotSame('high', $result['confidence']);
    }

    public function test_conflicting_documents_override_even_a_matching_name_and_reference(): void
    {
        $line = $this->line('PIX José Silva 529.982.247-25 NF 12345');
        $candidate = $this->installment(new Partner(['name' => 'José Silva', 'document_number' => '11144477735']), '12345');
        $result = app(BankStatementMatchScorer::class)->assess($line, $candidate);

        $this->assertSame(25, $result['points']['counterparty_name']);
        $this->assertSame(30, $result['points']['reference']);
        $this->assertContains('tax_document_conflict', $result['conflicts']);
        $this->assertLessThanOrEqual(39, $result['score']);
        $this->assertSame('low', $result['confidence']);
        $this->assertTrue($result['requires_review']);
    }

    public function test_a_valid_document_can_resolve_a_trade_name_difference(): void
    {
        $line = $this->line('PIX 529.982.247-25');
        $line->metadata = ['direction' => 'outflow', 'raw' => ['name' => 'Loja Comercial']];
        $result = app(BankStatementMatchScorer::class)->assess($line, $this->movement('Operação', new Partner(['name' => 'José Silva', 'document_number' => '52998224725'])));

        $this->assertSame([], $result['conflicts']);
        $this->assertSame('high', $result['confidence']);
    }

    public function test_an_unresolved_structured_name_conflict_is_low_confidence(): void
    {
        $line = $this->line('PIX');
        $line->metadata = ['direction' => 'outflow', 'raw' => ['name' => 'Maria Pereira']];
        $result = app(BankStatementMatchScorer::class)->assess($line, $this->movement('Operação', new Partner(['name' => 'José Silva'])));

        $this->assertContains('counterparty_name_conflict', $result['conflicts']);
        $this->assertLessThanOrEqual(39, $result['score']);
        $this->assertSame('low', $result['confidence']);
    }

    public function test_document_references_use_exact_identifiers_without_bank_codes_or_fitid(): void
    {
        $line = $this->line('PIX NF-e 00012345');
        $candidate = $this->installment(null, '12345');
        $result = app(BankStatementMatchScorer::class)->assess($line, $candidate);
        $this->assertSame(30, $result['points']['reference']);
        $this->assertSame('high', $result['confidence']);

        $line = $this->line('PIX');
        $line->document_number = '077';
        $line->external_id = '12345';
        $result = app(BankStatementMatchScorer::class)->assess($line, $candidate);
        $this->assertSame(0, $result['points']['reference']);
        $this->assertNotSame('high', $result['confidence']);

        $line->document_number = '00012345';
        $this->assertSame(30, app(BankStatementMatchScorer::class)->assess($line, $candidate)['points']['reference']);
    }

    public function test_money_comparisons_are_in_integer_cents(): void
    {
        $line = $this->line('PIX José Silva', 0.30);
        $candidate = $this->movement('Operação', new Partner(['name' => 'José Silva']), 0.29);
        $result = app(BankStatementMatchScorer::class)->assess($line, $candidate);
        $this->assertSame(1, $result['amount_difference_cents']);
        $this->assertNotSame(35, $result['points']['amount']);
        $this->assertTrue($result['requires_review']);
        $this->assertNotSame('high', $result['confidence']);

        $candidate->amount = 0.1 + 0.2;
        $this->assertSame(0, app(BankStatementMatchScorer::class)->assess($line, $candidate)['amount_difference_cents']);
    }

    public function test_movements_can_use_the_original_payment_title_identity_only_within_the_same_company(): void
    {
        $installment = $this->installment(new Partner(['name' => 'José Silva', 'document_number' => '52998224725']), '12345');
        $payment = new AccountPayableInstallmentPayment(['company_id' => 1]);
        $payment->setRelation('installment', $installment);
        $movement = $this->movement('Operação');
        $movement->origin_type = AccountPayableInstallmentPayment::class;
        $movement->setRelation('payablePayment', $payment);
        $line = $this->line('PIX 529.982.247-25 NF 12345');
        $scorer = app(BankStatementMatchScorer::class);
        $result = $scorer->assess($line, $movement);
        $this->assertSame(40, $result['points']['tax_document']);
        $this->assertSame(30, $result['points']['reference']);

        $payment->company_id = 2;
        $result = $scorer->assess($line, $movement);
        $this->assertSame(0, $result['points']['tax_document']);
        $this->assertSame(0, $result['points']['reference']);
        $this->assertNotSame('high', $result['confidence']);
    }

    private function line(string $description, float $amount = 100): BankStatementLine
    {
        $line = new BankStatementLine(['company_id' => 1, 'financial_account_id' => 1, 'amount' => $amount, 'description' => $description, 'transaction_date' => '2026-04-11', 'metadata' => ['direction' => 'outflow']]);
        $line->setRelation('company', new Company(['document_number' => '11222333000181']));
        $line->setRelation('financialAccount', new FinancialAccount(['reconciliation_amount_tolerance' => 0.05, 'reconciliation_date_tolerance_days' => 3]));

        return $line;
    }

    private function movement(string $description, ?Partner $partner = null, float $amount = 100): CashMovement
    {
        $movement = new CashMovement(['company_id' => 1, 'direction' => 'outflow', 'amount' => $amount, 'transaction_date' => '2026-04-11', 'description' => $description, 'origin_type' => 'manual']);
        $movement->setRelation('counterpartyPartner', $partner);

        return $movement;
    }

    private function installment(?Partner $partner, string $reference): AccountPayableInstallment
    {
        $parent = new AccountPayable(['company_id' => 1, 'document_number' => $reference]);
        $parent->setRelation('supplier', $partner);
        $installment = new AccountPayableInstallment(['company_id' => 1, 'balance_amount' => 100, 'due_date' => '2026-04-11']);
        $installment->setRelation('accountPayable', $parent);

        return $installment;
    }
}
