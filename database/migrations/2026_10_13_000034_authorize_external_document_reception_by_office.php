<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE office_capabilities DROP CONSTRAINT office_capabilities_capability_check');
        DB::statement(<<<'SQL'
            ALTER TABLE office_capabilities
            ADD CONSTRAINT office_capabilities_capability_check
            CHECK (capability IN (
                'archive_expedients', 'close_expedients', 'void_expedients',
                'approve_reopenings', 'receive_external_documents'
            ))
        SQL);

        $now = now();
        $officeIds = DB::table('offices')->whereIn('code', ['URA', 'SGEN'])->pluck('id');

        foreach ($officeIds as $officeId) {
            DB::table('office_capabilities')->insertOrIgnore([
                'office_id' => $officeId,
                'capability' => 'receive_external_documents',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('office_capabilities')
            ->where('capability', 'receive_external_documents')
            ->delete();

        DB::statement('ALTER TABLE office_capabilities DROP CONSTRAINT office_capabilities_capability_check');
        DB::statement(<<<'SQL'
            ALTER TABLE office_capabilities
            ADD CONSTRAINT office_capabilities_capability_check
            CHECK (capability IN ('archive_expedients', 'close_expedients', 'void_expedients', 'approve_reopenings'))
        SQL);
    }
};
