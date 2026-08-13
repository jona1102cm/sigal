<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['expedients', 'expedient_movements'] as $table) {
            DB::statement(<<<SQL
                UPDATE {$table}
                SET priority = CASE lower(trim(coalesce(priority, '')))
                    WHEN 'alta' THEN 'high'
                    WHEN 'high' THEN 'high'
                    WHEN 'urgente' THEN 'urgent'
                    WHEN 'urgent' THEN 'urgent'
                    ELSE 'normal'
                END
            SQL);

            DB::statement("ALTER TABLE {$table} ALTER COLUMN priority SET DEFAULT 'normal'");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN priority SET NOT NULL");
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_priority_check
                CHECK (priority IN ('normal', 'high', 'urgent'))
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['expedients', 'expedient_movements'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_priority_check");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN priority DROP DEFAULT");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN priority DROP NOT NULL");
        }
    }
};
