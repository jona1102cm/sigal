<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE employees SET first_names = UPPER(first_names), last_names = UPPER(last_names)');
    }

    public function down(): void
    {
        // La normalizacion evita inconsistencias de identidad y no se revierte.
    }
};
