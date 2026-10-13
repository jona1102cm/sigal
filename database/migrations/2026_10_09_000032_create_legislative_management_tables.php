<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legislature_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['legislature_id', 'effective_from']);
            $table->index(['user_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legislature_members
            ADD CONSTRAINT legislature_members_effective_range_check
            CHECK (effective_to IS NULL OR effective_to > effective_from)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislature_members
            ADD CONSTRAINT legislature_members_no_overlapping_user
            EXCLUDE USING gist (
                legislature_id WITH =,
                user_id WITH =,
                tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz), '[)') WITH &&
            )
        SQL);

        Schema::create('legislative_bodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('body_type', 40);
            $table->string('name');
            $table->text('purpose')->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['legislature_id', 'body_type']);
            $table->index(['office_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_bodies
            ADD CONSTRAINT legislative_bodies_type_check
            CHECK (body_type IN ('caucus', 'permanent_commission', 'temporary_mixed_commission'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_bodies
            ADD CONSTRAINT legislative_bodies_effective_range_check
            CHECK (effective_to IS NULL OR effective_to > effective_from)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_bodies
            ADD CONSTRAINT legislative_bodies_no_overlapping_office
            EXCLUDE USING gist (
                legislature_id WITH =,
                office_id WITH =,
                tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz), '[)') WITH &&
            )
        SQL);

        Schema::create('legislative_body_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislative_body_id')->constrained()->restrictOnDelete();
            $table->foreignId('legislature_member_id')->constrained()->restrictOnDelete();
            $table->string('role', 24);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['legislative_body_id', 'effective_from']);
            $table->index(['legislature_member_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_body_memberships
            ADD CONSTRAINT legislative_body_memberships_role_check
            CHECK (role IN ('chief', 'deputy_chief', 'president', 'vice_president', 'secretary', 'member'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_body_memberships
            ADD CONSTRAINT legislative_body_memberships_effective_range_check
            CHECK (effective_to IS NULL OR effective_to > effective_from)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_body_memberships
            ADD CONSTRAINT legislative_body_memberships_no_overlapping_member
            EXCLUDE USING gist (
                legislative_body_id WITH =,
                legislature_member_id WITH =,
                tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz), '[)') WITH &&
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_body_memberships
            ADD CONSTRAINT legislative_body_memberships_no_overlapping_unique_role
            EXCLUDE USING gist (
                legislative_body_id WITH =,
                role WITH =,
                tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz), '[)') WITH &&
            )
            WHERE (role IN ('chief', 'deputy_chief', 'president', 'vice_president', 'secretary'))
        SQL);

        Schema::create('legislative_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislative_body_id')->constrained()->restrictOnDelete();
            $table->foreignId('principal_membership_id')->constrained('legislative_body_memberships')->restrictOnDelete();
            $table->foreignId('delegate_membership_id')->constrained('legislative_body_memberships')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['legislative_body_id', 'effective_from']);
            $table->index(['delegate_membership_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_delegations
            ADD CONSTRAINT legislative_delegations_effective_range_check
            CHECK (effective_to > effective_from)
        SQL);

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('acting_legislature_member_id')
                ->nullable()
                ->after('issued_by')
                ->constrained('legislature_members')
                ->restrictOnDelete();
            $table->foreignId('acting_body_membership_id')
                ->nullable()
                ->after('acting_legislature_member_id')
                ->constrained('legislative_body_memberships')
                ->restrictOnDelete();
            $table->jsonb('legislative_sender_snapshot')->nullable()->after('acting_body_membership_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legislative_delegations
            ADD CONSTRAINT legislative_delegations_no_overlapping_principal
            EXCLUDE USING gist (
                principal_membership_id WITH =,
                tstzrange(effective_from, effective_to, '[)') WITH &&
            )
        SQL);

        Schema::table('expedient_movement_recipients', function (Blueprint $table) {
            $table->foreignId('attention_legislature_member_id')
                ->nullable()
                ->after('recipient_office_id')
                ->constrained('legislature_members')
                ->restrictOnDelete();
            $table->index(['attention_legislature_member_id', 'status'], 'movement_recipients_attention_status_index');
        });

        Schema::create('plenary_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('expedient_id')->constrained()->restrictOnDelete();
            $table->foreignId('secretariat_office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('referred_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('referred_at');
            $table->timestampTz('concluded_at')->nullable();
            $table->foreignId('concluded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['legislature_id', 'referred_at']);
            $table->index(['expedient_id', 'referred_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX plenary_referrals_one_open_per_expedient
            ON plenary_referrals (expedient_id)
            WHERE concluded_at IS NULL
        SQL);

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('acting_legislature_member_id')
                ->nullable()
                ->after('actor_id')
                ->constrained('legislature_members')
                ->nullOnDelete();
            $table->foreignId('acting_body_membership_id')
                ->nullable()
                ->after('acting_legislature_member_id')
                ->constrained('legislative_body_memberships')
                ->nullOnDelete();
        });

        DB::table('offices')->where('code', 'ARCH')->update([
            'code' => 'URA',
            'name' => 'Unidad de Registros y Archivos',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('offices')->where('code', 'URA')->update([
            'code' => 'ARCH',
            'name' => 'Sección de Registro y Archivo Institucional',
            'updated_at' => now(),
        ]);

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acting_body_membership_id');
            $table->dropConstrainedForeignId('acting_legislature_member_id');
        });

        Schema::dropIfExists('plenary_referrals');

        Schema::table('expedient_movement_recipients', function (Blueprint $table) {
            $table->dropIndex('movement_recipients_attention_status_index');
            $table->dropConstrainedForeignId('attention_legislature_member_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('legislative_sender_snapshot');
            $table->dropConstrainedForeignId('acting_body_membership_id');
            $table->dropConstrainedForeignId('acting_legislature_member_id');
        });

        Schema::dropIfExists('legislative_delegations');
        Schema::dropIfExists('legislative_body_memberships');
        Schema::dropIfExists('legislative_bodies');
        Schema::dropIfExists('legislature_members');
    }
};
