<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One immutable snapshot of a layout. `project_data` is the source of truth (the
 * GrapesJS document); `html` and `css` are derived from it and regenerable, kept only
 * so that reissuing a document does not depend on running the editor again.
 *
 * There are no soft deletes here on purpose: a version is never disabled, it moves
 * through `draft` -> `validated` -> `active` -> `archived`, and issued documents must
 * keep pointing at the exact version that produced them.
 *
 * `created_by` and `activated_by` carry no foreign key because users live in the
 * host's own database, not in this one. The denormalised name follows the audit log
 * convention: the record has to stay readable even after the user is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpl_template_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->ulid('template_id');
            $table->unsignedSmallInteger('number');
            $table->string('state', 12)->default('draft');

            if (config('templates.tenancy.mode') === 'column') {
                $table->string('tenant_id', 64)->nullable()->index();
            }

            $table->json('project_data');
            $table->longText('html')->nullable();
            $table->longText('css')->nullable();

            // SHA-256 of the canonical `project_data` JSON, for the audit trail (RA-02).
            $table->char('hash', 64)->nullable();

            // The catalogue revision this version was materialised from (spec 4.4).
            // Null on everything the user drew, filled in on what came from a seeder.
            $table->unsignedInteger('layout_revision')->nullable();

            $table->string('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->string('activated_by')->nullable();
            $table->string('activated_by_name')->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on('tpl_templates')
                ->cascadeOnDelete();

            $table->unique(['template_id', 'number']);
            $table->index(['template_id', 'state']);
        });

        Schema::table('tpl_templates', function (Blueprint $table): void {
            $table->foreign('active_version_id')
                ->references('id')
                ->on('tpl_template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tpl_templates', function (Blueprint $table): void {
            $table->dropForeign(['active_version_id']);
        });

        Schema::dropIfExists('tpl_template_versions');
    }
};
