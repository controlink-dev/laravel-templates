<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpl_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type_code', 50);
            $table->string('name', 150);
            $table->ulid('active_version_id')->nullable();

            if (config('templates.tenancy.mode') === 'column') {
                $table->string('tenant_id', 64)->nullable()->index();
            }

            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'type_code']);
            $table->index('active_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpl_templates');
    }
};
