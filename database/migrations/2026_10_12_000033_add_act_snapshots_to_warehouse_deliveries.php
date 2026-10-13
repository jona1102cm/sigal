<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_deliveries', function (Blueprint $table): void {
            // El acta emitida debe conservar los datos que eran válidos al confirmar la recepción.
            $table->string('receiver_name_snapshot')->nullable()->after('confirmation_observations');
            $table->string('receiver_position_snapshot')->nullable()->after('receiver_name_snapshot');
            $table->string('requesting_office_name_snapshot')->nullable()->after('receiver_position_snapshot');
            $table->string('warehouse_responsible_name_snapshot')->nullable()->after('requesting_office_name_snapshot');
            $table->string('warehouse_responsible_position_snapshot')->nullable()->after('warehouse_responsible_name_snapshot');
            $table->string('delivered_by_name_snapshot')->nullable()->after('warehouse_responsible_position_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_deliveries', function (Blueprint $table): void {
            $table->dropColumn([
                'receiver_name_snapshot',
                'receiver_position_snapshot',
                'requesting_office_name_snapshot',
                'warehouse_responsible_name_snapshot',
                'warehouse_responsible_position_snapshot',
                'delivered_by_name_snapshot',
            ]);
        });
    }
};
