<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->timestampTz('permissions_configured_at')->nullable();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('module');
            $table->string('section');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->primary(['permission_id', 'role_id']);
        });

        $now = now();
        foreach (PermissionCode::cases() as $index => $permission) {
            DB::table('permissions')->insert([
                'code' => $permission->value,
                'name' => $permission->label(),
                'module' => $permission->module(),
                'section' => $permission->section(),
                'description' => $permission->description(),
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (RoleCode::cases() as $roleCode) {
            $roleId = DB::table('roles')->where('code', $roleCode->value)->value('id');
            if ($roleId === null) {
                continue;
            }

            foreach (PermissionCode::defaultsFor($roleCode) as $permission) {
                DB::table('permission_role')->insert([
                    'permission_id' => DB::table('permissions')->where('code', $permission->value)->value('id'),
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('permissions_configured_at');
        });
    }
};
