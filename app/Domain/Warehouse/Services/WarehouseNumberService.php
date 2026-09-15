<?php

namespace App\Domain\Warehouse\Services;

use Illuminate\Support\Facades\DB;

/** Reserva correlativos anuales bajo bloqueo para evitar duplicados concurrentes. */
class WarehouseNumberService
{
    public function next(string $type, string $prefix): string
    {
        $year = (int) now()->format('Y');
        DB::table('warehouse_number_sequences')->insertOrIgnore([
            'sequence_type' => $type,
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('warehouse_number_sequences')
            ->where('sequence_type', $type)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
        $number = ((int) $sequence->last_number) + 1;

        DB::table('warehouse_number_sequences')->where('id', $sequence->id)->update([
            'last_number' => $number,
            'updated_at' => now(),
        ]);

        return sprintf('%s-%06d/%d', $prefix, $number, $year);
    }
}
