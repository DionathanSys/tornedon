<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('company_preferences')
            ->whereIn('key', [
                'customer_status_notification_config',
                'customer_status_notification_templates',
            ])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Sem restauração automática das preferências legadas removidas.
    }
};

