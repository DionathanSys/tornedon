<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_document_tax_details')) {
            return;
        }

        Schema::table('fiscal_document_tax_details', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_document_tax_details', 'tax_totals')) {
                $table->json('tax_totals')->nullable()->after('tax_data');
            }

            if (! Schema::hasColumn('fiscal_document_tax_details', 'fiscal_metadata')) {
                $table->json('fiscal_metadata')->nullable()->after('tax_totals');
            }
        });

        DB::table('fiscal_document_tax_details')
            ->select(['id', 'tax_data', 'tax_totals', 'fiscal_metadata'])
            ->whereNotNull('tax_data')
            ->orderBy('id')
            ->chunkById(500, function ($details): void {
                foreach ($details as $detail) {
                    [$taxTotals, $fiscalMetadata] = $this->splitDocumentTaxData($detail->tax_data);

                    DB::table('fiscal_document_tax_details')
                        ->where('id', $detail->id)
                        ->update([
                            'tax_totals' => $detail->tax_totals ?? ($taxTotals === null ? null : json_encode($taxTotals)),
                            'fiscal_metadata' => $detail->fiscal_metadata ?? ($fiscalMetadata === null ? null : json_encode($fiscalMetadata)),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not drop columns or remove migrated data.
    }

    /**
     * @return array{0: ?array, 1: ?array}
     */
    private function splitDocumentTaxData(mixed $value): array
    {
        if (! is_array($value)) {
            $value = is_string($value) && $value !== '' ? json_decode($value, true) : null;
        }

        if (! is_array($value)) {
            return [null, null];
        }

        $totals = $value['totais'] ?? null;
        unset($value['totais']);

        return [
            is_array($totals) ? $totals : null,
            $value !== [] ? $value : null,
        ];
    }
};
