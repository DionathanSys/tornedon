<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'service_order_signature_links';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $this->defineTable($table);
            });

            return;
        }

        // MySQL can leave the table created when a later index statement fails.
        $this->addIndexIfMissing(
            ['service_order_id', 'expires_at'],
            'so_sig_links_order_expiry_idx',
        );
        $this->addIndexIfMissing(
            ['service_order_id', 'used_at', 'revoked_at'],
            'so_sig_links_order_state_idx',
        );
    }

    private function defineTable(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('service_order_id')
            ->constrained('service_orders')
            ->cascadeOnDelete();
        $table->char('token_hash', 64)->unique();
        $table->timestamp('expires_at');
        $table->timestamp('used_at')->nullable();
        $table->timestamp('revoked_at')->nullable();
        $table->foreignId('created_by')
            ->nullable()
            ->constrained('users')
            ->nullOnDelete();
        $table->timestamps();

        $table->index(
            ['service_order_id', 'expires_at'],
            'so_sig_links_order_expiry_idx',
        );
        $table->index(
            ['service_order_id', 'used_at', 'revoked_at'],
            'so_sig_links_order_state_idx',
        );
    }

    private function addIndexIfMissing(array $columns, string $index): void
    {
        $indexExists = false;

        if (DB::connection()->getDriverName() === 'mysql') {
            $indexes = DB::table('information_schema.statistics')
                ->select(['index_name', 'column_name', 'seq_in_index'])
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', self::TABLE)
                ->get()
                ->groupBy('index_name');

            $indexExists = $indexes->contains(function ($indexColumns) use ($columns): bool {
                return $indexColumns
                    ->sortBy('seq_in_index')
                    ->pluck('column_name')
                    ->values()
                    ->all() === $columns;
            });
        }

        if ($indexExists) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $index): void {
            $table->index($columns, $index);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_signature_links');
    }
};
