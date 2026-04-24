<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('service_orders', 'gross_amount') ? 'gross_amount' : null,
                Schema::hasColumn('service_orders', 'discount_amount') ? 'discount_amount' : null,
                Schema::hasColumn('service_orders', 'total_amount') ? 'total_amount' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('requisitions', 'gross_amount') ? 'gross_amount' : null,
                Schema::hasColumn('requisitions', 'discount_amount') ? 'discount_amount' : null,
                Schema::hasColumn('requisitions', 'total_amount') ? 'total_amount' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('quotes', 'gross_amount') ? 'gross_amount' : null,
                Schema::hasColumn('quotes', 'discount_amount') ? 'discount_amount' : null,
                Schema::hasColumn('quotes', 'total_amount') ? 'total_amount' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('service_orders', 'gross_amount')) {
                $table->decimal('gross_amount', 15, 2)->default(0)->after('travel_value');
            }

            if (! Schema::hasColumn('service_orders', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('gross_amount');
            }

            if (! Schema::hasColumn('service_orders', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->default(0)->after('discount_amount');
            }
        });

        Schema::table('requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('requisitions', 'gross_amount')) {
                $table->decimal('gross_amount', 15, 2)->default(0)->after('status');
            }

            if (! Schema::hasColumn('requisitions', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('gross_amount');
            }

            if (! Schema::hasColumn('requisitions', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->default(0)->after('discount_amount');
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'gross_amount')) {
                $table->decimal('gross_amount', 15, 2)->default(0)->after('status');
            }

            if (! Schema::hasColumn('quotes', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('gross_amount');
            }

            if (! Schema::hasColumn('quotes', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->default(0)->after('discount_amount');
            }
        });
    }
};
