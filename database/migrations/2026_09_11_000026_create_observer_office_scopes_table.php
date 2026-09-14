<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observer_office_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();

            $table->index(['user_role_assignment_id', 'effective_from']);
            $table->index(['office_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE observer_office_scopes
            ADD CONSTRAINT observer_office_scopes_effective_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX observer_office_scopes_active_unique
            ON observer_office_scopes (user_role_assignment_id, office_id)
            WHERE effective_to IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('observer_office_scopes');
    }
};
