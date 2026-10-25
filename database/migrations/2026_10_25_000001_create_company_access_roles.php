<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('scoped_access_enabled')->default(false));
        Schema::table('company_user', function (Blueprint $table) {
            // Existing integrations keep their access until explicitly migrated through the team service.
            $table->string('access_mode')->default('legacy');
        });
        Schema::create('company_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('permissions');
            $table->json('panels');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::create('bpo_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responsible_id')->constrained('users');
            $table->foreignId('authorized_by')->constrained('users');
            $table->json('delegable_permissions');
            $table->boolean('can_delegate')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('company_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->foreignId('bpo_engagement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'is_active'], 'company_role_assignment_lookup');
        });
        Schema::create('company_access_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users');
            $table->foreignId('bpo_engagement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->json('role_ids');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        foreach (['account_payables', 'account_receivables'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['account_payables', 'account_receivables'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by'));
        }
        Schema::dropIfExists('company_access_invitations');
        Schema::dropIfExists('company_role_assignments');
        Schema::dropIfExists('bpo_engagements');
        Schema::dropIfExists('company_roles');
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('access_mode'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('scoped_access_enabled'));
    }
};
