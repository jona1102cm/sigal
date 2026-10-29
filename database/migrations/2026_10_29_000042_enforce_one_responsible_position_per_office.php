<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // El catálogo puede contener muchos cargos, pero solo uno de ellos puede
        // representar la responsabilidad institucional de una misma oficina.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX office_positions_one_manager_per_office
            ON office_positions (office_id)
            WHERE membership_role = 'manager'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS office_positions_one_manager_per_office');
    }
};
