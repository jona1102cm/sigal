<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $humanResourcesId = DB::table('offices')->where('code', 'RRHH')->value('id');

        if ($humanResourcesId !== null) {
            DB::table('offices')
                ->where('code', 'PLANILLAS')
                ->update(['parent_id' => $humanResourcesId, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $financialAdministrationId = DB::table('offices')->where('code', 'UAF')->value('id');

        if ($financialAdministrationId !== null) {
            DB::table('offices')
                ->where('code', 'PLANILLAS')
                ->update(['parent_id' => $financialAdministrationId, 'updated_at' => now()]);
        }
    }
};
