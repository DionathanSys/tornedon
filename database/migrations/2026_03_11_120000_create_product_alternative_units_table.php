<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_alternative_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('unit', 3);
            $table->decimal('conversion_factor', 20, 8)
                ->comment('Quantidade da unidade alternativa equivalente a 1 unidade padrao do produto');
            $table->timestamps();

            $table->unique(['product_id', 'unit']);
        });

        DB::table('products')
            ->select(['id', 'unit', 'alternative_units'])
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                $rows = [];
                $now = now();

                foreach ($products as $product) {
                    $alternativeUnits = json_decode($product->alternative_units ?? '[]', true);

                    if (!is_array($alternativeUnits)) {
                        continue;
                    }

                    foreach ($alternativeUnits as $alternativeUnit) {
                        if (!is_string($alternativeUnit) || $alternativeUnit === '' || $alternativeUnit === $product->unit) {
                            continue;
                        }

                        $rows[] = [
                            'product_id' => $product->id,
                            'unit' => $alternativeUnit,
                            'conversion_factor' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('product_alternative_units')->upsert(
                        $rows,
                        ['product_id', 'unit'],
                        ['conversion_factor', 'updated_at']
                    );
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_alternative_units');
    }
};
