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
            'service_order_signature_links_service_order_id_expires_at_index',
        );
        $this->addIndexIfMissing(
            ['service_order_id', 'used_at', 'revoked_at'],
            'so_sig_links_order_state_idx',
            'service_order_signature_links_service_order_id_used_at_revoked_at_index',
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

    private function addIndexIfMissing(array $columns, string $index, string $legacyIndex): void
    {
        $indexExists = DB::connection()->getDriverName() === 'mysql'
            && DB::table('information_schema.statistics')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', self::TABLE)
                ->whereIn('index_name', [$index, $legacyIndex])
                ->exists();

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
