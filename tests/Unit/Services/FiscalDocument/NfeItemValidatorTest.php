<?php

namespace Tests\Unit\Services\FiscalDocument;

use App\Services\FiscalDocument\Validators\Items\NfeItemValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NfeItemValidatorTest extends TestCase
{
    public function test_validate_update_accepts_product_code_when_present(): void
    {
        $validated = NfeItemValidator::validateUpdate([
            'product_code' => 'PRD-001',
        ]);

        $this->assertSame('PRD-001', $validated['product_code']);
    }

    public function test_validate_update_rejects_null_product_code(): void
    {
        $this->expectException(ValidationException::class);

        NfeItemValidator::validateUpdate([
            'product_code' => null,
        ]);
    }
}
