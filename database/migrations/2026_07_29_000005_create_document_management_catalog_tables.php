<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedient_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('category', 100);
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();

            $table->index(['category', 'status']);
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_official')->default(true);
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();
        });

        Schema::create('confidentiality_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('requires_explicit_access')->default(false);
            $table->unsignedSmallInteger('sort_order');
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();

            $table->unique('sort_order');
        });

        foreach (['expedient_types', 'document_types', 'confidentiality_levels'] as $table) {
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_status_check
                CHECK (status IN ('active', 'inactive'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('confidentiality_levels');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('expedient_types');
    }
};
