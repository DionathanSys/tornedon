<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('company_role_assignments')->whereNotNull('bpo_engagement_id')->where('is_active', true)->select('company_role_id');
        DB::table('company_roles')->whereIn('id', $ids)->orderBy('id')->chunkById(100, function ($roles): void {
            foreach ($roles as $role) {
                $panels = json_decode($role->panels, true) ?? [];
                DB::table('company_roles')->where('id', $role->id)->update(['panels' => json_encode(array_values(array_unique([...$panels, 'bpo'])))]);
            }
        });
    }

    public function down(): void
    {
        DB::table('company_roles')->orderBy('id')->chunkById(100, function ($roles): void {
            foreach ($roles as $role) {
                $panels = json_decode($role->panels, true) ?? [];
                DB::table('company_roles')->where('id', $role->id)->update(['panels' => json_encode(array_values(array_diff($panels, ['bpo'])))]);
            }
        });
    }
};
