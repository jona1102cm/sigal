<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();

            $table->index(['parent_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE offices
            ADD CONSTRAINT offices_status_check
            CHECK (status IN ('active', 'inactive'))
        SQL);

        Schema::create('office_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('membership_role', 20);
            $table->string('position_title')->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['office_id', 'effective_from']);
            $table->index(['user_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_memberships
            ADD CONSTRAINT office_memberships_role_check
            CHECK (membership_role IN ('manager', 'official'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE office_memberships
            ADD CONSTRAINT office_memberships_effective_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX office_memberships_active_user_office_unique
            ON office_memberships (office_id, user_id)
            WHERE effective_to IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('office_memberships');
        Schema::dropIfExists('offices');
    }
};
