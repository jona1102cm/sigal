<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year');
            $table->string('status', 8)->index();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('inactivated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['start_year', 'end_year']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legislatures
            ADD CONSTRAINT legislatures_consecutive_years_check
            CHECK (end_year = start_year + 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislatures
            ADD CONSTRAINT legislatures_status_check
            CHECK (status IN ('active', 'inactive'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX legislatures_only_one_active
            ON legislatures ((status))
            WHERE status = 'active'
        SQL);

        Schema::create('legislature_board_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('position', 24);
            $table->date('effective_on');
            $table->timestampTz('effective_at');
            $table->date('ended_on')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE legislature_board_assignments
            ADD CONSTRAINT legislature_board_assignments_position_check
            CHECK (position IN (
                'president',
                'vice_president',
                'second_vice_president',
                'secretary',
                'second_secretary'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislature_board_assignments
            ADD CONSTRAINT legislature_board_assignments_effective_date_check
            CHECK (effective_on = (effective_at AT TIME ZONE 'America/La_Paz')::date)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislature_board_assignments
            ADD CONSTRAINT legislature_board_assignments_end_date_check
            CHECK (
                (ended_on IS NULL AND ended_at IS NULL)
                OR (
                    ended_on = (ended_at AT TIME ZONE 'America/La_Paz')::date
                    AND ended_at >= effective_at
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislature_board_assignments
            ADD CONSTRAINT legislature_board_assignments_no_overlapping_position
            EXCLUDE USING gist (
                legislature_id WITH =,
                position WITH =,
                tstzrange(
                    effective_at,
                    COALESCE(ended_at, 'infinity'::timestamptz),
                    '[)'
                ) WITH &&
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('legislature_board_assignments');
        Schema::dropIfExists('legislatures');
    }
};
