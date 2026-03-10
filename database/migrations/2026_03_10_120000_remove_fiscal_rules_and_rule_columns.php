<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_document_items')) {
            Schema::table('fiscal_document_items', function (Blueprint $table) {
                if (Schema::hasColumn('fiscal_document_items', 'fiscal_rule_id')) {
                    $table->dropConstrainedForeignId('fiscal_rule_id');
                }

                if (Schema::hasColumn('fiscal_document_items', 'fiscal_rule_version')) {
                    $table->dropColumn('fiscal_rule_version');
                }
            });
        }

        if (Schema::hasTable('fiscal_rules')) {
            Schema::drop('fiscal_rules');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_rules')) {
            Schema::create('fiscal_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fiscal_profile_id')
                    ->constrained('fiscal_profiles')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->string('operation_type')->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->json('conditions')->nullable();
                $table->json('result')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['fiscal_profile_id', 'is_enabled', 'priority'], 'fiscal_rules_lookup_idx');
            });
        }

        if (Schema::hasTable('fiscal_document_items')) {
            Schema::table('fiscal_document_items', function (Blueprint $table) {
                if (! Schema::hasColumn('fiscal_document_items', 'fiscal_rule_id')) {
                    $table->foreignId('fiscal_rule_id')
                        ->nullable()
                        ->after('fiscal_snapshot')
                        ->constrained('fiscal_rules')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('fiscal_document_items', 'fiscal_rule_version')) {
                    $table->unsignedInteger('fiscal_rule_version')
                        ->nullable()
                        ->after('fiscal_rule_id');
                }
            });
        }
    }
};
